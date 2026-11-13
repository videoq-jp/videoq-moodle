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
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid'
        || !in_array($CFG->dbname, ['moodleaudit', 'moodleaudit_upgrade'], true) || $CFG->dbhost !== 'audit-db') {
    throw new RuntimeException('Refusing to inspect an unrelated Moodle site');
}
if ((int)$CFG->debug !== DEBUG_DEVELOPER || !$CFG->debugdisplay) {
    throw new RuntimeException('Developer debugging must be enabled');
}
$tables = ['local_videoq_sync', 'local_videoq_exports', 'local_videoq_assertions', 'local_videoq_courses', 'local_videoq_languages'];
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
    if ($DB->record_exists('external_functions', ['name' => 'local_videoq_request_learning'])) {
        throw new RuntimeException('Native learning function remains after uninstall');
    }
    echo json_encode(['passed' => true, 'phase' => $phase, 'database' => $CFG->dbtype,
        'tablesRemoved' => true, 'settingsRemoved' => true, 'coreSitePreserved' => true], JSON_PRETTY_PRINT) . "\n";
    exit;
}
if ($phase === 'single') {
    if ($DB->record_exists('config_plugins', ['plugin' => 'ltisource_videoq'])
            || is_dir($CFG->dirroot . '/mod/lti/source/videoq')) {
        throw new RuntimeException('Single-plugin install contains the retired helper');
    }
    $phase = 'installed';
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
    'local_videoq' => ['conversation', 'feedback', 'transcript', 'courseqa', 'materialmanagement', 'access', 'video', 'position', 'videofile', 'title'],
];
$versions = [];
foreach ($components as $component => $expected) {
    $class = '\\' . $component . '\\privacy\\provider';
    $collection = $class::get_metadata(new \core_privacy\local\metadata\collection($component));
    $entries = $collection->get_collection();
    $external = array_filter($entries, static fn($item) => $item instanceof \core_privacy\local\metadata\types\external_location);
    $locations = [];
    foreach ($external as $location) {
        if (isset($locations[$location->get_name()])) {
            throw new RuntimeException('Duplicate external service declaration');
        }
        $locations[$location->get_name()] = $location;
    }
    $names = array_keys($locations); sort($names);
    if ($names !== ($component === 'local_videoq' ? ['videoq', 'youtube'] : ['videoq'])) {
        throw new RuntimeException('Unexpected external service declarations');
    }
    $entry = $locations['videoq'];
    if (!$entry instanceof \core_privacy\local\metadata\types\external_location || $entry->get_name() !== 'videoq') {
        throw new RuntimeException('Missing external service declaration');
    }
    $fields = $entry->get_privacy_fields();
    if (array_diff($expected, array_keys($fields))) {
        throw new RuntimeException('Incomplete privacy fields: ' . $component);
    }
    $identifiers = [$entry->get_summary(), ...array_values($fields)];
    if ($component === 'local_videoq') {
        $youtube = $locations['youtube'];
        $playback = $youtube->get_privacy_fields();
        if (array_keys($playback) !== ['playback']) {
            throw new RuntimeException('Incomplete YouTube playback declaration');
        }
        $identifiers = [...$identifiers, $youtube->get_summary(), ...array_values($playback)];
    }
    foreach ($identifiers as $identifier) {
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
$gateway = \core_external\external_api::external_function_info('local_videoq_request_learning');
if (ltrim($gateway->classname, '\\') !== \local_videoq\external\request_learning::class ||
        $gateway->methodname !== 'execute' || $gateway->allowed_from_ajax !== true || $gateway->loginrequired !== true ||
        array_keys($gateway->parameters_desc->keys) !== ['courseid', 'operation', 'lesson', 'payload'] ||
        array_keys($gateway->returns_desc->keys) !== ['status', 'body', 'contenttype']) {
    throw new RuntimeException('Native Moodle learning function is not registered with its authenticated AJAX contract');
}
echo json_encode(['passed' => true, 'database' => $CFG->dbtype, 'moodle' => $release,
    'developerDebugging' => true, 'tables' => $tables, 'versions' => $versions,
    'privacyMetadata' => true, 'schemaWithoutRepairs' => true, 'accessKeyInitialized' => true,
    'nativeAjaxRegistered' => true], JSON_PRETTY_PRINT) . "\n";
