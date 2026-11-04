<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

/** Administrator-only, recoverable pairing. Existing synchronized courses are never rebound. */
final class connection_setup {
    public static function decode(string $code): array {
        $code = trim($code);
        if (strlen($code) > 8192 || !str_starts_with($code, 'VIDEOQ1.')) throw new \InvalidArgumentException('Invalid connection code');
        $json = base64_decode(substr($code, 8), true);
        $value = $json === false ? null : json_decode($json, true);
        if (!is_array($value) || array_diff(array_keys($value), ['origin', 'secret']) ||
                !is_string($value['origin'] ?? null) || !is_string($value['secret'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $value['secret']) || client::origin($value['origin']) !== $value['origin']) {
            throw new \InvalidArgumentException('Invalid connection code');
        }
        return $value;
    }

    public static function connect(string $code): void {
        global $CFG, $DB;
        require_capability('moodle/site:config', \context_system::instance());
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('connection-setup', 10);
        if (!$lock) throw new \RuntimeException('Setup is busy');
        try {
            // Replacing a configured installation would detach its existing resources.
            if (get_config('local_videoq', 'synctoken')) throw new \RuntimeException('Already configured');
            if (!$DB->record_exists('config_plugins', ['plugin' => 'ltisource_videoq', 'name' => 'version'])) {
                throw new \RuntimeException('Install both VideoQ plugins first');
            }
            $value = self::decode($code);
            $pending = json_decode((string)get_config('local_videoq', 'pendingconnection'), true);
            $codehash = hash('sha256', trim($code));
            if ($pending && ($pending['codehash'] ?? '') !== $codehash) {
                // A fresh code may replace an expired attempt, reusing its LTI identity.
                // Never silently switch an already committed connection to another account.
                if ($pending['origin'] !== $value['origin'] || self::probe($pending)) {
                    throw new \RuntimeException('Retry the original connection code');
                }
                $pending['codehash'] = $codehash;
                set_config('pendingconnection', json_encode($pending, JSON_THROW_ON_ERROR), 'local_videoq');
            }
            if (!$pending) {
                $typeid = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
                    'lti_typename' => 'VideoQ', 'lti_toolurl' => $value['origin'] . '/api/lti/launch',
                    'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1,
                    'lti_initiatelogin' => $value['origin'] . '/api/lti/login',
                    'lti_redirectionuris' => $value['origin'] . '/api/lti/launch',
                    'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, 'lti_contentitem' => 0,
                ]);
                $pending = ['codehash' => $codehash, 'origin' => $value['origin'], 'typeid' => (int)$typeid,
                    'token' => bin2hex(random_bytes(32))];
                set_config('pendingconnection', json_encode($pending, JSON_THROW_ON_ERROR), 'local_videoq');
            }
            $type = $DB->get_record('lti_types', ['id' => $pending['typeid']], '*', MUST_EXIST);
            $request = ['secret' => $value['secret'], 'issuer' => rtrim($CFG->wwwroot, '/'),
                'clientId' => $type->clientid, 'deploymentId' => (string)$type->id, 'syncToken' => $pending['token']];
            // The token is generated before exchange. A dropped successful HTTP response
            // can therefore be recovered without replaying the single-use code.
            if (!self::matches(self::probe($pending), $request, $pending['origin'])) {
                self::exchange($pending['origin'], $request);
                if (!self::matches(self::probe($pending), $request, $pending['origin'])) throw new \RuntimeException('Connection verification failed');
            }
            $transaction = $DB->start_delegated_transaction();
            set_config('apiurl', $pending['origin'], 'local_videoq');
            set_config('typeid', $pending['typeid'], 'local_videoq');
            set_config('synctoken', $pending['token'], 'local_videoq');
            unset_config('pendingconnection', 'local_videoq');
            $transaction->allow_commit();
        } finally { $lock->release(); }
    }
    /** Only abandon an unregistered attempt; a committed remote identity must be recovered. */
    public static function cancel_pending(): void {
        global $CFG;
        require_capability('moodle/site:config', \context_system::instance());
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('connection-setup', 10);
        if (!$lock) throw new \RuntimeException('Setup is busy');
        try {
            if (get_config('local_videoq', 'synctoken')) throw new \RuntimeException('Already configured');
            $pending = json_decode((string)get_config('local_videoq', 'pendingconnection'), true);
            if (!$pending) return;
            if (self::probe($pending)) throw new \RuntimeException('Retry the original code to recover the connection');
            lti_delete_type($pending['typeid']);
            unset_config('pendingconnection', 'local_videoq');
        } finally { $lock->release(); }
    }
    private static function matches(?array $data, array $request, string $origin): bool {
        return $data && ($data['issuer'] ?? '') === $request['issuer'] && ($data['clientId'] ?? '') === $request['clientId'] &&
            ($data['deploymentId'] ?? '') === $request['deploymentId'] && ($data['toolOrigin'] ?? '') === $origin;
    }
    private static function probe(array $pending): ?array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $pending['token'], 'Accept: application/json']);
        $response = $curl->get($pending['origin'] . '/api/lms/connection', [], ['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => 15]);
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || !in_array($status, [200, 401], true)) throw new \RuntimeException('Connection check unavailable');
        if ($status === 401) return null;
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
    private static function exchange(string $origin, array $request): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $curl->post($origin . '/api/lms/pairing/exchange', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => 30]);
        if ($curl->get_errno() || (int)($curl->get_info()['http_code'] ?? 0) !== 200) {
            throw new \RuntimeException('Connection could not be completed');
        }
    }
}
