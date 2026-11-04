"""Offline contracts for the Python lab; no browser, Docker or paid provider calls."""
import json
from pathlib import Path
import sys
import tempfile
import threading
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'lab'))
from videoq_lab.settings import Provider, parse_provider, read_provider, index_fingerprint
from videoq_lab.sse import Events, read_answer
from mock_ai import Handler, ThreadingHTTPServer


class ProviderTests(unittest.TestCase):
    def test_private_key_is_not_represented_or_fingerprinted(self):
        first = parse_provider('OPENROUTER_API_KEY=sk-or-test-12345678901234567890')
        second = Provider('different-key', model='different/model')
        self.assertNotIn(first.api_key, repr(first))
        self.assertEqual(index_fingerprint(first), index_fingerprint(second))

    def test_rejects_shell_code_unknown_duplicate_and_invalid_settings(self):
        for value in ['OPENROUTER_API_KEY=$(cat /secret)', 'UNKNOWN=value',
                      'OPENROUTER_API_KEY=short', 'export OPENROUTER_API_KEY=key',
                      'OPENROUTER_API_KEY=123456789012345678901\nOPENROUTER_API_KEY=123456789012345678902',
                      'OPENROUTER_API_KEY=123456789012345678901\nLTI_LAB_CHAT_MODEL=../../bad']:
            with self.subTest(value=value), self.assertRaises(ValueError):
                parse_provider(value)

    def test_refuses_public_secret_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'provider.env'
            path.write_text('OPENROUTER_API_KEY=123456789012345678901')
            path.chmod(0o644)
            with self.assertRaises(ValueError):
                read_provider(path)
            path.chmod(0o600)
            self.assertEqual(read_provider(path).api_key, '123456789012345678901')


class StreamTests(unittest.TestCase):
    def test_every_byte_boundary_including_utf8_and_crlf(self):
        data = 'data: {"text":"日本語"}\r\n\r\ndata: second\r\n\r\n'.encode()
        for boundary in range(len(data) + 1):
            parser = Events()
            self.assertEqual(parser.feed(data[:boundary]) + parser.feed(data[boundary:], final=True),
                             ['{"text":"日本語"}', 'second'])
        parser = Events()
        result = []
        for byte in data:
            result.extend(parser.feed(bytes([byte])))
        self.assertEqual(result, ['{"text":"日本語"}', 'second'])

    def test_answer_requires_done_preserves_citations_and_segment_order(self):
        events = [dict(type='source', source=dict(id=2, video_id=27)),
                  dict(type='text_delta', segmentIndex=1, text='後'),
                  dict(type='text_delta', segmentIndex=0, text='先'),
                  dict(type='citation', segmentIndex=0, sourceId=2),
                  dict(type='citation', segmentIndex=0, sourceId=2)]
        data = ''.join('data: ' + json.dumps(event) + '\n\n' for event in events).encode()
        with self.assertRaises(ValueError):
            read_answer(data)
        answer = read_answer(data + b'data: {"type":"done"}\n\n')
        self.assertEqual(answer['segments'], [dict(text='先', sourceIds=[2]), dict(text='後', sourceIds=[])])
        with self.assertRaises(ValueError):
            read_answer(b'data: {"type":"error"}\n\n')


class MockTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()

    def post(self, path, body):
        request = urllib.request.Request('http://127.0.0.1:' + str(self.server.server_port) + path,
                                         data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'})
        return urllib.request.urlopen(request, timeout=5)

    def test_transcript_and_embeddings(self):
        with self.post('/v1/audio/transcriptions', {}) as response:
            transcript = json.load(response)
        self.assertEqual(len(transcript['segments']), 3)
        self.assertTrue(transcript['text'])
        with self.post('/v1/embeddings', {'input': ['first', 'second']}) as response:
            embeddings = json.load(response)['data']
        self.assertEqual([row['index'] for row in embeddings], [0, 1])
        self.assertEqual(len(embeddings[0]['embedding']), 1536)

    def test_malformed_input_and_unknown_endpoint(self):
        for path, body, status in [('/v1/chat/completions', [], 400),('/missing', {}, 404)]:
            with self.subTest(path=path), self.assertRaises(urllib.error.HTTPError) as raised:
                self.post(path, body)
            self.assertEqual(raised.exception.code, status)

    def test_tool_then_answer_including_stream_and_usage(self):
        body = {'messages': [], 'tools': [{'function': {'name': 'search_scenes'}}]}
        with self.post('/v1/chat/completions', body) as response:
            self.assertEqual(json.load(response)['choices'][0]['finish_reason'], 'tool_calls')
        body.update(messages=[{'role': 'tool'}], stream=True)
        with self.post('/v1/chat/completions', body) as response:
            self.assertIn('text/event-stream', response.headers['Content-Type'])
            events = Events().feed(response.read(), final=True)
        self.assertEqual(events[-1], '[DONE]')
        self.assertIn('usage', json.loads(events[-2]))
        answer = json.loads(json.loads(events[1])['choices'][0]['delta']['content'])
        self.assertEqual(answer['segments'][0]['sourceIds'], [1])


if __name__ == '__main__':
    unittest.main()
