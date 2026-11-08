<?php
/** Actual native player asset and current-material browser contracts with an owned provider substitute. */
declare(strict_types=1);
require __DIR__ . '/support/browser.php';
use VideoQLab\Browser;
use function VideoQLab\ensure;
$work = sys_get_temp_dir() . '/videoq-youtube-player-' . bin2hex(random_bytes(8));
mkdir($work, 0700);
$router = $work . '/router.php';
$support = __DIR__ . '/support';
$plugin = dirname(__DIR__) . '/plugins/local/videoq';
$routes = ['/fixture.js' => $support . '/youtube-player-fixture.js', '/player.js' => $plugin . '/player.js',
    '/checks.js' => $support . '/youtube-player-checks.js', '/styles.css' => $plugin . '/styles.css'];
$html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<link rel="stylesheet" href="/styles.css"><body><script src="/fixture.js"></script>'
    . '<script src="/player.js"></script><script defer src="/checks.js"></script>';
file_put_contents($router, '<?php $routes=' . var_export($routes, true) . '; $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'
    . 'if($path==="/"){header("Content-Type: text/html");echo ' . var_export($html, true) . ';return;}'
    . 'if(!isset($routes[$path])){http_response_code(404);return;}'
    . 'header("Content-Type: ".(str_ends_with($path,".js")?"text/javascript":(str_ends_with($path,".css")?"text/css":"text/html")));'
    . 'readfile($routes[$path]);');
$socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
ensure($socket !== false, 'No loopback fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$process = proc_open([PHP_BINARY, '-S', $address, $router], [0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
ensure(is_resource($process), 'Cannot start owned player fixture');
$browser = null;
try {
    $browser = new Browser();
    $browser->cdp('Network.enable');
    $browser->cdp('Network.setBlockedURLs', ['urls' => ['*://*.youtube.com/*', '*://youtube.com/*', '*://*.ytimg.com/*']]);
    $browser->cdp('Emulation.setDeviceMetricsOverride', ['width' => 390, 'height' => 844, 'deviceScaleFactor' => 1, 'mobile' => false]);
    $browser->go('http://' . $address);
    $result = $browser->wait('return window.videoqPlayerResult || null', 30);
    ensure(($result['passed'] ?? false) === true, 'Player contract failed: ' . json_encode($result));
    echo json_encode(['passed' => true, 'checks' => $result['checks'], 'limits' => 'Provider SDK and File media are substitutes; no real YouTube, Moodle login or AI processing.'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    $browser?->close();
    proc_terminate($process); proc_close($process);
    unlink($router); rmdir($work);
}
