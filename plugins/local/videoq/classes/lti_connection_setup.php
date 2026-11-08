<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
// SPDX-License-Identifier: GPL-3.0-or-later.

/**
 * Registers the approved LTI tool and materials service.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * A single expiring pairing code registers LTI service access; no sync key is issued.
 */
final class lti_connection_setup {
    /**
     * Validates a connection approval and configures the registered VideoQ tool.
     *
     * @param string $code Temporary connection approval code.
     * @param string|null $codeverifier Optional S256 proof verifier from the initiating session.
     * @return void
     */
    public static function connect(string $code, ?string $codeverifier = null): void {
        global $CFG, $DB;
        require_capability('moodle/site:config', \context_system::instance());
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        require_once($CFG->libdir . '/filelib.php');
        if (!$DB->record_exists('config_plugins', ['plugin' => 'ltisource_videoq', 'name' => 'version'])) {
            throw new \RuntimeException('Install both VideoQ plugins first');
        }
        $value = connection_setup::decode($code);
        if (($value['mode'] ?? '') !== 'materials') {
            throw new \RuntimeException('Invalid connection mode');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('connection-setup', 10);
        if (!$lock) {
            throw new \RuntimeException('Setup busy');
        }
        try {
            $pending = json_decode((string)get_config('local_videoq', 'pendinglticonnection'), true);
            if ($pending && ($pending['origin'] !== $value['origin'])) {
                throw new \RuntimeException('Retry pending connection');
            }
            $configured = (int)get_config('local_videoq', 'typeid');
            // Existing material IDs and courses remain attached to the same LTI identity.
            if ($configured && rtrim((string)get_config('local_videoq', 'apiurl'), '/') !== $value['origin']) {
                throw new \RuntimeException('Disconnect the existing installation first');
            }
            if (!$pending) {
                $typeid = $configured ?: lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
                    'lti_typename' => 'VideoQ', 'lti_toolurl' => $value['origin'] . '/api/lti/launch',
                    'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1,
                    'lti_initiatelogin' => $value['origin'] . '/api/lti/login',
                         'lti_redirectionuris' => $value['origin'] . '/api/lti/launch',

                    'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, 'lti_contentitem' => 0,
                ]);
                $pending = ['origin' => $value['origin'], 'typeid' => (int)$typeid];
                set_config('pendinglticonnection', json_encode($pending, JSON_THROW_ON_ERROR), 'local_videoq');
            }
            $type = $DB->get_record('lti_types', ['id' => $pending['typeid']], '*', MUST_EXIST);
            $curl = new \curl();
            $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
            $request = [
                'secret' => $value['secret'], 'issuer' => $CFG->wwwroot, 'clientId' => $type->clientid,
                'deploymentId' => (string)$type->id, 'mode' => 'materials',
                'materialsService' => ['tokenEndpoint' => $CFG->wwwroot . '/local/videoq/token.php',
                     'endpoint' => $CFG->wwwroot . '/local/videoq/materials.php']];
            if ($codeverifier !== null) {
                $request['codeVerifier'] = $codeverifier;
            }
            $raw = $curl->post(
                $value['origin'] . '/api/lms/pairing/exchange',
                json_encode($request, JSON_THROW_ON_ERROR),
                client::http_options()
            );
            if ($curl->get_errno() || (int)($curl->get_info()['http_code'] ?? 0) !== 200 || strlen($raw) > 16384) {
                throw new \RuntimeException('Connection failed');
            }
            $result = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (
                ($result['toolOrigin'] ?? '') !== $value['origin'] || !is_array($result['uploadTargets'] ?? null)
                || !$result['uploadTargets'] || count($result['uploadTargets']) > 4
            ) {
                throw new \RuntimeException('Invalid connection response');
            }
            foreach ($result['uploadTargets'] as $target) {
                if (
                    client::origin($target['origin'] ?? '') !== $target['origin'] ||
                    !is_string($target['prefix'] ?? null) ||
                        !str_starts_with(
                            $target['prefix'],
                            '/'
                        ) ||
                            strlen($target['prefix']) < 2 ||
                            !str_ends_with(
                                $target['prefix'],
                                '/'
                            )
                ) {
                    throw new \RuntimeException('Invalid storage policy');
                }
            }
            $transaction = $DB->start_delegated_transaction();
            // Use Moodle's existing registered-tool key configuration and JWKS cache.
            $DB->delete_records('lti_types_config', ['typeid' => $type->id, 'name' => 'keytype']);
            $DB->insert_record('lti_types_config', (object)['typeid' => $type->id, 'name' => 'keytype', 'value' => LTI_JWK_KEYSET]);
            $DB->delete_records('lti_types_config', ['typeid' => $type->id, 'name' => 'publickeyset']);
            $DB->insert_record(
                'lti_types_config',
                (object)['typeid' => $type->id,
                 'name' => 'publickeyset',
                'value' => $value['origin'] . '/api/lti/jwks']
            );
            privacy_api::servers(true);
            privacy_api::remember($value['origin'], $type);
            set_config('apiurl', $value['origin'], 'local_videoq');
            set_config('typeid', $type->id, 'local_videoq');
            set_config('serviceuploadtargets', json_encode($result['uploadTargets'], JSON_THROW_ON_ERROR), 'local_videoq');
            set_config('servicemode', 'lti', 'local_videoq');
            unset_config('synctoken', 'local_videoq');
            unset_config('pendinglticonnection', 'local_videoq');
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }
}
