// Only run against the dedicated Compose lab database.
import pg from 'pg';
import { writeFileSync } from 'node:fs';
if (process.env.DATABASE_URL !== 'postgresql://postgres:lti-lab-only@db:5432/videoq') throw new Error('Use only the isolated LTI lab database');
const client = new pg.Client({ connectionString: process.env.DATABASE_URL });
await client.connect();
try {
  await client.query('BEGIN');
  const owner = 'lti-lab-owner';
  await client.query(`INSERT INTO users (id, email, username, name, is_over_quota, used_ai_answers, used_processing_seconds, used_storage_bytes)
    VALUES ($1, 'owner@example.invalid', 'lti-lab-owner', 'LTI Lab Owner', false, 0, 0, 0) ON CONFLICT (id) DO NOTHING`, [owner]);
  const { rows: existing } = await client.query(`SELECT id FROM video_courses WHERE user_id = $1 AND name = 'LTI lab course' LIMIT 1`, [owner]);
  if (existing.length) throw new Error('Lab fixtures already exist; reuse videoq-fixture.json');
  const { rows: [video] } = await client.query(`INSERT INTO videos (title, description, file, uploaded_at, transcript, status, error_message, user_id, source_type, source_url, youtube_video_id)
    VALUES ('LTI lab lesson', '', 'lab/lesson.mp4', now(), 'LMSとVideoQをLTIで接続します。', 'completed', '', $1, 'uploaded', '', '') RETURNING id`, [owner]);
  const { rows: [course] } = await client.query(`INSERT INTO video_courses (name, description, created_at, updated_at, user_id, display_order)
    VALUES ('LTI lab course', 'LMSとVideoQの連携教材', now(), now(), $1, 0) RETURNING id`, [owner]);
  await client.query(`INSERT INTO video_course_members (course_id, video_id, "order", added_at) VALUES ($1, $2, 0, now())`, [course.id, video.id]);
  const vector = JSON.stringify([1, ...Array(1535).fill(0)]);
  await client.query(`INSERT INTO scene_embeddings (langchain_id, content, embedding, user_id, video_id, langchain_metadata)
    VALUES (gen_random_uuid(), 'LMSとVideoQをLTIで接続します。', $1::vector, $2, $3, $4::json)`, [vector, owner, video.id, JSON.stringify({ video_title: 'LTI lab lesson', start_time: '00:00:00', end_time: '00:00:10' })]);
  await client.query('COMMIT');
  writeFileSync('/lab/videoq-fixture.json', JSON.stringify({ courseId: Number(course.id), videoId: Number(video.id), owner }));
  console.log('VideoQ fixture created in the isolated lab');
} catch (error) { await client.query('ROLLBACK'); throw error; }
finally { await client.end(); }
