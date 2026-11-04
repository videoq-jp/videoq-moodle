import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import assert from 'node:assert/strict';
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const fixture = JSON.parse(readFileSync(join(dir, 'moodle-fixture.json'), 'utf8'));
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
const results = [];
try {
  for (const username of ['learner', 'teacher']) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await page.goto(fixture.issuer + '/login/index.php');
    await page.locator('#username').fill(username);
    await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
    await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
    const sessionResponse = page.waitForResponse(response => response.url().endsWith('/api/lti/session') && response.status() === 200);
    await page.goto(fixture.activities['VideoQ questions'].url);
    const frame = page.frameLocator('#contentframe');
    await frame.getByLabel('教材について聞きたいこと').fill('この教材は何についての説明ですか？', { timeout: 30000 });
    await frame.getByRole('button', { name: '質問する' }).click();
    await frame.locator('.answer').waitFor({ timeout: 30000 });
    assert.match(await frame.locator('.answer').textContent(), /LMSとVideoQ/);
    assert.match(await frame.locator('.answer small').textContent(), /LTI lab lesson/);
    await frame.getByLabel('教材について聞きたいこと').fill('もう少し説明してください');
    await frame.getByRole('button', { name: '質問する' }).click();
    await frame.locator('.answer').nth(1).waitFor({ timeout: 30000 });
    await page.screenshot({ path: join(dir, username + '-embedded.png'), fullPage: true });
    results.push({ user: username, embeddedLaunch: true, questionAndFollowup: true, citations: true });
    const response = await sessionResponse;
    const proof = response.request().postDataJSON();
    const { token } = await response.json();
    const statuses = await frame.locator('html').evaluate(async (_el, { proof, token }) => {
      const replay = await fetch('/api/lti/session', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(proof) });
      const injection = await fetch('/api/lti/messages', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token }, body: JSON.stringify({ course_id: 99999, messages: [{ role: 'user', content: 'different course' }] }) });
      return { replay: replay.status, injection: injection.status };
    }, { proof, token });
    assert.equal(statuses.replay, 401);
    assert.equal(statuses.injection, 400);
    results.at(-1).replayRejected = true;
    results.at(-1).courseInjectionRejected = true;
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await frame.locator('html').evaluate(el => el.scrollWidth <= window.innerWidth), true);
    await page.screenshot({ path: join(dir, username + '-mobile.png'), fullPage: true });
    results.at(-1).mobileLayout = true;
    await page.setViewportSize({ width: 1280, height: 900 });
    const popupPromise = page.waitForEvent('popup');
    await page.goto(fixture.activities['VideoQ new window'].url);
    const popup = await popupPromise;
    await popup.getByLabel('教材について聞きたいこと').fill('別ウィンドウから質問します', { timeout: 30000 });
    await popup.getByRole('button', { name: '質問する' }).click();
    await popup.locator('.answer').waitFor({ timeout: 30000 });
    results.at(-1).newWindowLaunch = true;
    await popup.close();
    await page.goto(fixture.activities['Unbound questions'].url);
    await page.frameLocator('#contentframe').getByRole('status').filter({ hasText: '接続できません' }).waitFor({ timeout: 30000 });
    results.at(-1).unboundResourceRejected = true;
    await context.close();
  }
  writeFileSync(join(dir, 'verification.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results, null, 2));
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) {
    console.error('Page:', page.url());
    for (const frame of page.frames()) console.error('Frame:', frame.url(), (await frame.locator('body').innerText().catch(() => '')).slice(0, 1200));
    await page.screenshot({ path: join(dir, 'failure.png'), fullPage: true }).catch(() => {});
  }
  throw error;
} finally { await browser.close(); }
