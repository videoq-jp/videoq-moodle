<?php
// Public certificates only. Never copy Caddy's private signing key or weaken TLS verification.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->dbhost !== 'db' || !(
        ($CFG->wwwroot === 'https://moodle.localhost:18443' && $CFG->dbname === 'moodle') ||
        ($CFG->wwwroot === 'https://restore.moodle.localhost:18444' && $CFG->dbname === 'moodle_restore_check')
    )) throw new \RuntimeException('Isolated lab only');
$source = '/certificates/caddy/pki/authorities/local/root.crt';
$system = '/etc/ssl/certs/ca-certificates.crt';
if (!is_readable($source) || !is_readable($system)) throw new \RuntimeException('Lab CA is not ready');
$local = file_get_contents($source);
if (!openssl_x509_parse($local) || str_contains($local, 'PRIVATE KEY')) throw new \RuntimeException('Invalid public CA certificate');
$bundle = file_get_contents($system) . "\n" . $local;
$target = $CFG->dataroot . '/moodleorgca.crt';
if (!is_file($target) || hash_file('sha256', $target) !== hash('sha256', $bundle)) {
    if (file_put_contents($target, $bundle, LOCK_EX) !== strlen($bundle)) throw new \RuntimeException('Unable to install public trust bundle');
    chmod($target, 0644);
    echo "Configured the lab's public CA trust bundle.\n";
}
