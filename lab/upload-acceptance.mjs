// Create the acceptance video through Moodle's real File-activity form and file picker.
import { chromium } from 'playwright';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import assert from 'node:assert/strict';
import { basename, resolve } from 'node:path';
const dir = '/tmp/videoq-lti-lab/acceptance';
const artifact = dir + '/' + (process.env.LTI_UPLOAD_ARTIFACT || 'moodle-upload.json');
const media = resolve(process.env.LTI_UPLOAD_FILE || dir + '/spoken-lesson-100m.mp4');
assert(media.startsWith(dir + '/'));
assert(artifact.startsWith(dir + '/') && !artifact.includes('..'));
const name = process.env.LTI_UPLOAD_NAME || '自動同期確認：音声入り教材';
if (existsSync(artifact)) { console.log('The acceptance upload already has a saved activity ID; validate it before repeating.'); process.exit(0); }
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
try {
  const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
  page.on('response', response => {if(response.request().method()==='POST')console.log('Upload browser POST',new URL(response.url()).pathname,response.status());});
  await page.goto('https://moodle.localhost:18443/login/index.php');
  await page.locator('#username').fill('teacher'); await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  await page.goto('https://moodle.localhost:18443/course/modedit.php?add=resource&type=&course=2&section=0&return=0');
  await page.locator('#id_name').fill(name);
  await page.locator('.fp-btn-add').click();
  await page.locator('.fp-repo-area').getByText('ファイルのアップロード', { exact: true }).click();
  await page.locator('input[name="repo_upload_file"]').setInputFiles(media);
  const upload = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/repository_ajax.php') && response.request().method() === 'POST', { timeout: 30000 });
  await page.locator('.fp-upload-btn').click();
  const uploaded = await upload; assert.equal(uploaded.status(), 200);
  await page.locator('.filemanager').getByText(basename(media), { exact: true }).waitFor({ timeout: 120000 });
  await Promise.all([page.waitForURL(url => url.pathname === '/mod/resource/view.php', { timeout: 60000 }), page.locator('#id_submitbutton').click()]);
  const cmid = Number(new URL(page.url()).searchParams.get('id')); assert(Number.isSafeInteger(cmid) && cmid > 0);
  writeFileSync(artifact, JSON.stringify({ cmid, courseId: 2, name, uploadedViaMoodleUi: true, bytes: readFileSync(media).length, createdAt: new Date().toISOString() }, null, 2));
  await page.screenshot({ path: dir + '/moodle-uploaded.png', animations: 'disabled' });
  console.log('A teacher uploaded the spoken video through the Moodle UI.');
} catch (error) {
  for (const context of browser.contexts()) for (const page of context.pages()) {await page.screenshot({path:dir+'/upload-failure.png',fullPage:true});console.error((await page.locator('body').innerText().catch(() => '')).slice(-2400));}
  throw error;
} finally { await browser.close(); }
