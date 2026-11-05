<?php
// Read-only verification of both plugins against Moodle's actual privacy API.
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new RuntimeException('Lab only');
$expected = ['local_videoq'=>['conversation','feedback','access','video','position','videofile','title'],
    'ltisource_videoq'=>['userid','courseid','resourceid']];
$fields = [];
foreach ($expected as $component=>$required) {
    $class = '\\' . $component . '\\privacy\\provider';
    $collection = $class::get_metadata(new \core_privacy\local\metadata\collection($component));
    $locations = array_values(array_filter($collection->get_collection(), static fn($entry)=>$entry instanceof \core_privacy\local\metadata\types\external_location && $entry->get_name() === 'videoq'));
    if (count($locations) !== 1) throw new RuntimeException('Missing external-location metadata');
    $location = $locations[0]; $names = $location->get_privacy_fields();
    if (array_diff($required, array_keys($names))) throw new RuntimeException('Incomplete external data declaration');
    foreach ([$location->get_summary(), ...array_values($names)] as $identifier) {
        if (!get_string_manager()->string_exists($identifier, $component)) throw new RuntimeException('Missing privacy language string');
    }
    $fields[$component] = array_keys($names);
}
echo json_encode(['passed'=>true, 'externalFields'=>$fields], JSON_PRETTY_PRINT) . "\n";
