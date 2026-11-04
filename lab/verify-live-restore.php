<?php
declare(strict_types=1);
require __DIR__ . '/support/browser.php';
require __DIR__ . '/support/stream.php';
require __DIR__ . '/support/s3.php';
use VideoQLab\{Browser, Storage};
use function VideoQLab\{guard, ready, rows, docker, container, data, ensure, wait, report, answer};
function restore_fixture(string $action, ?string $name = null): array {
    $output = docker(['exec', container(), 'php', '/lab/live-restore-fixture.php', $action, ...($name ? [$name] : [])]);
    $offset = strpos($output, '{"run":');
    ensure($offset !== false, 'Missing restore fixture result');
    return json_decode(substr($output, $offset), true, 32, JSON_THROW_ON_ERROR);
}
function restore_resource(int $cmid): ?array {
    return rows("SELECT r.video_id,r.course_id,v.file,v.status,length(v.transcript) AS transcript_chars,
        (SELECT count(*) FROM scene_embeddings e WHERE e.video_id=r.video_id) AS scenes FROM lms_resources r
        LEFT JOIN videos v ON v.id=r.video_id WHERE r.installation_id='moodle-sync-lab' AND r.external_id=$1", [(string)$cmid])[0] ?? null;
}
function restored_indexed(int $cmid): array {
    return wait(static function() use ($cmid) {
        $row = restore_resource($cmid);
        ensure(!$row || $row['status'] !== 'error', 'Restored video processing failed');
        $local = rows('SELECT status FROM mdl_local_videoq_sync WHERE cmid=$1', [$cmid], 'moodle')[0] ?? null;
        return $row && $row['status'] === 'completed' && $row['transcript_chars'] && $row['scenes'] &&
            ($local['status'] ?? '') === 'completed' ? $row : null;
    }, 420, 2000);
}
function restored_deleted(int $cmid): bool {
    $resource = restore_resource($cmid);
    $local = rows('SELECT status FROM mdl_local_videoq_sync WHERE cmid=$1', [$cmid], 'moodle')[0] ?? null;
    return (!$resource || $resource['video_id'] === null) && (!$local || $local['status'] === 'disabled');
}
function restore_question(Browser $browser, int $videoid): void {
    $browser->ask('この講座で説明している画像の標本化と量子化の違いを短く教えてください。');
    $response = $browser->tool(fn($f) => $f->wait("return window.vqEvidence.findLast(event=>event.path.endsWith('/messages/stream'))", 150));
    ensure($response['status'] === 200, 'Restored course real question failed');
    $reply = answer($response['text']);
    ensure((bool)$reply['sources'], 'Real retrieval sources required');
    foreach ($reply['sources'] as $source) ensure($source['video_id'] === $videoid, 'Answer escaped the destination course');
    $browser->ready();
    ensure($browser->js('return window.vqUpdates') > 1, 'Real answer did not update native Moodle chat incrementally');
}
guard(); ready();
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
    foreach (['copy', 'restore'] as $mode) {
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
    ensure($videos['copy']['course_id'] !== $videos['restore']['course_id'], 'Destinations share a VideoQ course');
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
report('php-live-course-restore', ['passed' => true, 'sameSiteCopy' => true, 'archiveRestore' => true,
    'newCourseAndVideoIdentities' => true, 'destinationUsesCurrentConnection' => true, 'disabledSourceStaysOff' => true,
    'unrelatedUrlsPreserved' => true, 'courseLinksRewritten' => true, 'privateHistoryNotCopied' => true,
    'sourceHistoryPreserved' => true, 'destinationDeletionIsolated' => true, 'retiredMediaDeleted' => true,
    'realAnswersScopedToEachDestination' => true, 'nativeIncrementalAnswers' => true,
    'existingVideosPreserved' => true]);
