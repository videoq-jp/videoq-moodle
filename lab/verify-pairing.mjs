// Full browser pairing against the isolated lab. Restores its original settings in finally.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { setTimeout } from 'node:timers/promises';
import assert from 'node:assert/strict';
import { readLtiAnswer } from './read-lti-answer.mjs';
const docker = (...args) => execFileSync('sudo', ['-n', 'docker', ...args], { encoding: 'utf8', timeout: 120000 }).trim();
const action = (name, input) => JSON.parse(execFileSync('sudo', ['-n', 'docker', 'exec', '-i', 'videoq-lti-lab-moodle-1', 'php', '/lab/pairing-acceptance.php', name], { encoding: 'utf8', timeout: 120000, input }));
const query = (sql, params = []) => JSON.parse(docker('exec', 'videoq-lti-lab-api-1', 'node', '--input-type=module', '-e',
  `import{labDatabase}from'/fixtures/lab-database.mjs';const db=await labDatabase();try{console.log(JSON.stringify((await db.query(${JSON.stringify(sql)},${JSON.stringify(params)})).rows));}finally{await db.end();}`));
const checks = [], check = message => { checks.push(message); console.log(message); };
let backedUp = false, fixture, tool;
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ja-JP', viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
try {
  assert.equal((await context.request.post('https://videoq.localhost:18443/api/lms/pairing', { data: { moodleUrl: 'https://moodle.localhost:18443' } })).status(), 401);
  await page.goto('https://videoq.localhost:18443/login');
  await page.locator('#username').fill('videoq_lab'); await page.locator('#password').fill('Lti-lab-only-123!');
  await page.locator('form button[type="submit"]').click(); await page.waitForURL(url => !url.pathname.endsWith('/login'));
  assert.equal((await context.request.post('https://videoq.localhost:18443/api/lms/pairing', { headers: { Origin: 'https://unrelated.example' }, data: { moodleUrl: 'https://moodle.localhost:18443' } })).status(), 403);
  check('Anonymous callers and unrelated browser origins cannot issue pairing codes.');
  await page.goto('https://videoq.localhost:18443/settings');
  await page.getByLabel('MoodleのURL', { exact: true }).fill('https://moodle.localhost:18443');
  await page.getByRole('button', { name: '接続コードを発行', exact: true }).click();
  const code = await page.getByLabel('接続コード', { exact: true }).inputValue();
  assert(code.startsWith('VIDEOQ1.'));
  await page.screenshot({ path: '/tmp/videoq-lti-lab/acceptance/pairing-videoq.png', fullPage: true, mask: [page.getByLabel('接続コード', { exact: true })] });
  check('The signed-in VideoQ owner generates a 10-minute Moodle-bound code in Settings.');
  const moodle = await context.newPage();
  await moodle.goto('https://moodle.localhost:18443/login/index.php');
  await moodle.locator('#username').fill('admin'); await moodle.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([moodle.waitForURL(url => !url.pathname.includes('/login/')), moodle.locator('#loginbtn').click()]);
  await moodle.goto('https://moodle.localhost:18443/local/videoq/connect.php');
  assert(/VideoQに接続されています。|VideoQ is connected\./.test(await moodle.locator('body').innerText()));
  check('The Moodle setup screen recognizes the existing connection without overwriting it.');
  docker('stop', 'videoq-lti-lab-videoq-sync-1'); action('backup'); backedUp = true;
  await moodle.reload();
  const invalidCode = 'VIDEOQ1.' + Buffer.from(JSON.stringify({ origin: 'https://videoq.localhost:18443', secret: 'e'.repeat(64) })).toString('base64');
  await moodle.getByLabel(/^(接続コード|Connection code)$/).fill(invalidCode);
  await Promise.all([moodle.waitForNavigation(), moodle.getByRole('button', { name: /^(接続|Connect)$/ }).click()]);
  await Promise.all([moodle.waitForNavigation(), moodle.getByRole('button', { name: /未完了の設定を中止|Cancel incomplete setup/ }).click()]);
  await moodle.getByLabel(/^(接続コード|Connection code)$/).waitFor();
  check('An invalid-code attempt can be safely cancelled before starting again.');
  await moodle.getByLabel(/^(接続コード|Connection code)$/).fill(code);
  await Promise.all([moodle.waitForNavigation(), moodle.getByRole('button', { name: /^(接続|Connect)$/ }).click()]);
  assert(/VideoQに接続されています。|VideoQ is connected\./.test(await moodle.locator('body').innerText()), 'Moodle must verify the newly paired connection');
  tool = action('type');
  const installations = query('SELECT id, owner_user_id, issuer, client_id, deployment_id FROM lms_installations WHERE client_id=$1', [tool.clientId]);
  assert.equal(installations.length, 1); assert.equal(installations[0].owner_user_id, 'lti-lab-owner');
  assert.equal(installations[0].deployment_id, String(tool.typeid));
  await moodle.screenshot({ path: '/tmp/videoq-lti-lab/acceptance/pairing-moodle.png', fullPage: true });
  check('Pasting one code creates the LTI 1.3 tool and verified synchronization connection without manual identifiers or keys.');
  const replay = await context.request.post('https://videoq.localhost:18443/api/lms/pairing/exchange', { data: {
    secret: JSON.parse(Buffer.from(code.slice(8), 'base64').toString()).secret, issuer: 'https://moodle.localhost:18443',
    clientId: tool.clientId, deploymentId: String(tool.typeid), syncToken: 'b'.repeat(64),
  } });
  assert.equal(replay.status(), 401);
  check('The consumed code cannot create a second connection.');
  action('simulate-lost-response', code);
  await moodle.reload();
  await moodle.getByLabel(/^(接続コード|Connection code)$/).fill(code);
  await Promise.all([moodle.waitForNavigation(), moodle.getByRole('button', { name: /^(接続|Connect)$/ }).click()]);
  assert(/VideoQに接続されています。|VideoQ is connected\./.test(await moodle.locator('body').innerText()));
  assert.equal(action('type').typeid, tool.typeid);
  assert.equal(query('SELECT id FROM lms_installations WHERE client_id=$1', [tool.clientId]).length, 1);
  check('Retrying after a lost successful response restores the same connection without replaying the consumed code.');
  fixture = action('setup');
  await moodle.goto('https://moodle.localhost:18443/local/videoq/manage.php?cmid=' + fixture.cmid);
  await Promise.all([moodle.waitForNavigation(), moodle.getByRole('button', { name: /^(質問機能を有効にする|Enable questions)$/ }).click()]);
  let remote;
  for (let attempt = 0; attempt < 120; attempt++) {
    action('tick');
    [remote] = query('SELECT r.video_id,r.course_id,v.status FROM lms_resources r JOIN videos v ON v.id=r.video_id WHERE r.installation_id=$1 AND r.external_id=$2', [installations[0].id, String(fixture.cmid)]);
    if (remote?.status === 'completed') break;
    if (remote?.status === 'error') throw new Error('Real speech processing failed');
    await setTimeout(2000);
  }
  assert.equal(remote?.status, 'completed');
  check('A teacher enables a fresh video and the automatically configured connection transfers and processes real speech.');
  await moodle.goto('https://moodle.localhost:18443/local/videoq/view.php?course=' + fixture.courseid);
  await moodle.waitForFunction(() => !document.querySelector('#videoq-native-controls')?.disabled);
  const chat = moodle.locator('#videoq-native-chat');
  const resultPromise = moodle.waitForResponse(response => response.url().endsWith('/api/lti/messages/stream'));
  await chat.locator('[data-region="send-message-txt"]').fill('この教材の練習例につけられた英語の名前を、音声の表現どおりに答えてください。');
  await chat.locator('[data-action="send-message"]').click();
  const response = await resultPromise; assert.equal(response.status(), 200);
  const result = await readLtiAnswer(response);
  assert(result.answer.sources.some(source => Number(source.video_id) === Number(remote.video_id)));
  await moodle.waitForFunction(() => !document.querySelector('#videoq-native-controls').disabled);
  const text = await chat.locator('.answer [data-region="text-container"]').innerText();
  assert.match(text, /silver\s+maple|シルバー.*メープル|銀.*カエデ/i);
  await moodle.screenshot({ path: '/tmp/videoq-lti-lab/acceptance/pairing-answer.png', fullPage: true });
  check('The automatically registered LTI launch authenticates and returns a real grounded AI answer.');
} catch (error) {
  writeFileSync('/tmp/videoq-lti-lab/acceptance/pairing-failure.json', JSON.stringify({ checks, message: error.message }, null, 2));
  throw error;
} finally {
  try {
    if (fixture) action('cleanup');
    if (backedUp) { assert(action('restore').restored); check('Original Moodle connection settings were restored exactly.'); }
    if (tool) {
      query("DELETE FROM video_courses WHERE name='VideoQ pairing acceptance' AND NOT EXISTS (SELECT 1 FROM video_course_members m WHERE m.course_id=video_courses.id)");
      query('DELETE FROM lms_installations WHERE client_id=$1 AND issuer=$2 AND owner_user_id=$3', [tool.clientId, 'https://moodle.localhost:18443', 'lti-lab-owner']);
    }
  } finally { docker('start', 'videoq-lti-lab-videoq-sync-1'); await browser.close(); }
}

rmSync('/tmp/videoq-lti-lab/acceptance/pairing-failure.json', { force: true });
writeFileSync('/tmp/videoq-lti-lab/acceptance/pairing.json', JSON.stringify({ passed: true, checks, sourceCmid: fixture.cmid, realAnswer: true, originalSettingsRestored: true }, null, 2));
