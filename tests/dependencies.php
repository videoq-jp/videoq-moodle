<?php
declare(strict_types=1);
require dirname(__DIR__).'/lab/support/runtime.php';use function VideoQLab\ensure;
$root=dirname(__DIR__);$forbidden=[];
$walk=static function(string $directory)use(&$walk,&$forbidden,$root):void{
    foreach(scandir($directory) as $name){if(in_array($name,['.','..','.git','dist'],true))continue;$path=$directory.'/'.$name;
        if(in_array($name,['node_modules','package.json','package-lock.json','yarn.lock','pnpm-lock.yaml','.nvmrc','.node-version','requirements.txt','.venv','__pycache__'],true)||preg_match('/\.(?:py|pyc|mjs|cjs|ts|tsx)$/D',$name))$forbidden[]=substr($path,strlen($root)+1);
        if(is_dir($path)&&!is_link($path))$walk($path);
        elseif(is_file($path)&&$path!==__FILE__&&preg_match('/\.(?:php|sh|yml|yaml)$/D',$name)){
            ensure(!preg_match('/[\x27\x22](?:python[0-9.]*|pip[0-9]*|node|npm|npx|yarn|pnpm)[\x27\x22]/',file_get_contents($path)),'Indirect language invocation: '.substr($path,strlen($root)+1));
        }
    }
};$walk($root);ensure(!$forbidden,'Unexpected language dependency: '.implode(', ',$forbidden));
foreach(glob($root.'/.github/workflows/*') as $file){$source=file_get_contents($file);ensure(!preg_match('/^\s*-?\s*uses:/m',$source),'Hidden runtime action');ensure(!preg_match('/\b(?:python[0-9.]*|pip[0-9]*|node|npm|npx|yarn|pnpm)(?:\s|:)/i',$source),'CI language dependency');}
$compose=file_get_contents($root.'/lab/compose.yaml');ensure(!preg_match('/image:\s*(?:python|node):|\.(?:py|mjs)\b/',$compose),'Compose runtime dependency');
$services=[];preg_match_all('/^  ([a-z][a-z0-9-]+):\s*$/m',explode("\nvolumes:",$compose)[0],$services);$actual=$services[1];sort($actual);ensure($actual===['ai','db','moodle','proxy','videoq-sync'],'Unexpected application service ownership');
echo "PHP-only repository, CI and Moodle service dependency contracts passed.\n";
