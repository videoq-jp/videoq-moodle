<?php
// Temporary guarded lab endpoint, copied into the Moodle webroot only for a spike.
define('NO_MOODLE_COOKIES', true);
require('/var/www/html/config.php');
header('Cache-Control: no-store');
header('Content-Type: application/json');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
function refuse_spike(int $status, string $code): never { http_response_code($status); echo json_encode(['error' => $code]); exit; }
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') refuse_spike(404, 'NOT_FOUND');
$component = 'local_videoq_pull_spike';
$state = json_decode((string)get_config($component, 'fixture'), true);
if (!$state || !hash_equals($state['run'], (string)($_GET['run'] ?? ''))) refuse_spike(404, 'NOT_FOUND');
if (!$state['active']) refuse_spike(403, 'GRANT_REVOKED');
$scope = 'https://videoq.example/spec/lti-materials/scope/readonly';
$action = $_GET['action'] ?? '';
$stage = 'initial';
$base = $CFG->wwwroot . '/videoq-lti-pull-spike.php?run=' . $state['run'];
try {
    if ($action === 'token') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['grant_type'] ?? '') !== 'client_credentials' ||
                ($_POST['client_assertion_type'] ?? '') !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer') refuse_spike(400, 'INVALID_REQUEST');
        if (($_POST['scope'] ?? '') !== $scope) refuse_spike(403, 'INVALID_SCOPE');
        $jwt = (string)($_POST['client_assertion'] ?? '');
        if (strlen($jwt) > 8192) refuse_spike(400, 'INVALID_REQUEST');
        lti_verify_jwt_signature($state['typeid'], $state['clientid'], $jwt);
        $parts = explode('.', $jwt);
        $claims = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($parts[1]), true, 16, JSON_THROW_ON_ERROR);
        $now = time();
        if (($claims['iss'] ?? '') !== $state['clientid'] || ($claims['sub'] ?? '') !== $state['clientid'] ||
                ($claims['aud'] ?? '') !== $base . '&action=token' || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null) ||
                $claims['iat'] > $now + 5 || $claims['iat'] < $now - 65 || $claims['exp'] <= $now || $claims['exp'] - $claims['iat'] > 60 ||
                ($claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? '') !== (string)$state['typeid'] ||
                !preg_match('/^[a-f0-9]{32}$/D', $claims['jti'] ?? '')) refuse_spike(401, 'INVALID_ASSERTION');
        $name = 'jti_' . hash('sha256', $claims['jti']);
        // A unique (plugin,name) index supplies atomic replay rejection.
        try { $DB->insert_record('config_plugins', (object)['plugin' => $component, 'name' => $name, 'value' => (string)$claims['exp']]); }
        catch (\dml_write_exception $e) { refuse_spike(401, 'REPLAY'); }
        $token = lti_new_access_token($state['typeid'], [$scope]);
        echo json_encode(['access_token' => $token->token, 'token_type' => 'Bearer', 'expires_in' => LTI_ACCESS_TOKEN_LIFE, 'scope' => $scope]); exit;
    }
    $stage = 'bearer';
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $authorization = $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer ([a-f0-9]+)$/D', $authorization, $matches)) refuse_spike(401, 'AUTH_HEADER_MISSING');
    $token = $DB->get_record('lti_access_tokens', ['token' => $matches[1], 'typeid' => $state['typeid']]);
    if (!$token || $token->validuntil <= time() || !in_array($scope, json_decode($token->scope, true, 8, JSON_THROW_ON_ERROR), true)) refuse_spike(401, 'TOKEN_INVALID');
    if (($_GET['course'] ?? '') !== (string)$state['courseid']) refuse_spike(403, 'WRONG_COURSE');
    $stage = 'source';
    $cm = get_coursemodule_from_id('resource', $state['cmid'], $state['courseid'], false, IGNORE_MISSING);
    $deleted = $state['deleted'] || !$cm || $cm->deletioninprogress;
    $files = $deleted ? [] : get_file_storage()->get_area_files(context_module::instance($cm->id)->id, 'mod_resource', 'content', 0, 'sortorder,id', false);
    $file = $files ? reset($files) : null;
    if ($action === 'access') {
        $stage = 'access-body';
        $input = json_decode(file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
        if ((string)($input['subject'] ?? '') !== (string)$state['learnerid']) refuse_spike(403, 'WRONG_SUBJECT');
        $stage = 'access-user';
        $user = $DB->get_record('user', ['id' => $state['learnerid'], 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);
        $course = get_course($state['courseid']); $context = context_course::instance($course->id);
        $stage = 'access-permissions';
        $visible = false;
        if (!$deleted && is_enrolled($context, $user, '', true)) {
            $info = get_fast_modinfo($course, $user->id);
            $visible = $info->cms[$cm->id]->uservisible && has_capability('mod/resource:view', context_module::instance($cm->id), $user);
        }
        echo json_encode(['allowed' => $visible ? [(string)$state['cmid']] : []]); exit;
    }
    if ($action === 'export') {
        $stage = 'export';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$file || !$cm->visible) refuse_spike(403, 'SOURCE_UNAVAILABLE');
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) refuse_spike(400, 'REQUEST_TOO_LARGE');
        $job = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (($job['generation'] ?? null) !== $state['generation']) refuse_spike(412, 'STALE_CONTENT');
        $destination = parse_url($job['uploadUrl'] ?? '');
        if (!$destination || ($destination['scheme'] ?? '') !== 'https' || ($destination['host'] ?? '') !== 'storage.videoq.localhost' ||
                ($destination['port'] ?? 443) !== 18443 || isset($destination['user']) || isset($destination['pass']) || isset($destination['fragment']) ||
                !str_starts_with($destination['path'] ?? '', '/lti-sync/media/videos/lti-lab-owner/')) refuse_spike(403, 'UNTRUSTED_DESTINATION');
        require_once($CFG->libdir . '/filelib.php');
        $curl = new curl();
        $curl->setHeader(['Content-Type: ' . $file->get_mimetype(), 'Content-Length: ' . $file->get_filesize(), 'Expect:']);
        $temp = $file->copy_content_to_temp('local_videoq_pull_spike');
        try { $curl->put($job['uploadUrl'], ['file' => $temp], ['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => 30]); }
        finally { unlink($temp); }
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $status < 200 || $status >= 300) refuse_spike(502, 'EXPORT_FAILED');
        echo json_encode(['uploaded' => true, 'generation' => $state['generation'], 'bytes' => (int)$file->get_filesize()]); exit;
    }
    if ($action === 'content') {
        if (!$file || !$cm->visible) refuse_spike(403, 'SOURCE_UNAVAILABLE');
        if ((string)($_GET['generation'] ?? '') !== (string)$state['generation']) refuse_spike(412, 'STALE_CONTENT');
        send_stored_file($file, 0, 0, true); exit;
    }
    if ($action !== 'manifest') refuse_spike(404, 'NOT_FOUND');
    $manifest = ['generation' => $state['generation'], 'contextId' => (string)$state['courseid'],
        'courseTitle' => 'VideoQ LTI pull spike ' . $state['run'], 'resourceLinkId' => 'spike-' . $state['cmid'],
        'enabled' => !$deleted && $file && $cm->visible, 'deleted' => $deleted, 'title' => $cm->name ?? 'Deleted source'];
    if ($file) $manifest['file'] = ['filename' => $file->get_filename(), 'contentType' => $file->get_mimetype(), 'size' => (int)$file->get_filesize(), 'contentHash' => $file->get_contenthash()];
    echo json_encode(['externalId' => (string)$state['cmid'], 'manifest' => $manifest,
        'contentUrl' => $file ? $base . '&action=content&course=' . $state['courseid'] . '&generation=' . $state['generation'] : null]);
} catch (\Throwable $e) { refuse_spike(401, 'REQUEST_REJECTED_' . $stage . '_' . get_class($e)); }
