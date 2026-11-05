<?php
/** Read-only runtime check for the isolated Marketplace installation matrix. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
$site = $argv[1] ?? '';
if ($site !== '/site') {
    throw new RuntimeException('Use the isolated /site fixture mount');
}
require $site . '/config.php';
require $CFG->dirroot . '/version.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbname !== 'moodleaudit') {
    throw new RuntimeException('Refusing to inspect an unrelated Moodle site');
}
if ((int)$CFG->debug !== DEBUG_DEVELOPER || !$CFG->debugdisplay) {
    throw new RuntimeException('Developer debugging must be enabled');
}
$tables = ['local_videoq_sync', 'local_videoq_exports', 'local_videoq_assertions'];
$phase = $argv[2] ?? 'installed';
if ($phase === 'uninstalled') {
    foreach ($tables as $table) {
        if ($DB->get_manager()->table_exists($table)) {
            throw new RuntimeException('Plugin table remains after uninstall: ' . $table);
        }
    }
    foreach (['local_videoq', 'ltisource_videoq'] as $component) {
        if ($DB->record_exists('config_plugins', ['plugin' => $component])) {
            throw new RuntimeException('Plugin settings remain after uninstall');
        }
    }
    if (!$DB->record_exists('course', ['id' => SITEID])) {
        throw new RuntimeException('Core Moodle site was damaged');
    }
    echo json_encode(['passed' => true, 'phase' => $phase, 'database' => $CFG->dbtype,
        'tablesRemoved' => true, 'settingsRemoved' => true, 'coreSitePreserved' => true], JSON_PRETTY_PRINT) . "\n";
    exit;
}
if ($phase !== 'installed') {
    throw new RuntimeException('Unknown runtime inspection phase');
}
$definition = new xmldb_file($CFG->dirroot . '/local/videoq/db/install.xml');
ob_start();
try {
    $schemaloaded = $definition->loadXMLStructure();
} finally {
    $schemadiagnostics = ob_get_clean();
}
if (!$schemaloaded || trim($schemadiagnostics) !== '') {
    throw new RuntimeException('XMLDB schema must load without automatic repairs or developer diagnostics');
}
foreach ($tables as $table) {
    if (!$DB->get_manager()->table_exists($table)) {
        throw new RuntimeException('Missing plugin table: ' . $table);
    }
}
$components = [
    'local_videoq' => ['conversation', 'feedback', 'access', 'video', 'position', 'videofile', 'title'],
    'ltisource_videoq' => ['userid', 'courseid', 'resourceid'],
];
$versions = [];
foreach ($components as $component => $expected) {
    $class = '\\' . $component . '\\privacy\\provider';
    $collection = $class::get_metadata(new \core_privacy\local\metadata\collection($component));
    $entries = $collection->get_collection();
    if (count($entries) !== 1) {
        throw new RuntimeException('Expected one external service declaration');
    }
    $entry = reset($entries);
    if (!$entry instanceof \core_privacy\local\metadata\types\external_location || $entry->get_name() !== 'videoq') {
        throw new RuntimeException('Missing external service declaration');
    }
    $fields = $entry->get_privacy_fields();
    if (array_diff($expected, array_keys($fields))) {
        throw new RuntimeException('Incomplete privacy fields: ' . $component);
    }
    foreach ([$entry->get_summary(), ...array_values($fields)] as $identifier) {
        if (!get_string_manager()->string_exists($identifier, $component)) {
            throw new RuntimeException('Missing privacy language string');
        }
    }
    $versions[$component] = (int)get_config($component, 'version');
    if (!$versions[$component]) {
        throw new RuntimeException('Plugin was not installed');
    }
}
$key = (string)get_config('local_videoq', 'accesssigningkey');
if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
    throw new RuntimeException('Local access-proof signing key was not initialized');
}
echo json_encode(['passed' => true, 'database' => $CFG->dbtype, 'moodle' => $release,
    'developerDebugging' => true, 'tables' => $tables, 'versions' => $versions,
    'privacyMetadata' => true, 'schemaWithoutRepairs' => true, 'accessKeyInitialized' => true], JSON_PRETTY_PRINT) . "\n";
