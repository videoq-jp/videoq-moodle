import { currentFixture } from './current-fixture.mjs';
// Exercise the real Moodle launch and player. Mock answers by default; --live uses one AI answer.
import { chromium, firefox, webkit } from 'playwright';
import { readLtiAnswer } from './read-lti-answer.mjs';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import assert from 'node:assert/strict';
const dir = '/tmp/videoq-lti-lab';
const live = process.argv.includes('--live');
const { moodle, videoq, extra, firstLesson, standaloneUrl } = currentFixture(dir);
const second = extra.find(item => item.id === 'course-review' || item.lesson === 'course-review');
const hidden = extra.find(item => item.lesson === 'course-hidden');
const engine = process.env.LTI_BROWSER || 'chromium';
const outputDir = engine === 'chromium' ? dir : dir + '/acceptance/' + engine;
mkdirSync(outputDir, { recursive: true });
const browser = await ({ chromium, firefox, webkit })[engine].launch({ headless: true, args: engine === 'chromium' ? ['--no-proxy-server'] : [] });
try {
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  let session, sent;
  page.on('response', async response => { if (response.url().endsWith('/api/lti/session')) session = await response.json(); });
  if (!live) await page.route('**/api/lti/messages{,/stream}', async route => {
    sent = route.request().postDataJSON();
    await route.fulfill({ json: { answer: { segments: [
      { text: '第1回ではLMSとVideoQの役割を学びます。', sourceIds: [1] },
      { text: '\n\n第2回では、違いを自分の言葉で説明して復習します。', sourceIds: [2] },
    ], sources: [
      { id: 1, video_id: videoq.videoId, title: '第1回：LMSとVideoQ', start_time: '00:00:00', end_time: '00:00:08' },
      { id: 2, video_id: second.videoId, title: second.title, start_time: '00:00:08', end_time: '00:00:16' },
    ] } } });
  });
  await page.goto(moodle.issuer + '/login/index.php');
  await page.locator('#username').fill('learner'); await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  await page.goto(moodle.lessonUrl);
  const chat = page.locator('#videoq-native-chat');
  let initialSource;
  const input = chat.locator('[data-region="send-message-txt"]');
  await input.waitFor();
  await page.waitForFunction(() => document.querySelector("#videoq-native-controls")?.disabled === false);
  await page.waitForFunction(() => document.querySelector('video')?.readyState >= 1);
  initialSource = await page.locator('.local-videoq-video video').evaluate(video => video.currentSrc);
  await input.fill('第1回のLMSとVideoQの役割と、第2回の復習の進め方をまとめて教えてください。両方の動画の内容を参照してください。');
  assert(session.playback.videos.some(video => video.videoId === videoq.videoId));
  assert(session.playback.videos.some(video => video.videoId === second.videoId));
  assert(!session.playback.videos.some(video => video.videoId === hidden.videoId));
  assert.equal(await page.locator('#videoq-lesson option[value="course-hidden"]').count(), 0);
  const replyPromise = page.waitForResponse(response => new URL(response.url()).pathname.match(/\/api\/lti\/messages(?:\/stream)?$/));
  await chat.locator('[data-action="send-message"]').click(); const response = await replyPromise;
  assert.equal(response.status(), 200); const answer = await readLtiAnswer(response);
  assert(!answer.answer.sources.some(source => source.video_id === hidden.videoId));
  assert(answer.answer.sources.some(source => source.video_id === second.videoId));
  if (live) assert(answer.answer.sources.some(source => source.video_id === videoq.videoId));
  await chat.locator('.answer').waitFor();
  const secondCitation = chat.getByRole('button', { name: new RegExp(second.title) }).first();
  const cited = new Set(answer.answer.segments.flatMap(segment => segment.sourceIds));
  const source = answer.answer.sources.find(source => source.video_id === second.videoId && cited.has(source.id));
  assert(source, 'Second video must actually be cited');
  const seconds = source.start_time.replace(',', '.').split(':').reduce((total, part) => total * 60 + Number(part), 0);
  await secondCitation.click();
  await page.waitForFunction(expected => document.querySelector('#videoq-lesson').value === expected.lesson && Math.abs(document.querySelector('video').currentTime - expected.seconds) < 2 && !document.querySelector('video').paused, {lesson:second.lesson,seconds}, { timeout: 10000 });
  await page.locator('video').evaluate(video => video.pause());
  await page.waitForFunction(() => { const video = document.querySelector('.local-videoq-video video'); return !video.seeking && video.readyState >= 2; });
  const loadedSource = await page.locator('.local-videoq-video video').evaluate(video => ({ src: video.currentSrc, assigned: video.src, player: !!video.player, playerSource: video.closest('.video-js')?.player?.currentSource()?.src }));
  assert.notEqual(loadedSource.src, initialSource, 'The decoded media source must change, not only the selector and time');
  assert.equal(new URL(loadedSource.src).searchParams.get('id'), String(second.videoCmid));
  console.log(JSON.stringify({ actual: new URL(loadedSource.src).searchParams.get('id'), registeredInPlayer: loadedSource.playerSource ? new URL(loadedSource.playerSource).searchParams.get('id') : null }));
  assert.equal(new URL(loadedSource.playerSource).searchParams.get('id'), String(second.videoCmid), 'Video.js must track the new source as well as the raw video element');
  await page.locator('.local-videoq-media').screenshot({ path: outputDir + '/course-switched-video.png' });
  console.log(JSON.stringify({ firstSourcePath: new URL(initialSource).pathname, secondSourcePath: new URL(loadedSource.src).pathname, secondCmid: new URL(loadedSource.src).searchParams.get('id'), playerApiAvailable: loadedSource.player }));
  assert.equal(await chat.locator('.answer').count(), 1);
  let answerCount = 1;
  if (!live) {
    await input.fill('今見ている第2回の場面について、もう少し教えてください。');
    await chat.locator('[data-action="send-message"]').click();
    await chat.locator('.answer').nth(1).waitFor();
    assert.equal(sent.playback.video_id, second.videoId);
    assert(Math.abs(sent.playback.position_seconds - seconds) <= 1, JSON.stringify({reported:sent.playback.position_seconds,cited:seconds}));
    assert.equal(sent.messages.length, 3);
    answerCount = 2;
  }
  // Switching by the lecture selector preserves the same question frame and history.
  await page.locator('#videoq-lesson').selectOption(firstLesson);
  await page.waitForFunction(() => document.querySelector('video').currentTime < 1 && document.querySelector('video').readyState >= 1);
  assert.equal(await chat.locator('.answer').count(), answerCount);
  const forbidden = await page.request.post('https://videoq.localhost:18443/api/lti/messages', {
    headers: { Origin: 'https://videoq.localhost:18443', Authorization: 'Bearer ' + session.token },
    data: { messages: [{ role: 'user', content: '未公開の教材について教えて' }], playback: { video_id: hidden.videoId, position_seconds: 1 } },
  });
  assert.equal(forbidden.status(), 403);
  await page.screenshot({ path: outputDir + '/course-desktop.png', animations: 'disabled' });
  await page.setViewportSize({ width: 390, height: 844 });
  const drawer = page.locator('.drawer.show button[data-action="closedrawer"]'); if (await drawer.count()) await drawer.click();
  await secondCitation.click();
  await page.waitForFunction(() => { const rect = document.querySelector('.local-videoq-media').getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= innerHeight; }, null, { timeout: 5000 });
  await page.screenshot({ path: outputDir + '/course-mobile.png', animations: 'disabled' });
  let popup;
  if (standaloneUrl) {
    popup = await page.context().newPage(); await popup.goto(standaloneUrl);
  } else {
    const popupPromise = page.waitForEvent('popup');
    await page.goto(moodle.activities['VideoQ new window'].url);
    popup = await popupPromise;
  }
  await popup.waitForFunction(() => document.getElementById('question')?.disabled === false, null, { timeout: 10000 });
  await popup.close();
  writeFileSync(outputDir + (live ? '/course-live.json' : '/course-check.json'), JSON.stringify({ browserEngine: engine, live, videos: [videoq.videoId, second.videoId], hiddenVideoExcluded: true, forbiddenPlaybackRejected: true, crossVideoSeek: true, conversationPreserved: true, selectorSwitch: true, selectedVideoQuestionContext: !live, mobilePlayerVisible: true, separateWindow: true, ...(live ? { answer: answer.answer } : { paidRequests: 0 }) }, null, 2));
  console.log('Course questions, permission scope, cross-video citation, selector and mobile playback passed.');
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) {
    console.error('Page:', new URL(page.url()).pathname);
    for (const frame of page.frames()) console.error((await frame.locator('body').innerText().catch(() => '')).slice(-1700));
  }
  throw error;
} finally { await browser.close(); }
