// One real AI answer and real persisted feedback in the isolated trial.
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const dir = '/tmp/videoq-lti-lab';
const fixture = JSON.parse(readFileSync(dir + '/moodle-fixture.json'));
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
try {
  const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1100 } });
  page.on('pageerror', error => console.error('Browser:', error.name, error.message));
  page.on('response', response => { if (response.status() >= 400) console.error('HTTP', response.status(), new URL(response.url()).pathname); });
  await page.addInitScript(() => {
    if (location.origin !== 'https://moodle.localhost:18443') return;
    window.streamObservations = [];
    window.addEventListener('message', event => {
      if (event.origin !== 'https://videoq.localhost:18443' || event.data?.type !== 'videoq:chat') return;
      if (['answer-update', 'answer'].includes(event.data.event)) window.streamObservations.push({ event: event.data.event, at: performance.now(), characters: event.data.answer.segments.map(segment => segment.text).join('').length });
    });
  });
  await page.goto(fixture.issuer + '/login/index.php');
  await page.locator('#username').fill('learner'); await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  await page.goto(fixture.issuer + '/local/videoq/view.php?course=2');
  await page.waitForFunction(() => !document.querySelector('#videoq-native-controls').disabled, null, { timeout: 60000 });
  const chat = page.locator('#videoq-native-chat');
  await chat.locator('[data-region="send-message-txt"]').fill('第1回と第2回について、LMSとVideoQの役割の違いと、講座を使った復習の進め方を詳しく説明してください。両方の動画を参照してください。');
  const reply = page.waitForResponse(response => response.url().endsWith('/api/lti/messages/stream'));
  await chat.locator('[data-action="send-message"]').click();
  const response = await reply; assert.equal(response.status(), 200); assert.match(response.headers()['content-type'], /text\/event-stream/);
  await page.waitForFunction(() => !document.querySelector('#videoq-native-controls').disabled && document.querySelector('#videoq-native-chat [data-feedback="good"]'), null, { timeout: 120000 });
  const observations = await page.evaluate(() => window.streamObservations);
  const final = observations.find(event => event.event === 'answer');
  const partial = observations.filter(event => event.event === 'answer-update' && event.at < final.at);
  assert(partial.length > 1); assert(partial[0].characters < final.characters);
  assert.equal(await chat.locator('.answer h6').textContent(), 'AIアシスタント');
  const values = [];
  for (const value of ['good', 'bad', 'good']) {
    const received = page.waitForResponse(response => response.url().endsWith('/api/lti/feedback'));
    await chat.locator('[data-feedback="' + value + '"]').click();
    const response = await received; assert.equal(response.status(), 200); values.push((await response.json()).feedback);
    await page.waitForFunction(value => document.querySelector('[data-feedback="' + value + '"]').getAttribute('aria-pressed') === 'true' && !document.querySelector('[data-feedback="' + value + '"]').disabled, value);
  }
  assert.deepEqual(values, ['good', 'bad', 'good']);
  await page.screenshot({ path: dir + '/streaming-live.png', animations: 'disabled' });
  writeFileSync(dir + '/streaming-live.json', JSON.stringify({ providerAnswer: true, contentType: response.headers()['content-type'], partialUpdatesBeforeCompletion: partial.length, firstPartialCharacters: partial[0].characters, finalCharacters: final.characters, realFeedbackSaved: values, assistantName: 'AIアシスタント' }, null, 2));
  console.log('Live streaming answer showed incremental text and real Good/Bad feedback updates passed.');
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) console.error((await page.locator('#videoq-native-status').innerText().catch(() => '')).slice(0, 300));
  throw error;
} finally { await browser.close(); }
