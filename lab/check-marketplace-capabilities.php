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
require_once $CFG->dirroot . '/course/modlib.php';
require_once $CFG->dirroot . '/mod/lti/locallib.php';
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
    $state = ['courses' => [], 'users' => [], 'roles' => [], 'materials' => []];
    $suffix = bin2hex(random_bytes(6));
    foreach (['primary', 'secondary'] as $name) {
        $course = create_course((object)['fullname' => 'VideoQ capability ' . $name,
            'shortname' => 'VIDEOQ-CAPABILITY-' . strtoupper($name) . '-' . $suffix, 'category' => 1]);
        $state['courses'][] = (int)$course->id;
    }
    foreach (['student', 'teacher', 'editingteacher', 'manager'] as $archetype) {
        $role = $DB->get_record('role', ['archetype' => $archetype], '*', MUST_EXIST);
        $userid = user_create_user((object)['username' => 'videoq-capability-' . $archetype . '-' . $suffix,
            'firstname' => 'Synthetic', 'lastname' => $archetype, 'email' => $archetype . '@example.invalid',
            'password' => 'Only-fixture-123!', 'auth' => 'manual', 'confirmed' => 1]);
        $state['users'][$archetype] = (int)$userid;
        $state['roles'][$archetype] = (int)$role->id;
        if ($archetype === 'student') $state['studentRole'] = (int)$role->id;
        foreach ($state['courses'] as $courseid) {
            $manual = enrol_get_plugin('manual');
            $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
            if (!$instance) {
                $instanceid = $manual->add_default_instance(get_course($courseid));
                $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
            }
            $instance->customint1 = ENROL_DO_NOT_SEND_EMAIL;
            $DB->set_field('enrol', 'customint1', ENROL_DO_NOT_SEND_EMAIL, ['id' => $instance->id]);
            $manual->enrol_user($instance, $userid, $role->id);
        }
    }
    $course = get_course($state['courses'][0]);
    $typeid = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'Synthetic VideoQ management ' . $suffix, 'lti_toolurl' => 'https://videoq.example.invalid/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1, 'lti_contentitem' => 0,
        'lti_initiatelogin' => 'https://videoq.example.invalid/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.example.invalid/api/lti/launch',
        'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
    ]);
    foreach (['indexing', 'completed'] as $status) {
        $common = ['course' => $course->id, 'section' => 0, 'visible' => 1, 'intro' => '', 'introformat' => FORMAT_HTML];
        $source = add_moduleinfo((object)($common + [
            'modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
            'name' => 'Synthetic material ' . $status, 'files' => 0, 'display' => 0, 'printintro' => 0,
            'showsize' => 0, 'showtype' => 0, 'showdate' => 0,
        ]), $course);
        $launch = add_moduleinfo((object)($common + [
            'modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti']),
            'name' => 'Synthetic transport ' . $status, 'typeid' => $typeid,
            'toolurl' => 'https://videoq.example.invalid/api/lti/launch', 'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
            'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0, 'instructorchoicesendname' => 0,
            'instructorchoicesendemailaddr' => 0, 'instructorchoiceacceptgrades' => 0,
        ]), $course);
        $DB->insert_record('local_videoq_sync', (object)[
            'typeid' => $typeid, 'cmid' => $source->coursemodule, 'courseid' => $course->id,
            'lticmid' => $launch->coursemodule, 'ltiinstance' => $launch->instance, 'linkcmid' => 0,
            'enabled' => 1, 'generation' => 7, 'fingerprint' => '', 'status' => $status,
            'errorcode' => '', 'timemodified' => time(), 'lastsynced' => 0,
        ]);
        $state['materials'][$status] = ['cmid' => (int)$source->coursemodule, 'ltiid' => (int)$launch->instance];
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
$qacaps = ['viewqa', 'postqa', 'voteqa', 'deleteownqa', 'moderateqa', 'instructorqa'];
$historycap = 'local/videoq:manageownhistory';
$contentcap = 'local/videoq:managecontent';
$definition = $DB->get_record('capabilities', ['name' => $contentcap], '*', MUST_EXIST);
$check((int)$definition->contextlevel === CONTEXT_COURSE && $definition->captype === 'write', 'content write definition');
$check(!has_capability($contentcap, $primary, $guest, false), 'guest management denied');
foreach ($state['users'] as $archetype => $userid) {
    $expected = !($phase === 'revoked' && $archetype === 'student');
    $check(has_capability($historycap, $primary, $userid) === $expected, $archetype . ' personal history');
    $manage = in_array($archetype, ['editingteacher', 'manager'], true) && !($phase === 'revoked' && $archetype === 'editingteacher');
    $check(has_capability($contentcap, $primary, $userid) === $manage, $archetype . ' derived content management');
    $launchid = $state['materials']['completed']['ltiid'];
    $proof = \local_videoq\course_access::launch($launchid, $userid);
    $access = \local_videoq\course_access::resolve($proof['custom_videoq_access'], (string)$userid,
        (string)$state['courses'][0], (string)$launchid);
    $check($access['permissions']['manageContent'] === $manage, $archetype . ' fresh permission response');
    $processing = array_values(array_filter($access['resources'], fn($item) => $item['lesson'] === 'sync_' . $state['materials']['indexing']['cmid']));
    $processingmanage = $manage && !($phase === 'revoked' && $archetype === 'manager');
    $check(count($processing) === ($processingmanage ? 1 : 0), $archetype . ' processing scope');
    if ($processingmanage) {
        $check($processing[0]['learning'] === false && $processing[0]['management']['generation'] === 7,
            $archetype . ' separate generation-bound management metadata');
    }
    $ready = array_values(array_filter($access['resources'], fn($item) => $item['lesson'] === 'sync_' . $state['materials']['completed']['cmid']));
    $check(count($ready) === 1 && !isset($ready[0]['learning']) && $ready[0]['generation'] === 7, $archetype . ' ready learning scope');
    $check(isset($ready[0]['management']) === $manage, $archetype . ' per-resource management metadata');
}
$check(has_capability($contentcap, $secondary, $state['users']['editingteacher']), 'content override isolated to primary course');
$check(!has_capability($historycap, $primary, $guest, false), 'guest personal history denied');
$check(has_capability($historycap, $secondary, $state['users']['student']), 'other course personal history unaffected');
foreach ($qacaps as $name) {
    $full = 'local/videoq:' . $name;
    $definition = $DB->get_record('capabilities', ['name' => $full], '*', MUST_EXIST);
    $check((int)$definition->contextlevel === CONTEXT_COURSE, 'Q&A context ' . $name);
    $check(!has_capability($full, $primary, $guest, false), 'guest Q&A denied ' . $name);
    foreach ($state['users'] as $archetype => $userid) {
        $expected = $archetype !== 'student' || !in_array($name, ['moderateqa', 'instructorqa'], true);
        if ($phase === 'revoked' && (($archetype === 'student' && $name === 'postqa')
                || ($archetype === 'teacher' && $name === 'moderateqa'))) $expected = false;
        $check(has_capability($full, $primary, $userid) === $expected, $archetype . ' current ' . $name);
    }
}
$check(has_capability('local/videoq:postqa', $secondary, $state['users']['student']), 'other course posting unaffected');
$check(has_capability('local/videoq:moderateqa', $secondary, $state['users']['teacher']), 'other course moderation unaffected');
if ($phase === 'defaults') {
    assign_capability($capability, CAP_PREVENT, $state['studentRole'], $primary->id, true);
    assign_capability($historycap, CAP_PREVENT, $state['studentRole'], $primary->id, true);
    assign_capability('local/videoq:postqa', CAP_PREVENT, $state['studentRole'], $primary->id, true);
    assign_capability('local/videoq:moderateqa', CAP_PREVENT, $state['roles']['teacher'], $primary->id, true);
    assign_capability($contentcap, CAP_PREVENT, $state['roles']['editingteacher'], $primary->id, true);
    assign_capability('moodle/course:manageactivities', CAP_PREVENT, $state['roles']['manager'],
        context_module::instance($state['materials']['indexing']['cmid'])->id, true);
} elseif ($phase === 'revoked') {
    unassign_capability($capability, $state['studentRole'], $primary->id);
    unassign_capability($historycap, $state['studentRole'], $primary->id);
    unassign_capability('local/videoq:postqa', $state['studentRole'], $primary->id);
    unassign_capability('local/videoq:moderateqa', $state['roles']['teacher'], $primary->id);
    unassign_capability($contentcap, $state['roles']['editingteacher'], $primary->id);
    unassign_capability('moodle/course:manageactivities', $state['roles']['manager'],
        context_module::instance($state['materials']['indexing']['cmid'])->id);
} else {
    // The matrix discards the entire isolated database. Leave synthetic course
    // cleanup to that boundary, avoiding core recycle-bin backup debug output.
    // Remove only our probe bindings before the independent reinstall check
    // verifies empty plugin tables; never relax its preservation assertions.
    foreach ($state['materials'] as $material) {
        $DB->delete_records('local_videoq_sync', ['courseid' => $state['courses'][0], 'cmid' => $material['cmid']]);
    }
    unlink($statefile);
}
echo json_encode(['passed' => true, 'phase' => $phase, 'database' => $CFG->dbtype,
    'studentPermission' => $phase !== 'revoked', 'teacherPermission' => true,
    'studentPersonalHistory' => $phase !== 'revoked',
    'studentContentManagement' => false, 'nonEditingTeacherContentManagement' => false,
    'editingTeacherContentManagement' => $phase !== 'revoked', 'moduleOverrideEnforced' => true,
    'processingManagementSeparated' => true, 'sourceGenerationBound' => true,
    'qaCapabilities' => $qacaps, 'qaArchetypes' => array_keys($state['users']),
    'studentPostQa' => $phase !== 'revoked', 'teacherModerateQa' => $phase !== 'revoked',
    'otherCourseUnaffected' => true, 'guestDenied' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
