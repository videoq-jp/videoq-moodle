import { readdir, readFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
const root = fileURLToPath(new URL('../', import.meta.url));
let checked = 0;
async function check(directory) {
  for (const entry of await readdir(resolve(root, directory), { withFileTypes: true })) {
    const path = directory + '/' + entry.name;
    if (entry.isDirectory()) await check(path);
    else if (/\.(mjs|js)$/.test(path)) { execFileSync(process.execPath, ['--check', resolve(root, path)], { stdio: 'pipe' }); checked++; }
  }
}
for (const path of ['scripts', 'lab', 'plugins', 'tests']) await check(path);
for (const [component, path] of [['local_videoq', 'local/videoq'], ['ltisource_videoq', 'ltisource/videoq']]) {
  const version = await readFile(resolve(root, 'plugins', path, 'version.php'), 'utf8');
  if (!version.includes(`$plugin->component = '${component}'`)) throw new Error('Incorrect Moodle component: ' + component);
}
console.log(`Checked ${checked} JavaScript files and both Moodle component manifests.`);
