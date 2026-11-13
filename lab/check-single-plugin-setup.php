<?php
/** Verify actual single-plugin installation and connection preconditions without network requests. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site mount');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db'
        || $CFG->dbname !== 'moodleaudit_upgrade') throw new RuntimeException('Refusing unrelated Moodle database');
if (get_config('ltisource_videoq', 'version') || is_dir($CFG->dirroot . '/mod/lti/source/videoq')) {
    throw new RuntimeException('Retired helper must be absent');
}
\core\session\manager::set_user(get_admin());
$plugin = core_plugin_manager::instance()->get_plugin_info('local_videoq');
if (!$plugin || !empty($plugin->get_other_required_plugins())) throw new RuntimeException('Unexpected plugin dependency');
// The real setup must reach code validation with no helper. This malformed code
// must fail before networking, and must not be replaced by a missing-plugin error.
try {
    \local_videoq\lti_connection_setup::connect('VIDEOQ2.invalid');
    throw new RuntimeException('Invalid pairing code was accepted');
} catch (InvalidArgumentException $expected) {
    if ($expected->getMessage() !== 'Invalid connection code') throw $expected;
}
echo json_encode(['passed' => true, 'singlePluginInstalled' => true, 'retiredHelperAbsent' => true,
    'connectionCodeValidationReached' => true,
    'limits' => ['No remote pairing exchange; installed connected API verification is separate.']], JSON_PRETTY_PRINT) . "\n";
