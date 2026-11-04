import { createRequire } from 'node:module';
// These utilities run only inside the lab API container with the repository mounted.
const { Client } = createRequire('/workspace/apps/api/package.json')('pg');
export async function labDatabase() {
  if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq') throw new Error('Use only the isolated LTI lab database');
  const client = new Client({ connectionString: process.env.DATABASE_URL });
  await client.connect();
  return client;
}
export async function readAiState(client) {
  const { rows: [table] } = await client.query("SELECT to_regclass('public.lti_lab_ai_state') AS name");
  if (!table.name) return null;
  return (await client.query('SELECT state FROM lti_lab_ai_state WHERE id = 1')).rows[0]?.state ?? null;
}
