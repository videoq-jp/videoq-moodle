// Prepare or reuse the isolated lab connection. Credentials stay in a private
// file and process memory; none are printed in the verifier's output.
import { execFileSync } from 'node:child_process';
import { existsSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const args = ['-n', 'docker', 'compose', '-p', 'videoq-lti-lab', '--env-file', join(dir, 'compose.env'),
  '-f', fileURLToPath(new URL('./compose.yaml', import.meta.url)), '-f', fileURLToPath(new URL('./compose.sync.yaml', import.meta.url))];
const dc = (commands, input) => execFileSync('sudo', [...args, ...commands], { encoding: 'utf8', input, timeout: 60000 });
const fixture = JSON.parse(dc(['exec', '-T', 'moodle', 'php', '/lab/prepare-sync.php']));
writeFileSync(join(dir, 'sync-fixture.json'), JSON.stringify(fixture, null, 2));
writeFileSync(join(dir, 'sync-connection.json'), JSON.stringify(fixture.connection, null, 2));
if (!existsSync(join(dir, 'sync-credential.json'))) {
  dc(['exec', '-T', 'api', 'node', 'dist/provision-lms.mjs', 'register', '/lab/sync-connection.json', '/lab/sync-credential.json']);
}
const credential = dc(['exec', '-T', 'api', 'node', '--input-type=module', '-e',
  'import {readFileSync} from "node:fs";process.stdout.write(readFileSync("/lab/sync-credential.json","utf8"));']);
dc(['exec', '-T', 'moodle', 'php', '/lab/prepare-sync.php', 'configure'], credential);
console.log('Moodle synchronization lab configured using its existing VideoQ account.');
