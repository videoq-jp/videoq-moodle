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
spl_autoload_register(static function(string $class) use ($source): void {
    $prefix = 'core_privacy\\';
    if (str_starts_with($class, $prefix)) {
        $file = rtrim($source, '/') . '/public/privacy/classes/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
});
$root = dirname(__DIR__);
require_once $root . '/plugins/local/videoq/classes/privacy_api.php';
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
    if (count($entries) !== ($component === 'local_videoq' ? 3 : 1)) {
        throw new RuntimeException('Unexpected metadata locations: ' . $component);
    }
    $external = array_filter($entries, static fn($item) => $item instanceof \core_privacy\local\metadata\types\external_location);
    $locations = [];
    foreach ($external as $location) $locations[$location->get_name()] = $location;
    $names = array_keys($locations); sort($names);
    if ($names !== ($component === 'local_videoq' ? ['videoq', 'youtube'] : ['videoq'])) {
        throw new RuntimeException('External locations differ from the declared integration');
    }
    $entry = $locations['videoq'];
    $additional = [];
    if ($component === 'local_videoq') {
        $core = array_filter($entries, static fn($item) => $item instanceof \core_privacy\local\metadata\types\subsystem_link && $item->get_name() === 'core');
        if (count($core) !== 1) throw new RuntimeException('Core session/task metadata must be declared');
        $additional[] = reset($core)->get_summary();
        $youtube = $locations['youtube'];
        $youtubefields = $youtube->get_privacy_fields();
        if (array_keys($youtubefields) !== ['playback']) {
            throw new RuntimeException('YouTube browser playback metadata must be declared explicitly');
        }
        $additional = [...$additional, $youtube->get_summary(), ...array_values($youtubefields)];
    }
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
        foreach ([$entry->get_summary(), ...array_values($fields), ...$additional] as $key) {
            if (!isset($string[$key]) || trim($string[$key]) === '') {
                throw new RuntimeException('Missing privacy text ' . $key . ' in ' . $languagefile);
            }
        }
    }
    foreach ([\core_privacy\local\request\plugin\provider::class,
            \core_privacy\local\request\core_userlist_provider::class] as $interface) {
        if (!is_subclass_of($class, $interface)) {
            throw new RuntimeException('Missing external-only request interface: ' . $component);
        }
    }
    $contexts = $class::get_contexts_for_userid(987654321);
    if (count($contexts) !== 0) {
        throw new RuntimeException('External-only component must not claim local personal contexts');
    }
    $request = new \core_privacy\local\request\approved_contextlist(
        (object)['id' => 987654321], $component, [987654321]
    );
    // A nonempty request must neither query a nonexistent DB nor invoke an external service.
    $class::export_user_data($request);
    $class::delete_data_for_user($request);
    echo $component . ': actual Moodle privacy metadata, request contracts and translations passed' . PHP_EOL;
}
