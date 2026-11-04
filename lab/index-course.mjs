// Adds original extra lessons to the existing real-OpenRouter lab. No credentials are logged.
import { readFileSync, writeFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { courseLessons } from './course-scenes.mjs';
import { readOpenRouterSettings } from './openrouter-config.mjs';
import { labDatabase } from './lab-database.mjs';
const settings = readOpenRouterSettings('/lab/openrouter.env');
const fixture = JSON.parse(readFileSync('/lab/videoq-fixture.json', 'utf8'));
const moodle = JSON.parse(readFileSync('/lab/course-moodle.json', 'utf8'));
const texts = courseLessons.flatMap(lesson => lesson.scenes.map(scene => scene.text));
const response = await fetch(settings.baseUrl + '/embeddings', {
  method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + settings.apiKey },
  body: JSON.stringify({ model: settings.embeddingModel, input: texts, dimensions: 1536, encoding_format: 'float' }), signal: AbortSignal.timeout(60_000),
});
if (!response.ok) { await response.body?.cancel(); throw new Error('Course embeddings failed: HTTP ' + response.status); }
const data = await response.json();
const vectors = new Map((data.data || []).map(row => [row.index, row.embedding]));
if (vectors.size !== texts.length || [...vectors.values()].some(vector => !Array.isArray(vector) || vector.length !== 1536 || !vector.every(Number.isFinite))) throw new Error('Invalid document embeddings');
const db = await labDatabase();
try {
  await db.query('BEGIN');
  const videos = [];
  let index = 0;
  for (const lesson of courseLessons) {
    let video = (await db.query('SELECT id FROM videos WHERE user_id=$1 AND title=$2', [fixture.owner, lesson.title])).rows[0];
    if (!video) video = (await db.query(`INSERT INTO videos(user_id,title,file,description,uploaded_at,transcript,status,error_message,source_type,source_url,youtube_video_id)
      VALUES($1,$2,$3,'Original course demo',now(),'','completed','','uploaded','','') RETURNING id`, [fixture.owner, lesson.title, 'lab/' + lesson.lesson + '.mp4'])).rows[0];
    await db.query('INSERT INTO video_course_members(course_id,video_id,"order",added_at) VALUES($1,$2,$3,now()) ON CONFLICT DO NOTHING', [fixture.courseId, video.id, videos.length + 1]);
    await db.query('DELETE FROM scene_embeddings WHERE video_id=$1 AND user_id=$2', [video.id, fixture.owner]);
    for (const scene of lesson.scenes) await db.query(`INSERT INTO scene_embeddings(langchain_id,content,embedding,user_id,video_id,langchain_metadata)
      VALUES($1,$2,$3::vector,$4,$5,$6::json)`, [randomUUID(), scene.text, JSON.stringify(vectors.get(index++)), fixture.owner, video.id, JSON.stringify({ video_title: lesson.title, start_time: scene.start, end_time: scene.end })]);
    const srt = lesson.scenes.map((scene, i) => `${i+1}\n${scene.start},000 --> ${scene.end},000\n${scene.text}`).join('\n\n');
    await db.query('UPDATE videos SET transcript=$1 WHERE id=$2', [srt, video.id]);
    const activity = moodle.lessons.find(item => item.id === lesson.lesson);
    videos.push({ videoId: Number(video.id), lesson: lesson.lesson, title: lesson.title, ...activity });
  }
  await db.query('COMMIT');
  writeFileSync('/lab/course-videos.json', JSON.stringify({ courseId: fixture.courseId, videos }, null, 2));
  const config = JSON.parse(readFileSync('/lab/lti-config.json', 'utf8'));
  const registration = config.registrations.find(item => item.id === 'moodle-lab');
  registration.courseAccess = 'moodle';
  for (const video of videos) {
    registration.resources = registration.resources.filter(item => item.resourceLinkId !== video.resourceLinkId);
    registration.resources.push({ deploymentId: registration.resources[0].deploymentId, contextId: String(moodle.courseId), resourceLinkId: video.resourceLinkId, courseId: fixture.courseId, videoId: video.videoId });
  }
  writeFileSync('/lab/lti-config.json', JSON.stringify(config, null, 2));
  console.log('Indexed two original lessons and configured course bindings.');
} catch (error) { await db.query('ROLLBACK'); throw error; } finally { await db.end(); }
