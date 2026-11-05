<?php
// Optional Mozilla binaries for W3C WebDriver checks; PHP and native tar only.
declare(strict_types=1);
require dirname(__DIR__) . '/lab/support/runtime.php';
use function VideoQLab\{ensure, command};
$directory = $argv[1] ?? throw new InvalidArgumentException('Provide a private output directory');
if (!is_dir($directory)) mkdir($directory, 0700, true);
$directory = realpath($directory);
function firefox_download(string $url, ?string $file = null): string {
    $curl = curl_init($url); $output = $file ? fopen($file, 'wb') : null;
    curl_setopt_array($curl, [CURLOPT_TIMEOUT => 240, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'videoq-moodle-native-firefox', CURLOPT_RETURNTRANSFER => !$file]);
    if ($output) curl_setopt($curl, CURLOPT_FILE, $output);
    try { $result = curl_exec($curl); ensure($result !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200, 'Mozilla binary download failed'); return is_string($result) ? $result : ''; }
    finally { curl_close($curl); if ($output) fclose($output); }
}
$versions = json_decode(firefox_download('https://product-details.mozilla.org/1.0/firefox_versions.json'), true, 32, JSON_THROW_ON_ERROR);
$version = $versions['LATEST_FIREFOX_VERSION']; ensure((bool)preg_match('/^[0-9.]+$/D', $version), 'Unexpected Firefox version');
$driver = json_decode(firefox_download('https://api.github.com/repos/mozilla/geckodriver/releases/latest'), true, 64, JSON_THROW_ON_ERROR);
$assets = array_values(array_filter($driver['assets'], fn($asset) => str_ends_with($asset['name'], 'linux64.tar.gz')));
ensure(count($assets) === 1 && str_starts_with($assets[0]['browser_download_url'], 'https://github.com/mozilla/geckodriver/releases/download/'), 'Unexpected GeckoDriver asset');
foreach ([['https://archive.mozilla.org/pub/firefox/releases/' . $version . '/linux-x86_64/en-US/firefox-' . $version . '.tar.xz', 'firefox.tar.xz'],
    [$assets[0]['browser_download_url'], 'geckodriver.tar.gz']] as [$url, $name]) {
    $path = $directory . '/' . $name; firefox_download($url, $path);
    $entries = command(['tar', '-tf', $path]);
    foreach (explode("\n", $entries) as $entry) ensure(!str_starts_with($entry, '/') && !preg_match('~(?:^|/)\.\.(?:/|$)~', $entry), 'Unsafe Mozilla archive path');
    command(['tar', '-xf', $path, '-C', $directory]); unlink($path);
}
ensure(is_executable($directory . '/firefox/firefox') && is_executable($directory . '/geckodriver'), 'Mozilla executables missing');
echo 'FIREFOX_BINARY=' . $directory . "/firefox/firefox\nGECKODRIVER=" . $directory . "/geckodriver\n";
