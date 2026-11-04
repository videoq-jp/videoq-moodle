<?php
// Design feasibility probe: temporary tool, no courses or real service data.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Isolated lab only');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->libdir . '/filelib.php');
\core\session\manager::set_user(get_admin());
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $private);
$public = openssl_pkey_get_details($key)['key'];
$typeid = null;
try {
    $typeid = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'VideoQ read-only service design probe ' . bin2hex(random_bytes(5)),
        'lti_toolurl' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 0,
        'lti_initiatelogin' => 'https://videoq.localhost:18443/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_keytype' => LTI_RSA_KEY, 'lti_publickey' => $public,
        'ltiservice_memberships' => 1,
    ]);
    $tool = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
    $tokenurl = $CFG->wwwroot . '/mod/lti/token.php';
    $scope = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';
    $assertion = function(string $signingkey) use ($tool, $tokenurl) {
        return \Firebase\JWT\JWT::encode(['iss' => $tool->clientid, 'sub' => $tool->clientid,
            'aud' => $tokenurl, 'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(24))], $signingkey, 'RS256');
    };
    $request = function(string $jwt, string $requested) use ($tokenurl) {
        $curl = new curl(); $curl->setHeader(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        $raw = $curl->post($tokenurl, http_build_query(['grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $jwt, 'scope' => $requested], '', '&'), ['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => 15]);
        return ['status' => (int)($curl->get_info()['http_code'] ?? 0), 'body' => json_decode($raw, true)];
    };
    $valid = $request($assertion($private), $scope);
    if ($valid['status'] !== 200 || empty($valid['body']['access_token']) || $valid['body']['scope'] !== $scope) throw new Exception('Valid LTI service grant failed: status=' . $valid['status'] . ', code=' . (is_string($valid['body']['error'] ?? null) && preg_match('/^[a-z_]{1,60}$/D', $valid['body']['error']) ? $valid['body']['error'] : 'unavailable') . ', scopes=' . implode(',', lti_get_permitted_service_scopes($tool,lti_get_type_config($typeid))));
    $unsupported = $request($assertion($private), 'https://videoq.example.invalid/scope/materials.read');
    if ($unsupported['status'] === 200) throw new Exception('Unregistered material scope was unexpectedly granted');
    $wrongkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($wrongkey, $wrongprivate);
    $invalid = $request($assertion($wrongprivate), $scope);
    if ($invalid['status'] === 200) throw new Exception('Wrong signature was unexpectedly accepted');
    $base = new ReflectionClass(\mod_lti\local\ltiservice\service_base::class);
    $result = ['passed' => true, 'moodleVersion' => $CFG->release,
        'standardServiceTokenIssued' => true, 'wrongSignatureRejected' => true,
        'unregisteredMaterialScopeRejected' => true, 'existingVideoqApiUsed' => false,
        'personalServiceDataRequested' => false, 'ephemeralToolOnly' => true,
        'extensionHooks' => []];
    foreach (['get_launch_parameters', 'get_jwt_claim_mappings', 'get_resources', 'get_scopes', 'get_permitted_scopes'] as $hook) {
        if (!$base->hasMethod($hook)) throw new Exception('Required extension hook missing');
        $result['extensionHooks'][] = $hook;
    }
} finally {
    if ($typeid) { $DB->delete_records('lti_access_tokens', ['typeid' => $typeid]); lti_delete_type($typeid); }
}
$result['temporaryToolRemoved'] = !$DB->record_exists('lti_types', ['id' => $typeid]);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
