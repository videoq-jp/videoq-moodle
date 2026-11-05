<?php
/** Browser-install both submission ZIPs in the explicitly owned, empty Marketplace web fixture. */
declare(strict_types=1);
require __DIR__.'/support/browser.php';
use function VideoQLab\ensure;
ensure(VideoQLab\moodle()==='http://127.0.0.1:18881','Only the dedicated loopback web fixture is supported');
ensure((bool)getenv('LAB_PASSWORD'),'Explicit fixture password required');
ensure(is_string(getenv('LTI_LAB_DIR')) && getenv('LTI_LAB_DIR')!=='','Explicit fixture directory required');
$base=realpath(getenv('LTI_LAB_DIR'));
$archives=realpath($argv[1] ?? '');
ensure($base!==false && getenv('LTI_LAB_DIR')!==false && $archives!==false,'Explicit fixture and archive directories required');
ensure($base!==realpath(dirname(__DIR__)) && !str_starts_with($base,realpath(dirname(__DIR__)).'/'),'Fixture output must be outside the checkout');
$lock=fopen($base.'/.marketplace-web-install.lock','c');
ensure($lock!==false && flock($lock,LOCK_EX|LOCK_NB),'Another web installer check is running');
chmod($base.'/.marketplace-web-install.lock',0600);
register_shutdown_function(static function()use($lock):void{flock($lock,LOCK_UN);fclose($lock);});
foreach(['local_videoq','ltisource_videoq'] as $component) ensure(is_file($archives.'/'.$component.'.zip'),'Missing component ZIP');
$container='videoq-marketplace-web-20261106';
$identity=json_decode(VideoQLab\docker(['inspect','--format','{{json .}}',$container]),true,512,JSON_THROW_ON_ERROR);
ensure(($identity['Config']['Labels']['videoq.marketplace.audit']??'')==='web-20261106','Unrelated web container');
ensure(($identity['State']['Running']??false)===true,'Web fixture is stopped');
ensure(($identity['HostConfig']['PortBindings']['18881/tcp']??null)===[['HostIp'=>'127.0.0.1','HostPort'=>'18881']],'Web fixture must be loopback-only');
$preflight= <<<'PHP'
define('CLI_SCRIPT',true);
require '/site/config.php';
if($CFG->dbname!=='moodleaudit_web'||$CFG->wwwroot!=='http://127.0.0.1:18881'||$CFG->dbhost!=='audit-db')throw new RuntimeException('Unrelated fixture');
foreach(['local_videoq','ltisource_videoq'] as $component)if(get_config($component,'version'))throw new RuntimeException('Expected uninstalled plugins');
foreach(['/local/videoq','/mod/lti/source/videoq'] as $path)if(is_dir($CFG->dirroot.$path))throw new RuntimeException('Expected no staged plugin code');
echo 'owned-clean-fixture';
PHP;
ensure(VideoQLab\docker(['exec',$container,'php','-r',$preflight])==='owned-clean-fixture','Fixture preflight failed');
function installComponent(VideoQLab\Browser $b,string $component,string $base,string $archives): array {
 $b->go(VideoQLab\moodle().'/admin/tool/installaddon/index.php?lang=en');
 $b->click('input[name=zipfilechoose]');
 $b->wait('return document.querySelector(".fp-repo-area") !== null');
 $b->js('const e=[...document.querySelectorAll(".moodle-dialogue a.nav-link")].find(e=>e.textContent.trim()==="Upload a file");e.id="audit-upload-repository"');
 $b->click('#audit-upload-repository');
 $b->element('input[type=file]');
 $root=$b->cdp('DOM.getDocument');
 $node=$b->cdp('DOM.querySelector',['nodeId'=>$root['root']['nodeId'],'selector'=>'input[type=file]']);
 $b->cdp('DOM.setFileInputFiles',['nodeId'=>$node['nodeId'],'files'=>[$archives.'/'.$component.'.zip']]);
 $b->click('.fp-upload-btn');
 $b->wait('return document.querySelector(".filepicker-filename")?.textContent.includes(arguments[0])',30,[$component.'.zip']);
 $b->click('#id_submitbutton');
 $b->wait('return location.pathname.includes("installaddon") && document.querySelector("#id_submitbutton") === null');
 $text=$b->text('body');
 VideoQLab\ensure(str_contains($text,'Validation successful, installation can continue'),'ZIP validation did not succeed');
 VideoQLab\ensure(str_contains($text,'Validating '.$component.' ... OK'),'Component validation missing');
 $b->screenshot($base.'/web-'.$component.'-validated.png');
 $b->click('button[type=submit]');
 $b->wait('return location.pathname==="/admin/index.php"');
 $steps=[];
 for($step=0;$step<8;$step++) {
  $b->wait('return document.readyState==="complete"');
  $info=$b->js('return {path:location.pathname,title:document.title,buttons:[...document.querySelectorAll("button,input[type=submit]")].filter(e=>e.offsetParent!==null).map(e=>e.textContent.trim()||e.value),headings:[...document.querySelectorAll("h1,h2,h3")].map(e=>e.textContent.trim())}');
  VideoQLab\ensure(!str_contains(implode(' ', $info['headings']),'New settings - VideoQ'),'Optional manual setup interrupted installation');
  $steps[]=$info;
  $action=$b->js('const allowed=["Continue","Upgrade Moodle database now"];const e=[...document.querySelectorAll("button,input[type=submit]")].find(e=>e.offsetParent!==null&&allowed.includes(e.textContent.trim()||e.value));if(e){e.id="audit-next-step";return e.textContent.trim()||e.value;}return null');
  if(!$action) break;
  $b->click('#audit-next-step');
  usleep(500000);
 }
 VideoQLab\ensure(str_contains($b->js('return document.title'),'Notifications'),'Installer did not finish at site notifications');
 $b->screenshot($base.'/web-'.$component.'-installed.png');
 return ['component'=>$component,'archiveSha256'=>hash_file('sha256',$archives.'/'.$component.'.zip'),'validationPassed'=>true,'alphaWarning'=>str_contains($text,'MATURITY_ALPHA'),'manualSetupPrompted'=>false,'steps'=>$steps];
}
$b=new VideoQLab\Browser();
try {
 $b->login('auditadmin');
 $report=['passed'=>false,'method'=>'Native Chrome via PHP WebDriver; stock Moodle ZIP upload and database installer','components'=>[]];
 foreach(['local_videoq','ltisource_videoq'] as $component) {
  $report['components'][$component]=installComponent($b,$component,$base,$archives);
  file_put_contents($base.'/web-full-install-progress.json',json_encode($report,JSON_PRETTY_PRINT));
 }
 $b->go(VideoQLab\moodle().'/local/videoq/connect.php?lang=en');
 $b->wait('return document.readyState==="complete"');
 $b->screenshot($base.'/web-connect-after-install.png');
 $report['connectPage']=$b->js('return {path:location.pathname,title:document.title,headings:[...document.querySelectorAll("h1,h2,h3")].map(e=>e.textContent.trim()),labels:[...document.querySelectorAll("label")].map(e=>e.textContent.trim()),alerts:[...document.querySelectorAll(".alert-danger,.error,.notifyproblem,.debuggingmessage")].map(e=>e.textContent.trim())}');
 VideoQLab\ensure(!$report['connectPage']['alerts'],'Unexpected connection page errors');
 $postflight= <<<'PHP'
define('CLI_SCRIPT',true);
require '/site/config.php';
if($CFG->dbname!=='moodleaudit_web'||$CFG->wwwroot!=='http://127.0.0.1:18881'||$CFG->dbhost!=='audit-db')throw new RuntimeException('Unrelated fixture');
$versions=[];
foreach(['local_videoq'=>'/local/videoq','ltisource_videoq'=>'/mod/lti/source/videoq'] as $component=>$path){
 $plugin=new stdClass();require $CFG->dirroot.$path.'/version.php';
 $versions[$component]=(int)get_config($component,'version');
 if($versions[$component]!==$plugin->version)throw new RuntimeException('Version mismatch');
}
foreach(['apiurl'=>'','synctoken'=>'','typeid'=>'','lessons'=>'[]'] as $name=>$expected)if(get_config('local_videoq',$name)!==$expected)throw new RuntimeException('Optional manual defaults not initialized');
if(!preg_match('/^[a-f0-9]{64}$/D',(string)get_config('local_videoq','accesssigningkey')))throw new RuntimeException('Access key not initialized');
foreach(['local_videoq_sync','local_videoq_exports','local_videoq_assertions'] as $table)if(!$DB->get_manager()->table_exists($table))throw new RuntimeException('Missing plugin table');
echo json_encode(['database'=>$CFG->dbtype,'versions'=>$versions,'manualDefaultsInitialized'=>true,'accessKeyInitialized'=>true,'tablesCreated'=>3],JSON_THROW_ON_ERROR);
PHP;
 $report['installedRuntime']=json_decode(VideoQLab\docker(['exec',$container,'php','-r',$postflight]),true,32,JSON_THROW_ON_ERROR);
 $report['passed']=true;
 echo json_encode($report,JSON_PRETTY_PRINT)."\n";
} catch(Throwable $error) {
 $b->screenshot($base.'/web-full-install-failure.png');
 file_put_contents($base.'/web-full-install-failure.json',json_encode($b->js('return {path:location.pathname,title:document.title,headings:[...document.querySelectorAll("h1,h2,h3")].map(e=>e.textContent.trim())}'),JSON_PRETTY_PRINT));
 throw $error;
} finally {$b->close();}
