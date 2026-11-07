<?php
/** Installed Moodle URL identity, permissions and source lifecycle; no provider or AI processing. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site fixture');
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
foreach (['typeid', 'apiurl', 'servicemode'] as $key) $saved[$key] = get_config('local_videoq', $key);
$recycle = get_config('tool_recyclebin', 'coursebinenable');
$checks = [];
$check = static function(bool $valid, string $label) use (&$checks): void {
    if (!$valid) throw new RuntimeException('YouTube source check failed: ' . $label);
    $checks[] = $label;
};
$rejects = static function(callable $operation, string $label) use ($check): void {
    $denied = false; try { $operation(); } catch (Throwable) { $denied = true; }
    $check($denied, $label);
};
$courseid = 0;
try {
    $suffix = bin2hex(random_bytes(6));
    $course = create_course((object)['fullname' => 'Owned YouTube authorization ' . $suffix,
        'shortname' => 'VIDEOQ-YOUTUBE-' . $suffix, 'category' => 1]);
    $courseid = (int)$course->id; $context = context_course::instance($courseid);
    $manual = enrol_get_plugin('manual');
    $enrol = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
    if (!$enrol) $enrol = $DB->get_record('enrol', ['id' => $manual->add_default_instance($course)], '*', MUST_EXIST);
    $DB->set_field('enrol', 'customint1', ENROL_DO_NOT_SEND_EMAIL, ['id' => $enrol->id]); $enrol->customint1 = ENROL_DO_NOT_SEND_EMAIL;
    foreach (['student', 'editingteacher'] as $archetype) {
        $role = $DB->get_record('role', ['archetype' => $archetype], '*', MUST_EXIST);
        $userid = user_create_user((object)['username' => 'videoq-yt-' . $archetype . '-' . $suffix,
            'firstname' => 'Synthetic', 'lastname' => $archetype, 'email' => $archetype . '@example.invalid',
            'password' => 'Only-fixture-123!', 'auth' => 'manual', 'confirmed' => 1]);
        $manual->enrol_user($enrol, $userid, $role->id);
        $users[$archetype] = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $roles[$archetype] = (int)$role->id;
    }
    $typeid = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'Owned YouTube ' . $suffix, 'lti_toolurl' => 'https://videoq.example.invalid/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1, 'lti_contentitem' => 0,
        'lti_initiatelogin' => 'https://videoq.example.invalid/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.example.invalid/api/lti/launch', 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
    ]);
    set_config('typeid', $typeid, 'local_videoq'); set_config('apiurl', 'https://videoq.example.invalid', 'local_videoq');
    set_config('servicemode', 'lti', 'local_videoq');
    $common = ['course' => $courseid, 'section' => 0, 'visible' => 1, 'intro' => '', 'introformat' => FORMAT_HTML];
    $url = add_moduleinfo((object)array_merge($common, ['modulename' => 'url',
        'module' => $DB->get_field('modules', 'id', ['name' => 'url']), 'name' => 'Owned source URL',
        'externalurl' => 'https://youtu.be/abcDEF123_-', 'display' => 0, 'printintro' => 0, 'parameters' => []]), $course);
    $lti = add_moduleinfo((object)array_merge($common, ['modulename' => 'lti',
        'module' => $DB->get_field('modules', 'id', ['name' => 'lti']), 'name' => 'Owned source transport',
        'typeid' => $typeid, 'toolurl' => 'https://videoq.example.invalid/api/lti/launch',
        'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, 'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0,
        'instructorchoicesendname' => 0, 'instructorchoicesendemailaddr' => 0, 'instructorchoiceacceptgrades' => 0]), $course);
    $link = add_moduleinfo((object)array_merge($common, ['modulename' => 'url',
        'module' => $DB->get_field('modules', 'id', ['name' => 'url']), 'name' => 'Owned source shortcut',
        'externalurl' => $CFG->wwwroot . '/local/videoq/view.php?course=' . $courseid . '&video=' . $url->coursemodule,
        'display' => 0, 'printintro' => 0, 'parameters' => []]), $course);
    $cmid = (int)$url->coursemodule;
    // Install a synthetic binding/readiness receipt. This bypasses enable's
    // backend discovery deliberately; it cannot prove real import/processing.
    $id = $DB->insert_record('local_videoq_sync', (object)['sourcekind' => 'youtube', 'youtubeid' => 'abcDEF123_-',
        'typeid' => $typeid, 'cmid' => $cmid, 'courseid' => $courseid, 'lticmid' => $lti->coursemodule,
        'ltiinstance' => $lti->instance, 'linkcmid' => $link->coursemodule, 'generation' => 5,
        'enabled' => 1, 'status' => 'queued', 'timemodified' => time()]);
    $snapshot = \local_videoq\materials_service::manifest($courseid, $typeid, false, true, true);
    $manifest = $snapshot['resources'][0]['manifest'];
    $check($manifest['kind'] === 'youtube' && $manifest['youtubeVideoId'] === 'abcDEF123_-' && $manifest['enabled']
        && !isset($manifest['file']), 'new caller receives typed provider identity without a File');
    $check(\local_videoq\materials_service::manifest($courseid, $typeid)['resources'] === [], 'old caller never receives YouTube manifests');
    \local_videoq\materials_service::acknowledge($courseid, $typeid, ['externalId' => (string)$cmid,
        'generation' => $manifest['generation'], 'status' => 'completed']);
    $proof = \local_videoq\course_access::launch((int)$lti->instance, (int)$users['student']->id);
    $access = static function(bool $include = true) use ($proof, $users, $courseid, $lti): array {
        get_fast_modinfo($courseid, 0, true);
        return \local_videoq\course_access::resolve($proof['custom_videoq_access'], (string)$users['student']->id,
            (string)$courseid, (string)$lti->instance, $include);
    };
    $check(count($access()['resources']) === 1, 'enrolled learner can read current URL source');
    $rejects(fn() => $access(false), 'old permission caller cannot read a YouTube source');
    $check(\local_videoq\restoration::describe($cmid) === ['role' => 'source', 'enabled' => 1, 'sourcekind' => 'youtube'],
        'backup marker contains portable kind and opt-in without remote IDs');
    $sourcecontext = context_module::instance($cmid);
    // Core mod_url grants view to the authenticated-user role. A prevent on
    // student alone does not revoke that other grant; a prohibit does.
    assign_capability('mod/url:view', CAP_PREVENT, $roles['student'], $sourcecontext->id, true);
    accesslib_clear_all_caches(true);
    $check(has_capability('mod/url:view', $sourcecontext, $users['student'])
        && count($access()['resources']) === 1, 'another current Moodle role still grants URL view');
    assign_capability('mod/url:view', CAP_PROHIBIT, $roles['student'], $sourcecontext->id, true);
    accesslib_clear_all_caches(true);
    $check(!has_capability('mod/url:view', $sourcecontext, $users['student'])
        && has_capability('mod/url:view', $sourcecontext, $users['editingteacher']), 'fixture revokes only the learner URL view');
    $rejects(fn() => $access(), 'same proof denies revoked mod/url view');
    unassign_capability('mod/url:view', $roles['student'], $sourcecontext->id); accesslib_clear_all_caches(true);
    $check(count($access()['resources']) === 1, 'same proof follows restored URL view');
    \core\session\manager::set_user($users['editingteacher']);
    $check(array_column(\local_videoq\course_catalog::sources($courseid), 'cmid') === [$cmid], 'catalog excludes generated URL shortcuts');
    assign_capability('local/videoq:managecontent', CAP_PREVENT, $roles['editingteacher'], $context->id, true);
    accesslib_clear_all_caches(true);
    $before = $DB->get_record('local_videoq_sync', ['id' => $id], '*', MUST_EXIST);
    foreach (['enable', 'disable', 'retry'] as $action) {
        $rejects(fn() => \local_videoq\synchronizer::$action($cmid), 'URL ' . $action . ' denies revoked content capability');
    }
    $check($DB->get_record('local_videoq_sync', ['id' => $id], '*', MUST_EXIST) == $before, 'denied URL mutations preserve exact binding');
    unassign_capability('local/videoq:managecontent', $roles['editingteacher'], $context->id); accesslib_clear_all_caches(true);
    set_coursemodule_visible($cmid, 0);
    $rejects(fn() => $access(), 'same proof denies hidden source URL');
    set_coursemodule_visible($cmid, 1);
    $DB->set_field('url', 'externalurl', 'https://youtu.be/xyzUVW987_-', ['id' => $url->instance]);
    $rejects(fn() => $access(), 'canonical URL edit denies old provider identity even before observer/sync');
    $updated = \local_videoq\synchronizer::run((int)$id, true, true);
    $check($updated['youtubeVideoId'] === 'xyzUVW987_-' && $updated['generation'] > $manifest['generation'],
        'source resync records current ID in a fresh generation');
    \local_videoq\materials_service::acknowledge($courseid, $typeid, ['externalId' => (string)$cmid,
        'generation' => $updated['generation'], 'status' => 'completed']);
    $check($access()['resources'][0]['generation'] === $updated['generation'], 'fresh permission scope carries current source generation');
    $DB->set_field('url', 'externalurl', 'https://unrelated.invalid/', ['id' => $url->instance]);
    $rejects(fn() => $access(), 'invalid source URL cannot retain learning access');
    $check(\local_videoq\source_management::can_manage($cmid), 'invalidated URL remains manageable for disable');
    $check(array_column(\local_videoq\course_catalog::sources($courseid), 'cmid') === [$cmid], 'invalid bound source remains in management catalog');
    set_config('coursebinenable', 0, 'tool_recyclebin');
    \local_videoq\synchronizer::disable($cmid);
    $disabled = \local_videoq\synchronizer::run((int)$id, true, true);
    $check($disabled['kind'] === 'youtube' && !$disabled['enabled'] && $disabled['deleted']
        && $disabled['youtubeVideoId'] === 'xyzUVW987_-', 'disabled invalid source retains typed deletion identity');
    $check($DB->record_exists('course_modules', ['id' => $cmid]), 'integration disable preserves the core URL activity');
    course_delete_module($cmid, false);
    $deleted = \local_videoq\synchronizer::run((int)$id, true, true);
    $check(!$deleted['enabled'] && $deleted['kind'] === 'youtube' && $deleted['youtubeVideoId'] === 'xyzUVW987_-',
        'deleted core URL retains a typed remote cleanup tombstone');
    echo json_encode(['passed' => true, 'database' => $CFG->dbtype, 'checks' => $checks,
        'limits' => ['Synthetic binding/readiness, real Moodle URL/role/event APIs. No backend discovery, provider/AI, browser login, full archive/recycle-bin restore acceptance.']],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    \core\session\manager::set_user(get_admin());
    if ($courseid) $DB->delete_records('local_videoq_sync', ['courseid' => $courseid]);
    foreach ($saved as $key => $value) {
        if ($value === false) unset_config($key, 'local_videoq'); else set_config($key, $value, 'local_videoq');
    }
    if ($recycle === false) unset_config('coursebinenable', 'tool_recyclebin'); else set_config('coursebinenable', $recycle, 'tool_recyclebin');
}
