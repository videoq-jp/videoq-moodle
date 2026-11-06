<?php
/** Emit only source basenames/line numbers, never raw diagnostics or fixture credentials. */
declare(strict_types=1);
function matrix_diagnostic_locations(string $raw): string {
    preg_match_all('~(?:\bin (/[^\r\n]+\.php) on line ([0-9]+)|\bline ([0-9]+) of (/[^\r\n]+\.php))~', $raw, $matches, PREG_SET_ORDER);
    $locations = [];
    foreach ($matches as $match) {
        $file = $match[1] !== '' ? $match[1] : $match[4];
        $line = $match[1] !== '' ? $match[2] : $match[3];
        $basename = basename($file);
        if (preg_match('/^[A-Za-z0-9_.-]+\.php$/D', $basename)) $locations[] = $basename . ':' . $line;
    }
    return implode(', ', array_slice(array_unique($locations), 0, 8));
}
