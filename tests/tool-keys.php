<?php
/** Check the operator helper with a real RSA signature, without printing keys. */
declare(strict_types=1);
$directory = sys_get_temp_dir() . '/videoq-key-test-' . bin2hex(random_bytes(12));
mkdir($directory, 0700);
function checkkey(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function generatekey(string $target): int {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/generate-lti-tool-keys.php', $target],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($process);
}
function asnlength(int $length): string {
    if ($length < 128) return chr($length);
    $bytes = '';
    while ($length) { $bytes = chr($length & 255) . $bytes; $length >>= 8; }
    return chr(128 | strlen($bytes)) . $bytes;
}
function integer(string $value): string {
    $value = ltrim($value, "\0");
    if ($value === '' || ord($value[0]) & 128) $value = "\0" . $value;
    return "\x02" . asnlength(strlen($value)) . $value;
}
try {
    $file = $directory . '/keys.json';
    checkkey(generatekey($file) === 0, 'Key generation failed');
    checkkey((fileperms($file) & 0777) === 0600, 'Private key permissions');
    $original = file_get_contents($file);
    checkkey(generatekey($file) !== 0 && file_get_contents($file) === $original, 'Existing key overwritten');
    $ring = json_decode($original, true, 16, JSON_THROW_ON_ERROR);
    checkkey($ring['previous'] === [] && $ring['current']['kty'] === 'RSA', 'Invalid key ring');
    $sequence = integer("\0");
    foreach (['n','e','d','p','q','dp','dq','qi'] as $field) {
        $encoded = $ring['current'][$field];
        checkkey((bool)preg_match('/^[A-Za-z0-9_-]+$/D', $encoded), 'Non-base64url key material');
        $sequence .= integer(base64_decode(strtr($encoded, '-_', '+/'), true));
    }
    $der = "\x30" . asnlength(strlen($sequence)) . $sequence;
    $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END RSA PRIVATE KEY-----\n";
    $private = openssl_pkey_get_private($pem);
    checkkey($private !== false, 'Malformed RSA private components');
    checkkey(openssl_pkey_get_details($private)['bits'] === 2048, 'Incorrect key strength');
    checkkey(openssl_sign('videoq-key-contract', $signature, $private, OPENSSL_ALGO_SHA256), 'Cannot sign');
    checkkey(openssl_verify('videoq-key-contract', $signature, openssl_pkey_get_details($private)['key'], OPENSSL_ALGO_SHA256) === 1, 'RSA signature failed');
    checkkey(generatekey(dirname(__DIR__) . '/unexpected-private-key.json') !== 0, 'Accepted checkout key location');
    echo "PHP tool-key generation, private permissions and real RSA signing passed.\n";
} finally {
    foreach (scandir($directory) as $name) if ($name !== '.' && $name !== '..') unlink($directory . '/' . $name);
    rmdir($directory);
}
