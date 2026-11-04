<?php
// Temporary Moodle-only learners for the isolated concurrency acceptance check.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Use only the isolated lab');
require_once($CFG->dirroot . '/user/lib.php');
\core\session\manager::set_user(get_admin());
$courseid = (int)($argv[3] ?? 5);
if ($courseid !== 5) throw new Exception('Only the migrated lab course is permitted');
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
$roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$count = (int)($argv[2] ?? 5);
if ($count < 1 || $count > 200) throw new Exception('Acceptance learner count must be 1–200');
$users = [];
for ($i = 1; $i <= $count; $i++) {
    $username = 'videoq_acceptance_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if ($user && $user->idnumber !== 'videoq-acceptance-20261103') throw new Exception('Reserved test username already exists');
    if (($argv[1] ?? '') === 'cleanup') { if ($user) delete_user($user); continue; }
    if (!$user) {
        $id = user_create_user((object)['username' => $username, 'password' => 'Lti-lab-only-123!', 'firstname' => 'Acceptance', 'lastname' => (string)$i,
            'email' => $username . '@example.invalid', 'idnumber' => 'videoq-acceptance-20261103', 'auth' => 'manual', 'lang' => 'ja', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id]);
        $user = $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
    }
    enrol_get_plugin('manual')->enrol_user($enrol, $user->id, $roleid);
    $users[] = ['username' => $username, 'id' => (int)$user->id];
}
echo json_encode($users, JSON_THROW_ON_ERROR) . "\n";
