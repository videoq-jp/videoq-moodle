import { labDatabase } from './lab-database.mjs';
const externalId = process.argv[2];
if (!/^[1-9][0-9]*$/.test(externalId || '')) throw new Error('An exact lab activity ID is required');
const client = await labDatabase();
try {
  const { rows: [resource] } = await client.query(`SELECT r.generation, r.enabled, r.video_id, r.course_id, r.sync_error,
    v.status, v.file, v.processing_seconds, length(v.transcript) AS transcript_length, v.transcript,
    (SELECT count(*)::int FROM scene_embeddings e WHERE e.video_id=r.video_id) AS indexed_scenes
    FROM lms_resources r LEFT JOIN videos v ON v.id=r.video_id
    WHERE r.installation_id='moodle-sync-lab' AND r.external_id=$1`, [externalId]);
  const { rows: [usage] } = await client.query(`SELECT used_ai_answers, used_processing_seconds, used_storage_bytes, used_upload_bytes
    FROM users WHERE id='lti-lab-owner'`);
  const { rows: [inventory] } = await client.query(`SELECT
    (SELECT count(*)::int FROM users) AS account_count,
    (SELECT coalesce(json_agg(id), '[]'::json) FROM videos WHERE user_id='lti-lab-owner') AS video_ids,
    (SELECT coalesce(json_agg(DISTINCT video_id), '[]'::json) FROM scene_embeddings WHERE user_id='lti-lab-owner') AS indexed_video_ids,
    (SELECT count(*)::int FROM external_tasks WHERE kind='storage_cleanup' AND payload->>'user_id'='lti-lab-owner'
      AND completed_at IS NULL) AS pending_cleanup`);
  console.log(JSON.stringify({ resource: resource ?? null, usage, inventory }));
} finally { await client.end(); }
