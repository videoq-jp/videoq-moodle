/** CLI driver for production service code, confined to disposable local-lab records. */
import { readFile } from 'node:fs/promises';
import { createPrivateKey } from 'node:crypto';
import pg from 'pg';
import { sendChatMessage } from 'videoq-source/features/chat/message-service';
import { withDb } from 'videoq-source/db/pool';
import { lmsInstallations } from 'videoq-source/db/schema';
import { getLmsResource, syncLmsResource } from 'videoq-source/repositories/lms-resource-repository';
import { getCourseWithMembers } from 'videoq-source/repositories/chat-repository';
import { resourceManifestSchema } from 'videoq-source/features/lms/resource';
import { getR2ObjectSize } from 'videoq-source/integrations/media';
import { sweepMaterials } from 'videoq-source/features/lti/materials-sync';
import { MaterialsClient } from 'videoq-source/features/lti/materials-client';
import type { Bindings } from 'videoq-source/types/bindings';
const operation = process.argv[2], directory = process.argv[3];
if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq' || !/^\/lab\/pull-spike-[a-f0-9]{32}$/.test(directory ?? '')) throw new Error('Isolated lab only');
const fixture = JSON.parse(await readFile(directory + '/fixture.json', 'utf8'));
const pool = new pg.Pool({ connectionString: process.env.DATABASE_URL, max: 3 });
const env = { ...process.env, RUNTIME: 'node', ENVIRONMENT: 'production', DATABASE_POOL: pool, SQS_QUEUE_URL: fixture.queueUrl,
  LTI_CONFIG: JSON.stringify({ toolOrigin: 'https://videoq.localhost:18443', databaseRegistrations: true, registrations: [] }),
  LTI_TOOL_KEYS: JSON.stringify({ current: createPrivateKey(await readFile(directory + '/private.pem', 'utf8')).export({ format: 'jwk' }) }),
} as unknown as Bindings;
if (fixture.realAi) {
  const path = '/fixtures/openrouter-config.mjs';
  const provider = await import(path);
  Object.assign(env, provider.runtimeSettings(provider.readOpenRouterSettings('/lab/openrouter.env')));
}
const id = 'pull-spike-' + fixture.run, origin = 'https://moodle.localhost:18443';
const grant = { id, ownerUserId: 'lti-lab-owner' };
const materialsService = { tokenEndpoint: origin + '/local/videoq/token.php', endpoint: origin + '/local/videoq/materials.php' };
const state = async () => (await pool.query(`SELECT r.video_id,r.course_id,r.enabled,v.status,v.file,length(v.transcript) AS transcript_chars,
  (SELECT count(*)::int FROM scene_embeddings e WHERE e.video_id=r.video_id) AS scenes FROM lms_resources r LEFT JOIN videos v ON v.id=r.video_id
  WHERE r.installation_id=$1 AND r.external_id=$2`, [id, String(fixture.cmid)])).rows[0] ?? null;
try {
  let result: unknown;
  if (operation === 'init') {
    await withDb(env, db => db.insert(lmsInstallations).values({ ...grant, name: 'Materials runtime fixture', issuer: origin,
      clientId: fixture.clientid, deploymentId: String(fixture.typeid), authorizationUrl: origin + '/mod/lti/auth.php',
      jwksUrl: origin + '/mod/lti/certs.php', syncTokenHash: null, materialsService }));
    result = { initialized: true };
  } else if (operation === 'sync') {
    await pool.query('UPDATE lms_installations SET next_materials_sync_at=now() WHERE id=$1', [id]);
    if ((await sweepMaterials(env)).synchronized !== 1) throw new Error('Sweep did not run');
    result = await state();
  } else if (operation === 'answer') {
    const current = await state();
    const answer = await sendChatMessage(env, { userId: null, shareSlug: null, ltiCourseGrant: Number(current.course_id),
      ltiVideoGrant: [Number(current.video_id)], locale: 'ja', body: { course_id: Number(current.course_id),
        messages: [{ role: 'user', content: 'この動画の要点を短く説明してください。' }] } });
    if (answer.status !== 200 || !('answer' in answer.body)) throw new Error('Real answer failed');
    result = { answered: answer.body.answer.segments.length > 0 };
  } else if (operation === 'access' || operation === 'retrieval') {
    const client = new MaterialsClient(env, { clientId: fixture.clientid, deploymentId: String(fixture.typeid), materialsService });
    const item = (await client.manifest(String(fixture.courseid))).resources[0];
    // A denied learner request must produce no retrieval members.
    const access = item.manifest.deleted ? { resources: [] } : await client.access(String(fixture.courseid), item.manifest.resourceLinkId,
      String(fixture.learnerid)).catch(() => ({ resources: [] }));
    const allowed = access.resources.map(item => item.externalId);
    if (operation === 'access') result = { allowed };
    else {
      const resource = await state();
      const course = resource?.course_id ? await getCourseWithMembers(env, { courseId: Number(resource.course_id), ltiCourseGrant: Number(resource.course_id),
        ltiVideoGrant: allowed.includes(String(fixture.cmid)) && resource.video_id ? [Number(resource.video_id)] : [] }) : null;
      result = { videoIds: course?.memberVideoIds ?? [] };
    }
  } else if (operation === 'state') result = await state();
  else if (operation === 'object') {
    const { file } = JSON.parse(await readFile(directory + '/object.json', 'utf8'));
    result = { exists: await getR2ObjectSize(env, file) !== null };
  } else if (operation === 'cleanup') {
    const current = await state();
    if (current?.video_id) await syncLmsResource(env, grant, String(fixture.cmid), resourceManifestSchema.parse({ generation: 999999,
      contextId: String(fixture.courseid), resourceLinkId: 'removed-fixture', title: 'Removed fixture', enabled: false, deleted: true }));
    await pool.query('DELETE FROM lti_credentials WHERE kind=$1 AND payload->>\'installationId\'=$2', ['materials-export', id]);
    await pool.query("DELETE FROM video_courses c WHERE c.name=$1 AND c.user_id=$2 AND NOT EXISTS(SELECT 1 FROM video_course_members m WHERE m.course_id=c.id)",
      ['VideoQ LTI pull spike ' + fixture.run, grant.ownerUserId]);
    await pool.query('DELETE FROM lms_installations WHERE id=$1 AND owner_user_id=$2', [id, grant.ownerUserId]);
    result = { removed: true };
  } else throw new Error('Unknown operation');
  console.log(JSON.stringify(result));
} catch (error) {
  console.error(JSON.stringify({ operation, type: error instanceof Error ? error.name : 'unknown', error: 'MATERIALS_RUNTIME_CHECK_FAILED' }));
  process.exitCode = 1;
} finally { await pool.end(); }
