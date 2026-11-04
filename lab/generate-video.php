<?php
// Original lesson/test-tone/spoken fixtures through native FFmpeg and optional eSpeak.
declare(strict_types=1);
require __DIR__.'/support/runtime.php';use function VideoQLab\{command,ensure};
$directory=$argv[1]??VideoQLab\data();if(!is_dir($directory))mkdir($directory,0700,true);$directory=realpath($directory);
$font=getenv('LTI_LAB_JAPANESE_FONT')?:'/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf';
ensure(is_file($font),'Configure a Japanese font');ensure(!preg_match('/[\x27:,;\[\]\\\\]/',$font.$directory),'Use simple fixture paths');
$scenes=json_decode(file_get_contents(__DIR__.'/lesson-scenes.json'),true,16,JSON_THROW_ON_ERROR);file_put_contents($directory.'/video-title.txt','動画を見ながら、教材に質問');
$filters=['drawtext=fontfile='.$font.':textfile='.$directory.'/video-title.txt:fontcolor=0x93c5fd:fontsize=32:x=48:y=45'];
foreach($scenes as $i=>$scene){$path=$directory.'/video-slide-'.$i.'.txt';file_put_contents($path,$scene['slide']);$filters[]="drawtext=fontfile=$font:textfile=$path:fontcolor=white:fontsize=25:line_spacing=18:x=48:y=180:enable='between(t,".($i*8).','.($i*8+7.999).")'";}
$ffmpeg=static fn(array $args)=>command(['ffmpeg','-hide_banner','-loglevel','error','-y',...$args]);
$ffmpeg(['-f','lavfi','-i','color=c=0x0f172a:s=960x540:r=24:d=24','-vf',implode(',',$filters),'-c:v','libx264','-pix_fmt','yuv420p','-movflags','+faststart',$directory.'/lesson.mp4']);
foreach(['sync-lesson.mp4'=>440,'sync-replacement.mp4'=>660] as $file=>$frequency)$ffmpeg(['-i',$directory.'/lesson.mp4','-f','lavfi','-i','sine=frequency='.$frequency.':sample_rate=16000:duration=24','-c:v','copy','-c:a','aac','-shortest','-movflags','+faststart',$directory.'/'.$file]);
if(in_array('--spoken',$argv,true)){
    if(!is_dir($directory.'/acceptance'))mkdir($directory.'/acceptance',0700);
    command(['espeak','-s','145','-w',$directory.'/spoken.wav','This is an original test lesson. A learning management system distributes course videos and manages enrollment. VideoQ answers questions about the course and finds relevant scenes. LTI connects the two systems. Learners watch the video in Moodle and ask the AI assistant questions.']);
    $ffmpeg(['-i',$directory.'/lesson.mp4','-i',$directory.'/spoken.wav','-c:v','copy','-c:a','aac','-shortest','-movflags','+faststart',$directory.'/acceptance/spoken-lesson.mp4']);
}
echo "PHP generated original lesson, tone fixtures and requested spoken audio.\n";
