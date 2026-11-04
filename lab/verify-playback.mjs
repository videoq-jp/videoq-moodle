import { currentFixture } from './current-fixture.mjs';
// Default: intercept only the answer response (no paid inference). --live: one real answer.
import { chromium } from 'playwright';
import { readLtiAnswer } from './read-lti-answer.mjs';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import assert from 'node:assert/strict';
const live = process.argv.includes('--live');
const dir = process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : '/tmp/videoq-lti-lab';
const { moodle: fixture, videoq } = currentFixture(dir);
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
try {
  const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
  await page.addInitScript(() => window.addEventListener('message', event => {
    if (event.data?.type === 'videoq:ready') window.labBridge = { ...event.data, origin: event.origin };
  }));
  const sent = [];
  if (!live) await page.route('**/api/lti/messages{,/stream}', async route => {
    sent.push(route.request().postDataJSON());
    await route.fulfill({ json: { answer: {
      segments: [{ text: 'VideoQでは教材について質問できます。', sourceIds: [1, 3] }],
      sources: [
        { id: 1, video_id: videoq.videoId, title: '現在の教材', start_time: '00:00:08,000', end_time: '00:00:16,000' },
        { id: 2, video_id: videoq.videoId, title: '引用されていない場面', start_time: '00:00:16', end_time: '00:00:24' },
        { id: 3, video_id: 99999, title: '別の動画', start_time: '00:00:04', end_time: '00:00:08' },
      ],
    } } });
  });
  await page.goto(fixture.issuer + '/login/index.php');
  await page.locator('#username').fill('learner');
  await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  await page.goto(fixture.lessonUrl);
  const video = page.locator('.local-videoq-video video');
  const frame = page.locator('#videoq-native-chat');
  const transport = page.frameLocator('#videoq-questions');
  await page.waitForFunction(() => document.querySelector('video')?.readyState >= 1 && window.labBridge);
  await video.evaluate(el => { el.currentTime = 10; el.pause(); });
  await page.waitForTimeout(500);
  const bridge = await page.evaluate(() => window.labBridge);
  // The child must reject wrong origin, wrong window, and wrong channel.
  await transport.locator('html').evaluate((_el, { bridge, origin }) => {
    const data = { type: 'videoq:state', version: 1, channel: bridge.channel, videoId: bridge.videoId, seconds: 20, duration: 24 };
    window.dispatchEvent(new MessageEvent('message', { source: window.parent, origin: 'https://attacker.example', data }));
    window.dispatchEvent(new MessageEvent('message', { source: window, origin, data }));
    window.dispatchEvent(new MessageEvent('message', { source: window.parent, origin, data: { ...data, channel: 'f'.repeat(32) } }));
  }, { bridge, origin: fixture.issuer });
  assert.equal(await frame.locator('#position, #use-position, #current-question, [data-question]').count(), 0);
  // The Moodle player must reject equivalent forged seek requests and other video IDs.
  await page.evaluate(({ bridge }) => {
    const child = document.querySelector('#videoq-questions').contentWindow;
    const data = { type: 'videoq:seek', version: 1, channel: bridge.channel, videoId: bridge.videoId, seconds: 20 };
    for (const input of [
      { source: child, origin: 'https://attacker.example', data },
      { source: window, origin: bridge.origin, data },
      { source: child, origin: bridge.origin, data: { ...data, channel: 'f'.repeat(32) } },
      { source: child, origin: bridge.origin, data: { ...data, videoId: 99999 } },
      { source: child, origin: bridge.origin, data: { ...data, seconds: -1 } },
      { source: child, origin: bridge.origin, data: { ...data, seconds: 25 } },
    ]) window.dispatchEvent(new MessageEvent('message', input));
  }, { bridge });
  assert.equal(await video.evaluate(el => el.currentTime), 10);
  await frame.locator('[data-region="send-message-txt"]').fill('今の場面について教えてください');
  const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname.match(/\/api\/lti\/messages(?:\/stream)?$/), { timeout: 90000 });
  await frame.locator('[data-action="send-message"]').click();
  const response = await responsePromise;
  assert.equal(response.status(), 200);
  const body = await readLtiAnswer(response);
  if (live) assert.match(body.answer.segments.map(segment => segment.text).join(''), /VideoQ/);
  else assert.deepEqual(sent[0].playback, { video_id: videoq.videoId, position_seconds: 10 });
  await frame.locator('.answer').waitFor();
  assert.equal(await frame.locator('.question small').count(), 0);
  if (!live) {
    assert.equal(await frame.locator('.citation').count(), 1);
    assert.equal(await frame.getByText('引用されていない場面', { exact: false }).count(), 0);
  }
  // Simulate an idle/backgrounded frame whose last position update is stale.
  await transport.locator('html').evaluate(() => {
    const now = performance.now.bind(performance);
    performance.now = () => now() + 10000;
  });
  await frame.getByRole('button', { name: /00:00:08(?:[.,]000)?へ移動/ }).first().click();
  await page.waitForFunction(() => Math.abs(document.querySelector('video').currentTime - 8) < 1, null, { timeout: 4000 });
  await page.waitForFunction(() => !document.querySelector('video').paused, null, { timeout: 4000 });
  await video.evaluate(el => { el.pause(); el.currentTime = 8; });
  await page.waitForTimeout(500);
  if (!live) {
    // Restore the frame clock before checking automatically attached context.
    await transport.locator('html').evaluate(() => { delete performance.now; });
    await page.waitForTimeout(1100);
    await frame.locator('[data-region="send-message-txt"]').fill('コース全体について教えて');
    await frame.locator('[data-action="send-message"]').click();
    await page.waitForFunction(() => document.querySelector('#videoq-questions') !== null);
    await frame.locator('.answer').nth(1).waitFor();
    assert.deepEqual(sent[1].playback, { video_id: videoq.videoId, position_seconds: 8 });
  }
  await page.setViewportSize({ width: 390, height: 844 });
  const drawer = page.locator('.drawer.show button[data-action="closedrawer"]');
  if (await drawer.count()) await drawer.click();
  await frame.locator('.citation').first().click();
  await page.waitForFunction(() => { const r = document.querySelector('.local-videoq-media').getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; }, null, { timeout: 4000 });
  await page.screenshot({ path: join(dir, live ? 'playback-live.png' : 'playback-check.png'), fullPage: true, animations: 'disabled' });
  writeFileSync(join(dir, live ? 'playback-live.json' : 'playback-check.json'), JSON.stringify({ live, questionPosition: 10, citationSeek: 8, forgedMessagesRejected: true, startsPlayback: true, stalePositionSeek: true, mobilePlayerVisible: true }, null, 2));
  console.log(live ? 'Live answer at 10 seconds and citation seeking to 8 seconds passed.' : 'Playback bridge, message validation, citation filtering/seeking and automatic context passed (no paid inference).');
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) {
    console.error('Page:', page.url(), (await page.locator('body').innerText().catch(() => '')).slice(0, 1300));
    for (const frame of page.frames().slice(1)) console.error('Question view:', (await frame.locator('body').innerText().catch(() => '')).slice(0, 1300));
  }
  throw error;
} finally { await browser.close(); }
