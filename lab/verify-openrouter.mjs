// Paid-provider acceptance check: three questions against the original lab lesson.
import { chromium } from 'playwright';
import { readLtiAnswer } from './read-lti-answer.mjs';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import assert from 'node:assert/strict';
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const fixture = JSON.parse(readFileSync(join(dir, 'moodle-fixture.json'), 'utf8'));
const videoq = JSON.parse(readFileSync(join(dir, 'videoq-fixture.json'), 'utf8'));
const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
try {
  const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
  await page.goto(fixture.issuer + '/login/index.php');
  await page.locator('#username').fill('learner');
  await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url => !url.pathname.includes('/login/')), page.locator('#loginbtn').click()]);
  await page.goto(fixture.lessonUrl);
  const frame = page.locator('#videoq-native-chat');
  const questions = [
    { text: 'LMSは何を管理しますか？', expected: /受講|教材|進/ },
    { text: 'VideoQでは何ができますか？', expected: /質問|場面/ },
    { text: 'LTIでつなぐと、学習者は何ができるようになりますか？', expected: /動画|質問/ },
  ];
  const answers = [];
  for (const question of questions) {
    await frame.locator('[data-region="send-message-txt"]').fill(question.text, { timeout: 30000 });
    const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname.match(/\/api\/lti\/messages(?:\/stream)?$/), { timeout: 90000 });
    await frame.locator('[data-action="send-message"]').click();
    const response = await responsePromise;
    assert.equal(response.status(), 200);
    const body = await readLtiAnswer(response);
    const text = body.answer.segments.map(segment => segment.text).join('');
    assert.match(text, question.expected);
    assert.notEqual(text, 'この教材では、LMSとVideoQをLTIで接続します。');
    assert.ok(body.answer.sources.length > 0, 'A lesson answer must have retrieved sources');
    assert.ok(body.answer.sources.every(source => source.video_id === videoq.videoId));
    assert.ok(body.answer.sources.every(source => ['00:00:00', '00:00:08', '00:00:16'].includes(source.start_time?.replace(/[.,]000$/, ''))));
    answers.push({ question: question.text, answer: text, sources: body.answer.sources });
    await frame.locator('[data-action="send-message"]').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('#videoq-native-controls').disabled);
  }
  assert.equal(new Set(answers.map(item => item.answer)).size, questions.length);
  await page.screenshot({ path: join(dir, 'openrouter-companion.png'), fullPage: true, animations: 'disabled' });
  writeFileSync(join(dir, 'openrouter-verification.json'), JSON.stringify({ provider: 'OpenRouter', questionCount: answers.length, answers }, null, 2));
  console.log('Three real AI answers passed relevance, source scope and timestamp checks.');
} finally { await browser.close(); }
