<?php
declare(strict_types=1);
require __DIR__.'/support/s3.php';
use VideoQLab\Storage;use function VideoQLab\{guard,data,rows,docker,container,ensure,command,report};
guard();$private=data().'/acceptance/backups';if(!is_dir($private))mkdir($private,0700,true);
$info=json_decode(docker(['exec',container(),'php','-r',"define('CLI_SCRIPT',true);require '/var/www/html/config.php';\$f=\\local_videoq\\synchronizer::video_file(82);if(!\$f)throw new Exception('Missing source');echo json_encode(['hash'=>\$f->get_contenthash(),'bytes'=>\$f->get_filesize()]);"]),true,16,JSON_THROW_ON_ERROR);
ensure((bool)preg_match('/^[a-f0-9]{40}$/D',$info['hash']),'Invalid content hash');
$archive=$private.'/moodle-filedir.tar.gz';$mask=umask(0077);$output=fopen($archive,'wb');umask($mask);chmod($archive,0600);
try{docker(['exec',container(),'tar','-czf','-','-C','/var/www/moodledata','filedir'],output:$output);}finally{fclose($output);}
$restored=$private.'/moodle-restore-'.bin2hex(random_bytes(8));mkdir($restored,0700);
// Trusted self-created filedir archive, extracted only into a new private directory.
command(['tar','-xzf',$archive,'-C',$restored,'--no-same-owner']);$count=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($restored.'/filedir',FilesystemIterator::SKIP_DOTS)) as $path){
    if(!$path->isFile()||!preg_match('/^[a-f0-9]{40}$/D',$path->getFilename()))continue;
    ensure(hash_file('sha1',$path->getPathname())===$path->getFilename(),'Restored content mismatch');$count++;
}
$hash=$info['hash'];$source=$restored.'/filedir/'.substr($hash,0,2).'/'.substr($hash,2,2).'/'.$hash;
$video=rows("SELECT v.file FROM lms_resources r JOIN videos v ON v.id=r.video_id WHERE r.installation_id='moodle-sync-lab' AND r.external_id='82'")[0];
$s3=new Storage();$copy='acceptance-restore/'.bin2hex(random_bytes(16));$download=$private.'/media-download-'.bin2hex(random_bytes(8));$check=$download.'-restored';
try{
    ensure($s3->operation('GET','media/'.$video['file'],$download)['status']===200,'Media download failed');
    ensure(hash_file('sha256',$download)===hash_file('sha256',$source)&&filesize($download)===(int)$info['bytes'],'Source/VideoQ bytes differ');
    ensure($s3->operation('PUT',$copy,$download)['status']===200,'Scratch media upload failed');
    ensure($s3->operation('GET',$copy,$check)['status']===200&&hash_file('sha256',$check)===hash_file('sha256',$download),'Media restore mismatch');
}finally{$deleted=$s3->operation('DELETE',$copy)['status'];if(is_file($download))unlink($download);if(is_file($check))unlink($check);}
ensure(in_array($deleted,[200,204],true),'Scratch media deletion failed');
report('php-media-backup',['passed'=>true,'moodleFiledirRestored'=>true,'verifiedContentFiles'=>$count,'sourceAndVideoqCopyMatch'=>true,'sha256'=>hash_file('sha256',$source),'bytes'=>(int)$info['bytes'],'disposableS3CopyRemoved'=>true]);
