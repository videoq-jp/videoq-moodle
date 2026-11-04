"""Incremental SSE decoding, including split UTF-8 and CRLF boundaries."""
import codecs
import json

class Events:
    def __init__(self):
        self.decoder = codecs.getincrementaldecoder('utf-8')()
        self.buffer = ''

    def feed(self, chunk=b'', final=False):
        self.buffer += self.decoder.decode(chunk, final=final)
        events = []
        # Normalize only complete delimiters; a trailing CR may belong to the next chunk.
        self.buffer = self.buffer.replace('\r\n', '\n')
        while '\n\n' in self.buffer:
            block, self.buffer = self.buffer.split('\n\n', 1)
            data = '\n'.join(line[5:].lstrip(' ') for line in block.split('\n') if line.startswith('data:'))
            if data:
                events.append(data)
        return events

def read_answer(data):
    events = Events().feed(data, final=True)
    segments, sources, done = {}, {}, False
    for value in events:
        event = json.loads(value)
        kind = event.get('type')
        if kind == 'error':
            raise ValueError('Answer stream failed')
        if kind == 'source':
            sources[event['source']['id']] = event['source']
        if kind in {'text_delta', 'citation'}:
            segment = segments.setdefault(event['segmentIndex'], {'text': '', 'sourceIds': []})
            if kind == 'text_delta':
                segment['text'] += event['text']
            elif event['sourceId'] not in segment['sourceIds']:
                segment['sourceIds'].append(event['sourceId'])
        if kind == 'done':
            done = True
    if not done:
        raise ValueError('Answer stream did not complete')
    return {'segments': [segments[index] for index in sorted(segments)], 'sources': list(sources.values())}
