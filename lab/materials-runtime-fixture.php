<?php
// Guarded local lab fixture. Never switches an active existing connection.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new \RuntimeException('Lab only');
$state = json_decode((string)get_config('local_videoq_pull_spike', 'fixture'), true, 16, JSON_THROW_ON_ERROR);
if (!$state) throw new \RuntimeException('Disposable fixture required');
\core\session\manager::set_user(get_admin());
$backupfile = '/tmp/videoq-materials-settings-' . $state['run'] . '.json';
$names = ['servicemode', 'typeid', 'apiurl', 'serviceuploadtargets'];
$action = $argv[1] ?? '';
if ($action === 'start') {
    if (is_file($backupfile)) throw new \RuntimeException('Fixture already configured');
    $backup = [];
    foreach ($names as $name) $backup[$name] = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_videoq', 'name' => $name]);
    file_put_contents($backupfile, json_encode($backup, JSON_THROW_ON_ERROR)); chmod($backupfile, 0600);
    set_config('servicemode', 'lti', 'local_videoq');
    set_config('typeid', $state['typeid'], 'local_videoq');
    set_config('apiurl', 'https://videoq.localhost:18443', 'local_videoq');
    set_config('serviceuploadtargets', json_encode([['origin' => 'https://storage.videoq.localhost:18443', 'prefix' => '/lti-sync/media/videos/lti-lab-owner/']]), 'local_videoq');
    \local_videoq\synchronizer::enable($state['cmid']);
} else if ($action === 'exports') {
    foreach ($DB->get_records('local_videoq_exports', ['cmid' => $state['cmid'], 'typeid' => $state['typeid'], 'status' => 'queued']) as $job) {
        $task = new \local_videoq\task\export_material(); $task->set_custom_data(['jobid' => $job->jobid]); $task->execute();
        // This test executes the exact task class directly. Remove only its own queued dispatch.
        $DB->delete_records_select('task_adhoc', 'classname = ? AND customdata LIKE ?', ['\\local_videoq\\task\\export_material', '%' . $job->jobid . '%']);
    }
} else if ($action === 'restore') {
    if (is_file($backupfile)) {
        $backup = json_decode(file_get_contents($backupfile), true, 8, JSON_THROW_ON_ERROR);
        foreach ($names as $name) {
            if ($backup[$name] === false) unset_config($name, 'local_videoq');
            else set_config($name, $backup[$name], 'local_videoq');
        }
        unlink($backupfile);
        cache_helper::invalidate_by_definition('core', 'config', [], 'local_videoq');
    }
    $DB->delete_records('local_videoq_exports', ['cmid' => $state['cmid'], 'typeid' => $state['typeid']]);
    $DB->delete_records('local_videoq_sync', ['cmid' => $state['cmid']]);
}
echo json_encode(['done' => $action]);
