<?php
// Disposable lab contract test. Plugin settings are changed only inside a rolled-back transaction.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new \RuntimeException('Lab only');
$fixture = json_decode((string)get_config('local_videoq_materials_acceptance', 'fixture'), true, 16, JSON_THROW_ON_ERROR);
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
    $typesbefore = $DB->count_records('lti_types');
    $codepayload = base64_encode(json_encode(['origin' => 'https://videoq.localhost:18443', 'secret' => str_repeat('0', 64)]));
    $rejected = false;
    try { \local_videoq\connection_setup::connect('VIDEOQ1.' . $codepayload); }
    catch (\Throwable $error) { $rejected = true; }
    contract_check($rejected && $DB->count_records('lti_types') === $typesbefore &&
        (int)get_config('local_videoq', 'typeid') === $fixture['typeid'] && !get_config('local_videoq', 'pendingconnection'),
        'Legacy code cannot replace the configured materials connection');
    $DB->set_field('config_plugins', 'name', 'acceptance_version', ['plugin' => 'ltisource_videoq', 'name' => 'version']);
    try {
        $rejected = false;
        try { \local_videoq\lti_connection_setup::connect('VIDEOQ2.' . $codepayload); }
        catch (\Throwable $error) { $rejected = true; }
        contract_check($rejected && $DB->count_records('lti_types') === $typesbefore && !get_config('local_videoq', 'pendinglticonnection'),
            'Missing launch plugin is rejected before creating or changing a connection');
    } finally {
        $DB->set_field('config_plugins', 'name', 'version', ['plugin' => 'ltisource_videoq', 'name' => 'acceptance_version']);
    }
    \local_videoq\synchronizer::enable($fixture['cmid']);
    $rejected = false;
    try { \local_videoq\connection_setup::cancel_pending(); } catch (\Throwable $error) { $rejected = true; }
    contract_check($rejected && $DB->record_exists('lti_types', ['id' => $fixture['typeid']]), 'Cancel cannot remove a configured connection');
    $savedconnection = [];
    foreach (['typeid', 'servicemode', 'apiurl', 'synctoken'] as $name) {
        $savedconnection[$name] = get_config('local_videoq', $name);
        unset_config($name, 'local_videoq');
    }
    try {
        set_config('pendinglticonnection', json_encode(['origin' => 'https://videoq.localhost:18443', 'typeid' => $fixture['typeid']]), 'local_videoq');
        $rejected = false;
        try { \local_videoq\connection_setup::cancel_pending(); } catch (\Throwable $error) { $rejected = true; }
        contract_check($rejected && $DB->record_exists('lti_types', ['id' => $fixture['typeid']]), 'Cancel cannot remove a tool with course activities');
        $unusedtype = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
            'lti_typename' => 'Unused VideoQ setup acceptance', 'lti_toolurl' => 'https://videoq.localhost:18443/api/lti/launch',
            'lti_ltiversion' => LTI_VERSION_1P3, 'lti_initiatelogin' => 'https://videoq.localhost:18443/api/lti/login',
            'lti_redirectionuris' => 'https://videoq.localhost:18443/api/lti/launch']);
        set_config('pendinglticonnection', json_encode(['origin' => 'https://videoq.localhost:18443', 'typeid' => (int)$unusedtype]), 'local_videoq');
        \local_videoq\connection_setup::cancel_pending();
        contract_check(!$DB->record_exists('lti_types', ['id' => $unusedtype]) && !get_config('local_videoq', 'pendinglticonnection'), 'An unused failed materials setup can be cancelled');
    } finally {
        unset_config('pendinglticonnection', 'local_videoq');
        foreach ($savedconnection as $name => $value) {
            if ($value === false) unset_config($name, 'local_videoq');
            else set_config($name, $value, 'local_videoq');
        }
    }
    $issued = \local_videoq\service_auth::issue(['grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'scope' => \local_videoq\service_auth::SCOPE, 'client_assertion' => $testinput['jwt']]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['access_token'];
    contract_check((int)\local_videoq\service_auth::authenticate()->id === $fixture['typeid'], 'Tool signing key obtains scoped service access without sync API key');
    foreach (['replay' => ['jwt' => $testinput['jwt'], 'scope' => \local_videoq\service_auth::SCOPE],
            'wrong audience' => ['jwt' => $testinput['wrongAudience'], 'scope' => \local_videoq\service_auth::SCOPE],
            'ungranted scope' => ['jwt' => $testinput['jwt'], 'scope' => 'https://attacker.example/scope']] as $label => $negative) {
        $rejected = false;
        // Moodle's PostgreSQL driver recovers failed statements using its own
        // savepoint; an additional raw savepoint is discarded by that recovery.
        try {
            \local_videoq\service_auth::issue(['grant_type' => 'client_credentials',
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'scope' => $negative['scope'], 'client_assertion' => $negative['jwt']]);
        } catch (\Throwable $error) { $rejected = true; }
        contract_check($rejected, 'Rejects ' . $label);
    }
    \local_videoq\synchronizer::enable($fixture['cmid']);
    $snapshot = \local_videoq\materials_service::manifest($fixture['courseid'], $fixture['typeid']);
    contract_check($snapshot['complete'] && count($snapshot['resources']) === 1 && $snapshot['resources'][0]['manifest']['enabled'], 'Manifest includes opted-in real Moodle File');
    $item = $snapshot['resources'][0];
    $foreign = clone $DB->get_record('local_videoq_sync', ['cmid' => $fixture['cmid']], '*', MUST_EXIST);
    unset($foreign->id);
    $foreign->cmid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {course_modules}') + 1000000;
    $foreign->typeid = $fixture['typeid'] + 1000000;
    $foreign->status = 'completed';
    $foreign->id = $DB->insert_record('local_videoq_sync', $foreign);
    $foreignbefore = $DB->get_record('local_videoq_sync', ['id' => $foreign->id], '*', MUST_EXIST);
    \local_videoq\materials_service::acknowledge($fixture['courseid'], $fixture['typeid'], [
        'externalId' => (string)$foreign->cmid, 'generation' => (int)$foreign->generation, 'status' => 'disabled']);
    contract_check($DB->get_record('local_videoq_sync', ['id' => $foreign->id]) == $foreignbefore,
        'Status acknowledgements cannot modify another tool registration');
    $rejected = false;
    try {
        \local_videoq\materials_service::export($fixture['courseid'], $fixture['typeid'], [
            'externalId' => (string)$foreign->cmid, 'generation' => (int)$foreign->generation,
            'uploadUrl' => 'https://storage.videoq.localhost:18443/lti-sync/media/videos/lti-lab-owner/test.mp4?signature=fixture',
            'idempotencyKey' => str_repeat('b', 32)]);
    } catch (\Throwable $error) { $rejected = true; }
    contract_check($rejected && $DB->get_record('local_videoq_sync', ['id' => $foreign->id]) == $foreignbefore,
        'Export cannot read or change another tool registration');
    $DB->delete_records('local_videoq_sync', ['id' => $foreign->id]);
    $DB->set_field('local_videoq_sync', 'status', 'completed', ['cmid' => $fixture['cmid']]);
    $legacylessons = get_config('local_videoq', 'lessons');
    try {
        set_config('lessons', 'malformed legacy association fixture', 'local_videoq');
        $access = \local_videoq\materials_service::access($fixture['courseid'], $fixture['typeid'], $item['manifest']['resourceLinkId'], (string)$fixture['learnerid']);
        contract_check(count($access['resources']) === 1, 'Migrated service ignores legacy manual associations');
    } finally {
        if ($legacylessons === false) unset_config('lessons', 'local_videoq');
        else set_config('lessons', $legacylessons, 'local_videoq');
    }
    contract_check(count($access['resources']) === 1 && $access['resources'][0]['externalId'] === (string)$fixture['cmid'], 'Learner access resolves stable material identity');
    $proof = \local_videoq\course_access::launch((int)$item['manifest']['resourceLinkId'], $fixture['learnerid'])['custom_videoq_access'];
    $resolve = static fn($token, $subject) => \local_videoq\course_access::resolve($token, (string)$subject, (string)$fixture['courseid'], $item['manifest']['resourceLinkId']);
    foreach (['tampered' => [$proof . 'f', $fixture['learnerid']], 'wrong learner' => [$proof, get_admin()->id]] as $label => [$token, $subject]) {
        $denied = false;
        try { $resolve($token, $subject); } catch (\Throwable $error) { $denied = true; }
        contract_check($denied, 'Access proof rejects ' . $label);
    }
    $payload = json_decode(hex2bin(explode('.', $proof)[0]), true, 8, JSON_THROW_ON_ERROR);
    $payload['exp'] = time() - 1;
    $encoded = bin2hex(json_encode($payload, JSON_THROW_ON_ERROR));
    $expired = $encoded . '.' . hash_hmac('sha256', $encoded, hex2bin(get_config('local_videoq', 'accesssigningkey')));
    $denied = false;
    try { $resolve($expired, $fixture['learnerid']); } catch (\Throwable $error) { $denied = true; }
    contract_check($denied, 'Expired access proof is rejected');
    $DB->set_field('user', 'suspended', 1, ['id' => $fixture['learnerid']]);
    try {
        $denied = false;
        try { $resolve($proof, $fixture['learnerid']); } catch (\Throwable $error) { $denied = true; }
        contract_check($denied, 'An already issued proof cannot bypass account suspension');
    } finally { $DB->set_field('user', 'suspended', 0, ['id' => $fixture['learnerid']]); }
    $availability = $DB->get_field('course_modules', 'availability', ['id' => $fixture['cmid']]);
    try {
        $DB->set_field('course_modules', 'availability', json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + 3600]], 'showc' => [true]]), ['id' => $fixture['cmid']]);
        rebuild_course_cache($fixture['courseid'], true);
        $denied = false;
        try { $resolve($proof, $fixture['learnerid']); } catch (\Throwable $error) { $denied = true; }
        contract_check($denied, 'An already issued proof respects updated conditional availability');
    } finally {
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $fixture['cmid']]);
        rebuild_course_cache($fixture['courseid'], true);
    }
    $outsider = $DB->get_field('user', 'id', ['username' => 'teacher'], MUST_EXIST);
    $denied = false;
    try { \local_videoq\materials_service::access($fixture['courseid'], $fixture['typeid'], $item['manifest']['resourceLinkId'], (string)$outsider); }
    catch (\Throwable $error) { $denied = true; }
    contract_check($denied, 'Unenrolled account denied');
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
    $denied = false;
    try { \local_videoq\materials_service::access($fixture['courseid'], $fixture['typeid'], $item['manifest']['resourceLinkId'], (string)$fixture['learnerid']); }
    catch (\Throwable $error) { $denied = true; }
    contract_check($denied, 'Hidden source cannot be used by learner');
    $task = new \local_videoq\task\export_material(); $task->set_custom_data(['jobid' => $job['jobId']]); $task->execute();
    $receipt = \local_videoq\materials_service::status($fixture['courseid'], $fixture['typeid'], $job['jobId']);
    contract_check($receipt['status'] === 'failed' && (int)$receipt['bytes'] === 0, 'Retired import cancels queued transfer before network access');
    $stored = $DB->get_record('local_videoq_exports', ['jobid' => $job['jobId']], '*', MUST_EXIST);
    contract_check($stored->uploadurl === null, 'Terminal export clears signed destination');
} finally {
    try { $transaction->rollback(new \RuntimeException('Expected fixture rollback')); }
    catch (\RuntimeException $expected) { if ($expected->getMessage() !== 'Expected fixture rollback') throw $expected; }
    cache_helper::invalidate_by_definition('core', 'config', [], 'local_videoq');
}
foreach ($baseline as $name => $value) contract_check(get_config('local_videoq', $name) === $value, 'Restored configuration and cache: ' . $name);
echo json_encode(['passed' => true, 'checks' => $checks, 'transactionRolledBack' => true], JSON_PRETTY_PRINT) . "\n";
