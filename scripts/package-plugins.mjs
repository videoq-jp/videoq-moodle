// Build the two standard Moodle plugin ZIPs without bundling credentials or fixtures.
import { readdir, readFile, mkdir, writeFile } from 'node:fs/promises';
import { resolve, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { zipSync } from 'fflate';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const destination = process.argv[2];
if (!destination) throw new Error('Usage: node scripts/package-plugins.mjs OUTPUT_DIRECTORY');
await mkdir(destination, { recursive: true });
for (const [name, source] of [['local_videoq', 'plugins/local/videoq'], ['ltisource_videoq', 'plugins/ltisource/videoq']]) {
  const base = resolve(root, source), entries = {};
  async function add(directory) {
    for (const item of await readdir(directory, { withFileTypes: true })) {
      if (item.name.startsWith('.')) continue;
      const path = resolve(directory, item.name);
      if (item.isDirectory()) await add(path);
      else if (item.isFile()) entries['videoq/' + relative(base, path).replaceAll('\\', '/')] = await readFile(path);
    }
  }
  await add(base);
  await writeFile(resolve(destination, name + '.zip'), zipSync(entries));
  console.log(name + '.zip');
}
