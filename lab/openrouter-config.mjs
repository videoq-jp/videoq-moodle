import { readFileSync, statSync } from 'node:fs';
import { parseEnv } from 'node:util';
import { createHash } from 'node:crypto';
import { lessonScenes } from './lesson-scenes.mjs';

export function parseOpenRouterSettings(contents) {
  const settings = parseEnv(contents);
  if (Object.keys(settings).some(key => !['OPENROUTER_API_KEY', 'LTI_LAB_CHAT_MODEL'].includes(key))) {
    throw new Error('openrouter.env permits only OPENROUTER_API_KEY and LTI_LAB_CHAT_MODEL');
  }
  const apiKey = settings.OPENROUTER_API_KEY?.trim();
  if (!apiKey || apiKey.length < 20 || !/^[A-Za-z0-9_-]+$/.test(apiKey) || /YOUR|REPLACE|PLACEHOLDER/.test(apiKey)) {
    throw new Error('Set OPENROUTER_API_KEY in the private openrouter.env file (do not paste it into chat)');
  }
  const model = settings.LTI_LAB_CHAT_MODEL?.trim() || 'openai/gpt-4.1-mini';
  if (!/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._:-]+$/.test(model)) throw new Error('Invalid LTI_LAB_CHAT_MODEL identifier');
  return { apiKey, model, embeddingModel: 'openai/text-embedding-3-small', baseUrl: 'https://openrouter.ai/api/v1' };
}
export function readOpenRouterSettings(path) {
  if ((statSync(path).mode & 0o077) !== 0) throw new Error('Run chmod 600 on openrouter.env before enabling OpenRouter');
  return parseOpenRouterSettings(readFileSync(path, 'utf8'));
}
export function indexFingerprint(settings) {
  // Credentials and chat model do not define the embedding space.
  return createHash('sha256').update(JSON.stringify({ provider: 'openrouter', baseUrl: settings.baseUrl, embeddingModel: settings.embeddingModel, dimensions: 1536, lessonScenes })).digest('hex');
}
export function runtimeSettings(settings) {
  return {
    OPENROUTER_API_KEY: settings.apiKey, LLM_API_KEY: settings.apiKey,
    LLM_BASE_URL: settings.baseUrl, LLM_MODEL: settings.model,
    EMBEDDING_API_KEY: settings.apiKey, EMBEDDING_BASE_URL: settings.baseUrl,
    EMBEDDING_MODEL: settings.embeddingModel, EMBEDDING_PROFILE: 'openai',
    PGVECTOR_COLLECTION_NAME: 'scene_embeddings',
    LLM_SEPARATE_TOOL_SCHEMA: 'false', LLM_PRESERVE_TOOL_REASONING: 'false',
  };
}
