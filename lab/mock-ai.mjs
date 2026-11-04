// Deterministic OpenAI-compatible fixture. Never sends requests to external AI.
import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';
import { lessonScenes } from './lesson-scenes.mjs';
createServer(async (req, res) => {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  res.setHeader('Content-Type', 'application/json');
  if (req.url === '/v1/audio/transcriptions') {
    res.end(JSON.stringify({ task: 'transcribe', language: 'ja', duration: 24,
      text: lessonScenes.map(scene => scene.text).join(''),
      segments: lessonScenes.map((scene, index) => ({ id: index, start: index * 8, end: (index + 1) * 8, text: scene.text })),
    }));
    return;
  }
  const body = JSON.parse(Buffer.concat(chunks).toString() || '{}');
  if (req.url === '/v1/embeddings') {
    const inputs = Array.isArray(body.input) ? body.input : [body.input];
    res.end(JSON.stringify({ object: 'list', data: inputs.map((_, index) => ({ object: 'embedding', index, embedding: [1, ...Array(1535).fill(0)] })), usage: { prompt_tokens: 1, total_tokens: 1 } }));
    return;
  }
  const toolResult = body.messages?.some(message => message.role === 'tool');
  const message = !toolResult && body.tools?.some(tool => tool.function?.name === 'search_scenes')
    ? { role: 'assistant', content: null, tool_calls: [{ id: 'lab-search', type: 'function', function: { name: 'search_scenes', arguments: JSON.stringify({ query: 'LTI lab lesson', video_ids: null }) } }] }
    : { role: 'assistant', content: JSON.stringify({ segments: [{ text: 'この教材では、LMSとVideoQをLTIで接続します。', sourceIds: [1] }] }) };
  const id = randomUUID();
  if (body.stream) {
    res.setHeader('Content-Type', 'text/event-stream');
    const emit = (delta, finish_reason = null) => res.write('data: ' + JSON.stringify({ id, choices: [{ index: 0, delta, finish_reason }] }) + '\n\n');
    emit({ role: 'assistant', content: '' });
    if (message.tool_calls) emit({ tool_calls: message.tool_calls.map((call, index) => ({ ...call, index })) });
    else emit({ content: message.content });
    emit({}, message.tool_calls ? 'tool_calls' : 'stop');
    res.write('data: ' + JSON.stringify({ id, choices: [], usage: { prompt_tokens: 1, completion_tokens: 1, total_tokens: 2 } }) + '\n\n');
    res.end('data: [DONE]\n\n');
    return;
  }
  res.end(JSON.stringify({ id, object: 'chat.completion', created: 1, model: 'lab-model', choices: [{ index: 0, message, finish_reason: message.tool_calls ? 'tool_calls' : 'stop' }], usage: { prompt_tokens: 1, completion_tokens: 1, total_tokens: 2 } }));
}).listen(8080, '0.0.0.0');
