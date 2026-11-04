import { currentFixture } from './current-fixture.mjs';
// Real Moodle/LTI UI; hold an SSE response open to prove partial rendering (no paid inference).
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const dir = '/tmp/videoq-lti-lab';
const { moodle, videoq } = currentFixture(dir);
const feedbackId = 'b'.repeat(64);
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
try {
  const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1100 } });
  let token;
  page.on('response', async response => { if (response.url().endsWith('/api/lti/session')) token = (await response.json()).token; });
  await page.addInitScript(({ videoId, feedbackId }) => {
    if (location.origin === 'https://moodle.localhost:18443') {
      window.nativeEvents = [];
      window.addEventListener('message', event => { if (event.origin === 'https://videoq.localhost:18443') window.nativeEvents.push(event.data); });
    }
    if (location.origin !== 'https://videoq.localhost:18443') return;
    const original = window.fetch.bind(window);
    window.fetch = (input, init) => {
      if (!String(input).endsWith('/api/lti/messages/stream')) return original(input, init);
      window.sentStreamQuestion = JSON.parse(init.body);
      return Promise.resolve(new Response(new ReadableStream({ start(controller) {
        const encode = new TextEncoder();
        const emit = event => controller.enqueue(encode.encode('data: ' + JSON.stringify(event) + '\r\n\r\n'));
        const first = encode.encode('data: ' + JSON.stringify({ type: 'source', source: { id: 1, video_id: videoId, title: '動画教材', start_time: '00:00:08', end_time: '00:00:16' } }) + '\r\n\r\n');
        // Break a UTF-8 character and the CRLF delimiter across network reads.
        const split = first.findIndex(byte => byte >= 0xe0) + 1;
        controller.enqueue(first.slice(0, split)); controller.enqueue(first.slice(split, -1)); controller.enqueue(first.slice(-1));
        emit({ type: 'text_delta', segmentIndex: 0, text: '最初に見える説明です。<img src=x onerror="alert(1)">' });
        window.finishStream = () => {
          emit({ type: 'text_delta', segmentIndex: 0, text: '\n\n**続きの説明です。**' });
          emit({ type: 'citation', segmentIndex: 0, sourceId: 1 });
          emit({ type: 'done', chat_log_id: 1234, feedbackId, feedback: null });
          controller.close();
        };
        window.interruptStream = () => controller.close();
      } }), { headers: { 'Content-Type': 'text/event-stream' } }));
    };
  }, { videoId: videoq.videoId, feedbackId });
  const ratings = []; let failNext = false;
  await page.route('**/api/lti/feedback', async route => {
    const body = route.request().postDataJSON();
    assert.equal(body.feedbackId, feedbackId); assert.match(route.request().headers().authorization, /^Bearer [a-f0-9]{64}$/);
    ratings.push(body.feedback);
    if (failNext) { failNext = false; await route.fulfill({ status: 500, json: { error: 'Fixture failure' } }); }
    else await route.fulfill({ json: { feedback: body.feedback } });
  });
  await page.goto(moodle.issuer + '/login/index.php');
  await page.locator('#username').fill('learner'); await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  await page.goto(moodle.lessonUrl);
  const chat = page.locator('#videoq-native-chat');
  const input = chat.locator('[data-region="send-message-txt"]');
  await page.waitForFunction(() => !document.querySelector('#videoq-native-controls').disabled);
  for (const text of ['気になる場面で止めて、質問できます。', '動画教材を開く', '質問画面を別に開く']) assert.equal(await page.getByText(text, { exact: true }).count(), 0);
  await input.fill('ストリーミングを確認します'); await input.press('Enter');
  await chat.locator('.answer [data-region="text-container"]').filter({ hasText: '最初に見える説明です。' }).waitFor();
  assert.equal(await input.isDisabled(), true, 'Answer must be visible while generation is still held open');
  assert.equal(await chat.getByRole('button', { name: 'Good', exact: true }).count(), 0);
  assert.equal(await chat.locator('.answer h6').textContent(), 'AIアシスタント');
  assert.equal(await chat.locator('.answer [data-region="text-container"] img').count(), 0);
  await page.screenshot({ path: dir + '/streaming-partial.png', animations: 'disabled' });
  await page.frameLocator('#videoq-questions').locator('html').evaluate(() => window.finishStream());
  await page.waitForFunction(() => !document.querySelector('#videoq-native-controls').disabled);
  assert.match(await chat.locator('.answer strong').textContent(), /続きの説明/);
  const good = chat.getByRole('button', { name: 'Good', exact: true });
  const bad = chat.getByRole('button', { name: 'Bad', exact: true });
  const waitPressed = (value, pressed) => page.waitForFunction(({ value, pressed }) => document.querySelector('[data-feedback="' + value + '"]').getAttribute('aria-pressed') === String(pressed) && !document.querySelector('[data-feedback="' + value + '"]').disabled, { value, pressed });
  await good.click(); await waitPressed('good', true);
  await good.click(); await waitPressed('good', false);
  await bad.click(); await waitPressed('bad', true);
  failNext = true; await good.click();
  await chat.locator('[data-region="answer-feedback"] [role="status"]').filter({ hasText: '評価を保存できませんでした' }).waitFor();
  assert.equal(await bad.getAttribute('aria-pressed'), 'true'); assert.equal(await good.isDisabled(), false);
  await good.click(); await waitPressed('good', true); assert.equal(await bad.getAttribute('aria-pressed'), 'false');
  assert.deepEqual(ratings, ['good', null, 'bad', 'good', 'good']);
  const events = JSON.stringify(await page.evaluate(() => window.nativeEvents));
  assert(!events.includes(feedbackId)); assert(!events.includes(token)); assert(!events.includes('chat_log_id'));
  await chat.locator('.citation').click();
  await page.waitForFunction(() => Math.abs(document.querySelector('video').currentTime - 8) < 2 && !document.querySelector('video').paused);
  await page.locator('video').evaluate(video => video.pause());
  await page.screenshot({ path: dir + '/streaming-complete.png', animations: 'disabled' });
  await input.fill('途中切断の確認'); await input.press('Enter');
  await chat.locator('.answer').nth(1).waitFor();
  await page.frameLocator('#videoq-questions').locator('html').evaluate(() => window.interruptStream());
  await chat.locator('#videoq-native-status.text-danger').waitFor();
  assert.equal(await chat.locator('.answer').nth(1).getByRole('button', { name: 'Good', exact: true }).count(), 0);
  assert.equal(await input.inputValue(), '途中切断の確認'); assert.equal(await input.isDisabled(), false);
  writeFileSync(dir + '/streaming-check.json', JSON.stringify({ partialVisibleBeforeDone: true, utf8AndSplitCrlf: true, realSseParser: true, assistantName: true, removedHintsAndLinks: true, feedbackGoodBadClear: true, feedbackFailureRetainsPreviousValue: true, feedbackRetry: true, privateCredentialsRemainInFrame: true, citationSeek: true, interruptedStreamNotRated: true, paidRequests: 0 }, null, 2));
  console.log('Native Moodle streaming before completion, Good/Bad persistence contract, clearing/retry, privacy and interrupted-stream behavior passed.');
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) { console.error('Page:', new URL(page.url()).pathname); console.error((await page.locator('body').innerText().catch(() => '')).slice(-1400)); }
  throw error;
} finally { await browser.close(); }
