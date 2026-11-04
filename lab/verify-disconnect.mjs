// Exercise disconnection only on a disposable connection, preserving the live lab site.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import assert from 'node:assert/strict';
import { setTimeout } from 'node:timers/promises';
const base = 'https://videoq.localhost:18443';
const issuer = 'https://disconnect.videoq.example';
const database = (sql, params = []) => JSON.parse(execFileSync('sudo', ['-n', 'docker', 'exec', 'videoq-lti-lab-api-1', 'node', '--input-type=module', '-e',
  `import{labDatabase}from'/fixtures/lab-database.mjs';const d=await labDatabase();try{console.log(JSON.stringify((await d.query(${JSON.stringify(sql)},${JSON.stringify(params)})).rows));}finally{await d.end();}`], { encoding: 'utf8', timeout: 30000 }));
const snapshot = () => database("SELECT (SELECT coalesce(jsonb_agg(to_jsonb(v) ORDER BY v.id),'[]'::jsonb) FROM videos v) AS videos,(SELECT count(*)::int FROM scene_embeddings) AS vectors,(SELECT count(*)::int FROM chat_logs) AS logs,(SELECT count(*)::int FROM video_course_members) AS memberships");
const before = snapshot();
const originalConnections = database('SELECT id,active FROM lms_installations ORDER BY id');
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ja-JP', viewport: { width: 1360, height: 1000 } });
const page = await context.newPage();
let id;
try {
  let ready = false;
  for (let attempt = 0; attempt < 40; attempt++) {
    try { if ((await context.request.get(base + '/ready')).status() === 200) { ready = true; break; } } catch {}
    await setTimeout(500);
  }
  assert(ready, 'API must become ready after restart');
  const denied = await context.request.post(base + '/api/lms/pairing/connections/unknown/disconnect');
  assert.equal(denied.status(), 401);
  await page.goto(base + '/login');
  await page.locator('#username').fill('videoq_lab'); await page.locator('#password').fill('Lti-lab-only-123!');
  await page.locator('form button[type="submit"]').click(); await page.waitForURL(url => !url.pathname.endsWith('/login'));
  await page.goto(base + '/settings#moodle');
  await page.getByLabel('MoodleのURL', { exact: true }).fill(issuer);
  await page.getByRole('button', { name: '接続コードを発行', exact: true }).click();
  const code = await page.getByLabel('接続コード', { exact: true }).inputValue();
  const secret = JSON.parse(Buffer.from(code.slice(8), 'base64').toString()).secret;
  const syncToken = randomBytes(32).toString('hex');
  const paired = await context.request.post(base + '/api/lms/pairing/exchange', { data: { secret, issuer,
    clientId: 'disconnect-' + randomBytes(8).toString('hex'), deploymentId: '1', syncToken } });
  assert.equal(paired.status(), 200); id = (await paired.json()).installationId;
  await page.reload();
  const row = page.locator('[data-connection-id="' + id + '"]');
  await row.getByRole('button', { name: '接続を解除', exact: true }).click();
  await row.getByText(/同期済みの動画/).waitFor();
  await row.getByRole('button', { name: 'キャンセル', exact: true }).click();
  assert.equal(database('SELECT active FROM lms_installations WHERE id=$1', [id])[0].active, true);
  await row.getByRole('button', { name: '接続を解除', exact: true }).click();
  await row.getByRole('button', { name: '解除する', exact: true }).click();
  await page.getByText('接続を解除しました。教材と同期済みデータは残っています。', { exact: true }).waitFor();
  await row.getByText(/接続解除済み/).waitFor();
  assert.equal(database('SELECT active FROM lms_installations WHERE id=$1', [id])[0].active, false);
  assert.equal((await context.request.get(base + '/api/lms/connection', { headers: { Authorization: 'Bearer ' + syncToken } })).status(), 401);
  assert.deepEqual(snapshot(), before);
  assert.deepEqual(database('SELECT id,active FROM lms_installations WHERE id<>$1 ORDER BY id', [id]), originalConnections);
  await page.reload(); await row.getByText(/接続解除済み/).waitFor();
  assert.equal(await row.getByRole('button', { name: '接続を解除', exact: true }).count(), 0);
  await row.scrollIntoViewIfNeeded();
  await page.screenshot({ path: '/tmp/videoq-lti-lab/acceptance/disconnect.png', fullPage: true });
  writeFileSync('/tmp/videoq-lti-lab/acceptance/disconnect.json', JSON.stringify({ passed: true, cancelPreservesConnection: true,
    revokedTokenStatus: 401, learningDataPreserved: true, otherConnectionsPreserved: true, disconnectedStatePersistsAfterReload: true }, null, 2));
  console.log('Disconnect UI, cancellation, retained data, rejected synchronization credential and isolated revocation passed.');
} finally {
  if (id) database('DELETE FROM lms_installations WHERE id=$1 AND issuer=$2 AND owner_user_id=$3', [id, issuer, 'lti-lab-owner']);
  await browser.close();
}
