<?php
/** Check real course capability defaults and revocation across independent requests. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site mount');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db'
        || $CFG->dbname !== 'moodleaudit_upgrade') {
    throw new RuntimeException('Refusing to mutate an unrelated Moodle database');
}
require_once $CFG->dirroot . '/course/lib.php';
require_once $CFG->dirroot . '/user/lib.php';
\core\session\manager::set_user(get_admin());
$phase = $argv[2] ?? '';
$capability = 'local/videoq:viewtranscript';
$statefile = $CFG->dataroot . '/videoq-capabilities-state.json';
$check = static function(bool $value, string $label): void {
    if (!$value) throw new RuntimeException('Capability check failed: ' . $label);
};
if ($phase === 'defaults') {
    $check(!file_exists($statefile), 'fresh fixture');
    $definition = $DB->get_record('capabilities', ['name' => $capability], '*', MUST_EXIST);
    $check($definition->component === 'local_videoq' && (int)$definition->contextlevel === CONTEXT_COURSE, 'installed definition');
    $state = ['courses' => [], 'users' => []];
    $suffix = bin2hex(random_bytes(6));
    foreach (['primary', 'secondary'] as $name) {
        $course = create_course((object)['fullname' => 'VideoQ capability ' . $name,
            'shortname' => 'VIDEOQ-CAPABILITY-' . strtoupper($name) . '-' . $suffix, 'category' => 1]);
        $state['courses'][] = (int)$course->id;
    }
    foreach (['student', 'teacher'] as $archetype) {
        $role = $DB->get_record('role', ['archetype' => $archetype], '*', MUST_EXIST);
        $userid = user_create_user((object)['username' => 'videoq-capability-' . $archetype . '-' . $suffix,
            'firstname' => 'Synthetic', 'lastname' => $archetype, 'email' => $archetype . '@example.invalid',
            'password' => 'Only-fixture-123!', 'auth' => 'manual', 'confirmed' => 1]);
        $state['users'][$archetype] = (int)$userid;
        if ($archetype === 'student') $state['studentRole'] = (int)$role->id;
        foreach ($state['courses'] as $courseid) role_assign($role->id, $userid, context_course::instance($courseid)->id);
    }
    file_put_contents($statefile, json_encode($state, JSON_THROW_ON_ERROR));
} else {
    $check(in_array($phase, ['revoked', 'restored'], true) && is_file($statefile), 'known phase and fixture');
    $state = json_decode(file_get_contents($statefile), true, 16, JSON_THROW_ON_ERROR);
}
$primary = context_course::instance($state['courses'][0]);
$secondary = context_course::instance($state['courses'][1]);
$check(has_capability($capability, $primary, $state['users']['student']) === ($phase !== 'revoked'), 'student current permission');
$check(has_capability($capability, $secondary, $state['users']['student']), 'override isolated to primary course');
$check(has_capability($capability, $primary, $state['users']['teacher']), 'teacher unaffected by student override');
$guest = $DB->get_record('user', ['username' => 'guest'], '*', MUST_EXIST);
$check(!has_capability($capability, $primary, $guest, false), 'guest denied');
if ($phase === 'defaults') {
    assign_capability($capability, CAP_PREVENT, $state['studentRole'], $primary->id, true);
} elseif ($phase === 'revoked') {
    unassign_capability($capability, $state['studentRole'], $primary->id);
} else {
    // The matrix discards the entire isolated database. Leave synthetic course
    // cleanup to that boundary, avoiding core recycle-bin backup debug output.
    unlink($statefile);
}
echo json_encode(['passed' => true, 'phase' => $phase, 'database' => $CFG->dbtype,
    'studentPermission' => $phase !== 'revoked', 'teacherPermission' => true,
    'otherCourseUnaffected' => true, 'guestDenied' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
