<?php
// This file is part of Moodle - https://moodle.org/
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
// SPDX-License-Identifier: GPL-3.0-or-later.

/**
 * Resolves canonical Moodle File and YouTube URL source activities.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Provider requests use a validated video ID, never the submitted URL.
 */
final class source {
    /**
     * Extracts a single unambiguous YouTube ID without making a network request.
     *
     * @param string $url Core URL activity's current external URL.
     * @return string|null
     */
    public static function youtube_id(string $url): ?string {
        $url = trim($url);
        if (strlen($url) > 2048 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (
            !$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        ) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'], true)) {
            return null;
        }
        $values = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            $pair = explode('=', $pair, 2);
            if (urldecode($pair[0]) === 'v') {
                $values[] = urldecode($pair[1] ?? '');
            }
        }
        $path = $parts['path'] ?? '';
        if ($path === '/watch' && !str_ends_with($host, 'youtu.be')) {
            $candidate = count($values) === 1 ? $values[0] : '';
        } else {
            if ($values) {
                return null;
            }
            $pattern = str_ends_with($host, 'youtu.be') ? '~^/([A-Za-z0-9_-]{11})/?$~D'
                : '~^/(?:embed|shorts)/([A-Za-z0-9_-]{11})/?$~D';
            $candidate = preg_match($pattern, $path, $match) ? $match[1] : '';
        }
        return preg_match('/^[A-Za-z0-9_-]{11}$/D', $candidate) && preg_match('/[A-Za-z0-9]/', $candidate)
            ? $candidate : null;
    }

    /**
     * Checks the installed schema before admitting a new source kind.
     *
     * @return bool
     */
    public static function youtube_available(): bool {
        return (int)get_config('local_videoq', 'version') >= 2026110704;
    }

    /**
     * Resolves the current source media; invalid or deleted activities have none.
     *
     * @param int $cmid Course-module identifier.
     * @return array|null
     */
    public static function read(int $cmid): ?array {
        global $DB;
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || $cm->deletioninprogress) {
            return null;
        }
        if ($cm->modname === 'resource') {
            $file = synchronizer::video_file($cmid);
            return $file ? ['kind' => 'file', 'file' => $file] : null;
        }
        if ($cm->modname !== 'url' || !self::youtube_available()) {
            return null;
        }
        $id = self::youtube_id((string)$DB->get_field('url', 'externalurl', ['id' => $cm->instance, 'course' => $cm->course]));
        return $id ? ['kind' => 'youtube', 'youtubeVideoId' => $id] : null;
    }

    /**
     * Keeps invalidated URL bindings manageable so teachers can disable them.
     *
     * @param object $cm Current Moodle course module.
     * @return bool
     */
    public static function supports(object $cm): bool {
        global $DB;
        if ($cm->modname === 'resource') {
            return true;
        }
        if ($cm->modname !== 'url' || !self::youtube_available()) {
            return false;
        }
        $row = $DB->get_record('local_videoq_sync', ['cmid' => (int)$cm->id]);
        return ($row->sourcekind ?? '') === 'youtube' || self::read((int)$cm->id) !== null;
    }

    /**
     * Loads Moodle's course/cm_info for a supported source module.
     *
     * @param int $cmid Source course-module identifier.
     * @return array
     */
    public static function course(int $cmid): array {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        if (!self::supports($cm)) {
            throw new \moodle_exception('unknownlesson', 'local_videoq');
        }
        return get_course_and_cm_from_cmid($cmid, $cm->modname);
    }

    /**
     * Resolves the current module's core read capability.
     *
     * @param object $cm Current Moodle course module.
     * @return string
     */
    public static function view_capability(object $cm): string {
        return match ($cm->modname) {
            'resource' => 'mod/resource:view',
            'url' => 'mod/url:view',
            default => throw new \moodle_exception('unknownlesson', 'local_videoq'),
        };
    }

    /**
     * Checks the current module against a binding, including its provider revision.
     *
     * @param object $cm Current Moodle course module.
     * @param array $binding Server-derived source binding.
     * @param bool $includeyoutube Whether this caller supports YouTube sources.
     * @return bool
     */
    public static function matches(object $cm, array $binding, bool $includeyoutube = false): bool {
        if (($binding['sourceKind'] ?? 'file') === 'file') {
            return $cm->modname === 'resource';
        }
        if (!$includeyoutube || ($binding['sourceKind'] ?? '') !== 'youtube' || $cm->modname !== 'url') {
            return false;
        }
        $current = self::read((int)$cm->id);
        return $current && $current['kind'] === 'youtube'
            && $current['youtubeVideoId'] === ($binding['youtubeVideoId'] ?? '');
    }
}
