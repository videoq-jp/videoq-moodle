// Generate an original, silent lab lesson whose text matches the retrieval fixture.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { lessonScenes } from './lesson-scenes.mjs';
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
mkdirSync(dir, { recursive: true });
const font = process.env.LTI_LAB_JAPANESE_FONT || '/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf';
if (!existsSync(font)) throw new Error('Set LTI_LAB_JAPANESE_FONT to a Japanese font file');
const slides = lessonScenes.map(scene => scene.slide);
writeFileSync(join(dir, 'video-title.txt'), '動画を見ながら、教材に質問');
const filters = [
  `drawtext=fontfile=${font}:textfile=${join(dir, 'video-title.txt')}:fontcolor=0x93c5fd:fontsize=32:x=48:y=45`,
  ...slides.map((text, i) => {
    const path = join(dir, `video-slide-${i}.txt`);
    writeFileSync(path, text);
    return `drawtext=fontfile=${font}:textfile=${path}:fontcolor=white:fontsize=25:line_spacing=18:x=48:y=180:enable='between(t,${i * 8},${i * 8 + 7.999})'`;
  }),
];
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=0x0f172a:s=960x540:r=24:d=24', '-vf', filters.join(','), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', join(dir, 'lesson.mp4')], { stdio: 'inherit' });
console.log('Generated original 24-second lesson.mp4 (silent)');
// Audio extraction runs normally; the optional sync lab's Whisper provider is a
// deterministic fixture. The tone is explicitly test audio, not a spoken lesson.
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', join(dir, 'lesson.mp4'),
  '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=16000:duration=24', '-c:v', 'copy',
  '-c:a', 'aac', '-shortest', '-movflags', '+faststart', join(dir, 'sync-lesson.mp4')], { stdio: 'inherit' });
console.log('Generated sync-lesson.mp4 with deterministic test-tone audio');
execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', join(dir, 'lesson.mp4'),
  '-f', 'lavfi', '-i', 'sine=frequency=660:sample_rate=16000:duration=24', '-c:v', 'copy',
  '-c:a', 'aac', '-shortest', '-movflags', '+faststart', join(dir, 'sync-replacement.mp4')], { stdio: 'inherit' });
