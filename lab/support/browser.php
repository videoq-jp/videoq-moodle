<?php
// W3C WebDriver over HTTP. Native Chrome and ChromeDriver, no Selenium bindings.
declare(strict_types=1);
namespace VideoQLab;
require_once __DIR__.'/runtime.php';
final class Browser {
    private string $endpoint; private ?string $session=null; private $process=null;
    private const ELEMENT='element-6066-11e4-a52e-4f735466cecf';
    public function __construct(private string $init='') {
        $this->endpoint = rtrim(getenv('WEBDRIVER_URL') ?: '', '/');
        try {
            if (!$this->endpoint) {
                $socket=stream_socket_server('tcp://127.0.0.1:0',$error,$message);ensure($socket!==false,'No loopback port available');
                $address=stream_socket_get_name($socket,false);fclose($socket);$port=substr($address,strrpos($address,':')+1);
                $this->endpoint='http://127.0.0.1:'.$port;
                $this->process=proc_open([getenv('CHROMEDRIVER') ?: 'chromedriver','--port='.$port,'--allowed-ips=127.0.0.1'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
                ensure(is_resource($this->process),'Cannot start native ChromeDriver');
                wait(function(){try{return $this->send('GET','/status',null,false);}catch(\Throwable){return false;}},15);
            }
            $chrome=['args'=>['--headless=new','--no-sandbox','--disable-dev-shm-usage','--no-proxy-server','--window-size=1440,1100']];
            if (getenv('CHROME_BINARY')) $chrome['binary']=getenv('CHROME_BINARY');
            $local=str_ends_with((string)parse_url(moodle(),PHP_URL_HOST),'.localhost')&&str_ends_with((string)parse_url(videoq(),PHP_URL_HOST),'.localhost');
            $value=$this->send('POST','/session',['capabilities'=>['alwaysMatch'=>['browserName'=>'chrome','pageLoadStrategy'=>'eager','acceptInsecureCerts'=>$local,'goog:chromeOptions'=>$chrome]]],false);
            $this->session=$value['sessionId'];$this->send('POST','/timeouts',['script'=>150000,'pageLoad'=>45000,'implicit'=>0]);
            $capture=file_get_contents(__DIR__.'/capture.js');
            $capture=str_replace(['VIDEOQ_ORIGIN','MOODLE_ORIGIN'],[json_encode(videoq()),json_encode(moodle())],$capture);
            $this->cdp('Page.addScriptToEvaluateOnNewDocument',['source'=>$capture]);
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }
    private function send(string $method,string $path,?array $body=null,bool $session=true): mixed {
        $url=$this->endpoint.($session?'/session/'.$this->session:'').$path;
        $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>160,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
        if ($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body ?: new \stdClass(),JSON_THROW_ON_ERROR));
        $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        ensure($raw!==false,'Native WebDriver transport failed');
        $response=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$value=$response['value']??null;
        // Browser errors may contain tokens or DOM source: only expose the error category.
        ensure($status<400 && !(is_array($value)&&isset($value['error'])),'WebDriver: '.(is_array($value)?($value['error']??'request failed'):'request failed'));
        return $value;
    }
    public function go(string $url): void { $this->send('POST','/url',['url'=>$url]); }
    public function refresh(): void { $this->send('POST','/refresh',[]); }
    public function js(string $script,array $args=[]): mixed { return $this->send('POST','/execute/sync',['script'=>$script,'args'=>$args]); }
    public function cdp(string $cmd,array $params=[]): mixed { return $this->send('POST','/goog/cdp/execute',['cmd'=>$cmd,'params'=>$params ?: new \stdClass()]); }
    public function wait(string $script,int $seconds=30,array $args=[]): mixed { return wait(fn()=>$this->js($script,$args),$seconds); }
    public function element(string $selector): array { return wait(function()use($selector){$all=$this->send('POST','/elements',['using'=>'css selector','value'=>$selector]);return $all[0]??false;},30); }
    public function fill(string $selector,string $value): void {
        wait(function()use($selector,$value){try{$element=$this->element($selector);$id=$element[self::ELEMENT];$this->send('POST','/element/'.$id.'/clear',[]);$this->send('POST','/element/'.$id.'/value',['text'=>$value]);return true;}catch(\Throwable){return false;}},30);
    }
    public function click(string $selector): void { $element=$this->element($selector);$this->send('POST','/element/'.$element[self::ELEMENT].'/click',[]); }
    public function text(string $selector): string { return (string)$this->js('return document.querySelector(arguments[0]).textContent',[$selector]); }
    public function value(string $selector): string { return (string)$this->js('return document.querySelector(arguments[0]).value',[$selector]); }
    public function tool(callable $callback): mixed {
        $this->send('POST','/frame',['id'=>$this->element('#videoq-questions')]);
        try { return $callback($this); } finally { $this->send('POST','/frame',['id'=>null]); }
    }
    public function login(string $username='admin',?string $target=null): void {
        $target??=moodle();$this->go($target.($target===moodle()?'/login/index.php':'/login'));
        $this->fill('#username',$username);if($target===moodle())$this->element('.toggle-sensitive-wrapper #password');
        $this->fill('#password',getenv('LAB_PASSWORD') ?: 'Lti-lab-only-123!');$this->click($target===moodle()?'#loginbtn':'form button[type=submit]');
        $this->wait("return !location.pathname.includes('/login')");
    }
    public function course(int $course=5): void {
        $this->go(moodle().'/local/videoq/view.php?course='.$course.'&lang=ja');$this->ready();
        $capture=str_replace(['VIDEOQ_ORIGIN','MOODLE_ORIGIN'],[json_encode(videoq()),json_encode(moodle())],file_get_contents(__DIR__.'/capture.js'));
        $this->tool(fn($b)=>$b->js($capture.$this->init));
    }
    public function ready(): void { $this->wait("return document.querySelector('#videoq-native-controls')?.disabled===false",150); }
    public function ask(string $text): void { $this->fill('#videoq-native-question',$text);$this->click('#videoq-native-send'); }
    public function resize(int $width,int $height=1100): void { $this->send('POST','/window/rect',['width'=>$width,'height'=>$height]); }
    public function screenshot(string $file): void { file_put_contents($file,base64_decode($this->send('GET','/screenshot'))); }
    public function close(): void {
        if ($this->session) { try{$this->send('DELETE','',null);}catch(\Throwable){} $this->session=null; }
        if (is_resource($this->process)) {proc_terminate($this->process);proc_close($this->process);$this->process=null;}
    }
    public function __destruct() { $this->close(); }
}
