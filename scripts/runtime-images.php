<?php
// The small, fixed manifest needs no YAML extension or package manager.
declare(strict_types=1);
function runtime_images(?string $file = null): array {
    $source = file_get_contents($file ?? dirname(__DIR__) . '/lab/images/compose.yaml');
    if ($source === false) throw new RuntimeException('Cannot read image manifest');
    preg_match_all('/^  ([a-z]+):\R    image: ([a-z0-9.\/-]+:[a-zA-Z0-9._-]+@sha256:[a-f0-9]{64})$/m', $source, $matches, PREG_SET_ORDER);
    $images = [];
    foreach ($matches as $match) {
        if (isset($images[$match[1]])) throw new RuntimeException('Duplicate image service');
        $images[$match[1]] = $match[2];
    }
    $names = array_keys($images); sort($names);
    if ($names !== ['db', 'php', 'proxy'] || preg_match_all('/^\s+image:/m', $source) !== 3) throw new RuntimeException('Expected three immutable images');
    if (!preg_match('/^moodlehq\/moodle-php-apache:8\.3(?:\.\d+)?@/', $images['php']) ||
            !preg_match('/^pgvector\/pgvector:pg17(?:\.\d+)?@/', $images['db']) ||
            !preg_match('/^caddy:2(?:\.\d+)*-alpine@/', $images['proxy'])) throw new RuntimeException('Unsupported runtime release line');
    return $images;
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $images = runtime_images($argv[2] ?? null);
    $name = $argv[1] ?? '';
    if (!isset($images[$name])) throw new InvalidArgumentException('Usage: php scripts/runtime-images.php php|db|proxy');
    echo $images[$name], "\n";
}
