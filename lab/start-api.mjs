import { readFileSync } from 'node:fs';
import { labDatabase, readAiState } from './lab-database.mjs';
import { readOpenRouterSettings, indexFingerprint, runtimeSettings } from './openrouter-config.mjs';
process.env.LTI_CONFIG = readFileSync('/lab/lti-config.json', 'utf8');
const client = await labDatabase();
try {
  const state = await readAiState(client);
  if (state) {
    const settings = readOpenRouterSettings('/lab/openrouter.env');
    if (state.provider !== 'openrouter' || state.fingerprint !== indexFingerprint(settings)) throw new Error('Reindex the lab lesson before starting with this AI configuration');
    Object.assign(process.env, runtimeSettings(settings));
    console.log('LTI lab AI mode: OpenRouter (real answers)');
  } else {
    console.log('LTI lab AI mode: deterministic demonstration');
  }
} finally { await client.end(); }
await import('/workspace/apps/api/dist/node.mjs');
