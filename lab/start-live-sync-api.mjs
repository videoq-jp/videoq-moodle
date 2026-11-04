// Isolated acceptance environment: real storage/queue and the user's configured AI connection.
import { readFileSync } from 'node:fs';
import { readOpenRouterSettings, runtimeSettings } from './openrouter-config.mjs';
if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq') throw new Error('Use only the isolated lab');
const config = JSON.parse(readFileSync('/lab/lti-config.json', 'utf8'));
process.env.LTI_CONFIG = JSON.stringify({ ...config, databaseRegistrations: true });
Object.assign(process.env, runtimeSettings(readOpenRouterSettings('/lab/openrouter.env')));
console.log('Moodle acceptance: real storage, queue, transcription, embeddings and answers.');
await import('/workspace/apps/api/dist/node.mjs');
