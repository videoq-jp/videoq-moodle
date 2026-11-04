import { currentFixture } from './current-fixture.mjs';
// Real Moodle core_message templates and theme; all answer responses are intercepted (no paid calls).
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import assert from 'node:assert/strict';
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const { moodle: fixture, videoq } = currentFixture(dir);
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
try {
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  await page.addInitScript(() => window.addEventListener('message', event => {
    if (event.data?.type === 'videoq:ready') window.designBridge = { ...event.data, origin: event.origin };
  }));
  let requests = 0;
  let nextStatus = 200;
  const paragraph = 'この教材では、動画の内容を確認しながら質問できます。説明がわからないときは、気になる場面で動画を止めて聞いてみましょう。';
  await page.route('**/api/lti/messages{,/stream}', async route => {
    requests++;
    if (nextStatus !== 200) { const status = nextStatus; nextStatus = 200; await route.fulfill({ status, json: { error: 'Fixture failure' } }); return; }
    await route.fulfill({ json: { answer: {
      segments: [{ text: '## 学習のポイント\n\n**教材に沿って確認する**ことが大切です。\n\n- 動画を見る\n- 気になる場面で質問する\n\n' + Array(7).fill(paragraph).join('\n\n') + '\n\n<img src=x onerror="alert(1)">', sourceIds: [1] }],
      sources: [{ id: 1, video_id: videoq.videoId, title: 'LMSとVideoQ', start_time: '00:00:08,000', end_time: '00:00:16,000' }],
    } } });
  });
  await page.goto(fixture.issuer + '/login/index.php');
  await page.locator('#username').fill('learner'); await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  await page.goto(fixture.lessonUrl);
  const chat = page.locator('#videoq-native-chat');
  const input = chat.locator('[data-region="send-message-txt"]');
  const send = chat.locator('[data-action="send-message"]');
  const log = chat.locator('[role="log"]');
  const waitReady = () => page.waitForFunction(() => !document.querySelector('#videoq-native-controls').disabled);
  await waitReady();
  await page.locator('.video-js .vjs-control-bar').waitFor({ state: 'attached' });
  assert.deepEqual(await page.locator('h1').allTextContents(), ['動画教材：LMSとVideoQ']);
  assert.equal(await chat.locator('#videoq-native-empty').isVisible(), true);
  assert.equal(await chat.locator('textarea.form-control.bg-light[data-auto-rows]').count(), 1);
  assert.equal(await send.locator('[data-region="send-icon-container"] .icon').count(), 1, 'Send icon must come from Moodle');
  assert.equal(await input.getAttribute('rows'), '3');
  assert.equal(await page.locator('#videoq-questions').getAttribute('aria-hidden'), 'true');
  assert.ok((await page.locator('#videoq-questions').boundingBox()).width <= 1);
  assert.ok((await chat.locator('.footer-container').boundingBox()).y < 1000);
  assert.equal(await chat.locator('#playback-controls, #current-question, #use-position, [data-question]').count(), 0);
  await page.screenshot({ path: join(dir, 'design-desktop-empty.png'), animations: 'disabled' });
  await input.fill('一行目\n二行目\n三行目\n四行目\n五行目');
  await page.waitForFunction(() => Number(document.querySelector('#videoq-native-question').rows) > 3, null, { timeout: 4000 });
  assert.equal(requests, 0);
  await input.fill('この動画について教えてください。');
  await input.evaluate(el => el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', isComposing: true, bubbles: true, cancelable: true })));
  assert.equal(requests, 0, 'IME composition must not submit');
  await input.press('Shift+Enter'); assert.match(await input.inputValue(), /\n/);
  await input.press('Enter');
  await chat.locator('.answer strong').waitFor(); await waitReady();
  assert.equal(await chat.locator('.answer [data-region="text-container"] img').count(), 0);
  assert.equal(await chat.locator('.answer ul li').count(), 2);
  assert.equal(await chat.locator('.message.send.question').count(), 1);
  assert.equal(await chat.locator('.message.received.answer').count(), 1);
  assert.equal(await chat.locator('.message[role="checkbox"]').count(), 0, 'Read-only AI messages must not pretend to support human-message deletion');
  assert.equal(await chat.locator('#videoq-native-empty').isHidden(), true);
  const question = chat.locator('.question').first();
  const original = await question.evaluate(el => getComputedStyle(el).backgroundColor);
  const override = await page.addStyleTag({ content: '.message-app .message.send { background-color: rgb(96, 54, 151) !important; }' });
  assert.equal(await question.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(96, 54, 151)');
  await override.evaluate(el => el.remove());
  assert.equal(await question.evaluate(el => getComputedStyle(el).backgroundColor), original);
  // Forged messages cannot append model text or change the native composer's state.
  const bridge = await page.evaluate(() => window.designBridge);
  await page.evaluate(({ bridge }) => {
    const child = document.querySelector('#videoq-questions').contentWindow;
    const data = { type: 'videoq:chat', version: 1, channel: bridge.channel, videoId: bridge.videoId, event: 'question', requestId: 'f'.repeat(32), text: 'FORGED' };
    window.dispatchEvent(new MessageEvent('message', { source: child, origin: 'https://attacker.example', data }));
    window.dispatchEvent(new MessageEvent('message', { source: window, origin: bridge.origin, data }));
    window.dispatchEvent(new MessageEvent('message', { source: child, origin: bridge.origin, data: { ...data, channel: 'f'.repeat(32) } }));
  }, { bridge });
  assert.equal(await chat.getByText('FORGED', { exact: true }).count(), 0);
  const video = page.locator('.local-videoq-video video');
  await video.evaluate(el => { el.muted = true; return el.play(); });
  for (let i = 0; i < 3; i++) {
    await input.fill('続きの質問 ' + (i + 1)); await input.press('Enter');
    await chat.locator('.answer').nth(i + 1).waitFor(); await waitReady();
  }
  nextStatus = 500;
  await input.fill('エラー時に入力を残す質問'); await input.press('Enter');
  await chat.locator('[role="status"].text-danger').waitFor(); await waitReady();
  assert.equal(await input.inputValue(), 'エラー時に入力を残す質問');
  assert.equal(await chat.locator('.answer').count(), 4);
  await input.press('Enter'); await chat.locator('.answer').nth(4).waitFor(); await waitReady();
  const bottom = () => log.evaluate(el => new Promise((resolve, reject) => {
    const end = Date.now() + 3000;
    const check = () => el.scrollHeight - el.scrollTop - el.clientHeight < 40 ? resolve() : Date.now() > end ? reject(new Error('Latest answer did not scroll into view')) : setTimeout(check, 30);
    check();
  }));
  await bottom();
  const messageBox = await log.boundingBox();
  const sourceBox = await chat.locator('.answer').last().locator('.citation').last().boundingBox();
  assert.ok(sourceBox.y + sourceBox.height <= messageBox.y + messageBox.height + 1);
  assert.ok(await log.evaluate(el => el.scrollHeight > el.clientHeight && el.scrollTop > 0));
  assert.equal(await video.evaluate(el => !el.paused), true);
  await video.evaluate(el => { el.currentTime = 10; el.pause(); });
  await page.screenshot({ path: join(dir, 'design-desktop-chat.png'), animations: 'disabled' });
  for (const width of [390, 768, 1280]) {
    await page.setViewportSize({ width, height: width === 1280 ? 900 : 844 });
    if (width <= 768) { const close = page.locator('.drawer.show button[data-action="closedrawer"]').first(); if (await close.isVisible()) await close.click(); }
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    const panel = await page.locator('.local-videoq-questions').boundingBox();
    const footer = await chat.locator('.footer-container').boundingBox();
    assert.ok(footer.y + footer.height <= panel.y + panel.height + 1);
    if (width <= 768) { const a = await page.locator('.local-videoq-video').boundingBox(); assert.ok(a.y + a.height <= panel.y); }
    await bottom();
    if (width === 390) { await chat.scrollIntoViewIfNeeded(); await page.screenshot({ path: join(dir, 'design-mobile-chat.png'), animations: 'disabled' }); }
  }
  const failedPage = await page.context().newPage();
  await failedPage.route('**/api/lti/session', route => route.fulfill({ status: 403, json: { error: 'Fixture failure' } }));
  await failedPage.goto(fixture.lessonUrl);
  await failedPage.locator('#videoq-native-status.text-danger').waitFor();
  assert.equal(await failedPage.locator('#videoq-native-question').isDisabled(), true);
  assert.equal(await failedPage.locator('.local-videoq-chat-link').count(), 0);
  await failedPage.close();
  const compatiblePage = await page.context().newPage();
  await compatiblePage.route('**/api/lti/session', route => route.fulfill({ json: { token: 'fixture-only-no-requests' } }));
  await compatiblePage.goto(fixture.lessonUrl);
  await compatiblePage.waitForFunction(() => document.querySelector('#videoq-native-chat').hidden && !document.querySelector('#videoq-questions').classList.contains('local-videoq-transport'), null, { timeout: 20000 });
  assert.equal(await compatiblePage.locator('#videoq-questions').getAttribute('aria-hidden'), null);
  await compatiblePage.close();
  writeFileSync(join(dir, 'design-verification.json'), JSON.stringify({ nativeMoodleMessagingTemplates: true, nativeComposer: true, nativeSendIcon: true, coreAutoRows: true, connectionFailureVisible: true, legacyFallback: true, failedQuestionRetry: true, themeInheritedDirectly: true, forgedMessagesRejected: true, messageOnlyScroll: true, latestSourceVisible: true, fixedComposer: true, safeFormatting: true, noExtraControls: true, imeSafe: true, playbackPreserved: true, responsiveWidths: [390, 768, 1280, 1440], paidRequests: 0 }, null, 2));
  console.log('Native Moodle messaging templates, composer, theme, auto-rows, safe rendering, scrolling, IME and responsive layout passed.');
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) {
    console.error('Page:', new URL(page.url()).pathname, (await page.locator('body').innerText().catch(() => '')).slice(-1200));
    await page.screenshot({ path: join(dir, 'design-failure.png'), animations: 'disabled' }).catch(() => {});
  }
  throw error;
} finally { await browser.close(); }
