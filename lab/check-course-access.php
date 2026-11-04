<?php
// Reversible negative permission tests in the isolated lab only. No proofs are printed.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Use only the isolated lab');
require_once($CFG->dirroot . '/course/lib.php');
$first = \local_videoq\lesson::find('lms-videoq');
$second = \local_videoq\lesson::find('course-review');
$hidden = \local_videoq\lesson::find('course-hidden');
$cm = get_coursemodule_from_id('lti', $first['ltiCmid']);
$learner = $DB->get_record('user', ['username' => 'learner'], '*', MUST_EXIST);
$teacher = $DB->get_record('user', ['username' => 'teacher'], '*', MUST_EXIST);
$proof = \local_videoq\course_access::launch((int)$cm->instance, (int)$learner->id)['custom_videoq_access'];
$resolve = fn($token = null, $subject = null, $course = null, $resource = null) => \local_videoq\course_access::resolve($token ?? $proof, $subject ?? (string)$learner->id, $course ?? (string)$cm->course, $resource ?? (string)$cm->instance);
$assert = function($value, $name) { if (!$value) throw new Exception('Failed: ' . $name); };
$denied = function($fn) use ($assert) { $failed = false; try { $fn(); } catch (Throwable $e) { $failed = true; } $assert($failed, 'expected rejection'); };
$ids = fn($response) => array_column($response['resources'], 'lesson');
$assert(in_array('course-review', $ids($resolve()), true), 'second video allowed');
$assert(!in_array('course-hidden', $ids($resolve()), true), 'hidden video excluded');
$teacherproof = \local_videoq\course_access::launch((int)$cm->instance, (int)$teacher->id)['custom_videoq_access'];
$assert(in_array('course-hidden', $ids($resolve($teacherproof, (string)$teacher->id)), true), 'teacher visibility');
$denied(fn() => $resolve($proof . 'f'));
$denied(fn() => $resolve(null, (string)$teacher->id));
$denied(fn() => $resolve(null, null, '9999'));
$denied(fn() => $resolve(null, null, null, '9999'));
$payload = json_decode(hex2bin(explode('.', $proof)[0]), true);
$payload['exp'] = time() - 1;
$encoded = bin2hex(json_encode($payload));
$expired = $encoded . '.' . hash_hmac('sha256', $encoded, hex2bin(get_config('local_videoq', 'accesssigningkey')));
$denied(fn() => $resolve($expired));
$secondcm = $DB->get_record('course_modules', ['id' => $second['videoCmid']], '*', MUST_EXIST);
$secondlti = $DB->get_record('course_modules', ['id' => $second['ltiCmid']], '*', MUST_EXIST);
$enrolment = $DB->get_record_sql('SELECT ue.* FROM {user_enrolments} ue JOIN {enrol} e ON e.id=ue.enrolid WHERE e.courseid=? AND ue.userid=?', [$cm->course, $learner->id], MUST_EXIST);
try {
    set_coursemodule_visible($second['videoCmid'], 0);
    $assert(!in_array('course-review', $ids($resolve()), true), 'video hide immediately revokes');
    set_coursemodule_visible($second['videoCmid'], $secondcm->visible);
    set_coursemodule_visible($second['ltiCmid'], 0);
    $assert(!in_array('course-review', $ids($resolve()), true), 'LTI hide immediately revokes');
    set_coursemodule_visible($second['ltiCmid'], $secondlti->visible);
    $DB->set_field('course_modules', 'availability', json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + 86400]], 'showc' => [true]]), ['id' => $second['videoCmid']]);
    rebuild_course_cache($cm->course, true);
    $assert(!in_array('course-review', $ids($resolve()), true), 'future availability excluded');
    $DB->set_field('course_modules', 'availability', $secondcm->availability, ['id' => $second['videoCmid']]);
    rebuild_course_cache($cm->course, true);
    $DB->set_field('user', 'suspended', 1, ['id' => $learner->id]);
    $denied(fn() => $resolve());
    $DB->set_field('user', 'suspended', $learner->suspended, ['id' => $learner->id]);
    $DB->set_field('user_enrolments', 'status', 1, ['id' => $enrolment->id]);
    $denied(fn() => $resolve());
} finally {
    $DB->set_field('user', 'suspended', $learner->suspended, ['id' => $learner->id]);
    $DB->set_field('user_enrolments', 'status', $enrolment->status, ['id' => $enrolment->id]);
    $DB->set_field('course_modules', 'availability', $secondcm->availability, ['id' => $second['videoCmid']]);
    set_coursemodule_visible($second['videoCmid'], $secondcm->visible);
    set_coursemodule_visible($second['ltiCmid'], $secondlti->visible);
    rebuild_course_cache($cm->course, true);
}
echo "Course access: valid scope, hidden activities, date restriction, tampering, binding mismatch, account suspension and enrolment suspension passed.\n";
