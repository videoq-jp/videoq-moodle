// Existing local lab only. Never passes private keys or credentials to command arguments.
import { execFileSync } from 'node:child_process';
import { generateKeyPairSync, randomBytes, sign, randomUUID } from 'node:crypto';
const docker = (args, input) => execFileSync('sudo', ['-n', 'docker', ...args], { encoding: 'utf8', input, timeout: 120000 }).trim();
const container = 'videoq-lti-lab-moodle-1';
const action = (name, input) => JSON.parse(docker(['exec', '-i', container, 'php', '/lab/spikes/lti-pull/moodle-fixture.php', name], input));
let initialized = false;
try {
  docker(['cp', '/tmp/videoq-lti-lab/acceptance/spoken-lesson.mp4', container + ':/tmp/videoq-pull-spoken.mp4']);
  const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' } });
  const fixture = action('setup', JSON.stringify({ run: randomBytes(16).toString('hex'), publicKey })); initialized = true;
  const header = Buffer.from(JSON.stringify({ alg: 'RS256', typ: 'JWT' })).toString('base64url');
  const now = Math.floor(Date.now() / 1000);
  const payload = Buffer.from(JSON.stringify({ iss: fixture.clientid, sub: fixture.clientid,
    aud: 'https://moodle.localhost:18443/local/videoq/token.php', iat: now, exp: now + 60, jti: randomUUID(),
    'https://purl.imsglobal.org/spec/lti/claim/deployment_id': String(fixture.typeid) })).toString('base64url');
  const input = header + '.' + payload;
  const jwt = input + '.' + sign('RSA-SHA256', Buffer.from(input), privateKey).toString('base64url');
  const result = JSON.parse(docker(['exec', '-i', container, 'php', '/lab/check-materials-service.php'], JSON.stringify({ jwt })));
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
} finally {
  if (initialized) action('cleanup');
  docker(['exec', container, 'rm', '-f', '/tmp/videoq-pull-spoken.mp4']);
}
