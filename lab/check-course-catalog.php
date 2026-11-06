<?php
/** Actual Moodle catalog/order/visibility authorization; synthetic File bytes, no AI processing. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the owned isolated /site fixture');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db' || $CFG->dbname !== 'moodleaudit_upgrade') {
    throw new RuntimeException('Refusing unrelated Moodle database');
}
require_once $CFG->dirroot . '/course/lib.php';
require_once $CFG->dirroot . '/course/modlib.php';
require_once $CFG->dirroot . '/user/lib.php';
require_once $CFG->dirroot . '/mod/lti/locallib.php';
$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());
$saved = [];
foreach (['typeid', 'apiurl', 'servicemode', 'serviceuploadtargets', 'synctoken'] as $key) $saved[$key] = get_config('local_videoq', $key);
$recycle = get_config('tool_recyclebin', 'coursebinenable');
$checks = [];
$check = static function(bool $value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('Catalog check failed: ' . $label);
    $checks[] = $label;
};
$rejects = static function(callable $operation, string $label) use ($check): void {
    $denied = false; try { $operation(); } catch (Throwable) { $denied = true; }
    $check($denied, $label);
};
$courseid = 0;
try {
    $suffix = bin2hex(random_bytes(6));
    $course = create_course((object)['fullname' => 'Owned VideoQ catalog ' . $suffix, 'shortname' => 'VIDEOQ-CATALOG-' . $suffix, 'category' => 1]);
    $courseid = (int)$course->id;
    $context = context_course::instance($courseid);
    $manual = enrol_get_plugin('manual');
    $enrol = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
    if (!$enrol) $enrol = $DB->get_record('enrol', ['id' => $manual->add_default_instance($course)], '*', MUST_EXIST);
    $DB->set_field('enrol', 'customint1', ENROL_DO_NOT_SEND_EMAIL, ['id' => $enrol->id]);
    $enrol->customint1 = ENROL_DO_NOT_SEND_EMAIL;
    foreach (['student', 'teacher', 'editingteacher'] as $archetype) {
        $role = $DB->get_record('role', ['archetype' => $archetype], '*', MUST_EXIST);
        $userid = user_create_user((object)['username' => 'videoq-catalog-' . $archetype . '-' . $suffix,
            'firstname' => 'Synthetic', 'lastname' => $archetype, 'email' => $archetype . '@example.invalid',
            'password' => 'Only-fixture-123!', 'auth' => 'manual', 'confirmed' => 1]);
        $manual->enrol_user($enrol, $userid, $role->id);
        $users[$archetype] = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $roles[$archetype] = (int)$role->id;
    }
    $typeid = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'Owned catalog ' . $suffix, 'lti_toolurl' => 'https://videoq.example.invalid/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1, 'lti_contentitem' => 0,
        'lti_initiatelogin' => 'https://videoq.example.invalid/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.example.invalid/api/lti/launch', 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
    ]);
    set_config('typeid', $typeid, 'local_videoq'); set_config('apiurl', 'https://videoq.example.invalid', 'local_videoq');
    set_config('servicemode', 'lti', 'local_videoq');
    set_config('serviceuploadtargets', json_encode([['origin' => 'https://storage.example.invalid', 'prefix' => '/fixture/']]), 'local_videoq');
    $canonicalurl = $CFG->wwwroot . '/local/videoq/view.php?course=' . $courseid;
    foreach ([$canonicalurl . '-suffix', $CFG->wwwroot . '/local/videoq/VIEW.php?course=' . $courseid] as $nearbyurl) {
        add_moduleinfo((object)['modulename' => 'url', 'module' => $DB->get_field('modules', 'id', ['name' => 'url']),
            'course' => $courseid, 'section' => 0, 'visible' => 1, 'name' => 'Synthetic near-match URL',
            'intro' => '', 'introformat' => FORMAT_HTML, 'externalurl' => $nearbyurl, 'display' => 0, 'printintro' => 0,
            'parameters' => []], $course);
    }
    foreach ([1, 2] as $index) {
        $source = add_moduleinfo((object)[
            'modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
            'course' => $courseid, 'section' => 0, 'visible' => 1, 'intro' => '', 'introformat' => FORMAT_HTML,
            'name' => 'Synthetic source ' . $index, 'files' => 0, 'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0,
        ], $course);
        $cmid = (int)$source->coursemodule;
        get_file_storage()->create_file_from_string(['contextid' => context_module::instance($cmid)->id,
            'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'source-' . $index . '.mp4', 'mimetype' => 'video/mp4'], 'Synthetic authorization bytes ' . $index);
        \local_videoq\synchronizer::enable($cmid);
        $sources[] = $cmid;
    }
    $urlcount = $DB->count_records('url', ['course' => $courseid]);
    $exact = array_filter($DB->get_records('url', ['course' => $courseid]), static fn($url) => $url->externalurl === $canonicalurl);
    $check(count($exact) === 1, 'stable course URL cannot adopt a prefix or case-folded match');
    \local_videoq\synchronizer::ensure_course_entry($courseid);
    $check($DB->count_records('url', ['course' => $courseid]) === $urlcount, 'stable course URL is exact and idempotent under developer diagnostics');
    \core\session\manager::set_user($users['student']);
    $rejects(fn() => \local_videoq\course_catalog::sources($courseid), 'learner catalog denied');
    \core\session\manager::set_user($users['teacher']);
    $rejects(fn() => \local_videoq\course_catalog::actions($courseid), 'nonediting teacher catalog denied');
    \core\session\manager::set_user($users['editingteacher']);
    $catalog = static fn() => \local_videoq\course_catalog::sources($courseid);
    $check(array_column($catalog(), 'cmid') === $sources, 'catalog initial core activity order');
    $actions = \local_videoq\course_catalog::actions($courseid);
    $check(isset($actions['catalogupload'], $actions['catalogorganize'], $actions['catalogcourseedit'], $actions['catalogparticipants']), 'permitted canonical course workflows');
    $check(!isset($actions['catalogcoursecreate'], $actions['catalogcoursedelete']), 'course manager has no category creation or deletion grant');
    foreach (['local/videoq:managecontent', 'moodle/course:manageactivities'] as $capability) {
        assign_capability($capability, CAP_PREVENT, $roles['editingteacher'], $context->id, true); accesslib_clear_all_caches(true);
        $rejects(fn() => $catalog(), 'catalog denies revoked ' . $capability);
        unassign_capability($capability, $roles['editingteacher'], $context->id); accesslib_clear_all_caches(true);
    }
    assign_capability('mod/resource:addinstance', CAP_PREVENT, $roles['editingteacher'], $context->id, true); accesslib_clear_all_caches(true);
    $check(!isset(\local_videoq\course_catalog::actions($courseid)['catalogupload']), 'upload action follows current module capability');
    unassign_capability('mod/resource:addinstance', $roles['editingteacher'], $context->id); accesslib_clear_all_caches(true);
    $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 0], '*', MUST_EXIST);
    moveto_module(get_coursemodule_from_id('resource', $sources[1], $courseid), $section, $sources[0]);
    rebuild_course_cache($courseid, true);
    $check(array_column($catalog(), 'cmid') === array_reverse($sources), 'catalog follows real core move');
    $check(array_column(\local_videoq\course_access::lessons($courseid), 'videoCmid') === array_reverse($sources), 'access catalog follows core order');
    course_create_sections_if_missing($courseid, 1);
    $sectionone = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 1], '*', MUST_EXIST);
    moveto_module(get_coursemodule_from_id('resource', $sources[1], $courseid), $sectionone);
    rebuild_course_cache($courseid, true);
    $check(array_column($catalog(), 'cmid') === $sources, 'catalog follows section changes');
    $snapshot = \local_videoq\materials_service::manifest($courseid, $typeid, false, true);
    foreach ($snapshot['resources'] as $item) {
        // Acknowledgement is a protocol-readiness fixture, not evidence of AI processing.
        \local_videoq\materials_service::acknowledge($courseid, $typeid, ['externalId' => $item['externalId'],
            'generation' => $item['manifest']['generation'], 'status' => 'completed']);
    }
    $row = $DB->get_record('local_videoq_sync', ['cmid' => $sources[0]], '*', MUST_EXIST);
    $launch = static function(string $archetype) use ($row, $courseid, $users): array {
        // Real service requests get fresh per-request dynamic module visibility.
        // This CLI checker changes roles repeatedly within one PHP process.
        get_fast_modinfo($courseid, 0, true);
        $proof = \local_videoq\course_access::launch((int)$row->ltiinstance, (int)$users[$archetype]->id);
        return \local_videoq\course_access::resolve($proof['custom_videoq_access'] ?? '', (string)$users[$archetype]->id,
            (string)$courseid, (string)$row->ltiinstance);
    };
    $check(count($launch('student')['resources']) === 2, 'learner visible-source positive control');
    $job = \local_videoq\materials_service::export($courseid, $typeid, ['externalId' => (string)$row->cmid,
        'generation' => (int)$row->generation, 'uploadUrl' => 'https://storage.example.invalid/fixture/source.mp4',
        'idempotencyKey' => str_repeat('a', 32), 'includeUserVisibility' => true]);
    $taskrow = $DB->get_record_select('task_adhoc', 'customdata LIKE ?', ['%' . $job['jobId'] . '%'], '*', MUST_EXIST);
    $taskdata = json_decode($taskrow->customdata, true, 16, JSON_THROW_ON_ERROR);
    $check(($taskdata['userVisibility'] ?? false) === true, 'export mode survives durable task boundary');
    set_coursemodule_visible($sources[0], 0);
    $hidden = \local_videoq\synchronizer::run((int)$row->id, true, true);
    $check($hidden['enabled'] && !$hidden['deleted'] && $hidden['generation'] > $row->generation, 'new backend keeps hidden source with fresh generation');
    $rejects(fn() => $launch('student'), 'hidden source denies learner');
    $check(count($launch('editingteacher')['resources']) === 2, 'teacher hidden-view rights retain resource and management');
    assign_capability('moodle/course:viewhiddenactivities', CAP_PREVENT, $roles['editingteacher'], $context->id, true);
    accesslib_clear_all_caches(true);
    $rejects(fn() => $launch('editingteacher'), 'hidden source denies teacher without core hidden-view capability');
    unassign_capability('moodle/course:viewhiddenactivities', $roles['editingteacher'], $context->id);
    accesslib_clear_all_caches(true);
    $old = \local_videoq\synchronizer::run((int)$row->id, true);
    $check(!$old['enabled'] && !$old['deleted'], 'old caller retains global visibility suppression');
    $hidden = \local_videoq\synchronizer::run((int)$row->id, true, true);
    $task = new \local_videoq\task\export_material(); $task->set_custom_data($taskdata); $task->execute();
    $check(\local_videoq\materials_service::status($courseid, $typeid, $job['jobId'])['status'] === 'stale', 'visibility change cancels old queued export before network');
    update_course((object)['id' => $courseid, 'visible' => 0]);
    $coursehidden = \local_videoq\synchronizer::run((int)$row->id, true, true);
    $check($coursehidden['enabled'] && !$coursehidden['deleted'], 'hidden course is not a global disable for new backend');
    $rejects(fn() => $launch('student'), 'hidden course denies learner');
    $check(count($launch('editingteacher')['resources']) === 2, 'teacher hidden-course rights retain current source access');
    assign_capability('moodle/course:viewhiddencourses', CAP_PREVENT, $roles['editingteacher'], $context->id, true);
    accesslib_clear_all_caches(true);
    $rejects(fn() => $launch('editingteacher'), 'hidden course denies teacher without core hidden-course capability');
    unassign_capability('moodle/course:viewhiddencourses', $roles['editingteacher'], $context->id);
    accesslib_clear_all_caches(true);
    update_course((object)['id' => $courseid, 'visible' => 1]);
    set_coursemodule_visible($sources[0], 1);
    $restored = \local_videoq\synchronizer::run((int)$row->id, true, true);
    $check($restored['enabled'], 'restored source stays enabled');
    $DB->set_field('local_videoq_sync', 'status', 'completed', ['courseid' => $courseid]);
    $check(count($launch('student')['resources']) === 2, 'restored learner resource scope');
    // This authorization fixture does not exercise core recycle-bin backups.
    // Keep developer diagnostics enabled; recycle-bin backup acceptance is separate.
    set_config('coursebinenable', 0, 'tool_recyclebin');
    \local_videoq\synchronizer::disable($sources[0]);
    set_config('synctoken', '', 'local_videoq');
    $rejects(fn() => \local_videoq\synchronizer::run((int)$row->id), 'offline legacy backend still refuses outbound sync');
    $check(!$DB->record_exists('course_modules', ['id' => $row->lticmid]) &&
        !$DB->record_exists('course_modules', ['id' => $row->linkcmid]), 'offline backend cannot leave retired local launch activities');
    $disabled = \local_videoq\synchronizer::run((int)$row->id, true, true);
    $check(!$disabled['enabled'] && $disabled['deleted'], 'explicit disable still emits tombstone');
    $rejects(fn() => $launch('editingteacher'), 'teacher cannot bypass explicit disable');
    echo json_encode(['passed' => true, 'database' => $CFG->dbtype, 'checks' => $checks,
        'limits' => ['Real core File APIs, moves, roles and visibility; synthetic readiness acknowledgements, no browser/backend/AI processing or recycle-bin backup acceptance.']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    \core\session\manager::set_user(get_admin());
    if ($courseid) $DB->delete_records('local_videoq_sync', ['courseid' => $courseid]);
    foreach ($saved as $key => $value) {
        if ($value === false) unset_config($key, 'local_videoq'); else set_config($key, $value, 'local_videoq');
    }
    if ($recycle === false) unset_config('coursebinenable', 'tool_recyclebin'); else set_config('coursebinenable', $recycle, 'tool_recyclebin');
}
