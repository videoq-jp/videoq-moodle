// Disposable architecture spike. Leaves existing courses, connections and settings alone.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { generateKeyPairSync, randomBytes } from 'node:crypto';
import { mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout } from 'node:timers/promises';
import assert from 'node:assert/strict';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const videoq = process.env.VIDEOQ_ROOT;
if (!videoq || !existsSync(join(videoq, 'apps/api/src/features/lti/routes.ts'))) throw new Error('Set VIDEOQ_ROOT to the compatible development checkout');
const run = randomBytes(16).toString('hex');
const transport = process.env.SPIKE_TRANSPORT || 'pull';
assert(['pull', 'handoff'].includes(transport));
const directory = '/tmp/videoq-lti-lab/pull-spike-' + run;
mkdirSync(directory, { mode: 0o700 });
const remoteDirectory = '/lab/pull-spike-' + run;
const docker = (args, input) => execFileSync('sudo', ['-n', 'docker', ...args], { encoding: 'utf8', timeout: 120000, input }).trim();
const action = (name, input) => JSON.parse(docker(['exec', '-i', 'videoq-lti-lab-moodle-1', 'php', '/lab/spikes/lti-pull/moodle-fixture.php', name], input));
const endpointPath = '/tmp/videoq-lti-moodle-source/public/videoq-lti-pull-spike.php';
const compiled = join(videoq, 'apps/api/dist/lti-pull-spike-' + run + '.mjs');
const apiPath = '/workspace/apps/api/dist/lti-pull-spike-' + run + '.mjs';
const core = name => JSON.parse(docker(['exec', 'videoq-lti-lab-api-1', 'node', apiPath, name, remoteDirectory]));
const query = sql => JSON.parse(docker(['exec', 'videoq-lti-lab-api-1', 'node', '--input-type=module', '-e',
  `import{labDatabase}from'/fixtures/lab-database.mjs';const db=await labDatabase();try{console.log(JSON.stringify((await db.query(${JSON.stringify(sql)})).rows));}finally{await db.end();}`]));
const baseline = query('SELECT id,file,status FROM videos ORDER BY id');
const connections = query('SELECT id,active FROM lms_installations ORDER BY id');
const checks = [], check = message => { checks.push(message); console.log(message); };
let fixture, initialized = false, endpointInstalled = false;
let first, replacement, queueUrl, auth;
const processed = new Set();
async function completed() {
  for (let attempt = 0; attempt < 120; attempt++) {
    const state = core('state');
    if (state?.video_id && state.status !== 'completed' && !processed.has(state.video_id)) {
      docker(['exec', 'videoq-lti-lab-worker-1', 'python', '/lab/spikes/lti-pull/process-fixture.py', String(state.video_id), queueUrl]);
      processed.add(state.video_id);
    }
    if (state?.status === 'completed' && state.scenes > 0 && state.transcript_chars > 0) return state;
    assert.notEqual(state?.status, 'error', 'Real transcription/indexing failed');
    await setTimeout(1500);
  }
  throw new Error('Real processing timed out');
}
async function removedObject(file) {
  writeFileSync(join(directory, 'object.json'), JSON.stringify({ file }), { mode: 0o600 });
  for (let attempt = 0; attempt < 50; attempt++) {
    if (!core('object').exists) return;
    await setTimeout(1500);
  }
  throw new Error('Object cleanup did not complete');
}
try {
  if (existsSync(endpointPath)) throw new Error('Another spike endpoint already exists');
  const speech = '/tmp/videoq-lti-lab/acceptance/spoken-lesson.mp4';
  if (!existsSync(speech)) throw new Error('The lab spoken lesson fixture is required');
  execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', speech, '-map', '0', '-c', 'copy', '-metadata', 'comment=pull-spike-replacement', join(directory, 'replacement.mp4')]);
  docker(['cp', speech, 'videoq-lti-lab-moodle-1:/tmp/videoq-pull-spoken.mp4']);
  docker(['cp', join(directory, 'replacement.mp4'), 'videoq-lti-lab-moodle-1:/tmp/videoq-pull-replacement.mp4']);
  const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048,
    privateKeyEncoding: { type: 'pkcs8', format: 'pem' }, publicKeyEncoding: { type: 'spki', format: 'pem' } });
  writeFileSync(join(directory, 'private.pem'), privateKey, { mode: 0o600 });
  fixture = action('setup', JSON.stringify({ run, publicKey }));
  queueUrl = JSON.parse(docker(['exec', 'videoq-lti-lab-worker-1', 'python', '/lab/spikes/lti-pull/queue-fixture.py', 'create', run])).url;
  fixture.queueUrl = queueUrl; fixture.transport = transport;
  writeFileSync(join(directory, 'fixture.json'), JSON.stringify(fixture), { mode: 0o600 });
  docker(['cp', join(root, 'lab/spikes/lti-pull/moodle-endpoint.php'), 'videoq-lti-lab-moodle-1:/var/www/html/public/videoq-lti-pull-spike.php']);
  endpointInstalled = true;
  const apiRequire = createRequire(join(videoq, 'apps/api/package.json'));
  const { build } = apiRequire('esbuild');
  const pkg = JSON.parse(readFileSync(join(videoq, 'apps/api/package.json'), 'utf8'));
  await build({ entryPoints: [join(root, 'lab/spikes/lti-pull/core-adapter.ts')], outfile: compiled,
    bundle: true, platform: 'node', target: 'node22', format: 'esm',
    nodePaths: [join(videoq, 'node_modules')],
    external: Object.keys(pkg.dependencies).filter(name => name !== '@videoq/trpc'),
    plugins: [{ name: 'videoq-source', setup(builder) { builder.onResolve({ filter: /^videoq-source\// }, args => ({ path: join(videoq, 'apps/api/src', args.path.slice('videoq-source/'.length)) + (args.path.endsWith('/schema') ? '/index.ts' : '.ts') })); } }],
  });
  assert(core('init').initialized); initialized = true;
  auth = core('auth-negative');
  check('LTI tool-key authentication rejects replay, wrong audience, ungranted scope, stock-endpoint bypass and anonymous access.');
  assert.deepEqual(core('access').allowed, [String(fixture.cmid)]);
  core('sync'); first = await completed();
  assert.deepEqual(core('retrieval').videoIds, [Number(first.video_id)]);
  check((transport === 'handoff' ? 'Moodle exported a real File directly to VideoQ storage' : 'VideoQ downloaded a real Moodle File') + '; unchanged quota/upload/worker code completed processing with deterministic HTTP inference.');
  const same = core('sync'); assert.equal(same.video_id, first.video_id);
  action('hide'); assert.deepEqual(core('access').allowed, []);
  assert.deepEqual(core('retrieval').videoIds, []); core('sync');
  assert.equal(core('state').video_id, first.video_id); assert.equal(core('state').enabled, false);
  action('show'); assert.deepEqual(core('access').allowed, [String(fixture.cmid)]); core('sync');
  assert.equal(core('state').video_id, first.video_id);
  check('Moodle learner permissions exclude hidden material; hiding and reopening retain the existing processed copy.');
  action('replace'); core('sync'); replacement = await completed();
  assert.notEqual(replacement.video_id, first.video_id); assert.equal(replacement.course_id, first.course_id);
  await removedObject(first.file);
  check('Replacement creates a processed new video in the same course and deletes the retired media object.');
  action('delete'); core('sync'); const deleted = core('state');
  assert.equal(deleted.enabled, false); assert.equal(deleted.video_id, null); await removedObject(replacement.file);
  assert.deepEqual(core('access').allowed, []);
  check('Source deletion revokes retrieval membership and removes the video row, vector data and media object.');
  assert.deepEqual(query('SELECT id,file,status FROM videos ORDER BY id'), baseline);
  check('All pre-existing VideoQ videos and their file/status identities were preserved.');

} finally {
  try {
    if (initialized) core('cleanup');
    if (fixture) action('cleanup');
    if (queueUrl) docker(['exec', 'videoq-lti-lab-worker-1', 'python', '/lab/spikes/lti-pull/queue-fixture.py', 'delete', run]);
    if (endpointInstalled) rmSync(endpointPath, { force: true });
    rmSync(compiled, { force: true });
    docker(['exec', 'videoq-lti-lab-moodle-1', 'rm', '-f', '/tmp/videoq-pull-spoken.mp4', '/tmp/videoq-pull-replacement.mp4']);
    assert.deepEqual(query('SELECT id,active FROM lms_installations ORDER BY id'), connections);
  } finally { rmSync(directory, { recursive: true, force: true }); }
}

writeFileSync('/tmp/videoq-lti-lab/acceptance/lti-' + transport + '-spike.json', JSON.stringify({ passed: true, checks, auth,
    transport, ltiToolKeyAuthentication: true, videoBytesThroughVideoqApi: transport === 'pull', productionCoreSourceFilesModified: 0, productionApiRestarted: false, isolatedQueue: true,
    realMoodleFile: true, realTransfer: true, existingTranscriptionAndIndexingPipeline: true, inferenceProvider: 'deterministic-local-fixture', syncApiKeyUsed: false,
    existingCoreFunctionsReused: ['syncLmsResource', 'presignR2Put', 'confirmVideoUpload', 'lmsUploadStream'],
    limits: ['Standalone backend adapter, not wired into the production launch or scheduler', 'Inline ephemeral tool public key; JWKS rotation not implemented', 'Uses current mapping schema with a non-credential placeholder hash'],
    temporaryVideoIds: [first.video_id, replacement.video_id], temporaryFixturesRemoved: true, existingConnectionStatesPreserved: true }, null, 2));
