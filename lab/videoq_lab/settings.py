"""Strict private provider settings used only by explicitly requested live tests."""
from dataclasses import dataclass, field
import hashlib
import json
from pathlib import Path
import re
import shlex

@dataclass(frozen=True)
class Provider:
    api_key: str = field(repr=False)
    model: str = 'openai/gpt-4.1-mini'
    embedding_model: str = 'openai/text-embedding-3-small'
    base_url: str = 'https://openrouter.ai/api/v1'

def parse_provider(contents):
    values = {}
    for line in contents.splitlines():
        tokens = shlex.split(line, comments=True)
        if not tokens:
            continue
        if len(tokens) != 1 or '=' not in tokens[0]:
            raise ValueError('Invalid private provider settings')
        name, value = tokens[0].split('=', 1)
        if name not in {'OPENROUTER_API_KEY', 'LTI_LAB_CHAT_MODEL'} or name in values:
            raise ValueError('Only OPENROUTER_API_KEY and LTI_LAB_CHAT_MODEL are allowed')
        values[name] = value
    key = values.get('OPENROUTER_API_KEY', '').strip()
    if len(key) < 20 or not re.fullmatch(r'[A-Za-z0-9_-]+', key) or re.search(r'YOUR|REPLACE|PLACEHOLDER', key):
        raise ValueError('Configure a valid key in the private provider settings file')
    model = values.get('LTI_LAB_CHAT_MODEL') or 'openai/gpt-4.1-mini'
    if not re.fullmatch(r'[a-zA-Z0-9._-]+/[a-zA-Z0-9._:-]+', model):
        raise ValueError('Invalid provider model')
    return Provider(key, model)

def read_provider(path):
    path = Path(path)
    if path.stat().st_mode & 0o077:
        raise ValueError('Provider settings require mode 600')
    return parse_provider(path.read_text())

def index_fingerprint(provider):
    # Keys and answer models do not change the document/query embedding space.
    material = [provider.base_url, provider.embedding_model, 1536]
    return hashlib.sha256(json.dumps(material, separators=(',', ':')).encode()).hexdigest()
