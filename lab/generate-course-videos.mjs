import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { courseLessons } from './course-scenes.mjs';
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const font = '/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf';
for (const lesson of courseLessons) {
  const title = join(dir, lesson.lesson + '-title.txt'); writeFileSync(title, lesson.title);
  const filters = [`drawtext=fontfile=${font}:textfile=${title}:fontcolor=0x93c5fd:fontsize=32:x=48:y=45`];
  for (const [index, scene] of lesson.scenes.entries()) {
    const path = join(dir, lesson.lesson + '-' + index + '.txt');
    writeFileSync(path, scene.text.match(/.{1,28}/gu).join('\n'));
    const seconds = time => time.split(':').reduce((total, part) => total * 60 + Number(part), 0);
    filters.push(`drawtext=fontfile=${font}:textfile=${path}:fontcolor=white:fontsize=25:line_spacing=18:x=48:y=180:enable='between(t,${seconds(scene.start)},${seconds(scene.end) - .001})'`);
  }
  execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=0x0f172a:s=960x540:r=24:d=24', '-vf', filters.join(','), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', join(dir, lesson.lesson + '.mp4')]);
}
console.log('Generated two original course video fixtures.');
