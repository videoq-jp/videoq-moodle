<?php
/** Verify metadata with actual Moodle classes, without a database or Moodle login. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only');
}
$source = $argv[1] ?? '';
$privacy = rtrim($source, '/') . '/public/privacy/classes/local/metadata';
if (!is_file($privacy . '/collection.php')) {
    throw new RuntimeException('Pass a Moodle 5.1 source checkout as the first argument');
}
define('MOODLE_INTERNAL', true);
// Load Moodle's real debugging helper as well as its metadata classes.
$CFG = (object)['debug' => 0];
require_once rtrim($source, '/') . '/public/lib/setuplib.php';
require_once rtrim($source, '/') . '/public/lib/weblib.php';
foreach (['provider.php', 'types/type.php', 'types/external_location.php', 'collection.php'] as $file) {
    require_once $privacy . '/' . $file;
}
$root = dirname(__DIR__);
$components = [
    'local_videoq' => ['local', ['conversation', 'feedback', 'access', 'video', 'position', 'videofile', 'title']],
    'ltisource_videoq' => ['ltisource', ['userid', 'courseid', 'resourceid']],
];
foreach ($components as $component => [$type, $required]) {
    $directory = $root . '/plugins/' . $type . '/videoq';
    require_once $directory . '/classes/privacy/provider.php';
    $class = '\\' . $component . '\\privacy\\provider';
    $collection = $class::get_metadata(new \core_privacy\local\metadata\collection($component));
    $entries = $collection->get_collection();
    if (count($entries) !== 1) {
        throw new RuntimeException('Unexpected metadata locations: ' . $component);
    }
    $entry = reset($entries);
    if (!$entry instanceof \core_privacy\local\metadata\types\external_location || $entry->get_name() !== 'videoq') {
        throw new RuntimeException('VideoQ must be declared as an external data location');
    }
    $fields = $entry->get_privacy_fields();
    if (array_diff($required, array_keys($fields))) {
        throw new RuntimeException('Incomplete transferred-data declaration: ' . $component);
    }
    foreach (glob($directory . '/lang/*/' . $component . '.php') as $languagefile) {
        $string = [];
        require $languagefile;
        foreach ([$entry->get_summary(), ...array_values($fields)] as $key) {
            if (!isset($string[$key]) || trim($string[$key]) === '') {
                throw new RuntimeException('Missing privacy text ' . $key . ' in ' . $languagefile);
            }
        }
    }
    echo $component . ': actual Moodle Privacy API metadata and translations passed' . PHP_EOL;
}
