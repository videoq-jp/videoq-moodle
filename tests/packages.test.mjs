import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { unzipSync, strFromU8 } from 'fflate';
const root = fileURLToPath(new URL('../', import.meta.url));
test('builds both Moodle installers without a VideoQ checkout or credentials', async () => {
  const output = await mkdtemp(join(tmpdir(), 'videoq-moodle-packages-'));
  try {
    // Running outside either checkout also verifies the build has no cwd dependency.
    execFileSync(process.execPath, [join(root, 'scripts/package-plugins.mjs'), output], { cwd: tmpdir(), stdio: 'pipe' });
    for (const name of ['local_videoq', 'ltisource_videoq']) {
      const files = unzipSync(await readFile(join(output, name + '.zip')));
      assert(Object.keys(files).every(path => path.startsWith('videoq/') && !path.includes('../')));
      assert.match(strFromU8(files['videoq/version.php']), new RegExp(`component = '${name}'`));
      assert(files[`videoq/lang/en/${name}.php`]);
      assert.match(strFromU8(files['videoq/LICENSE']), /GNU GENERAL PUBLIC LICENSE/);
      assert(!Object.keys(files).some(path => /(?:node_modules|api\.env|compose\.env|\.mp4|\.pem|credential)/.test(path)));
      if (name === 'local_videoq') {
        assert(files['videoq/connect.php']); assert(files['videoq/classes/connection_setup.php']);
        assert(files['videoq/templates/chat.mustache']); assert(files['videoq/player.js']);
      }
    }
  } finally { await rm(output, { recursive: true, force: true }); }
});
