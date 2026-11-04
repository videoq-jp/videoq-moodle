import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const compose = fileURLToPath(new URL('./compose.yaml', import.meta.url));
function visibility(activity, visible) {
  execFileSync('sudo', ['-n', 'docker', 'compose', '-p', 'videoq-lti-lab', '--env-file', join(dir, 'compose.env'), '-f', compose, 'exec', '-T', 'moodle', 'php', '/lab/visibility.php', activity, visible ? '1' : '0'], { stdio: 'pipe' });
}
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const fixture = JSON.parse(readFileSync(join(dir, 'moodle-fixture.json'), 'utf8'));
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
const results = [];
try {
  const anonymous = await browser.newContext({ ignoreHTTPSErrors: true });
  const anonpage = await anonymous.newPage();
  await anonpage.goto(fixture.lessonUrl);
  assert.match(new URL(anonpage.url()).pathname, /login/);
  await anonymous.close();
  for (const username of ['learner', 'teacher']) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    await page.goto(fixture.issuer + '/login/index.php');
    await page.locator('#username').fill(username);
    await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
    await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
    await page.goto(fixture.lessonUrl);
    const video = page.locator('.local-videoq-video video');
    await video.waitFor();
    await page.waitForFunction(() => document.querySelector('video')?.readyState >= 1);
    assert.equal(await video.evaluate(el => Math.round(el.duration)), 24);
    await video.evaluate(el => { el.muted = true; return el.play(); });
    const frame = page.frameLocator('#videoq-questions');
    await frame.getByLabel('教材について聞きたいこと').fill('LMSとVideoQはどうつながりますか？', { timeout: 30000 });
    await frame.getByRole('button', { name: '質問する' }).click();
    await frame.locator('.answer').waitFor({ timeout: 30000 });
    assert.match(await frame.locator('.answer').textContent(), /LMSとVideoQ/);
    assert.ok(await video.evaluate(el => el.currentTime > 0 && !el.paused));
    const videoBox = await page.locator('.local-videoq-video').boundingBox();
    const chatBox = await page.locator('.local-videoq-questions').boundingBox();
    assert.ok(videoBox.x + videoBox.width <= chatBox.x);
    await video.evaluate(el => { el.currentTime = 10; el.pause(); });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.screenshot({ path: join(dir, username + '-companion-desktop.png'), fullPage: true, animations: 'disabled' });
    await page.setViewportSize({ width: 390, height: 844 });
    const mobileVideo = await page.locator('.local-videoq-video').boundingBox();
    const mobileChat = await page.locator('.local-videoq-questions').boundingBox();
    assert.ok(mobileVideo.y + mobileVideo.height <= mobileChat.y);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.screenshot({ path: join(dir, username + '-companion-mobile.png'), fullPage: true });
    results.push({ user: username, authenticatedVideo: true, playbackDuringQuestion: true, desktopSideBySide: true, mobileStacked: true, anonymousLoginRequired: true });
    if (username === 'learner') {
      for (const activity of ['video', 'lti']) {
        try {
          visibility(activity, false);
          await page.goto(fixture.lessonUrl);
          assert.equal(await page.locator('.local-videoq-workspace').count(), 0);
          assert.equal(await page.locator('#videoq-questions').count(), 0);
        } finally { visibility(activity, true); }
      }
      results.at(-1).hiddenActivitiesRejected = true;
    }
    await context.close();
  }
  const outsiderContext = await browser.newContext({ ignoreHTTPSErrors: true });
  const outsiderPage = await outsiderContext.newPage();
  await outsiderPage.goto(fixture.issuer + '/login/index.php');
  await outsiderPage.locator('#username').fill('outsider');
  await outsiderPage.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await outsiderPage.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([outsiderPage.waitForURL(url => !url.pathname.includes('/login/')), outsiderPage.locator('#loginbtn').click()]);
  await outsiderPage.goto(fixture.lessonUrl);
  assert.equal(await outsiderPage.locator('.local-videoq-workspace').count(), 0);
  assert.equal(await outsiderPage.locator('#videoq-questions').count(), 0);
  results.push({ unenrolledUserRejected: true });
  await outsiderContext.close();
  writeFileSync(join(dir, 'companion-verification.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results, null, 2));
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) {
    console.error('Page:', page.url(), (await page.locator('body').innerText().catch(() => '')).slice(0, 2000));
    await page.screenshot({ path: join(dir, 'companion-failure.png'), fullPage: true }).catch(() => {});
  }
  throw error;
} finally { await browser.close(); }
