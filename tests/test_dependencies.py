"""Repository-wide dependency contract. Browser JavaScript is intentionally allowed."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]

class DependencyTests(unittest.TestCase):
    def test_no_server_javascript_or_dependency_installation(self):
        forbidden = []
        for path in ROOT.rglob('*'):
            relative = path.relative_to(ROOT)
            if any(part in {'.git', '.venv', '__pycache__', 'dist'} for part in relative.parts):
                continue
            if path.name in {'node_modules', 'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', '.nvmrc', '.node-version'} or path.suffix in {'.mjs', '.cjs', '.ts', '.tsx'}:
                forbidden.append(str(relative))
            if path.suffix == '.js' and relative.parts[0] != 'plugins':
                forbidden.append(str(relative))
        self.assertEqual(forbidden, [], 'Unexpected server JS or dependency tree')

    def test_workflows_use_shell_steps_not_hidden_javascript_actions(self):
        for path in (ROOT / '.github' / 'workflows').glob('*'):
            contents = path.read_text()
            self.assertNotRegex(contents, r'(?m)^\s*-?\s*uses:')
            self.assertNotRegex(contents, r'(?i)\b(?:node|npm|npx|yarn|pnpm)(?:\s|:)')

    def test_compose_owns_only_moodle_and_python_mock(self):
        import yaml
        compose = yaml.safe_load((ROOT / 'lab' / 'compose.yaml').read_text())
        self.assertEqual(set(compose['services']), {'db','moodle','proxy','videoq-sync','ai'})
        self.assertNotRegex(json_text(compose), r'(?i)\b(?:node|npm|npx|playwright)(?:\s|:|\")')

    def test_no_indirect_runtime_invocations_or_bundled_browser_tool(self):
        for path in ROOT.rglob('*'):
            relative = path.relative_to(ROOT)
            if any(part in {'.git','.venv','__pycache__','dist'} for part in relative.parts) or path == Path(__file__):
                continue
            if path.is_file() and path.suffix in {'.py','.sh','.php','.yaml','.yml'}:
                self.assertNotRegex(path.read_text(), r'''['"](?:node|npm|npx|yarn|pnpm)['"]''', str(relative))
        requirements = (ROOT / 'lab' / 'requirements.txt').read_text().lower()
        self.assertNotRegex(requirements, r'(?m)^\s*(?:playwright|pyexecjs|nodejs|nodeenv)\b')

def json_text(value):
    import json
    return json.dumps(value)

if __name__ == '__main__':
    unittest.main()
