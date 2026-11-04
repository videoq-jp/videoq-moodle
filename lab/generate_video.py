"""Generate an original silent lesson and two test-tone fixtures with FFmpeg."""
import argparse
import json
import os
from pathlib import Path
import subprocess

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path, nargs='?', default=Path('/tmp/videoq-lti-lab'))
    args = parser.parse_args()
    directory = args.directory.resolve()
    directory.mkdir(parents=True, exist_ok=True)
    font = Path(os.environ.get('LTI_LAB_JAPANESE_FONT', '/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf'))
    if not font.is_file():
        raise RuntimeError('Set LTI_LAB_JAPANESE_FONT to a Japanese font file')
    # FFmpeg filter language has its own escaping; restrict these fixture paths.
    if any(character in str(directory) + str(font) for character in "':,;[]\\"):
        raise ValueError('Use simple fixture and font paths')
    scenes = json.loads(Path(__file__).with_name('lesson-scenes.json').read_text())
    title = directory / 'video-title.txt'
    title.write_text('動画を見ながら、教材に質問')
    filters = [f'drawtext=fontfile={font}:textfile={title}:fontcolor=0x93c5fd:fontsize=32:x=48:y=45']
    for index, scene in enumerate(scenes):
        path = directory / f'video-slide-{index}.txt'
        path.write_text(scene['slide'])
        filters.append(f"drawtext=fontfile={font}:textfile={path}:fontcolor=white:fontsize=25:line_spacing=18:x=48:y=180:enable='between(t,{index*8},{index*8+7.999})'")
    def ffmpeg(*arguments):
        subprocess.run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', *map(str, arguments)], check=True)
    ffmpeg('-f','lavfi','-i','color=c=0x0f172a:s=960x540:r=24:d=24','-vf',','.join(filters),
           '-c:v','libx264','-pix_fmt','yuv420p','-movflags','+faststart',directory / 'lesson.mp4')
    for name, frequency in [('sync-lesson.mp4', 440), ('sync-replacement.mp4', 660)]:
        ffmpeg('-i',directory / 'lesson.mp4','-f','lavfi','-i',f'sine=frequency={frequency}:sample_rate=16000:duration=24',
               '-c:v','copy','-c:a','aac','-shortest','-movflags','+faststart',directory / name)
    print('Generated silent lesson and two test-tone videos; these are not spoken inference fixtures.')

if __name__ == '__main__':
    main()
