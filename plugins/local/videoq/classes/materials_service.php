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
 * Implements the LTI materials service for course videos.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

/**
 * Opted-in materials only. Missing manifest entries are never deletion instructions.
 */
final class materials_service {
    public static function course(int $courseid, int $typeid): array {
        global $DB;
        $rows = $DB->get_records('local_videoq_sync', ['courseid' => $courseid, 'typeid' => $typeid], 'id ASC');
        if (!$rows || count($rows) > 1000 || $typeid !== (int)get_config('local_videoq', 'typeid')) {
            throw new \RuntimeException('Course not granted');
        }
        foreach ($rows as $row) {
            $type = $DB->get_field('lti', 'typeid', ['id' => $row->ltiinstance]);
            if ($type && (int)$type !== $typeid) {
                throw new \RuntimeException('Wrong tool binding');
            }
        }
        return $rows;
    }
    public static function manifest(int $courseid, int $typeid): array {
        $items = [];
        foreach (self::course($courseid, $typeid) as $row) {
            $manifest = synchronizer::run((int)$row->id, true);
            if (!$manifest) {
                throw new \RuntimeException('Incomplete snapshot');
            }
            $items[] = ['externalId' => (string)$row->cmid, 'manifest' => $manifest];
        }
        return ['contextId' => (string)$courseid, 'complete' => true, 'resources' => $items];
    }
    public static function access(int $courseid, int $typeid, string $resource, string $subject): array {
        global $DB;
        self::course($courseid, $typeid);
        if (
            !ctype_digit($resource) || !ctype_digit($subject) ||
                (int)$DB->get_field('lti', 'typeid', ['id' => (int)$resource, 'course' => $courseid]) !== $typeid
        ) {
            throw new \RuntimeException('Access denied');
        }
        $proof = course_access::launch((int)$resource, (int)$subject);
        $access = course_access::resolve($proof['custom_videoq_access'] ?? '', $subject, (string)$courseid, $resource);
        foreach ($access['resources'] as &$item) {
            $row = $DB->get_record('local_videoq_sync', ['ltiinstance' => (int)$item['resourceLinkId'], 'courseid' => $courseid], '*', MUST_EXIST);
            $item['externalId'] = (string)$row->cmid;
        }
        return $access;
    }
    public static function destination(string $url): void {
        $parts = parse_url($url);
        if (strlen($url) > 8192 || !$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['scheme'] ?? '') !== 'https') {
            throw new \RuntimeException('Untrusted destination');
        }
        $targets = json_decode((string)get_config('local_videoq', 'serviceuploadtargets'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($targets as $target) {
            if (
                client::origin($url) === ($target['origin'] ?? '') && is_string($target['prefix'] ?? null) &&
                    str_starts_with($target['prefix'], '/') && strlen($target['prefix']) > 1 &&
                    str_starts_with($parts['path'] ?? '', $target['prefix']) &&
                    !preg_match('/%(?:2f|5c|2e)|\\\\|(?:^|\/)\.\.(?:\/|$)/i', $parts['path'] ?? '')
            ) {
                return;
            }
        }
        throw new \RuntimeException('Untrusted destination');
    }
    public static function receipt(\stdClass $job): array {
        return ['jobId' => $job->jobid, 'generation' => (int)$job->generation, 'status' => $job->status, 'bytes' => (int)$job->bytes];
    }
    public static function export(int $courseid, int $typeid, array $input): array {
        global $DB;
        self::course($courseid, $typeid);
        $cmid = $input['externalId'] ?? '';
        $generation = $input['generation'] ?? null;
        if (
            !is_string($cmid) || !ctype_digit($cmid) || !is_int($generation) || $generation < 1 ||
                !is_string($input['idempotencyKey'] ?? null) || !preg_match('/^[a-f0-9-]{32,128}$/D', $input['idempotencyKey']) ||
                !is_string($input['uploadUrl'] ?? null)
        ) {
            throw new \RuntimeException('Invalid export request');
        }
        self::destination($input['uploadUrl']);
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('export-' . $cmid, 5);
        if (!$lock) {
            throw new \RuntimeException('Export busy');
        }
        try {
            $row = $DB->get_record('local_videoq_sync', ['cmid' => (int)$cmid, 'courseid' => $courseid, 'typeid' => $typeid], '*', MUST_EXIST);
            $manifest = synchronizer::run((int)$row->id, true);
            if (!$manifest['enabled'] || $manifest['generation'] !== $generation) {
                throw new \RuntimeException('Stale source');
            }
            $hash = hash('sha256', $typeid . ':' . $courseid . ':' . $cmid . ':' . $generation . ':' . $input['idempotencyKey']);
            $job = $DB->get_record('local_videoq_exports', ['requesthash' => $hash]);
            if ($job) {
                return self::receipt($job);
            }
            $transaction = $DB->start_delegated_transaction();
            $job = (object)['jobid' => bin2hex(random_bytes(32)), 'requesthash' => $hash, 'typeid' => $typeid,
                'courseid' => $courseid, 'cmid' => (int)$cmid, 'generation' => $generation, 'uploadurl' => $input['uploadUrl'],
                'status' => 'queued', 'bytes' => 0, 'timecreated' => time(), 'timemodified' => time()];
            $job->id = $DB->insert_record('local_videoq_exports', $job);
            $task = new \local_videoq\task\export_material();
            $task->set_custom_data(['jobid' => $job->jobid]);
            \core\task\manager::queue_adhoc_task($task);
            $transaction->allow_commit();
            return self::receipt($job);
        } finally {
            $lock->release();
        }
    }
    public static function acknowledge(int $courseid, int $typeid, array $input): array {
        global $DB;
        self::course($courseid, $typeid);
        if (
            !is_string($input['externalId'] ?? null) || !ctype_digit($input['externalId']) ||
                !is_int($input['generation'] ?? null) || !in_array(
                    $input['status'] ?? '',
                    ['disabled', 'uploading', 'pending', 'processing', 'indexing', 'completed', 'error', 'blocked', 'awaiting_upload'],
                    true
                )
        ) {
            throw new \RuntimeException('Invalid acknowledgement');
        }
        $error = in_array($input['error'] ?? '', ['STORAGE_LIMIT_EXCEEDED', 'MONTHLY_UPLOAD_LIMIT_EXCEEDED'], true) ? $input['error'] : '';
        $DB->execute(
            'UPDATE {local_videoq_sync} SET status = ?, errorcode = ?, lastsynced = ? WHERE cmid = ? AND courseid = ? AND typeid = ? AND generation = ?',
            [$input['status'], $error, time(), (int)$input['externalId'], $courseid, $typeid, $input['generation']]
        );
        return ['acknowledged' => true];
    }
    public static function status(int $courseid, int $typeid, string $jobid): array {
        global $DB;
        self::course($courseid, $typeid);
        $job = $DB->get_record('local_videoq_exports', ['jobid' => $jobid, 'typeid' => $typeid, 'courseid' => $courseid], '*', MUST_EXIST);
        return self::receipt($job);
    }
}
