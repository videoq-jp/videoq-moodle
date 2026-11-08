<?php
/** Exercise the real token endpoint with a rejected assertion and developer diagnostics. */
declare(strict_types=1);
$root = sys_get_temp_dir() . '/videoq-token-test-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
mkdir($root . '/local');
mkdir($root . '/local/videoq');
try {
    copy(dirname(__DIR__) . '/plugins/local/videoq/token.php', $root . '/local/videoq/token.php');
    file_put_contents($root . '/config.php', <<<'CONFIG'
<?php
namespace local_videoq {
    final class service_auth {
        public static function issue(array $input): array {
            throw new \RuntimeException('secret assertion details must not leak');
        }
    }
}
namespace {
    define('DEBUG_DEVELOPER', 32767);
    function debugging(string $message, int $level): bool {
        if (!defined('NO_DEBUG_DISPLAY') || !NO_DEBUG_DISPLAY) echo 'Developer diagnostics: ' . $message;
        fwrite(STDERR, "diagnostic-recorded\n");
        return true;
    }
    register_shutdown_function(static function(): void { fwrite(STDERR, 'http-status=' . http_response_code()); });
}
CONFIG);
    $program = '$_SERVER["REQUEST_METHOD"]="POST";$_SERVER["CONTENT_LENGTH"]=0;$_POST=[];require $argv[1];';
    $process = proc_open([PHP_BINARY, '-r', $program, $root . '/local/videoq/token.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start endpoint fixture');
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]); $diagnostics = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || json_decode($body, true) !== ['error' => 'invalid_client']
            || !str_contains($diagnostics, 'http-status=401') || !str_contains($diagnostics, 'diagnostic-recorded')
            || str_contains($body . $diagnostics, 'secret assertion details')) {
        throw new RuntimeException('Rejected token request must preserve JSON, status and secret-free diagnostics');
    }
    echo "Token rejection preserves OAuth JSON and 401 with developer diagnostics enabled.\n";
} finally {
    foreach (['/local/videoq/token.php', '/config.php'] as $file) if (is_file($root . $file)) unlink($root . $file);
    rmdir($root . '/local/videoq'); rmdir($root . '/local'); rmdir($root);
}
