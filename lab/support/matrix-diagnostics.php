<?php
/** Emit only source basenames/line numbers, never raw diagnostics or fixture credentials. */
declare(strict_types=1);
function matrix_has_diagnostics(string $raw): bool {
    // Moodle debugging() uses "++ message ++" in CLI mode. This output may
    // accompany a successful exit and must never be treated as JSON progress.
    return (bool)preg_match('/Warning:|Deprecated:|Notice:|Debug info:|Stack trace:|!!!|XMLDB has detected|Debugging:|debuggingmessage|^\+\+ /im', $raw);
}
function matrix_diagnostic_locations(string $raw): string {
    preg_match_all('~(?:\bin (/[^\r\n]+\.php)(?: on line |:)([0-9]+)|\bline ([0-9]+) of (/[^\r\n]+\.php)|(/[^\r\n]+\.php)\(([0-9]+)\))~', $raw, $matches, PREG_SET_ORDER);
    $locations = [];
    foreach ($matches as $match) {
        if ($match[1] !== '') {
            [$file, $line] = [$match[1], $match[2]];
        } else if ($match[3] !== '') {
            [$file, $line] = [$match[4], $match[3]];
        } else {
            [$file, $line] = [$match[5], $match[6]];
        }
        $basename = basename($file);
        if (preg_match('/^[A-Za-z0-9_.-]+\.php$/D', $basename)) $locations[] = $basename . ':' . $line;
    }
    return implode(', ', array_slice(array_unique($locations), 0, 8));
}
