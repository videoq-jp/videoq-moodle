<?php
/** Browser acceptance for native assets rendered with pinned Moodle core templates. */
declare(strict_types=1);
require __DIR__ . '/support/browser.php';
use VideoQLab\Browser;
use function VideoQLab\ensure;
if (empty($argv[1]) || !is_dir($argv[1])) throw new InvalidArgumentException('Pass the pinned Moodle source checkout');
putenv('MOODLE_SOURCE=' . realpath($argv[1]));
$work = sys_get_temp_dir() . '/videoq-native-learning-' . bin2hex(random_bytes(8));
mkdir($work, 0700);
$support = __DIR__ . '/support';
$plugin = dirname(__DIR__) . '/plugins/local/videoq';
$routes = ['/fixture.js' => $support . '/native-learning-fixture.js', '/checks.js' => $support . '/native-learning-checks.js',
    '/course-history.js' => $plugin . '/course-history.js', '/layout.js' => $plugin . '/layout.js', '/learning.js' => $plugin . '/learning.js', '/player.js' => $plugin . '/player.js', '/styles.css' => $plugin . '/styles.css'];
$router = $work . '/router.php';
file_put_contents($router, '<?php $routes=' . var_export($routes, true) . ';$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);'
    . 'if($path==="/"){require ' . var_export($support . '/native-learning-render.php', true) . ';return;}'
    . 'if(!isset($routes[$path])){http_response_code(404);return;}'
    . 'header("Content-Type: ".(str_ends_with($path,".js")?"text/javascript":"text/css"));readfile($routes[$path]);');
$socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
ensure($socket !== false, 'No owned loopback port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$process = proc_open([PHP_BINARY, '-S', $address, $router], [0 => ['file', '/dev/null', 'r'],
    1 => ['file', $work . '/server.log', 'a'], 2 => ['file', $work . '/server.log', 'a']], $pipes);
ensure(is_resource($process), 'Cannot start isolated native fixture');
$browser = null;
try {
    $browser = new Browser();
    $browser->cdp('Emulation.setDeviceMetricsOverride', ['width' => 390, 'height' => 1200, 'deviceScaleFactor' => 1, 'mobile' => false]);
    $results = [];
    foreach (['file' => '/', 'empty' => '/?empty=1'] as $mode => $path) {
        $browser->go('http://' . $address . $path);
        $result = $browser->wait('return window.nativeLearningResult || null', 45);
        ensure(($result['passed'] ?? false) === true, 'Native ' . $mode . ' UI failed: ' . json_encode($result));
        $results[$mode] = $result;
        if ($proof = getenv('NATIVE_PROOF_DIR')) $browser->screenshot($proof . '/native-' . $mode . '.png');
    }
    echo json_encode(['passed' => true, 'results' => $results,
        'limits' => 'Real plugin/Moodle templates and browser assets; Moodle session, upstream API, media and model are controlled substitutes. Installed PHP services and actual AI require separate acceptance.'], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    if ($proof = getenv('NATIVE_PROOF_DIR')) copy($work . '/server.log', $proof . '/native-server.log');
    throw $error;
} finally {
    $browser?->close(); proc_terminate($process); proc_close($process);
    unlink($router); unlink($work . '/server.log'); rmdir($work);
}
