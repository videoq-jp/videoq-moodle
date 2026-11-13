<?php
/** Exercise Privacy API requests in the isolated, seeded Marketplace upgrade fixture. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site mount');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db'
        || $CFG->dbname !== 'moodleaudit_upgrade') {
    throw new RuntimeException('Refusing to inspect an unrelated Moodle database');
}
if ((int)$CFG->debug !== DEBUG_DEVELOPER || !$CFG->debugdisplay) {
    throw new RuntimeException('Developer debugging required');
}
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-MARKETPLACE-UPGRADE'], '*', MUST_EXIST);
$modules = $DB->get_records('course_modules', ['course' => $course->id], 'id ASC', '*', 0, 1);
$cm = reset($modules);
if (!$cm) throw new RuntimeException('Expected a seeded course activity');
$user = get_admin();
$contexts = [context_system::instance(), context_course::instance($course->id), context_module::instance($cm->id)];
$tables = ['course', 'course_modules', 'files', 'local_videoq_sync', 'local_videoq_exports', 'local_videoq_courses',
    'local_videoq_assertions', 'config_plugins'];
$snapshot = static function() use ($DB, $tables): array {
    $result = [];
    foreach ($tables as $table) {
        $result[$table] = hash('sha256', json_encode($DB->get_records($table, null, 'id ASC'), JSON_THROW_ON_ERROR));
    }
    return $result;
};
$before = $snapshot();
$report = ['passed' => false, 'database' => $CFG->dbtype, 'components' => []];
foreach (['local_videoq'] as $component) {
    $class = '\\' . $component . '\\privacy\\provider';
    foreach ([\core_privacy\local\request\plugin\provider::class,
            \core_privacy\local\request\core_userlist_provider::class] as $interface) {
        if (!is_subclass_of($class, $interface)) throw new RuntimeException('Missing privacy request contract');
    }
    if (count($class::get_contexts_for_userid((int)$user->id)) !== 0) {
        throw new RuntimeException('External-only component claimed local personal contexts');
    }
    $approved = new \core_privacy\local\request\approved_contextlist(
        $user, $component, array_map(static fn($context) => $context->id, $contexts)
    );
    $class::export_user_data($approved);
    $class::delete_data_for_user($approved);
    foreach ($contexts as $context) {
        $users = new \core_privacy\local\request\userlist($context, $component);
        $class::get_users_in_context($users);
        if (count($users) !== 0) throw new RuntimeException('Unexpected local personal users');
        $class::delete_data_for_users(new \core_privacy\local\request\approved_userlist($context, $component, [$user->id]));
        $class::delete_data_for_all_users_in_context($context);
    }
    if ($before !== $snapshot()) throw new RuntimeException('Privacy request changed shared materials or connection state');
    $report['components'][$component] = ['requestProvider' => true, 'userlistProvider' => true,
        'localPersonalContexts' => 0, 'localPersonalUsers' => 0, 'requestMethodsExercised' => 6,
        'systemCourseModuleContextsExercised' => true, 'sharedRecordsPreserved' => true];
}
$report['passed'] = true;
$report['limits'] = ['External VideoQ export/deletion is not invoked; operator handling is required.',
    'Moodle core owns sessions/tasks/source activity data; no legal compliance certification is implied.'];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
