// Run on the host. Only the API is stopped; Moodle and its media stay available.
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join, resolve } from 'node:path';
import { readOpenRouterSettings } from './openrouter-config.mjs';
const dir = resolve(process.argv[2] || '/tmp/videoq-lti-lab');
readOpenRouterSettings(join(dir, 'openrouter.env')); // Fail before stopping anything if incomplete.
const compose = ['-n', 'docker', 'compose', '-p', 'videoq-lti-lab', '--env-file', join(dir, 'compose.env'), '-f', fileURLToPath(new URL('./compose.yaml', import.meta.url))];
const run = args => execFileSync('sudo', [...compose, ...args], { stdio: 'inherit' });
run(['stop', 'api']);
try {
  run(['run', '--rm', '--no-deps', 'api', 'node', '/fixtures/index-openrouter.mjs']);
} finally {
  // State and document vectors commit together; startup cannot mix real and fixture vectors.
  run(['up', '-d', 'api']);
}
console.log('OpenRouter configuration applied. Refresh the Moodle lesson before asking a new question.');
