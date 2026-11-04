// Run with the lab API stopped. No secrets or upstream response bodies are logged.
import { readFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { lessonScenes } from './lesson-scenes.mjs';
import { readOpenRouterSettings, indexFingerprint } from './openrouter-config.mjs';
import { labDatabase } from './lab-database.mjs';

const settings = readOpenRouterSettings('/lab/openrouter.env');
const fixture = JSON.parse(readFileSync('/lab/videoq-fixture.json', 'utf8'));
const response = await fetch(settings.baseUrl + '/embeddings', {
  method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + settings.apiKey },
  body: JSON.stringify({ model: settings.embeddingModel, input: lessonScenes.map(scene => scene.text), dimensions: 1536, encoding_format: 'float' }),
  signal: AbortSignal.timeout(60_000),
}).catch(() => { throw new Error('OpenRouter embeddings connection failed'); });
if (!response.ok) { await response.body?.cancel(); throw new Error('OpenRouter embeddings failed (HTTP ' + response.status + ')'); }
const data = await response.json();
const vectors = new Map();
for (const item of data.data || []) {
  if (!Number.isInteger(item.index) || item.index < 0 || item.index >= lessonScenes.length || vectors.has(item.index)
    || !Array.isArray(item.embedding) || item.embedding.length !== 1536
    || !item.embedding.every(value => typeof value === 'number' && Number.isFinite(value) && Number.isFinite(Math.fround(value)))
    || !item.embedding.some(value => Math.fround(value) !== 0)) throw new Error('Invalid OpenRouter document embeddings');
  vectors.set(item.index, item.embedding);
}
if (vectors.size !== lessonScenes.length) throw new Error('OpenRouter returned incomplete document embeddings');
const client = await labDatabase();
try {
  await client.query('BEGIN');
  const owned = await client.query('SELECT id FROM videos WHERE id = $1 AND user_id = $2 FOR UPDATE', [fixture.videoId, 'lti-lab-owner']);
  if (owned.rowCount !== 1 || fixture.owner !== 'lti-lab-owner') throw new Error('The configured lab video does not belong to the fixture owner');
  await client.query('CREATE TABLE IF NOT EXISTS lti_lab_ai_state (id integer PRIMARY KEY CHECK (id = 1), state jsonb NOT NULL)');
  await client.query('DELETE FROM scene_embeddings WHERE video_id = $1 AND user_id = $2', [fixture.videoId, fixture.owner]);
  for (const [index, scene] of lessonScenes.entries()) {
    await client.query(`INSERT INTO scene_embeddings (langchain_id, content, embedding, user_id, video_id, langchain_metadata)
      VALUES ($1, $2, $3::vector, $4, $5, $6::json)`, [randomUUID(), scene.text, JSON.stringify(vectors.get(index)), fixture.owner, fixture.videoId,
      JSON.stringify({ video_title: 'LTI lab lesson', start_time: scene.start, end_time: scene.end })]);
  }
  const srt = lessonScenes.map((scene, index) => `${index + 1}\n${scene.start},000 --> ${scene.end},000\n${scene.text}`).join('\n\n');
  await client.query('UPDATE videos SET transcript = $1 WHERE id = $2 AND user_id = $3', [srt, fixture.videoId, fixture.owner]);
  // A bounded allowance for this isolated paid-provider trial; existing usage is retained.
  await client.query('UPDATE users SET ai_answers_limit = used_ai_answers + 30 WHERE id = $1', [fixture.owner]);
  const state = { provider: 'openrouter', fingerprint: indexFingerprint(settings), embeddingModel: settings.embeddingModel, indexedAt: new Date().toISOString() };
  await client.query('INSERT INTO lti_lab_ai_state (id, state) VALUES (1, $1::jsonb) ON CONFLICT (id) DO UPDATE SET state = excluded.state', [JSON.stringify(state)]);
  await client.query('COMMIT');
  console.log('Indexed 3 lesson scenes with OpenRouter; lab is ready for real AI answers.');
} catch (error) { await client.query('ROLLBACK'); throw error; }
finally { await client.end(); }
