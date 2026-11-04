<?php
declare(strict_types=1);
require __DIR__.'/support/browser.php';
use VideoQLab\Browser;
$browser=new Browser();
try {
    $browser->cdp('Runtime.enable');
    foreach ([...glob(dirname(__DIR__).'/plugins/local/videoq/*.js'),...glob(__DIR__.'/support/*.js')] as $path) {
        $result=$browser->cdp('Runtime.compileScript',['expression'=>file_get_contents($path),'sourceURL'=>basename($path),'persistScript'=>false]);
        VideoQLab\ensure(!isset($result['exceptionDetails']),'Browser syntax error in '.basename($path));
        echo basename($path).": native browser syntax passed\n";
    }
} finally {$browser->close();}
