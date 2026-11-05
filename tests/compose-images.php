<?php
declare(strict_types=1);
require dirname(__DIR__) . '/scripts/runtime-images.php';
$mode = $argv[1] ?? '';
$expected = match ($mode) {
    'lab' => ['db'=>'db', 'moodle'=>'php', 'proxy'=>'proxy', 'videoq-sync'=>'php', 'ai'=>'php'],
    'restore' => ['moodle-restore'=>'php', 'restore-proxy'=>'proxy', 'restore-sync'=>'php'],
    default => throw new InvalidArgumentException('Expected lab or restore'),
};
$config = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$images = runtime_images();
if (count($config['services'] ?? []) !== count($expected)) throw new RuntimeException('Unexpected Compose services');
foreach ($expected as $service => $image) {
    if (($config['services'][$service]['image'] ?? '') !== $images[$image]) throw new RuntimeException('Resolved image mismatch: ' . $service);
}
echo "Resolved $mode Compose images match the canonical dependency manifest.\n";
