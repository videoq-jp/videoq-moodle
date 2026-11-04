/** Experimental backend adapter. Built separately, never mounted into the running API. */
import { readFile } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';
import { SignJWT, importPKCS8 } from 'jose';
import pg from 'pg';
import { withDb } from 'videoq-source/db/pool';
import { lmsInstallations } from 'videoq-source/db/schema';
import { syncLmsResource, getLmsResource } from 'videoq-source/repositories/lms-resource-repository';
import { resourceManifestSchema } from 'videoq-source/features/lms/resource';
import { presignR2Put, getR2ObjectSize } from 'videoq-source/integrations/media';
import { confirmVideoUpload } from 'videoq-source/features/videos/service';
import { lmsUploadStream } from 'videoq-source/features/lms/upload';
import { getCourseWithMembers } from 'videoq-source/repositories/chat-repository';
import { disconnectLmsInstallation } from 'videoq-source/repositories/lms-installation-repository';
import type { Bindings } from 'videoq-source/types/bindings';

type Fixture = { run: string; courseid: number; cmid: number; clientid: string; typeid: number; learnerid: number; queueUrl: string; transport: string };
const operation = process.argv[2];
const runDirectory = process.argv[3];
if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq' || !/^\/lab\/pull-spike-[a-f0-9]{32}$/.test(runDirectory ?? '')) throw new Error('Isolated lab only');
const fixture: Fixture = JSON.parse(await readFile(runDirectory + '/fixture.json', 'utf8'));
const pool = new pg.Pool({ connectionString: process.env.DATABASE_URL, max: 3 });
const env = { ...process.env, RUNTIME: 'node', ENVIRONMENT: 'production', DATABASE_POOL: pool, SQS_QUEUE_URL: fixture.queueUrl } as unknown as Bindings;
const installationId = 'pull-spike-' + fixture.run;
const grant = { id: installationId, ownerUserId: 'lti-lab-owner' };
const origin = 'https://moodle.localhost:18443';
const endpoint = origin + '/videoq-lti-pull-spike.php?run=' + fixture.run;
const scope = 'https://videoq.example/spec/lti-materials/scope/readonly';
async function assertion(audience = endpoint + '&action=token') {
  const key = await importPKCS8(await readFile(runDirectory + '/private.pem', 'utf8'), 'RS256');
  return new SignJWT({ 'https://purl.imsglobal.org/spec/lti/claim/deployment_id': String(fixture.typeid) })
    .setProtectedHeader({ alg: 'RS256' }).setIssuer(fixture.clientid).setSubject(fixture.clientid).setAudience(audience)
    .setIssuedAt().setExpirationTime('60s').setJti(randomBytes(16).toString('hex')).sign(key);
}
async function tokenRequest(jwt: string, requestedScope = scope, url = endpoint + '&action=token') {
  return fetch(url, { method: 'POST', redirect: 'error', signal: AbortSignal.timeout(15000),
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'client_credentials', client_assertion_type: 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', client_assertion: jwt, scope: requestedScope }) });
}
async function platformToken() {
  const response = await tokenRequest(await assertion());
  if (!response.ok) throw new Error('PLATFORM_TOKEN_REJECTED_' + response.status);
  const token = await response.json() as { access_token: string; scope: string };
  if (token.scope !== scope || !token.access_token) throw new Error('PLATFORM_SCOPE_MISMATCH');
  return token.access_token;
}
async function platform(action: string, token: string, init: RequestInit = {}) {
  const url = endpoint + '&action=' + action + '&course=' + fixture.courseid;
  return fetch(url, { ...init, redirect: 'error', signal: AbortSignal.timeout(30000), headers: { ...init.headers, Authorization: 'Bearer ' + token } });
}
async function active() {
  const row = (await pool.query('SELECT active FROM lms_installations WHERE id=$1', [installationId])).rows[0];
  if (!row?.active) throw new Error('CONNECTION_REVOKED');
}
async function state() {
  const rows = (await pool.query(`SELECT r.video_id,r.course_id,r.enabled,v.status,v.file,length(v.transcript) AS transcript_chars,
    (SELECT count(*)::int FROM scene_embeddings e WHERE e.video_id=r.video_id) AS scenes
    FROM lms_resources r LEFT JOIN videos v ON v.id=r.video_id WHERE r.installation_id=$1 AND r.external_id=$2`, [installationId, String(fixture.cmid)])).rows;
  return rows[0] ?? null;
}
let stage = 'start';
try {
  let result: unknown;
  if (operation === 'init') {
    // The legacy schema requires a hash even though this prototype never issues or uses a sync key.
    await withDb(env, db => db.insert(lmsInstallations).values({ ...grant, id: installationId, name: 'LTI pull spike', issuer: origin,
      clientId: fixture.clientid, deploymentId: String(fixture.typeid), authorizationUrl: origin + '/mod/lti/auth.php',
      jwksUrl: origin + '/mod/lti/certs.php', syncTokenHash: randomBytes(32).toString('hex') }));
    result = { initialized: true };
  } else if (operation === 'sync') {
    await active();
    const token = await platformToken();
    const response = await platform('manifest', token);
    if (!response.ok) throw new Error('MANIFEST_REJECTED_' + response.status);
    const source = await response.json() as { externalId: string; manifest: unknown; contentUrl: string | null };
    if (source.externalId !== String(fixture.cmid)) throw new Error('SOURCE_MISMATCH');
    stage = 'manifest-schema';
    const manifest = resourceManifestSchema.parse(source.manifest);
    if (manifest.contextId !== String(fixture.courseid)) throw new Error('CONTEXT_MISMATCH');
    stage = 'reconcile';
    const resource = await syncLmsResource(env, grant, source.externalId, manifest);
    const data = await getLmsResource(env, grant, source.externalId);
    if (manifest.enabled && data?.video?.status === 'uploading') {
      if (!source.contentUrl || new URL(source.contentUrl).origin !== origin || !source.contentUrl.startsWith(endpoint + '&action=content&')) throw new Error('UNTRUSTED_CONTENT_URL');
      stage = 'storage-url';
      const url = await presignR2Put(env, data.video.file, manifest.file!.contentType, manifest.file!.size);
      if (fixture.transport === 'handoff') {
        stage = 'moodle-export';
        const exported = await platform('export', token, { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ generation: manifest.generation, uploadUrl: url }) });
        if (!exported.ok) throw new Error('MOODLE_EXPORT_FAILED_' + exported.status);
        const receipt = await exported.json() as { uploaded: boolean; generation: number; bytes: number };
        if (!receipt.uploaded || receipt.generation !== manifest.generation || receipt.bytes !== manifest.file!.size) throw new Error('EXPORT_RECEIPT_MISMATCH');
      } else {
        stage = 'download';
        const media = await fetch(source.contentUrl, { headers: { Authorization: 'Bearer ' + token }, redirect: 'error', signal: AbortSignal.timeout(30000) });
        if (!media.ok || !media.body || media.headers.get('content-length') !== String(manifest.file!.size)) throw new Error('INVALID_CONTENT_RESPONSE');
        stage = 'storage-put';
        const uploaded = await fetch(url, { method: 'PUT', redirect: 'error', signal: AbortSignal.timeout(30000),
          headers: { 'Content-Type': manifest.file!.contentType, 'Content-Length': String(manifest.file!.size) },
          body: lmsUploadStream(media.body, manifest.file!.size), duplex: 'half' } as RequestInit);
        if (!uploaded.ok) throw new Error('OBJECT_UPLOAD_FAILED');
      }
      await active();
      const current = await getLmsResource(env, grant, source.externalId);
      if (current?.resource.generation !== resource.generation) throw new Error('SOURCE_CHANGED_DURING_TRANSFER');
      stage = 'confirm';
      const confirmed = await confirmVideoUpload(env, data.video.id, grant.ownerUserId);
      if (!('video' in confirmed)) throw new Error('UPLOAD_CONFIRMATION_FAILED');
    }
    result = await state();
  } else if ((operation === 'access' || operation === 'retrieval') ) {
    await active();
    const response = await platform('access', await platformToken(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ subject: String(fixture.learnerid) }) });
    if (!response.ok) { const detail = await response.json() as {error?: string}; console.error(JSON.stringify({accessStatus: response.status,code: detail.error})); throw new Error('ACCESS_CHECK_FAILED'); }
    const access = await response.json() as { allowed: string[] };
    if (operation === 'retrieval') {
      const resource = await state();
      const course = resource?.course_id ? await getCourseWithMembers(env, { courseId: Number(resource.course_id),
        ltiCourseGrant: Number(resource.course_id), ltiVideoGrant: access.allowed.includes(String(fixture.cmid)) && resource.video_id ? [Number(resource.video_id)] : [] }) : null;
      result = { videoIds: course?.memberVideoIds ?? [] };
    } else result = access;
  } else if (operation === 'auth-negative') {
    const jwt = await assertion();
    const first = await tokenRequest(jwt); if (!first.ok) throw new Error('AUTH_FIXTURE_FAILED'); await first.body?.cancel();
    const replay = await tokenRequest(jwt); const wrongAudience = await tokenRequest(await assertion(origin + '/wrong-endpoint'));
    const deniedScope = await tokenRequest(await assertion(), scope + '.write');
    const bypass = await tokenRequest(await assertion(origin + '/mod/lti/token.php'), scope, origin + '/mod/lti/token.php');
    const anonymous = await fetch(endpoint + '&action=manifest&course=' + fixture.courseid);
    result = { replay: replay.status, wrongAudience: wrongAudience.status, deniedScope: deniedScope.status,
      stockEndpointBypass: bypass.status, anonymous: anonymous.status };
    if (replay.status !== 401 || wrongAudience.status !== 401 || deniedScope.status !== 403 || bypass.status === 200 || anonymous.status !== 401) throw new Error('NEGATIVE_AUTH_CHECK_FAILED');
  } else if (operation === 'state') result = await state();
  else if (operation === 'revoke') result = { revoked: await disconnectLmsInstallation(env, grant.ownerUserId, installationId) };
  else if (operation === 'cleanup') {
    const current = await state();
    if (current?.video_id) await syncLmsResource(env, grant, String(fixture.cmid), resourceManifestSchema.parse({ generation: 999999,
      contextId: String(fixture.courseid), resourceLinkId: 'spike-' + fixture.cmid, title: 'Removed spike', enabled: false, deleted: true }));
    await pool.query("DELETE FROM video_courses c WHERE c.name=$1 AND c.user_id=$2 AND NOT EXISTS(SELECT 1 FROM video_course_members m WHERE m.course_id=c.id)", ['VideoQ LTI pull spike ' + fixture.run, grant.ownerUserId]);
    await pool.query('DELETE FROM lms_installations WHERE id=$1 AND owner_user_id=$2', [installationId, grant.ownerUserId]);
    result = { removed: true };
  } else if (operation === 'object') {
    const input = JSON.parse(await readFile(runDirectory + '/object.json', 'utf8'));
    result = { exists: await getR2ObjectSize(env, input.file) !== null };
  } else throw new Error('UNKNOWN_OPERATION');
  console.log(JSON.stringify(result));
} catch (error) {
  console.error(JSON.stringify({ failedOperation: operation, stage, type: error instanceof Error ? error.name : 'unknown', reason: error instanceof Error && /^[A-Z_]+(?:[0-9]+)?$/.test(error.message) ? error.message : 'SPIKE_OPERATION_FAILED' }));
  process.exitCode = 1;
} finally { await pool.end(); }
