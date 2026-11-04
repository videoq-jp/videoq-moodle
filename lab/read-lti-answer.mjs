// Browser acceptance helpers may inspect either the legacy JSON or the live SSE response.
export async function readLtiAnswer(response) {
  if (!response.headers()['content-type']?.includes('text/event-stream')) return response.json();
  const segments = new Map(), sources = new Map(); let done = false;
  for (const block of (await response.text()).replace(/\r\n/g, '\n').split('\n\n')) {
    const line = block.split('\n').filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n');
    if (!line) continue;
    const event = JSON.parse(line);
    if (event.type === 'error') throw new Error('LTI answer failed: ' + event.code);
    if (event.type === 'source') sources.set(event.source.id, event.source);
    if (event.type === 'text_delta' || event.type === 'citation') {
      if (!segments.has(event.segmentIndex)) segments.set(event.segmentIndex, { text: '', sourceIds: [] });
      const part = segments.get(event.segmentIndex);
      if (event.type === 'text_delta') part.text += event.text;
      else if (!part.sourceIds.includes(event.sourceId)) part.sourceIds.push(event.sourceId);
    }
    if (event.type === 'done') done = true;
  }
  if (!done) throw new Error('LTI stream ended without completion');
  return { answer: { segments: [...segments.entries()].sort((a,b) => a[0]-b[0]).map(([,value]) => value), sources: [...sources.values()] } };
}
