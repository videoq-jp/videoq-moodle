// Deliberately bypasses the optional paid provider mode for synchronization tests.
import { readFileSync } from 'node:fs';
if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq') throw new Error('Use only the isolated LTI lab');
const config = JSON.parse(readFileSync('/lab/lti-config.json', 'utf8'));
process.env.LTI_CONFIG = JSON.stringify({ ...config, databaseRegistrations: true });
console.log('Moodle synchronization lab: real storage, queue and worker; deterministic inference');
await import('/workspace/apps/api/dist/node.mjs');
