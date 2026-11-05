<?php
/** Produce an AMOS import ZIP; this is not a Moodle plugin or language-pack installer. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 2 || !class_exists(ZipArchive::class)) {
    throw new InvalidArgumentException('Usage: php scripts/package-translations.php OUTPUT_DIRECTORY');
}
$root = dirname(__DIR__);
$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0755, true)) throw new RuntimeException('Cannot create output directory');
$destination = realpath($destination);
foreach (['local', 'ltisource'] as $type) {
    $source = realpath($root . '/plugins/' . $type . '/videoq');
    if (str_starts_with($destination . '/', $source . '/')) throw new RuntimeException('Output must be outside plugin source');
}
function videoq_translation_strings(string $file): array {
    if (is_link($file)) throw new RuntimeException('Translation symlinks are not distributable');
    $tokens = array_values(array_filter(token_get_all(file_get_contents($file), TOKEN_PARSE),
        static fn($token) => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    if (!$tokens || count($tokens) % 7 !== 0) throw new RuntimeException('Expected data-only language file');
    for ($index = 0; $index < count($tokens); $index += 7) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_VARIABLE || $tokens[$index][1] !== '$string'
                || $tokens[$index + 1] !== '[' || $tokens[$index + 3] !== ']'
                || $tokens[$index + 4] !== '=' || $tokens[$index + 6] !== ';') {
            throw new RuntimeException('Only literal string assignments are supported by AMOS');
        }
        foreach ([$tokens[$index + 2], $tokens[$index + 5]] as $literal) {
            if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING || $literal[1][0] !== "'") {
                throw new RuntimeException('AMOS keys and values must use single-quoted literals');
            }
        }
    }
    // The grammar above excludes calls, interpolation and executable statements.
    $string = [];
    require $file;
    if (count($string) !== count($tokens) / 7) throw new RuntimeException('Duplicate language identifiers');
    foreach ($string as $value) {
        if (!is_string($value) || $value === '' || trim($value) !== $value) throw new RuntimeException('Invalid language value');
    }
    return $string;
}
function videoq_translation_placeholders(string $text): array {
    preg_match_all('/\{\$a(?:->[a-zA-Z0-9_]+)?\}/', $text, $matches);
    $values = array_values(array_unique($matches[0])); sort($values); return $values;
}
$sources = [
    'local_videoq' => ['plugins/local/videoq/lang/en/local_videoq.php', 'plugins/local/videoq/lang/ja/local_videoq.php'],
    'ltisource_videoq' => ['plugins/ltisource/videoq/lang/en/ltisource_videoq.php', 'translations/ja/ltisource_videoq.php'],
];
$report = ['status' => 'prepared-not-submitted', 'language' => 'ja', 'moodleBranch' => '5.1', 'components' => []];
foreach ($sources as $component => [$english, $japanese]) {
    $en = videoq_translation_strings($root . '/' . $english);
    $ja = videoq_translation_strings($root . '/' . $japanese);
    if (array_diff_key($en, $ja) || array_diff_key($ja, $en)) throw new RuntimeException('Translation identifiers do not match English');
    foreach ($en as $key => $value) {
        if (videoq_translation_placeholders($value) !== videoq_translation_placeholders($ja[$key])) {
            throw new RuntimeException('Translation placeholders differ: ' . $component . ':' . $key);
        }
    }
    $report['components'][$component] = ['strings' => count($ja), 'sourceSha256' => hash_file('sha256', $root . '/' . $japanese)];
}
$temporary = tempnam($destination, '.videoq-amos-');
try {
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create AMOS import');
    foreach ($sources as $component => [$english, $japanese]) {
        $name = $component . '.php';
        if (!$zip->addFile($root . '/' . $japanese, $name) || !$zip->setMtimeName($name, 315532800)) {
            throw new RuntimeException('Cannot add translation');
        }
    }
    if (!$zip->close() || filesize($temporary) > 2097152) throw new RuntimeException('AMOS import exceeds the supported size');
    $report['archiveSha256'] = hash_file('sha256', $temporary);
    if (!rename($temporary, $destination . '/amos-ja.zip')) throw new RuntimeException('Cannot publish AMOS import');
    chmod($destination . '/amos-ja.zip', 0644);
    $metadata = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $metatemp = tempnam($destination, '.videoq-amos-metadata-');
    try {
        if (file_put_contents($metatemp, $metadata) !== strlen($metadata)
                || !rename($metatemp, $destination . '/amos-ja.json')) {
            throw new RuntimeException('Cannot publish AMOS metadata');
        }
        chmod($destination . '/amos-ja.json', 0644);
    } finally {
        if (is_file($metatemp)) unlink($metatemp);
    }
    echo json_encode($report, JSON_THROW_ON_ERROR) . "\n";
} finally {
    if (is_file($temporary)) unlink($temporary);
}
