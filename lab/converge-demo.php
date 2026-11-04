<?php
// Upgrade the original silent demo sources to real spoken sources and one sync authority.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Lab only');
require_once($CFG->dirroot . '/course/lib.php');
\core\session\manager::set_user(get_admin());
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-LTI-LAB'], '*', MUST_EXIST);
$manual = \local_videoq\lesson::parse(get_config('local_videoq', 'lessons'));
$results = [];
foreach (['lms-videoq' => 'chapter-one', 'course-review' => 'chapter-two'] as $alias => $name) {
    $item = $manual[$alias];
    $cm = get_coursemodule_from_id('resource', $item['videoCmid'], $course->id, false, MUST_EXIST);
    if (!$DB->record_exists('local_videoq_sync', ['cmid' => $cm->id])) {
        $fs = get_file_storage();
        $context = context_module::instance($cm->id);
        $source = '/tmp/' . $name . '.mp4';
        if (!is_file($source)) throw new Exception('Spoken fixture missing');
        // Preserve the former original fixture outside Moodle for local rollback.
        $old = \local_videoq\synchronizer::video_file((int)$cm->id);
        $old->copy_content_to('/tmp/videoq-before-' . $cm->id . '.mp4');
        $transaction = $DB->start_delegated_transaction();
        $fs->delete_area_files($context->id, 'mod_resource', 'content', 0);
        $fs->create_file_from_pathname(['contextid' => $context->id, 'component' => 'mod_resource',
            'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => $name . '.mp4',
            'mimetype' => 'video/mp4', 'sortorder' => 1], $source);
        $DB->set_field('resource', 'intro', $alias === 'lms-videoq' ? 'LMSとVideoQの役割を説明する音声入り教材です。' : '講座を横断した復習について説明する音声入り教材です。', ['id' => $cm->instance]);
        $transaction->allow_commit();
        \local_videoq\synchronizer::enable((int)$cm->id);
    }
    $row = $DB->get_record('local_videoq_sync', ['cmid' => $cm->id], '*', MUST_EXIST);
    \local_videoq\synchronizer::run((int)$row->id);
    $results[] = ['alias' => $alias, 'sourceCmid' => (int)$cm->id, 'ltiCmid' => (int)$row->lticmid,
        'lesson' => 'sync_' . $cm->id, 'file' => $name . '.mp4'];
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
