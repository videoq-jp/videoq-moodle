<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Bounded, checked external data exports for Moodle's approved privacy requests.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Writes complete records, or numbered fragments for exceptionally large records.
 */
final class privacy_export {
    /** @var array Protocol version 1 collections, never selected by a browser. */
    public const COLLECTIONS = ['logs', 'evaluations', 'conversations', 'turns', 'questions', 'replies', 'votes',
        'moderation', 'material_operations', 'tag_operations', 'recent_history', 'checkpoints',
        'checkpoint_blobs', 'checkpoint_writes'];

    /**
     * Exports one approved external context with bounded memory and checked pagination.
     *
     * @param array $server Remembered connection identity.
     * @param int $userid Core-approved subject.
     * @param string $remotecontext External context selected by privacy_api::targets.
     */
    public static function write(array $server, int $userid, string $remotecontext): void {
        $writer = \core_privacy\local\request\writer::with_context(privacy_api::context($remotecontext));
        $path = ['VideoQ', hash('sha256', json_encode($server, JSON_THROW_ON_ERROR)), hash('sha256', $remotecontext)];
        $counts = [];
        foreach (self::COLLECTIONS as $collection) {
            $cursor = null;
            $record = null;
            $count = 0;
            $page = 0;
            $lines = '';
            do {
                $request = ['action' => 'export', 'subject' => (string)$userid, 'contexts' => [$remotecontext],
                    'collection' => $collection];
                if ($cursor !== null) {
                    $request['cursor'] = $cursor;
                }
                $response = privacy_api::request($server, $request);
                if (!array_key_exists('part', $response) || !array_key_exists('next', $response)) {
                    self::fail();
                }
                $part = $response['part'];
                if ($part === null) {
                    if ($record !== null || $response['next'] !== null) {
                        self::fail();
                    }
                    break;
                }
                self::check_part($part, $remotecontext);
                if ($record === null) {
                    if ($part['offset'] !== 0 || ($cursor !== null && $part['id'] === ($cursor['after'] ?? null))) {
                        self::fail();
                    }
                    $record = ['id' => $part['id'], 'revision' => $part['revision'], 'total' => $part['total'],
                        'offset' => 0, 'hash' => hash_init('sha256'), 'text' => '', 'fragments' => 0];
                }
                if (
                    $part['id'] !== $record['id'] || $part['revision'] !== $record['revision'] ||
                        $part['total'] !== $record['total'] || $part['offset'] !== $record['offset']
                ) {
                    self::fail();
                }
                hash_update($record['hash'], $part['chunk']);
                $record['offset'] += mb_strlen($part['chunk'], 'UTF-8');
                $recordpath = [...$path, $collection];
                if ($record['total'] > 4194304) {
                    $record['fragments']++;
                    $filename = ($count + 1) . '-part-' . $record['fragments'] . '.txt';
                    $writer->export_custom_file($recordpath, $filename, $part['chunk']);
                } else {
                    $record['text'] .= $part['chunk'];
                }
                $complete = $record['offset'] === $record['total'];
                $expected = $complete ? ['after' => $record['id']] : ['record' => [
                    'id' => $record['id'], 'offset' => $record['offset'], 'revision' => $record['revision']]];
                if ($response['next'] !== $expected) {
                    self::fail();
                }
                $cursor = $expected;
                if ($complete) {
                    if (!hash_equals($record['revision'], hash_final($record['hash']))) {
                        self::fail();
                    }
                    $count++;
                    if ($record['fragments']) {
                        $writer->export_custom_file($recordpath, $count . '-manifest.json', json_encode([
                            'id' => $record['id'], 'context' => $remotecontext, 'encoding' => 'UTF-8',
                            'fragments' => $record['fragments'], 'characters' => $record['total'],
                            'sha256' => $record['revision'],
                            'instructions' => 'Concatenate numbered part files as bytes in ascending order '
                                . 'to obtain the complete JSON record.',
                        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                    } else {
                        json_decode($record['text'], false, 512, JSON_THROW_ON_ERROR);
                        $lines .= '{"id":' . json_encode($record['id'], JSON_THROW_ON_ERROR) . ',"context":'
                            . json_encode($remotecontext, JSON_THROW_ON_ERROR) . ',"data":' . $record['text'] . "}\n";
                        if (strlen($lines) >= 8388608 || $count % 100 === 0) {
                            $writer->export_custom_file($recordpath, 'records-' . (++$page) . '.ndjson', $lines);
                            $lines = '';
                        }
                    }
                    $record = null;
                }
            } while (true);
            if ($lines !== '') {
                $writer->export_custom_file([...$path, $collection], 'records-' . (++$page) . '.ndjson', $lines);
            }
            $counts[$collection] = $count;
        }
        $writer->export_data($path, (object)['version' => 1, 'origin' => $server['origin'],
            'context' => $remotecontext, 'records' => $counts, 'scope' => get_string('privacyexportscope', 'local_videoq')]);
    }

    /**
     * Validates a fragment before writing it to Moodle's archive.
     *
     * @param mixed $part Untrusted decoded response.
     * @param string $context Approved remote context.
     */
    private static function check_part($part, string $context): void {
        if (
            !is_array($part) || !isset(
                $part['id'],
                $part['context'],
                $part['offset'],
                $part['total'],
                $part['revision'],
                $part['chunk']
            ) ||
                !is_string($part['id']) || $part['id'] === '' || strlen($part['id']) > 2048 || $part['context'] !== $context ||
                !is_int($part['offset']) || $part['offset'] < 0 || !is_int($part['total']) || $part['total'] <= $part['offset'] ||
                !is_string($part['revision']) || !preg_match('/^[a-f0-9]{64}$/D', $part['revision']) ||
                !is_string($part['chunk']) || !mb_check_encoding($part['chunk'], 'UTF-8')
        ) {
            self::fail();
        }
        $length = mb_strlen($part['chunk'], 'UTF-8');
        if ($length < 1 || $length > 65536 || $part['offset'] + $length > $part['total']) {
            self::fail();
        }
    }

    /**
     * Rejects incomplete exports without putting personal response data in errors.
     */
    private static function fail(): void {
        throw new \moodle_exception('privacyunavailable', 'local_videoq');
    }
}
