<?php
// Run as the web user. The negative case must fail against the lab's private CA.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new \RuntimeException('Lab only');
require_once($CFG->libdir . '/filelib.php');
$url = 'https://videoq.localhost:18443/api/lti/jwks';
$untrusted = new curl();
$untrusted->get($url, [], \local_videoq\client::http_options(10) + ['CURLOPT_CAINFO'=>'/etc/ssl/certs/ca-certificates.crt']);
if ($untrusted->get_errno() !== 60) throw new \RuntimeException('Untrusted certificates must be rejected');
$trusted = new curl();
$body = $trusted->get($url, [], \local_videoq\client::http_options(10));
if ($trusted->get_errno() || (int)($trusted->get_info()['http_code'] ?? 0) !== 200 || !count(json_decode($body,true)['keys'] ?? [])) throw new \RuntimeException('The web user must verify the configured CA and fetch public keys');
echo json_encode(['passed'=>true,'untrustedCertificateRejected'=>true,'verifiedPublicKeyFetch'=>true,'executedAsWebUser'=>true]);
