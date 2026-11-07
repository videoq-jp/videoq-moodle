<?php
/** Emit only source basenames/line numbers, never raw diagnostics or fixture credentials. */
declare(strict_types=1);
function matrix_has_diagnostics(string $raw): bool {
    // CLI success headings also use "++". Recognize the source backtrace
    // from debugging(), which can accompany a successful process exit.
    return (bool)preg_match('~Warning:|Deprecated:|Notice:|Debug info:|Stack trace:|!!!|XMLDB has detected|Debugging:|debuggingmessage|^\* line [0-9]+ of /~im', $raw);
}
function matrix_diagnostic_locations(string $raw): string {
    $locations = [];
    preg_match_all('/^VIDEOQ_NATIVE_PAGE_FAILURE ([^\r\n]+)$/m', $raw, $records);
    foreach ($records[1] as $record) {
        $metadata = json_decode($record, true);
        if (!is_array($metadata)) continue;
        if (in_array($metadata['kind'] ?? null, ['Error', 'TypeError', 'RuntimeException', 'coding_exception',
                'moodle_exception', 'dml_read_exception', 'dml_missing_record_exception'], true)) {
            $locations[] = 'native-page-' . $metadata['kind'];
        }
        if (in_array($metadata['source'] ?? null, ['view.php', 'native_context.php', 'native_api.php',
                'workspace.php', 'ui_strings.php', 'source.php', 'lesson.php'], true)
                && is_int($metadata['line'] ?? null) && $metadata['line'] > 0 && $metadata['line'] <= 100000) {
            $locations[] = $metadata['source'] . ':' . $metadata['line'];
        }
        if (in_array($metadata['code'] ?? null,
                ['codingerror', 'invalidmodules', 'unknownlesson', 'nativeaccessdenied', 'nativeunavailable'], true)) {
            $locations[] = $metadata['code'];
        }
    }
    preg_match_all('~(?:\bin (/[^\r\n]+\.php)(?: on line |:)([0-9]+)|\bline ([0-9]+) of (/[^\r\n]+\.php)|(/[^\r\n]+\.php)\(([0-9]+)\))~', $raw, $matches, PREG_SET_ORDER);
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
