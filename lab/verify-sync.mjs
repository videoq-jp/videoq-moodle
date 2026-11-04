// Full synchronization acceptance test. Providers are fixtures; no transcripts,
// embeddings, courses or videos are inserted into VideoQ by this verifier.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, unlinkSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { setTimeout } from 'node:timers/promises';
import { chromium } from 'playwright';
import { readLtiAnswer } from './read-lti-answer.mjs';

const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const fixture = JSON.parse(readFileSync(join(dir, 'sync-fixture.json'), 'utf8'));
assert.ok(Number.isSafeInteger(fixture.cmid) && fixture.cmid > 0);
const origin = 'https://moodle.localhost:18443';
const lessonUrl = origin + '/local/videoq/view.php?lesson=sync_' + fixture.cmid;
const composeArgs = ['-n', 'docker', 'compose', '-p', 'videoq-lti-lab', '--env-file', join(dir, 'compose.env'),
  '-f', fileURLToPath(new URL('./compose.yaml', import.meta.url)), '-f', fileURLToPath(new URL('./compose.sync.yaml', import.meta.url))];
const dc = (...args) => execFileSync('sudo', [...composeArgs, ...args], { encoding: 'utf8', timeout: 60000 });
const moodleState = () => JSON.parse(dc('exec', '-T', 'moodle', 'php', '/lab/sync-actions.php', 'state', String(fixture.cmid)));
const state = () => JSON.parse(dc('exec', '-T', 'api', 'node', '/fixtures/sync-lab-state.mjs', String(fixture.cmid)));
const cron = () => dc('exec', '-T', 'moodle', 'php', 'admin/cli/scheduled_task.php', String.raw`--execute=\local_videoq\task\synchronize`);
const mutate = action => dc('exec', '-T', 'moodle', 'php', '/lab/sync-actions.php', action, String(fixture.cmid));
const cleanup = () => dc('exec', '-T', 'api', 'node', 'dist/node.mjs', 'maintenance');
const objectExists = key => JSON.parse(dc('exec', '-T', 'worker', 'python', '/lab/check-sync-object.py', key)).exists;
const checks = [];
function checked(name) { checks.push(name); console.log(name); }
async function processed() {
  const deadline = Date.now() + 90000;
  while (Date.now() < deadline) {
    const current = state();
    if (current.resource?.status === 'completed') { cron(); return current; }
    if (current.resource?.status === 'error') throw new Error('Video processing failed; inspect isolated worker diagnostics');
    await setTimeout(1000);
  }
  throw new Error('Timed out waiting for real worker processing');
}
async function login(browser, username) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  await page.goto(origin + '/login/index.php');
  await page.locator('#username').fill(username); await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  return { context, page };
}
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
try {
  const { page: teacher } = await login(browser, 'teacher');
  const settingsUrl = origin + '/local/videoq/manage.php?cmid=' + fixture.cmid;
  await teacher.goto(settingsUrl);
  if (await teacher.getByRole('button', { name: '質問機能を有効にする' }).count()) {
    await Promise.all([teacher.waitForNavigation(), teacher.getByRole('button', { name: '質問機能を有効にする' }).click()]);
  }
  assert.equal(await teacher.getByRole('button', { name: '質問機能を無効にする' }).count(), 1, await teacher.locator('#page-content').innerText());
  checked('Teacher enables questions from Moodle without a VideoQ login');
  const companion = moodleState();
  assert.ok(companion.lticmid > 0 && companion.linkcmid > 0);
  const before = state();
  cron();
  const first = await processed();
  assert.ok(first.resource.transcript_length > 0 && first.resource.indexed_scenes > 0 && first.resource.processing_seconds > 0);
  assert.equal(objectExists(first.resource.file), true);
  checked('Moodle uploads media; the real worker extracts audio, saves subtitles and indexes scenes');
  cron();
  assert.equal(state().resource.video_id, first.resource.video_id);
  assert.equal(state().usage.used_upload_bytes, first.usage.used_upload_bytes);
  checked('Repeated synchronization does not duplicate media or upload usage');
  await teacher.reload();
  await teacher.screenshot({ path: join(dir, 'sync-ready-teacher.png'), fullPage: true });

  const { page: learner } = await login(browser, 'learner');
  await learner.goto(settingsUrl);
  assert.equal(await learner.getByRole('button', { name: '質問機能を無効にする' }).count(), 0);
  const sessionResponse = learner.waitForResponse(response => response.url().endsWith('/api/lti/session') && response.status() === 200);
  await learner.goto(lessonUrl);
  const { token } = await (await sessionResponse).json(); // in memory only; never logged or written
  const frame = learner.locator('#videoq-native-chat');
  await frame.locator('[data-region="send-message-txt"]').fill('LMSとVideoQはどうつながりますか？');
  const answerResponse = learner.waitForResponse(response => new URL(response.url()).pathname.match(/\/api\/lti\/messages(?:\/stream)?$/) && response.status() === 200);
  await frame.locator('[data-action="send-message"]').click();
  await frame.locator('.answer').waitFor({ timeout: 30000 });
  const answer = (await readLtiAnswer(await answerResponse)).answer;
  assert.ok(answer.sources.length > 0);
  assert.ok(answer.sources.every(source => String(source.video_id) === String(first.resource.video_id)));
  assert.match(await frame.locator('.answer').textContent(), /LMSとVideoQ/);
  assert.equal(state().usage.used_ai_answers, first.usage.used_ai_answers + 1);
  await learner.screenshot({ path: join(dir, 'sync-learner-question.png'), fullPage: true });
  checked('Learner asks from Moodle and consumes the existing school account answer allowance');
  const questionFrame = learner.frames().find(item => item.url().startsWith('https://videoq.localhost:18443/api/lti/launch'));
  assert.ok(questionFrame);
  mutate('rename'); cron();
  const renamed = state();
  assert.equal(renamed.resource.video_id, first.resource.video_id);
  const refused = await questionFrame.evaluate(async sessionToken => {
    const response = await fetch('/api/lti/messages', { method: 'POST', headers: {
      Authorization: 'Bearer ' + sessionToken, 'Content-Type': 'application/json',
    }, body: JSON.stringify({ messages: [{ role: 'user', content: '以前の画面からの質問' }] }) });
    return response.status;
  }, token);
  assert.equal(refused, 403);
  checked('Renaming reuses the video and revokes the old resource generation');

  mutate('hide'); cron();
  assert.equal(state().resource.enabled, false);
  await learner.goto(lessonUrl);
  assert.equal(await learner.locator('#videoq-questions').count(), 0);
  mutate('show'); cron();
  assert.equal(state().resource.video_id, first.resource.video_id);
  assert.equal(state().usage.used_upload_bytes, first.usage.used_upload_bytes);
  checked('Hide/show propagates access without reuploading or reprocessing the video');

  mutate('restrict'); cron();
  await learner.goto(origin + '/mod/lti/view.php?id=' + companion.lticmid);
  assert.equal(await learner.locator('iframe[src*="/mod/lti/launch.php"]').count(), 0);
  await learner.goto(lessonUrl); assert.equal(await learner.locator('#videoq-questions').count(), 0);
  mutate('unrestrict'); cron();
  checked('Source availability restrictions also protect standalone question activities');

  mutate('replace'); cron();
  const replaced = await processed();
  assert.notEqual(replaced.resource.video_id, first.resource.video_id);
  assert.ok(replaced.resource.indexed_scenes > 0);
  cleanup();
  assert.equal(objectExists(first.resource.file), false);
  checked('Replacement transfers and processes a new video and retires the previous one');
  await teacher.goto(settingsUrl);
  await Promise.all([teacher.waitForNavigation(), teacher.getByRole('button', { name: '質問機能を無効にする' }).click()]);
  cron(); cleanup();
  assert.equal(state().resource.enabled, false);
  assert.equal(objectExists(replaced.resource.file), false);
  assert.equal(moodleState().linkExists, false);
  checked('Teacher disables questions from Moodle and the generated activities and remote media are removed');
  await teacher.reload();
  await Promise.all([teacher.waitForNavigation(), teacher.getByRole('button', { name: '質問機能を有効にする' }).click()]);
  cron();
  const resumed = await processed();
  assert.ok(resumed.resource.indexed_scenes > 0);
  assert.notEqual(resumed.resource.video_id, replaced.resource.video_id);
  assert.equal(moodleState().linkExists, true);
  checked('Reenabling recreates the lesson and its new LTI binding entirely from Moodle');
  mutate('delete'); cron(); cleanup();
  const deleted = state();
  assert.equal(deleted.resource.enabled, false); assert.equal(deleted.resource.video_id, null); assert.equal(String(deleted.resource.course_id), String(first.resource.course_id));
  const retired = moodleState();
  assert.equal(retired.ltiExists, false); assert.equal(retired.linkExists, false);
  assert.equal(objectExists(resumed.resource.file), false);
  assert.equal(deleted.inventory.account_count, before.inventory.account_count);
  assert.equal(deleted.inventory.pending_cleanup, 0);
  for (const id of [first.resource.video_id, replaced.resource.video_id, resumed.resource.video_id]) {
    assert.ok(!deleted.inventory.video_ids.map(String).includes(String(id)));
    assert.ok(!deleted.inventory.indexed_video_ids.map(String).includes(String(id)));
  }
  checked('Deleting the Moodle source removes the VideoQ lesson and schedules/completes storage cleanup');
  writeFileSync(join(dir, 'sync-verification.json'), JSON.stringify({ checks, provider: 'deterministic fixture', realMediaWorker: true,
    noAdditionalVideoQAccounts: true, courseScopedCitations: true, physicalCleanupVerified: true,
    initialUsage: before.usage, finalUsage: deleted.usage }, null, 2));
  if (existsSync(join(dir, 'sync-verification-failure.json'))) unlinkSync(join(dir, 'sync-verification-failure.json'));
} catch (error) {
  writeFileSync(join(dir, 'sync-verification-failure.json'), JSON.stringify({ checks, message: error.message }, null, 2));
  throw error;
} finally { await browser.close(); }
