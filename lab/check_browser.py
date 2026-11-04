"""Compile shipped browser scripts in native Chrome, never in a server runtime."""
from pathlib import Path
from videoq_lab.browser import browser

root = Path(__file__).resolve().parents[1]
with browser() as driver:
    driver.execute_cdp_cmd('Runtime.enable', {})
    for path in sorted((root / 'plugins').rglob('*.js')):
        result = driver.execute_cdp_cmd('Runtime.compileScript', {
            'expression': path.read_text(), 'sourceURL': str(path.relative_to(root)), 'persistScript': False,
        })
        if result.get('exceptionDetails'):
            raise RuntimeError('Browser syntax error in ' + str(path.relative_to(root)))
        print(str(path.relative_to(root)) + ': browser syntax passed')
