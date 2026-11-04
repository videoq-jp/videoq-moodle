// Isolated acceptance environment: real storage/queue and the user's configured AI connection.
import { readFileSync, existsSync } from 'node:fs';
import { readOpenRouterSettings, runtimeSettings } from './openrouter-config.mjs';
if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq') throw new Error('Use only the isolated lab');
const config = JSON.parse(readFileSync('/lab/lti-config.json', 'utf8'));
process.env.LTI_CONFIG = JSON.stringify({ ...config, databaseRegistrations: true });
if (existsSync('/lab/lti-tool-keys.json')) process.env.LTI_TOOL_KEYS = readFileSync('/lab/lti-tool-keys.json', 'utf8');
Object.assign(process.env, runtimeSettings(readOpenRouterSettings('/lab/openrouter.env')));
console.log('Moodle acceptance: real storage, queue, transcription, embeddings and answers.');
await import('/workspace/apps/api/dist/node.mjs');
