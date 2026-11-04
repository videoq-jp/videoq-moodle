// Local lab maintenance reads the same signing secret as the API without logging it.
import { existsSync, readFileSync } from 'node:fs';
if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq') throw new Error('Isolated lab only');
const config = JSON.parse(readFileSync('/lab/lti-config.json', 'utf8'));
process.env.LTI_CONFIG = JSON.stringify({ ...config, databaseRegistrations: true });
if (existsSync('/lab/lti-tool-keys.json')) process.env.LTI_TOOL_KEYS = readFileSync('/lab/lti-tool-keys.json', 'utf8');
await import('/workspace/apps/api/dist/node.mjs');
