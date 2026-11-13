<?php
/** Execute the actual native page with an installed Moodle learner and HTML renderer. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
define('PREFERRED_RENDERER_TARGET', 'general');
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site mount');
require __DIR__ . '/support/matrix-diagnostics.php';
if (($argv[2] ?? '') !== '--render') {
    if (isset($argv[2])) throw new RuntimeException('Unknown native page fixture mode');
    // Moodle may flush output buffers when starting a full page. Capture the
    // whole child process instead of mixing its HTML with this JSON report.
    $output = tempnam(sys_get_temp_dir(), 'videoq-native-page-');
    $errors = tempnam(sys_get_temp_dir(), 'videoq-native-page-');
    if ($output === false || $errors === false) throw new RuntimeException('Cannot create private render capture');
    try {
        $process = proc_open([PHP_BINARY, '-d', 'max_input_vars=5000', '-d', 'memory_limit=256M',
            __FILE__, '/site', '--render'], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $errors, 'w'],
        ], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start native page render');
        $status = proc_close($process);
        $stderr = file_get_contents($errors);
        if ($status !== 0 || $stderr !== '') {
            foreach (matrix_native_page_metadata($stderr) as $metadata) {
                fwrite(STDERR, 'VIDEOQ_NATIVE_PAGE_FAILURE ' . json_encode($metadata, JSON_THROW_ON_ERROR) . "\n");
            }
            throw new RuntimeException('Native page child did not complete cleanly');
        }
        $html = file_get_contents($output);
        // This lifecycle fixture has no VideoQ API. The linked-course viewer
        // must render its unavailable state rather than fabricate a live chat.
        $string = [];
        require dirname(__DIR__) . '/plugins/local/videoq/lang/en/local_videoq.php';
        if (!str_contains($html, 'class="local-videoq-viewer"')
                || str_contains($html, 'data-videoq-native="1"') || str_contains($html, 'id="videoq-native-form"')
                || !str_contains($html, $string['nativeunavailable'])
                || str_contains($html, '<iframe') || matrix_has_diagnostics($html)
                || str_contains($html, 'Cannot modify header information')) {
            throw new RuntimeException('Actual Moodle native page did not render its authenticated workspace cleanly');
        }
        echo json_encode(['passed' => true, 'nativePageRendered' => true, 'unavailableBackendRefused' => true,
            'limits' => ['Actual PHP page and HTML renderer; synthetic media bytes, no browser/API/model execution.']],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    } finally {
        unlink($output);
        unlink($errors);
    }
    exit;
}
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db'
        || $CFG->dbname !== 'moodleaudit_upgrade') {
    throw new RuntimeException('Refusing to mutate an unrelated Moodle database');
}
// Avoid replacing the original exception with a secondary HTML error page.
set_exception_handler(static function(Throwable $error): void {
    while (ob_get_level()) ob_end_clean();
    $sources = ['/lab/check-marketplace-native-page.php' => 'native-page-fixture.php',
        '/lib/pagelib.php' => 'pagelib.php', '/lib/classes/output/core_renderer.php' => 'core_renderer.php'];
    foreach (['view', 'classes/native_context', 'classes/native_api', 'classes/output/workspace',
            'classes/ui_strings', 'classes/source', 'classes/lesson'] as $path) {
        $sources['/local/videoq/' . $path . '.php'] = basename($path) . '.php';
    }
    $metadata = [];
    $kind = basename(str_replace('\\', '/', get_class($error)));
    if (in_array($kind, ['Error', 'TypeError', 'RuntimeException', 'coding_exception',
            'moodle_exception', 'dml_read_exception', 'dml_missing_record_exception'], true)) {
        $metadata['kind'] = $kind;
    }
    foreach (array_merge([['file' => $error->getFile(), 'line' => $error->getLine()]], $error->getTrace()) as $frame) {
        foreach ($sources as $suffix => $source) {
            if (isset($frame['file'], $frame['line']) && str_ends_with($frame['file'], $suffix)) {
                $metadata += ['source' => $source, 'line' => (int)$frame['line']];
                break 2;
            }
        }
    }
    if ($error instanceof moodle_exception && in_array($error->errorcode,
            ['codingerror', 'invalidmodules', 'unknownlesson', 'nativeaccessdenied', 'nativeunavailable'], true)) {
        $metadata['code'] = $error->errorcode;
    }
    fwrite(STDERR, 'VIDEOQ_NATIVE_PAGE_FAILURE ' . json_encode($metadata, JSON_THROW_ON_ERROR) . "\n");
    exit(1);
});
$state = json_decode(file_get_contents($CFG->dataroot . '/videoq-capabilities-state.json'), true, 16, JSON_THROW_ON_ERROR);
$courseid = (int)$state['courses'][0];
$sourceid = (int)$state['materials']['completed']['cmid'];
$launchid = (int)$state['materials']['completed']['ltiid'];
$course = get_course($courseid);
$user = $DB->get_record('user', ['id' => $state['users']['student']], '*', MUST_EXIST);
if (!str_starts_with($course->shortname, 'VIDEOQ-CAPABILITY-PRIMARY-')
        || !str_starts_with($user->username, 'videoq-capability-student-')) {
    throw new RuntimeException('Owned native page fixture missing');
}
$binding = $DB->get_record('local_videoq_sync', ['cmid' => $sourceid, 'courseid' => $courseid,
    'ltiinstance' => $launchid, 'enabled' => 1], '*', MUST_EXIST);
$saved = [];
foreach (['typeid', 'apiurl'] as $name) $saved[$name] = get_config('local_videoq', $name);
$file = null;
try {
    set_config('typeid', $binding->typeid, 'local_videoq');
    set_config('apiurl', 'https://videoq.example.invalid', 'local_videoq');
    $file = get_file_storage()->create_file_from_string((object)[
        'contextid' => context_module::instance($sourceid)->id, 'component' => 'mod_resource',
        'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'native-render.mp4',
        'mimetype' => 'video/mp4', 'sortorder' => 1,
    ], 'Synthetic page-render fixture; no playable video or AI response.');
    \core\session\manager::set_user($user);
    $_GET = ['course' => $courseid, 'video' => $sourceid];
    require $CFG->dirroot . '/local/videoq/view.php';
} finally {
    if ($file) $file->delete();
    foreach ($saved as $name => $value) {
        if ($value === false) unset_config($name, 'local_videoq'); else set_config($name, $value, 'local_videoq');
    }
}
