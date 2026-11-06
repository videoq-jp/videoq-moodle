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
 * Displays the Moodle video player and VideoQ assistant.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
$courseid = optional_param('course', 0, PARAM_INT);
$videoid = optional_param('video', 0, PARAM_INT);
$lessonid = optional_param('lesson', '', PARAM_ALPHANUMEXT);
require_login();
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $PAGE->set_url('/local/videoq/view.php', ['course' => $courseid] + ($videoid ? ['video' => $videoid] : []));
    $PAGE->set_context(context_course::instance($courseid));
    $PAGE->set_title(get_string('courseassistant', 'local_videoq'));
    $PAGE->set_heading(format_string($course->fullname));
    $lesson = \local_videoq\lesson::for_course($courseid);
    if ($videoid) {
        $lesson = null;
        if ($DB->record_exists('local_videoq_sync', ['courseid' => $courseid, 'cmid' => $videoid, 'enabled' => 1])) {
            $lesson = \local_videoq\lesson::find('sync_' . $videoid);
        } else {
            foreach (\local_videoq\course_access::lessons($courseid) as $item) {
                if ($item['videoCmid'] === $videoid) {
                    $lesson = \local_videoq\lesson::find($item['id']);
                    break;
                }
            }
        }
        if (!$lesson) {
            throw new moodle_exception('unknownlesson', 'local_videoq');
        }
    }
    $courseentry = \local_videoq\course_entry::find($courseid);
    $entryavailable = $courseentry && $courseentry->enabled && \local_videoq\course_entry::valid($courseentry);
    if ($entryavailable) {
        $entrycm = get_fast_modinfo($course)->get_cm((int)$courseentry->lticmid);
        $entryavailable = $entrycm->uservisible && has_capability('mod/lti:view', context_module::instance($entrycm->id));
    }
    // The default selector prefers a ready lesson. With only pending sources,
    // use the course entry's standalone UI rather than a video-bound bridge.
    $pendingcourse = !$videoid && $lesson && isset($lesson['syncStatus']) && $lesson['syncStatus'] !== 'completed';
    if (!$lesson || ($pendingcourse && $entryavailable)) {
        $entry = $courseentry;
        $canmanageentry = $courseid !== SITEID
            && has_capability('local/videoq:managecontent', context_course::instance($courseid))
            && has_capability('moodle/course:manageactivities', context_course::instance($courseid));
        $launchentry = $entryavailable;
        if ($launchentry) {
            [$entrycourse, $entrycm] = get_course_and_cm_from_cmid((int)$entry->lticmid, 'lti');
            require_login($entrycourse, false, $entrycm);
            require_capability('mod/lti:view', context_module::instance($entrycm->id));
            $proof = \local_videoq\course_access::launch((int)$entry->ltiinstance, (int)$USER->id);
            \local_videoq\course_access::resolve(
                $proof['custom_videoq_access'] ?? '',
                (string)$USER->id,
                (string)$courseid,
                (string)$entry->ltiinstance,
                true
            );
            $PAGE->requires->css(new moodle_url(
                '/local/videoq/styles.css',
                ['rev' => substr(sha1_file(__DIR__ . '/styles.css'), 0, 12)]
            ));
        }
        header('Cache-Control: private, no-store');
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string($launchentry ? 'courseentryempty' : 'nocoursevideos', 'local_videoq'), 'info');
        if ($canmanageentry) {
            echo html_writer::link(
                new moodle_url('/local/videoq/catalog.php', ['course' => $courseid]),
                get_string('coursecatalog', 'local_videoq'),
                ['class' => 'btn btn-secondary mb-3']
            );
            echo html_writer::link(
                new moodle_url('/local/videoq/course.php', ['course' => $courseid]),
                get_string('managecourseentry', 'local_videoq'),
                ['class' => 'btn btn-secondary mb-3']
            );
        }
        if (has_capability('local/videoq:managecontent', context_course::instance($courseid))) {
            echo html_writer::tag('p', get_string('accountmanagementhelp', 'local_videoq'));
            echo html_writer::link(
                new moodle_url('/local/videoq/account.php', ['course' => $courseid]),
                get_string('accountmanagement', 'local_videoq'),
                ['target' => '_blank', 'rel' => 'noopener noreferrer', 'class' => 'btn btn-secondary mb-3']
            );
        }
        if ($launchentry) {
            if ($entry->status === 'completed') {
                echo html_writer::tag('iframe', '', ['class' => 'local-videoq-empty-frame',
                    'title' => get_string('courseassistant', 'local_videoq'),
                    'src' => (new moodle_url('/mod/lti/launch.php', ['id' => $entrycm->id]))->out(false),
                    'allow' => 'clipboard-write', 'referrerpolicy' => 'strict-origin-when-cross-origin']);
            } else {
                echo $OUTPUT->notification(get_string('courseentrypending', 'local_videoq'), 'info');
                echo html_writer::link(
                    new moodle_url('/local/videoq/view.php', ['course' => $courseid]),
                    get_string('courseentryrefresh', 'local_videoq'),
                    ['class' => 'btn btn-secondary']
                );
            }
        }
        echo $OUTPUT->footer();
        exit;
    }
    $lessonid = $lesson['id'];
} else {
    $lessonid = required_param('lesson', PARAM_ALPHANUMEXT);
    $PAGE->set_url('/local/videoq/view.php', ['lesson' => $lessonid]);
    $lesson = \local_videoq\lesson::find($lessonid);
}
$lessonid = $lesson['id'];
[$course, $lticm] = get_course_and_cm_from_cmid($lesson['ltiCmid'], 'lti');
[$videocourse, $videocm] = \local_videoq\source::course($lesson['videoCmid']);
if ($course->id !== $videocourse->id || ($courseid && (int)$course->id !== $courseid)) {
    throw new moodle_exception('invalidmodules', 'local_videoq');
}
// Core checks enrolment, hidden activities, conditional availability and deletion.
require_login($course, false, $videocm);
require_capability(\local_videoq\source::view_capability($videocm), context_module::instance($videocm->id));
require_login($course, false, $lticm);
require_capability('mod/lti:view', context_module::instance($lticm->id));
$entry = \local_videoq\course_entry::find((int)$course->id);
if (
    $entry && $entry->enabled && $entry->status === 'completed' && \local_videoq\course_entry::valid($entry)
    && (!isset($lesson['syncStatus']) || $lesson['syncStatus'] === 'completed')
) {
    $entrycm = get_fast_modinfo($course)->get_cm((int)$entry->lticmid);
    if ($entrycm->uservisible && has_capability('mod/lti:view', context_module::instance($entrycm->id))) {
        [$entrycourse, $lticm] = get_course_and_cm_from_cmid((int)$entry->lticmid, 'lti');
        require_login($entrycourse, false, $lticm);
        require_capability('mod/lti:view', context_module::instance($lticm->id));
    }
}
// Legacy per-video bookmarks lead to the same course assistant, preselecting the video.
if (!$courseid) {
    redirect(new moodle_url('/local/videoq/view.php', ['course' => $course->id, 'video' => $videocm->id]));
}
require_once($CFG->dirroot . '/mod/lti/locallib.php');
$lti = $DB->get_record('lti', ['id' => $lticm->instance], '*', MUST_EXIST);
$type = $DB->get_record('lti_types', ['id' => $lti->typeid], '*', MUST_EXIST);
if (
    $type->ltiversion !== LTI_VERSION_1P3 || !in_array(
        (int)lti_get_launch_container($lti, lti_get_type_type_config($type->id)),
        [LTI_LAUNCH_CONTAINER_EMBED, LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS],
        true
    )
) {
    throw new moodle_exception('invalidmodules', 'local_videoq');
}
$PAGE->activityheader->disable();
$pagetitle = $courseid ? get_string('courseassistant', 'local_videoq') : format_string($lesson['title']);
$PAGE->set_title($pagetitle);
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_heading($courseid ? format_string($course->fullname) : format_string($lesson['title']));
$PAGE->requires->css(new moodle_url('/local/videoq/styles.css', ['rev' => substr(sha1_file(__DIR__ . '/styles.css'), 0, 12)]));
$PAGE->requires->js(new moodle_url('/local/videoq/player.js', ['rev' => substr(sha1_file(__DIR__ . '/player.js'), 0, 12)]));
$PAGE->requires->js(new moodle_url('/local/videoq/chat.js', ['rev' => substr(sha1_file(__DIR__ . '/chat.js'), 0, 12)]));
$PAGE->requires->js(new moodle_url('/local/videoq/transcript.js', ['rev' => substr(sha1_file(__DIR__ . '/transcript.js'), 0, 12)]));
$PAGE->requires->js(new moodle_url('/local/videoq/qa.js', ['rev' => substr(sha1_file(__DIR__ . '/qa.js'), 0, 12)]));
$PAGE->requires->js(new moodle_url('/local/videoq/personal_history.js', [
    'rev' => substr(sha1_file(__DIR__ . '/personal_history.js'), 0, 12),
]));
$PAGE->requires->js(new moodle_url('/local/videoq/management.js', [
    'rev' => substr(sha1_file(__DIR__ . '/management.js'), 0, 12),
]));
$PAGE->requires->js(new moodle_url('/local/videoq/tags.js', [
    'rev' => substr(sha1_file(__DIR__ . '/tags.js'), 0, 12),
]));
$PAGE->requires->js(new moodle_url('/local/videoq/reports.js', [
    'rev' => substr(sha1_file(__DIR__ . '/reports.js'), 0, 12),
]));
$PAGE->requires->js_call_amd('core/auto_rows', 'init', ['#videoq-native-chat']);
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(format_string($course->shortname), new moodle_url('/course/view.php', ['id' => $course->id]));
$PAGE->navbar->add($pagetitle);
// The resource module serves its own file and enforces file access on every request.
$videourl = new moodle_url('/mod/resource/view.php', ['id' => $videocm->id, 'redirect' => 1]);
$launchurl = new moodle_url('/mod/lti/launch.php', ['id' => $lticm->id]);
$toolparts = parse_url($type->baseurl);
if (
    !$toolparts || ($toolparts['scheme'] ?? '') !== 'https' || empty($toolparts['host'])
        || isset($toolparts['user']) || isset($toolparts['pass'])
) {
    throw new moodle_exception('invalidmodules', 'local_videoq');
}
$toolorigin = 'https://' . strtolower($toolparts['host']) . (isset($toolparts['port'])
    && $toolparts['port'] !== 443 ? ':' . $toolparts['port'] : '');
header('Cache-Control: private, no-store');
echo $OUTPUT->header();
if (isset($lesson['syncStatus']) && $lesson['syncStatus'] !== 'completed' && $entryavailable) {
    echo html_writer::link(
        new moodle_url('/local/videoq/view.php', ['course' => $course->id]),
        get_string('courseassistant', 'local_videoq'),
        ['class' => 'btn btn-secondary mb-3']
    );
}

// Render only activities the current learner may view. API independently rechecks this on each question.
$scope = ['resources' => [['lesson' => $lessonid, 'title' => $lesson['title']]]];
$canmanage = has_capability('local/videoq:managecontent', context_course::instance($course->id))
    && has_capability('moodle/course:manageactivities', context_module::instance($videocm->id));
if ($canmanage || !isset($lesson['syncStatus']) || $lesson['syncStatus'] === 'completed') {
    $launchproof = \local_videoq\course_access::launch((int)$lti->id, (int)$USER->id);
    $scope = \local_videoq\course_access::resolve(
        $launchproof['custom_videoq_access'],
        (string)$USER->id,
        (string)$course->id,
        (string)$lti->id,
        true
    );
}
$catalog = [];
$managedsources = [];
foreach ($scope['resources'] as $allowed) {
    $item = \local_videoq\lesson::find($allowed['lesson']);
    $descriptor = \local_videoq\source::read((int)$item['videoCmid']);
    if (!$descriptor) {
        continue;
    }
    $media = $descriptor['file'] ?? null;
    $module = $descriptor['kind'] === 'youtube' ? 'url' : 'resource';
    if (isset($allowed['management'])) {
        $managedsources[] = ['cmid' => (int)$item['videoCmid'], 'title' => $allowed['title']];
    }
    $catalog[] = ['kind' => $descriptor['kind'], 'youtubeVideoId' => $descriptor['youtubeVideoId'] ?? null,
        'sourceGeneration' => (int)($allowed['generation'] ?? 0),
        'mime' => $media ? $media->get_mimetype() : 'video/mp4', 'lesson' => $item['id'], 'title' => $allowed['title'],
        'url' => $media ? (new moodle_url(
            '/mod/resource/view.php',
            ['id' => $item['videoCmid'], 'redirect' => 1]
        ))->out(false) : null,
        'activityUrl' => (new moodle_url('/mod/' . $module . '/view.php', ['id' => $item['videoCmid']]))->out(false)];
}
if (
    has_capability('local/videoq:managecontent', context_course::instance($course->id))
        && has_capability('moodle/course:manageactivities', context_course::instance($course->id))
) {
    echo html_writer::link(
        new moodle_url('/local/videoq/catalog.php', ['course' => $course->id]),
        get_string('coursecatalog', 'local_videoq'),
        ['class' => 'btn btn-secondary mb-3']
    );
}
echo html_writer::start_div('local-videoq-workspace', ['data-videoq-origin' => $toolorigin,
    'data-videoq-youtube-error' => get_string('youtubeplaybackerror', 'local_videoq'),
    'data-videoq-youtube-autoplay' => get_string('youtubeautoplay', 'local_videoq'),
    'data-videoq-source-updated' => get_string('sourceupdated', 'local_videoq'),
    'data-videoq-lessons' => json_encode($catalog, JSON_THROW_ON_ERROR), 'data-videoq-lesson' => $lessonid]);
echo html_writer::start_tag(
    'section',
    ['class' => 'local-videoq-panel local-videoq-video card',
     'aria-label' => get_string(
         'video',
         'local_videoq'
     )]
);
echo html_writer::start_div('card-body py-3');
echo html_writer::tag('label', get_string('video', 'local_videoq'), ['for' => 'videoq-lesson', 'class' => 'form-label']);
$options = [];
foreach ($catalog as $item) {
    $options[$item['lesson']] = $item['title'];
}
echo html_writer::select($options, 'videoq-lesson', $lessonid, false, ['id' => 'videoq-lesson', 'class' => 'form-select']);
echo html_writer::end_div();
echo html_writer::start_div('local-videoq-media mediaplugin mediaplugin_videojs', ['data-videoq-file' => '']);
$sourcefile = \local_videoq\synchronizer::video_file((int)$videocm->id);
echo html_writer::tag(
    'video',
    $sourcefile ? html_writer::empty_tag(
        'source',
        ['src' => $videourl->out(false),
        'type' => $sourcefile ? $sourcefile->get_mimetype() : 'video/mp4']
    ) : '',
    [
    'id' => 'videoq-player', 'class' => get_config('media_videojs', 'videocssclass') ?: 'video-js',
    'controls' => 'controls', 'playsinline' => 'playsinline', 'preload' => 'metadata',
    'data-setup-lazy' => json_encode([
        'fluid' => true, 'playbackRates' => [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2],
        'userActions' => ['hotkeys' => true], 'language' => current_language(),
    ]),
    'aria-label' => get_string('video', 'local_videoq'),
    ]
);
echo html_writer::end_div();
echo html_writer::start_div('local-videoq-youtube', ['data-videoq-youtube' => '', 'hidden' => 'hidden']);
echo html_writer::end_div();
echo html_writer::tag('p', '', ['data-videoq-player-status' => '', 'role' => 'status', 'class' => 'text-muted px-3']);
echo html_writer::link(
    new moodle_url('/mod/' . $videocm->modname . '/view.php', ['id' => $videocm->id]),
    get_string('sourceactivity', 'local_videoq'),
    ['data-videoq-player-activity' => '', 'class' => 'btn btn-link']
);
if ($managedsources) {
    echo html_writer::start_div('card-body py-3');
    echo html_writer::start_tag('details');
    echo html_writer::tag('summary', get_string('sourcematerialactions', 'local_videoq'));
    echo html_writer::start_tag('ul', ['class' => 'list-unstyled mt-3']);
    foreach ($managedsources as $source) {
        echo html_writer::start_tag('li', ['class' => 'mb-3']);
        echo html_writer::tag('p', s($source['title']), ['class' => 'fw-semibold mb-1']);
        $links = [
            [new moodle_url('/course/modedit.php', ['update' => $source['cmid']]), 'sourceedit'],
            [new moodle_url('/local/videoq/manage.php', ['cmid' => $source['cmid']]), 'sourcesync'],
            [new moodle_url('/course/view.php', ['id' => $course->id], 'module-' . $source['cmid']), 'sourcecourse'],
        ];
        foreach ($links as [$url, $label]) {
            echo html_writer::link(
                $url,
                get_string($label, 'local_videoq'),
                ['class' => 'btn btn-outline-secondary btn-sm me-2 mb-2']
            );
        }
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
    echo html_writer::tag('p', get_string('accountmanagementhelp', 'local_videoq'));
    echo html_writer::link(
        new moodle_url('/local/videoq/account.php', ['course' => $course->id]),
        get_string('accountmanagement', 'local_videoq'),
        ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank', 'rel' => 'noopener noreferrer']
    );
    echo html_writer::end_tag('details');
    echo html_writer::end_div();
}
echo html_writer::end_tag('section');
echo html_writer::start_tag(
    'section',
    ['class' => 'local-videoq-panel local-videoq-questions card',
     'aria-label' => get_string(
         'questions',
         'local_videoq'
     )]
);
echo html_writer::tag('h2', get_string('questions', 'local_videoq'), ['class' => 'visually-hidden']);
if (!$canmanage && isset($lesson['syncStatus']) && $lesson['syncStatus'] !== 'completed') {
    echo html_writer::tag('p', get_string('state_' . $lesson['syncStatus'], 'local_videoq'), ['role' => 'status']);
    echo html_writer::link($PAGE->url, get_string('refreshstatus', 'local_videoq'));
} else {
    $message = ['id' => '', 'text' => '', 'formattedtime' => userdate(time(), get_string('strftimetime', 'langconfig'))];
    if ($managedsources) {
        echo $OUTPUT->render_from_template('local_videoq/management', [
            'heading' => get_string('materialmanagement', 'local_videoq'),
            'unavailable' => get_string('materialmanagementunavailable', 'local_videoq'),
        ]);
    }
    echo $OUTPUT->render_from_template('local_videoq/reports', [
        'heading' => get_string('coursereports', 'local_videoq'),
        'unavailable' => get_string('coursereportsunavailable', 'local_videoq'),
    ]);
    echo $OUTPUT->render_from_template('local_videoq/tags', [
        'heading' => get_string('coursetags', 'local_videoq'),
        'unavailable' => get_string('coursetagsunavailable', 'local_videoq'),
    ]);
    echo $OUTPUT->render_from_template('local_videoq/personal_history', [
        'heading' => get_string('personalconversations', 'local_videoq'),
        'unavailable' => get_string('personalconversationsunavailable', 'local_videoq'),
    ]);
    echo $OUTPUT->render_from_template('local_videoq/qa', [
        'heading' => get_string('courseqa', 'local_videoq'),
        'unavailable' => get_string('courseqaunavailable', 'local_videoq'),
    ]);
    echo $OUTPUT->render_from_template('local_videoq/transcript', [
        'heading' => get_string('transcript', 'local_videoq'),
        'lesson' => get_string('transcriptlesson', 'local_videoq'),
        'refresh' => get_string('transcriptrefresh', 'local_videoq'),
        'more' => get_string('transcriptmore', 'local_videoq'),
        'empty' => get_string('transcriptempty', 'local_videoq'),
        'error' => get_string('transcripterror', 'local_videoq'),
        'seek' => get_string('chatseek', 'local_videoq'),
    ]);
    echo $OUTPUT->render_from_template('local_videoq/chat', [
    'heading' => get_string('coursequestions', 'local_videoq'),
    'connectionerror' => get_string('chatconnectionerror', 'local_videoq'),
    'seeksuffix' => get_string('chatseek', 'local_videoq'),
    'feedbackgood' => get_string('chatfeedbackgood', 'local_videoq'),
    'feedbackbad' => get_string('chatfeedbackbad', 'local_videoq'),
    'empty' => get_string('chatempty', 'local_videoq'),
    'connecting' => get_string('chatconnecting', 'local_videoq'),
    'hint' => get_string('chathint', 'local_videoq'),
    'messagemaxlength' => 4000, 'showemojipicker' => false,
    'questionmessage' => $message + ['fromloggedinuser' => true],
    'answermessage' => $message + ['fromloggedinuser' => false, 'userfrom' => [
        'fullname' => get_string(
            'assistant',
            'local_videoq'
        ),
             'profileimageurl' => $OUTPUT->image_url(
                 'i/course',
                 'core'
             )->out(false),

    ]],
    ]);
    echo html_writer::tag('iframe', '', [
    'id' => 'videoq-questions',
         'class' => 'local-videoq-transport',
         'tabindex' => '-1',
         'aria-hidden' => 'true',
         'title' => get_string(
             'questions',
             'local_videoq'
         ),

    'src' => $launchurl->out(false), 'allow' => 'clipboard-write',
    'referrerpolicy' => 'strict-origin-when-cross-origin',
    ]);
}
echo html_writer::end_tag('section');
echo html_writer::end_div();
echo $OUTPUT->footer();
