<?php
// Download a matching native Chrome/ChromeDriver pair for optional CI browser checks.
declare(strict_types=1);
require dirname(__DIR__).'/lab/support/runtime.php';use function VideoQLab\ensure;
$directory=$argv[1]??throw new InvalidArgumentException('Provide a private output directory');
if(!is_dir($directory))mkdir($directory,0700,true);$directory=realpath($directory);ensure(!is_link($directory),'Use a real output directory');
$metadata=file_get_contents('https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json');ensure($metadata!==false&&strlen($metadata)<1048576,'Cannot read native browser manifest');
$stable=json_decode($metadata,true,32,JSON_THROW_ON_ERROR)['channels']['Stable'];$paths=[];
foreach(['chrome','chromedriver'] as $kind){
    $entries=array_values(array_filter($stable['downloads'][$kind],static fn($entry)=>$entry['platform']==='linux64'));ensure(count($entries)===1,'Missing native Linux binary');$url=$entries[0]['url'];
    ensure(str_starts_with($url,'https://storage.googleapis.com/chrome-for-testing-public/'),'Unexpected native binary origin');
    $zip=$directory.'/'.$kind.'.zip';$output=fopen($zip,'wb');$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_FILE=>$output,CURLOPT_TIMEOUT=>180,CURLOPT_FOLLOWLOCATION=>false]);
    try{ensure(curl_exec($curl)!==false&&curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===200,'Native binary download failed');}finally{curl_close($curl);fclose($output);}
    $archive=new ZipArchive();ensure($archive->open($zip)===true,'Invalid native binary archive');
    for($index=0;$index<$archive->numFiles;$index++){ $name=$archive->getNameIndex($index);ensure(!str_contains($name,'..')&&!str_starts_with($name,'/'),'Unsafe browser archive path');}
    ensure($archive->extractTo($directory),'Cannot extract native browser');$archive->close();unlink($zip);
    $path=$directory.'/'.$kind.'-linux64/'.$kind;ensure(is_file($path),'Native executable missing');chmod($path,0755);
    if($kind==='chrome')foreach(['chrome_crashpad_handler','chrome_sandbox'] as $file)if(is_file(dirname($path).'/'.$file))chmod(dirname($path).'/'.$file,0755);
    $paths[$kind==='chrome'?'CHROME_BINARY':'CHROMEDRIVER']=$path;
}
foreach($paths as $name=>$path)echo $name.'='.$path."\n";
