"""Deterministic OpenAI-compatible fixture; never contacts a real AI provider."""
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import uuid

SCENES = json.loads(Path(__file__).with_name('lesson-scenes.json').read_text())

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_POST(self):
        try:
            length = int(self.headers.get('Content-Length', '0'))
        except ValueError:
            self.send_error(400)
            return
        if length < 0 or length > 16 * 1024 * 1024:
            self.send_error(413)
            return
        raw = self.rfile.read(length)
        if self.path == '/v1/audio/transcriptions':
            self.reply({'task': 'transcribe', 'language': 'ja', 'duration': 24,
                        'text': ''.join(scene['text'] for scene in SCENES),
                        'segments': [{'id': i, 'start': i*8, 'end': (i+1)*8, 'text': scene['text']} for i, scene in enumerate(SCENES)]})
            return
        try:
            body = json.loads(raw or b'{}')
        except (ValueError, TypeError):
            self.send_error(400)
            return
        if not isinstance(body, dict):
            self.send_error(400)
            return
        if self.path == '/v1/embeddings':
            inputs = body.get('input', '')
            if not isinstance(inputs, list):
                inputs = [inputs]
            self.reply({'object': 'list', 'data': [{'object': 'embedding', 'index': i, 'embedding': [1]+[0]*1535} for i, _ in enumerate(inputs)],
                        'usage': {'prompt_tokens': 1, 'total_tokens': 1}})
            return
        if self.path != '/v1/chat/completions':
            self.send_error(404)
            return
        searched = any(message.get('role') == 'tool' for message in body.get('messages', []))
        tools = any(tool.get('function', {}).get('name') == 'search_scenes' for tool in body.get('tools', []))
        message = {'role': 'assistant', 'content': json.dumps({'segments': [{'text': 'この教材では、LMSとVideoQをLTIで接続します。', 'sourceIds': [1]}]}, ensure_ascii=False)}
        if tools and not searched:
            message = {'role': 'assistant', 'content': None, 'tool_calls': [{'id': 'lab-search', 'type': 'function', 'function': {'name': 'search_scenes', 'arguments': json.dumps({'query': 'LTI lab lesson', 'video_ids': None})}}]}
        identifier = str(uuid.uuid4())
        finish = 'tool_calls' if 'tool_calls' in message else 'stop'
        usage = {'prompt_tokens': 1, 'completion_tokens': 1, 'total_tokens': 2}
        if body.get('stream'):
            self.send_response(200)
            self.send_header('Content-Type', 'text/event-stream; charset=utf-8')
            self.end_headers()
            self.emit({'id': identifier, 'choices': [{'index': 0, 'delta': {'role': 'assistant', 'content': ''}, 'finish_reason': None}]})
            delta = {'content': message['content']} if finish == 'stop' else {'tool_calls': [dict(call, index=i) for i, call in enumerate(message['tool_calls'])]}
            self.emit({'id': identifier, 'choices': [{'index': 0, 'delta': delta, 'finish_reason': None}]})
            self.emit({'id': identifier, 'choices': [{'index': 0, 'delta': {}, 'finish_reason': finish}]})
            self.emit({'id': identifier, 'choices': [], 'usage': usage})
            self.wfile.write(b'data: [DONE]\n\n')
            return
        self.reply({'id': identifier, 'object': 'chat.completion', 'created': 1, 'model': 'lab-model',
                    'choices': [{'index': 0, 'message': message, 'finish_reason': finish}], 'usage': usage})

    def emit(self, data):
        self.wfile.write(('data: '+json.dumps(data, ensure_ascii=False)+'\n\n').encode())
        self.wfile.flush()

    def reply(self, data):
        encoded = json.dumps(data, ensure_ascii=False).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8080)
    args = parser.parse_args()
    ThreadingHTTPServer(('0.0.0.0', args.port), Handler).serve_forever()
