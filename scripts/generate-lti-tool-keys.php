<?php
// Optional operator helper for the external VideoQ service. Never prints private material.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !extension_loaded('openssl')) throw new RuntimeException('Usage: php scripts/generate-lti-tool-keys.php /private/tool-keys.json');
$parent = realpath(dirname($argv[1]));
if (!$parent || str_starts_with($parent . '/', dirname(__DIR__) . '/')) throw new RuntimeException('Use an existing private directory outside the checkout');
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$key) throw new RuntimeException('Cannot generate tool key');
$details = openssl_pkey_get_details($key)['rsa'];
$current = ['kty' => 'RSA'];
foreach (['n'=>'n','e'=>'e','d'=>'d','p'=>'p','q'=>'q','dp'=>'dmp1','dq'=>'dmq1','qi'=>'iqmp'] as $field=>$source) {
    $current[$field] = rtrim(strtr(base64_encode($details[$source]), '+/', '-_'), '=');
}
$mask = umask(0077);
try {
    $stream = fopen($argv[1], 'x');
    if (!$stream) throw new RuntimeException('Refusing to overwrite an existing key file');
    try { fwrite($stream, json_encode(['current'=>$current,'previous'=>[]], JSON_THROW_ON_ERROR)); }
    finally { fclose($stream); }
} finally { umask($mask); }
echo "Created the private tool key ring. Configure it only on the VideoQ service.\n";
