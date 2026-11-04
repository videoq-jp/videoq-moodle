<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lab/support/runtime.php';
use function VideoQLab\ensure;
$directory = sys_get_temp_dir() . '/videoq-live-lock-' . bin2hex(random_bytes(8)); mkdir($directory,0700);
$previous = getenv('LTI_LAB_DIR'); putenv('LTI_LAB_DIR=' . $directory);
$source = 'require ' . var_export(dirname(__DIR__) . '/lab/support/runtime.php',true) . ';VideoQLab\\guard();';
$holder = proc_open([PHP_BINARY,'-r',$source.'echo "locked\\n";fgets(STDIN);'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
try {
    ensure(trim(fgets($pipes[1]))==='locked','First check did not acquire lock');
    $blocked = proc_open([PHP_BINARY,'-r',$source],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$ignored);
    ensure(proc_close($blocked)!==0,'Concurrent mutation check was permitted');
    $read = proc_open([PHP_BINARY,'-r','require '.var_export(dirname(__DIR__).'/lab/support/runtime.php',true).';VideoQLab\\guard(false);'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$ignored);
    ensure(proc_close($read)===0,'Read-only lab validation should remain available');
} finally {
    fwrite($pipes[0],"finish\n");fclose($pipes[0]);fclose($pipes[1]);proc_close($holder);
}
try {
    $next = proc_open([PHP_BINARY,'-r',$source],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$ignored);
    ensure(proc_close($next)===0,'Lock was not released when check exited');
    echo "PHP live-check exclusion and lock cleanup passed.\n";
} finally {
    unlink($directory.'/acceptance/.live-check.lock');rmdir($directory.'/acceptance');rmdir($directory);
    if($previous===false)putenv('LTI_LAB_DIR');else putenv('LTI_LAB_DIR='.$previous);
}
