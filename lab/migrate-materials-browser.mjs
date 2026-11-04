// Explicit local-lab rollout: migrate the existing owner-approved installation in place.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { setTimeout } from 'node:timers/promises';
const origin = 'https://videoq.localhost:18443', moodle = 'https://moodle.localhost:18443';
const database = sql => JSON.parse(execFileSync('sudo', ['-n','docker','exec','videoq-lti-lab-api-1','node','--input-type=module','-e',
  `import{labDatabase}from'/fixtures/lab-database.mjs';const db=await labDatabase();try{console.log(JSON.stringify((await db.query(${JSON.stringify(sql)})).rows));}finally{await db.end();}`], {encoding:'utf8',timeout:30000}));
const startedAt = Math.floor(Date.now()/1000);
const moodleState = () => JSON.parse(execFileSync('sudo',['-n','docker','exec','videoq-lti-lab-db-1','psql','-U','postgres','-d','moodle','-Atc',
  "SELECT json_build_object('completed',status='completed','acknowledged',lastsynced>=" + startedAt + ") FROM mdl_local_videoq_sync WHERE cmid=82"],{encoding:'utf8'}));
const before = database('SELECT id,file,status FROM videos ORDER BY id');
assert.equal(database("SELECT id FROM lms_installations WHERE id='moodle-sync-lab'").length, 1);
const browser = await chromium.launch({ headless:true, args:['--no-proxy-server'] });
const owner = await browser.newContext({ignoreHTTPSErrors:true,locale:'ja-JP'});
try {
  const page = await owner.newPage();
  await page.goto(origin + '/login');
  await page.locator('#username').fill('videoq_lab'); await page.locator('#password').fill('Lti-lab-only-123!');
  await page.locator('form button[type="submit"]').click(); await page.waitForURL(url=>!url.pathname.endsWith('/login'));
  await page.goto(origin + '/settings#moodle');
  await page.getByLabel('MoodleのURL',{exact:true}).fill(moodle);
  await page.getByRole('button',{name:'接続コードを発行',exact:true}).click();
  const code = await page.getByLabel('接続コード',{exact:true}).inputValue();
  assert(code.startsWith('VIDEOQ2.'),'New setup must not issue a sync-key connection');
  const admin = await browser.newContext({ignoreHTTPSErrors:true,locale:'ja-JP'});
  const settings = await admin.newPage();
  await settings.goto(moodle + '/login/index.php');
  await settings.locator('#username').fill('admin'); await settings.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});
  await settings.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([settings.waitForURL(url=>!url.pathname.includes('/login/')),settings.locator('#loginbtn').click()]);
  await settings.goto(moodle + '/local/videoq/connect.php');
  const update = settings.locator('details:has(#videoq-connectioncode) > summary');
  if (await update.count()) await update.click();
  await settings.locator('#videoq-connectioncode').fill(code);
  await settings.locator('form:has(#videoq-connectioncode) button[type=submit]').click();
  await settings.locator('#videoq-connectioncode').waitFor({state:'hidden',timeout:30000});
  const migrated = database("SELECT id,active,sync_token_hash IS NULL AS no_sync_key,materials_service IS NOT NULL AS service FROM lms_installations WHERE id='moodle-sync-lab'")[0];
  assert.deepEqual(migrated,{id:'moodle-sync-lab',active:true,no_sync_key:true,service:true});
  let synchronized = false;
  for(let attempt=0;attempt<90;attempt++) {
    const current = moodleState();
    if(current?.completed && current.acknowledged) { synchronized=true; break; } await setTimeout(1000);
  }
  assert(synchronized,'The background service must run');
  const learner = await browser.newContext({ignoreHTTPSErrors:true,locale:'ja-JP',viewport:{width:1440,height:1000}});
  const lesson = await learner.newPage();
  await lesson.goto(moodle + '/login/index.php');
  await lesson.locator('#username').fill('admin'); await lesson.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});
  await lesson.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([lesson.waitForURL(url=>!url.pathname.includes('/login/')),lesson.locator('#loginbtn').click()]);
  let ready=false;
  for(let attempt=0;attempt<45;attempt++) {
    await lesson.goto(moodle + '/local/videoq/view.php?course=5');
    try { await lesson.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false,null,{timeout:4000}); ready=true;break; } catch {}
    await setTimeout(1000);
  }
  assert(ready,'A native Moodle chat must connect through LTI and the registered tool JWKS');
  assert.equal(await lesson.locator('#videoq-lesson option').count(),1);
  assert.deepEqual(database('SELECT id,file,status FROM videos ORDER BY id'),before);
  await lesson.screenshot({path:'/tmp/videoq-lti-lab/acceptance/materials-migrated.png',fullPage:true});
  const result={passed:true,existingInstallationMigratedInPlace:true,noSyncApiKey:true,existingMediaPreserved:true,
    publicJwksServiceAuthentication:true,nativeMoodleChatReady:true,backgroundSynchronization:true,
    courseUrl:moodle+'/local/videoq/view.php?course=5'};
  writeFileSync('/tmp/videoq-lti-lab/acceptance/materials-browser.json',JSON.stringify(result,null,2));
  console.log(JSON.stringify(result));
} finally { await browser.close(); }
