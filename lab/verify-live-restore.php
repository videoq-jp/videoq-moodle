<?php
declare(strict_types=1);
require __DIR__ . '/support/browser.php';
require __DIR__ . '/support/stream.php';
require __DIR__ . '/support/s3.php';
use VideoQLab\{Browser, Storage};
use function VideoQLab\{guard, ready, rows, docker, container, data, ensure, wait, report, answer};
require __DIR__ . '/support/restore.php';
guard(); ready();
$options = getopt('', ['modes:']); $modes = explode(',', $options['modes'] ?? 'copy,restore');
ensure(count($modes) > 0 && !array_diff($modes, ['copy', 'restore', 'partial', 'import']) && count(array_unique($modes)) === count($modes), 'Invalid restore modes');
$before = rows('SELECT id,file,status FROM videos ORDER BY id');
$existingCourses = array_column(rows('SELECT id FROM video_courses'), 'id');
$state = null; $videos = []; $files = []; $storage = new Storage();
docker(['cp', data() . '/acceptance/spoken-lesson.mp4', container() . ':/tmp/videoq-restore-acceptance.mp4']);
try {
    $state = restore_fixture('create');
    $videos['source'] = restored_indexed($state['sources']['source']);
    echo "Source material indexed.\n";
    $files[] = $videos['source']['file'];
    $b = new Browser();
    try {
        $b->login(); $b->course($state['courses']['source']);
        restore_question($b, $videos['source']['video_id']);
    } finally { $b->close(); }
    $history = rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id', [$videos['source']['course_id']]);
    ensure(count($history) > 0, 'Nonempty source history required');
    foreach ($modes as $mode) {
        $state = restore_fixture($mode);
        echo "Moodle {$mode} finished; waiting for independent indexing.\n";
        $videos[$mode] = restored_indexed($state['sources'][$mode]); $files[] = $videos[$mode]['file'];
        ensure($videos[$mode]['course_id'] !== $videos['source']['course_id'] &&
            $videos[$mode]['video_id'] !== $videos['source']['video_id'], 'Source and destination identities overlap');
        ensure($videos[$mode]['file'] !== $videos['source']['file'] && $storage->exists($videos[$mode]['file']), 'Destination must have its own physical media');
        ensure(!rows('SELECT id FROM chat_logs WHERE course_id=$1', [$videos[$mode]['course_id']]), 'Private history copied');
        $b = new Browser();
        try {
            $b->login(); $b->course($state['courses'][$mode]);
            ensure($b->js("return document.querySelectorAll('.answer').length") === 0, 'Restored UI contains source history');
            $lessons = $b->js("return JSON.parse(document.querySelector('.local-videoq-workspace').dataset.videoqLessons)");
            ensure(count($lessons) === 1 && $lessons[0]['lesson'] === 'sync_' . $state['sources'][$mode], 'Restored UI references source material');
            restore_question($b, $videos[$mode]['video_id']);
        } finally { $b->close(); }
        $current = restore_fixture('status')['sync'][$mode];
        ensure($current['lticmid'] > 0 && $current['linkcmid'] > 0 && $current['typeid'] === 2, 'Fresh destination transports missing');
        ensure(rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id', [$videos['source']['course_id']]) === $history, 'Source history changed');
        restore_fixture('delete', $mode);
        wait(fn() => restored_deleted($state['sources'][$mode]), 240, 2000);
        ensure(restore_resource($state['sources']['source'])['video_id'] === $videos['source']['video_id'], 'Deleting destination affected source');
        ensure($storage->exists($videos['source']['file']), 'Deleting destination removed source media');
        ensure(rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id', [$videos['source']['course_id']]) === $history, 'Deleting destination changed source history');
        echo "Independent {$mode} playback, history and deletion verified.\n";
    }
    $ids = array_column($videos, 'course_id');
    ensure(count(array_unique($ids)) === count($ids), 'Destinations share a VideoQ course');
} finally {
    // Persistent fixture state also permits safe recovery after a stopped test process.
    if ($state) {
        $state = restore_fixture('status');
        $fixtureCourses = [];
        foreach ($state['sources'] as $cmid) {
            $resource = restore_resource($cmid);
            if ($resource && $resource['course_id']) $fixtureCourses[] = $resource['course_id'];
        }
        foreach (array_reverse(array_keys($state['courses'])) as $name) restore_fixture('delete', $name);
        foreach ($state['sources'] as $cmid) wait(fn() => restored_deleted($cmid), 240, 2000);
        restore_fixture('cleanup');
        foreach ($state['sources'] as $cmid) rows("DELETE FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id=$1 AND video_id IS NULL", [(string)$cmid]);
        foreach (array_unique($fixtureCourses) as $courseid) {
            ensure(!in_array($courseid, $existingCourses, true), 'Refusing to clean an existing VideoQ course');
            ensure(!rows('SELECT video_id FROM video_course_members WHERE course_id=$1', [$courseid]), 'Fixture still has video members');
            rows("DELETE FROM lms_resources WHERE installation_id='moodle-sync-lab' AND course_id=$1 AND video_id IS NULL", [$courseid]);
            rows("DELETE FROM lms_courses WHERE installation_id='moodle-sync-lab' AND course_id=$1", [$courseid]);
            rows("DELETE FROM video_courses WHERE id=$1 AND user_id='lti-lab-owner'", [$courseid]);
        }
        ensure(rows('SELECT id,file,status FROM videos ORDER BY id') === $before, 'Existing videos changed');
    }
    docker(['exec', container(), 'php', '-r', "if(is_file('/tmp/videoq-restore-acceptance.mp4'))unlink('/tmp/videoq-restore-acceptance.mp4');"]);
}
foreach ($files as $file) wait(fn() => !$storage->exists($file), 180, 2000);
report(isset($options['modes']) ? 'php-live-course-restore-extended' : 'php-live-course-restore', ['passed' => true, 'modes' => $modes,
    'sameSiteCopy' => in_array('copy', $modes, true), 'archiveRestore' => in_array('restore', $modes, true),
    'newCourseAndVideoIdentities' => true, 'destinationUsesCurrentConnection' => true, 'disabledSourceStaysOff' => true,
    'unrelatedUrlsPreserved' => true, 'courseLinksRewritten' => true, 'privateHistoryNotCopied' => true,
    'sourceHistoryPreserved' => true, 'destinationDeletionIsolated' => true, 'retiredMediaDeleted' => true,
    'realAnswersScopedToEachDestination' => true, 'nativeIncrementalAnswers' => true,
    'existingVideosPreserved' => true]);
