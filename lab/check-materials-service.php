<?php
// Disposable lab contract test. Plugin settings are changed only inside a rolled-back transaction.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new \RuntimeException('Lab only');
$fixture = json_decode((string)get_config('local_videoq_pull_spike', 'fixture'), true, 16, JSON_THROW_ON_ERROR);
if (!$fixture || !preg_match('/^[a-f0-9]{32}$/D', $fixture['run'])) throw new \RuntimeException('Isolated fixture required');
\core\session\manager::set_user(get_admin());
$baseline = [];
foreach (['servicemode','typeid','apiurl','serviceuploadtargets'] as $name) $baseline[$name] = $DB->get_field('config_plugins','value',['plugin'=>'local_videoq','name'=>$name]);
$transaction = $DB->start_delegated_transaction();
$checks = [];
$testinput = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
function contract_check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new \RuntimeException($label);
    $checks[] = $label;
}
try {
    set_config('servicemode', 'lti', 'local_videoq');
    set_config('typeid', $fixture['typeid'], 'local_videoq');
    set_config('apiurl', 'https://videoq.localhost:18443', 'local_videoq');
    set_config('serviceuploadtargets', json_encode([['origin' => 'https://storage.videoq.localhost:18443', 'prefix' => '/lti-sync/media/videos/lti-lab-owner/']]), 'local_videoq');
    $issued = \local_videoq\service_auth::issue(['grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'scope' => \local_videoq\service_auth::SCOPE, 'client_assertion' => $testinput['jwt']]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];
    contract_check((int)\local_videoq\service_auth::authenticate()->id === $fixture['typeid'], 'Tool signing key obtains scoped service access without sync API key');
    \local_videoq\synchronizer::enable($fixture['cmid']);
    $snapshot = \local_videoq\materials_service::manifest($fixture['courseid'], $fixture['typeid']);
    contract_check($snapshot['complete'] && count($snapshot['resources']) === 1 && $snapshot['resources'][0]['manifest']['enabled'], 'Manifest includes opted-in real Moodle File');
    $item = $snapshot['resources'][0];
    $DB->set_field('local_videoq_sync', 'status', 'completed', ['cmid' => $fixture['cmid']]);
    $access = \local_videoq\materials_service::access($fixture['courseid'], $fixture['typeid'], $item['manifest']['resourceLinkId'], (string)$fixture['learnerid']);
    contract_check(count($access['resources']) === 1 && $access['resources'][0]['externalId'] === (string)$fixture['cmid'], 'Learner access resolves stable material identity');
    try { \local_videoq\materials_service::destination('https://attacker.example/upload'); throw new \LogicException('Accepted arbitrary destination'); }
    catch (\RuntimeException $e) { contract_check(true, 'Unapproved upload origin rejected'); }
    $job = \local_videoq\materials_service::export($fixture['courseid'], $fixture['typeid'], [
        'externalId' => (string)$fixture['cmid'], 'generation' => $item['manifest']['generation'],
        'uploadUrl' => 'https://storage.videoq.localhost:18443/lti-sync/media/videos/lti-lab-owner/test.mp4?signature=fixture',
        'idempotencyKey' => str_repeat('a', 32)]);
    $again = \local_videoq\materials_service::export($fixture['courseid'], $fixture['typeid'], [
        'externalId' => (string)$fixture['cmid'], 'generation' => $item['manifest']['generation'],
        'uploadUrl' => 'https://storage.videoq.localhost:18443/lti-sync/media/videos/lti-lab-owner/test.mp4?signature=fixture',
        'idempotencyKey' => str_repeat('a', 32)]);
    contract_check($job['status'] === 'queued' && $again['jobId'] === $job['jobId'], 'Export queues one durable idempotent ad-hoc job');
    set_coursemodule_visible($fixture['cmid'], 0);
    $task = new \local_videoq\task\export_material(); $task->set_custom_data(['jobid' => $job['jobId']]); $task->execute();
    $receipt = \local_videoq\materials_service::status($fixture['courseid'], $fixture['typeid'], $job['jobId']);
    contract_check($receipt['status'] === 'stale', 'Changed visibility cancels queued transfer before network access');
    $stored = $DB->get_record('local_videoq_exports', ['jobid' => $job['jobId']], '*', MUST_EXIST);
    contract_check($stored->uploadurl === null, 'Terminal export clears signed destination');
} finally {
    try { $transaction->rollback(new \RuntimeException('Expected fixture rollback')); }
    catch (\RuntimeException $expected) { if ($expected->getMessage() !== 'Expected fixture rollback') throw $expected; }
    cache_helper::invalidate_by_definition('core', 'config', [], 'local_videoq');
}
foreach ($baseline as $name => $value) contract_check(get_config('local_videoq', $name) === $value, 'Restored configuration and cache: ' . $name);
echo json_encode(['passed' => true, 'checks' => $checks, 'transactionRolledBack' => true], JSON_PRETTY_PRINT) . "\n";
