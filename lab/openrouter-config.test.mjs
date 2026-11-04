import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { indexFingerprint, parseOpenRouterSettings, readOpenRouterSettings, runtimeSettings } from './openrouter-config.mjs';
const key = 'test-key-with-at-least-twenty-characters';
test('selects real answer and matching document/query embedding endpoints', () => {
  const config = parseOpenRouterSettings('OPENROUTER_API_KEY=' + key);
  const runtime = runtimeSettings(config);
  assert.equal(runtime.LLM_MODEL, 'openai/gpt-4.1-mini');
  assert.equal(runtime.EMBEDDING_MODEL, 'openai/text-embedding-3-small');
  assert.equal(runtime.LLM_BASE_URL, runtime.EMBEDDING_BASE_URL);
  assert.equal(runtime.LLM_API_KEY, key);
});
test('does not allow env injection or echo credentials in validation errors', () => {
  for (const contents of ['', 'OPENROUTER_API_KEY=YOUR_SECRET_KEY_VALUE', 'OPENROUTER_API_KEY=' + key + '\nDATABASE_URL=postgres://other', 'OPENROUTER_API_KEY=' + key + '\nLTI_LAB_CHAT_MODEL=invalid value']) {
    assert.throws(() => parseOpenRouterSettings(contents), error => !error.message.includes(key));
  }
});
test('index fingerprint follows embedding space, not API key or answer model', () => {
  const config = parseOpenRouterSettings('OPENROUTER_API_KEY=' + key);
  assert.equal(indexFingerprint(config), indexFingerprint({ ...config, apiKey: 'rotated', model: 'other/model' }));
  assert.notEqual(indexFingerprint(config), indexFingerprint({ ...config, embeddingModel: 'other/model' }));
});
test('rejects credential files readable by other users', () => {
  const dir = mkdtempSync(join(tmpdir(), 'videoq-lti-key-test-'));
  try {
    const path = join(dir, 'openrouter.env');
    writeFileSync(path, 'OPENROUTER_API_KEY=' + key, { mode: 0o600 });
    assert.equal(readOpenRouterSettings(path).apiKey, key);
    chmodSync(path, 0o644);
    assert.throws(() => readOpenRouterSettings(path), /chmod 600/);
  } finally { rmSync(dir, { recursive: true, force: true }); }
});
