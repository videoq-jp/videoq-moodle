// Real browser/provider acceptance for the migrated lab course. No mocked HTTP responses.
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { readLtiAnswer } from './read-lti-answer.mjs';
const browser = await chromium.launch({headless:true,args:['--no-proxy-server']});
try {
  const page = await browser.newPage({ignoreHTTPSErrors:true,locale:'ja-JP',viewport:{width:1440,height:1100}});
  await page.goto('https://moodle.localhost:18443/login/index.php');
  await page.locator('#username').fill('admin'); await page.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});
  await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);
  await page.goto('https://moodle.localhost:18443/local/videoq/view.php?course=5');
  await page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);
  const chat = page.locator('#videoq-native-chat');
  const responsePromise = page.waitForResponse(r=>r.url().endsWith('/api/lti/messages/stream'),{timeout:120000});
  await chat.locator('[data-region="send-message-txt"]').fill('この動画で説明している、画像の標本化と量子化の違いを簡潔に説明してください。');
  await chat.locator('[data-action="send-message"]').click();
  const response = await responsePromise;
  assert.equal(response.status(),200); assert.match(response.headers()['content-type'],/text\/event-stream/);
  const answer = (await readLtiAnswer(response)).answer;
  assert(answer.segments.some(part=>part.text.trim().length>20));
  assert(answer.sources.length>0); assert(answer.sources.every(source=>source.video_id===27));
  await page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);
  const feedback = chat.locator('.answer [data-region="answer-feedback"]').last();
  for(const value of ['good','bad','bad']) {
    const saved = page.waitForResponse(r=>r.url().endsWith('/api/lti/feedback'));
    await feedback.locator('[data-feedback="'+value+'"]').click(); assert.equal((await saved).status(),200);
    await feedback.locator('[data-feedback="'+value+'"]').waitFor({state:'visible'});
    await page.waitForFunction(()=>[...document.querySelectorAll('[data-region="answer-feedback"]')].at(-1)?.querySelector('button')?.disabled===false);
  }
  const citation=chat.locator('.answer').last().locator('button.citation').first();
  const label=await citation.textContent();
  const seconds=label.replace(/[()]/g,'').split(':').reduce((total,value)=>total*60+Number(value),0);
  await citation.click();
  await page.waitForFunction(seconds=>Math.abs(document.querySelector('video').currentTime-seconds)<2,seconds,{timeout:15000});
  await page.locator('.local-videoq-video video').evaluate(video=>video.pause());
  await page.screenshot({path:'/tmp/videoq-lti-lab/acceptance/materials-chat.png',fullPage:true});
  const result={passed:true,realProvider:'openrouter',nativeMoodleChat:true,streamingAnswer:true,
    sourceVideoIds:[...new Set(answer.sources.map(source=>source.video_id))],feedbackGoodBadAndReset:true,timestampSeek:true};
  writeFileSync('/tmp/videoq-lti-lab/acceptance/materials-chat.json',JSON.stringify(result,null,2));
  console.log(JSON.stringify(result));
} finally { await browser.close(); }
