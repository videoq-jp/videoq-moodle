<?php
// The same connection setup as the admin form, scoped to the disposable second site.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://restore.moodle.localhost:18444' || $CFG->dbname !== 'moodle_restore_check') throw new RuntimeException('Disposable restored site only');
\core\session\manager::set_user(get_admin());
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
\local_videoq\lti_connection_setup::connect($input['code']);
echo json_encode(['connected' => get_config('local_videoq', 'servicemode') === 'lti', 'typeid' => (int)get_config('local_videoq', 'typeid')]);
