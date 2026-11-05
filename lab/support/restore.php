<?php
declare(strict_types=1);
require_once __DIR__.'/browser.php';
require_once __DIR__.'/stream.php';
use VideoQLab\Browser;
use function VideoQLab\{ensure,rows,wait,answer,docker,container};
function restore_fixture(string $action, ?string $name = null): array {
    $output = docker(['exec', container(), 'php', '/lab/live-restore-fixture.php', $action, ...($name ? [$name] : [])]);
    $offset = strpos($output, '{"run":');
    ensure($offset !== false, 'Missing restore fixture result');
    return json_decode(substr($output, $offset), true, 32, JSON_THROW_ON_ERROR);
}
function restore_resource(int $cmid, string $installation = 'moodle-sync-lab'): ?array {
    return rows("SELECT r.video_id,r.course_id,v.file,v.status,length(v.transcript) AS transcript_chars,
        (SELECT count(*) FROM scene_embeddings e WHERE e.video_id=r.video_id) AS scenes FROM lms_resources r
        LEFT JOIN videos v ON v.id=r.video_id WHERE r.installation_id=$1 AND r.external_id=$2", [$installation, (string)$cmid])[0] ?? null;
}
function restored_indexed(int $cmid, string $installation = 'moodle-sync-lab', string $database = 'moodle'): array {
    return wait(static function() use ($cmid, $installation, $database) {
        $row = restore_resource($cmid, $installation);
        ensure(!$row || $row['status'] !== 'error', 'Restored video processing failed');
        $local = rows('SELECT status FROM mdl_local_videoq_sync WHERE cmid=$1', [$cmid], $database)[0] ?? null;
        return $row && $row['status'] === 'completed' && $row['transcript_chars'] && $row['scenes'] &&
            ($local['status'] ?? '') === 'completed' ? $row : null;
    }, 900, 2000);
}
function restored_deleted(int $cmid, string $installation = 'moodle-sync-lab', string $database = 'moodle'): bool {
    $resource = restore_resource($cmid, $installation);
    $local = rows('SELECT status FROM mdl_local_videoq_sync WHERE cmid=$1', [$cmid], $database)[0] ?? null;
    return (!$resource || $resource['video_id'] === null) && (!$local || $local['status'] === 'disabled');
}
function restore_question(Browser $browser, int $videoid): void {
    $browser->js('window.vqUpdates=0');
    $browser->tool(fn($f)=>$f->js('window.vqEvidence=[]'));
    $browser->ask('この講座で説明している画像の標本化と量子化の違いを短く教えてください。');
    $response = $browser->tool(fn($f) => $f->wait("return window.vqEvidence.findLast(event=>event.path.endsWith('/messages/stream'))", 150));
    ensure($response['status'] === 200, 'Restored course real question failed');
    $reply = answer($response['text']);
    ensure((bool)$reply['sources'], 'Real retrieval sources required');
    foreach ($reply['sources'] as $source) ensure($source['video_id'] === $videoid, 'Answer escaped the destination course');
    $browser->ready();
    ensure($browser->js('return window.vqUpdates') > 1, 'Real answer did not update native Moodle chat incrementally');
}
