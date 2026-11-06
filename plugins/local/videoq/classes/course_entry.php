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
 * Maintains a course-owned LTI entry without a video source.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * A real launch activity, independent of File activities and learner data.
 */
final class course_entry {
    /**
     * Avoid querying the new table before the plugin upgrade completes.
     *
     * @return bool
     */
    public static function available(): bool {
        return (int)get_config('local_videoq', 'version') >= 2026110701;
    }
    /**
     * Finds the retained entry, including explicitly disabled entries.
     *
     * @param int $courseid Course identifier.
     * @return \stdClass|null
     */
    public static function find(int $courseid): ?\stdClass {
        global $DB;
        return self::available() ? ($DB->get_record('local_videoq_courses', ['courseid' => $courseid]) ?: null) : null;
    }
    /**
     * Finds the current entry for a launch, even when disabled.
     *
     * @param int $ltiid LTI instance identifier.
     * @return \stdClass|null
     */
    public static function for_launch(int $ltiid): ?\stdClass {
        global $DB;
        return self::available() ? ($DB->get_record('local_videoq_courses', ['ltiinstance' => $ltiid]) ?: null) : null;
    }
    /**
     * Verifies the real activity still belongs to this course and configured tool.
     *
     * Visibility and learner capabilities are checked separately for each user.
     *
     * @param \stdClass $row Entry record.
     * @return bool
     */
    public static function valid(\stdClass $row): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $cm = get_coursemodule_from_id('lti', (int)$row->lticmid, 0, false, IGNORE_MISSING);
        $type = $DB->get_record('lti_types', ['id' => $row->typeid]);
        $toolurl = (string)$DB->get_field('lti', 'toolurl', ['id' => $row->ltiinstance, 'course' => $row->courseid]);
        $expected = rtrim((string)get_config('local_videoq', 'apiurl'), '/') . '/api/lti/launch';
        return (int)$row->courseid !== SITEID && $DB->record_exists('course', ['id' => $row->courseid])
            && $type && (int)$type->state === LTI_TOOL_STATE_CONFIGURED && $type->ltiversion === LTI_VERSION_1P3
            && rtrim($type->baseurl, '/') === $expected
            && $cm && !$cm->deletioninprogress && (int)$cm->course === (int)$row->courseid
            && (int)$cm->instance === (int)$row->ltiinstance
            && (int)$row->typeid === (int)get_config('local_videoq', 'typeid')
            && ($toolurl === '' || rtrim($toolurl, '/') === $expected)
            && (int)$DB->get_field('lti', 'typeid', ['id' => $row->ltiinstance, 'course' => $row->courseid]) === (int)$row->typeid;
    }
    /**
     * Requires both course-wide content and activity-management capabilities.
     *
     * @param int $courseid Course identifier.
     * @return void
     */
    public static function require_manager(int $courseid): void {
        if ($courseid === SITEID || !self::available()) {
            throw new \moodle_exception('invalidconfiguration', 'local_videoq');
        }
        $context = \context_course::instance($courseid);
        require_capability('local/videoq:managecontent', $context);
        require_capability('moodle/course:manageactivities', $context);
    }
    /**
     * Requires explicit support from the configured backend before creating an entry.
     *
     * @return void
     */
    private static function require_backend(): void {
        global $CFG;
        if (get_config('local_videoq', 'servicemode') !== 'lti') {
            // Legacy push need not have S3/materials-service discovery enabled.
            // Use its already authenticated, approved connection instead.
            $connection = (new client())->request('GET', 'connection');
            if (($connection['courseEntries'] ?? false) !== true) {
                throw new \RuntimeException('Course entries unavailable');
            }
            return;
        }
        require_once($CFG->libdir . '/filelib.php');
        $origin = rtrim((string)get_config('local_videoq', 'apiurl'), '/');
        if (client::origin($origin) !== $origin) {
            throw new \RuntimeException('Invalid configured origin');
        }
        $curl = new \curl();
        $curl->setHeader(['Accept: application/json']);
        $body = '';
        $options = client::http_options(15);
        $options['CURLOPT_WRITEFUNCTION'] = static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 8192) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        };
        $curl->get($origin . '/api/lms/pairing/discovery', [], $options);
        if ($curl->get_errno() || (int)($curl->get_info()['http_code'] ?? 0) !== 200) {
            throw new \RuntimeException('Course entries unavailable');
        }
        $metadata = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (($metadata['toolOrigin'] ?? '') !== $origin || ($metadata['courseEntries'] ?? false) !== true) {
            throw new \RuntimeException('Course entries unavailable');
        }
    }
    /**
     * Enables/repairs or explicitly disables an entry after a teacher POST.
     *
     * @param int $courseid Course identifier.
     * @param bool $enabled Requested integration state.
     * @return void
     */
    public static function configure(int $courseid, bool $enabled): void {
        global $CFG, $DB;
        self::require_manager($courseid);
        if ($enabled) {
            $type = synchronizer::check_connection();
            if ((int)$type->state !== LTI_TOOL_STATE_CONFIGURED) {
                throw new \RuntimeException('Tool unavailable');
            }
            self::require_backend();
            require_once($CFG->dirroot . '/course/modlib.php');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('course-assistant-' . $courseid, 10);
        if (!$lock) {
            throw new \RuntimeException('Course entry is being configured');
        }
        try {
            $row = self::find($courseid);
            if (!$enabled) {
                if ($row) {
                    $DB->execute('UPDATE {local_videoq_courses} SET enabled = 0, generation = generation + 1,
                        fingerprint = ?, status = ?, timemodified = ? WHERE id = ?', ['', 'queued', time(), $row->id]);
                }
                return;
            }
            if ((int)get_config('local_videoq', 'typeid') !== (int)$type->id) {
                throw new \RuntimeException('Connection changed');
            }
            $course = get_course($courseid);
            $transaction = $DB->start_delegated_transaction();
            if ($row && self::valid($row)) {
                $lticmid = (int)$row->lticmid;
                $ltiinstance = (int)$row->ltiinstance;
            } else {
                // Never delete or adopt a former activity moved/edited by a teacher.
                $lti = add_moduleinfo((object)[
                    'modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti']),
                    'course' => $courseid, 'section' => 0, 'visible' => 1, 'intro' => '', 'introformat' => FORMAT_HTML,
                    'name' => get_string('courseassistant', 'local_videoq'), 'typeid' => $type->id, 'toolurl' => $type->baseurl,
                    'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, 'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0,
                    'instructorchoicesendname' => 0, 'instructorchoicesendemailaddr' => 0, 'instructorchoiceacceptgrades' => 0,
                ], $course);
                $lticmid = (int)$lti->coursemodule;
                $ltiinstance = (int)$lti->instance;
            }
            $values = (object)['courseid' => $courseid, 'typeid' => (int)$type->id, 'lticmid' => $lticmid,
                'ltiinstance' => $ltiinstance, 'enabled' => 1, 'generation' => $row ? (int)$row->generation + 1 : 1,
                'fingerprint' => '', 'status' => 'queued', 'errorcode' => '', 'timemodified' => time(), 'lastsynced' => 0];
            if ($row) {
                $values->id = $row->id;
                $DB->update_record('local_videoq_courses', $values);
            } else {
                $DB->insert_record('local_videoq_courses', $values);
            }
            $transaction->allow_commit();
            synchronizer::hide_transport_entries($courseid, [$lticmid]);
            // This helper has its own course-entry lock; keep the lock names distinct.
            synchronizer::ensure_course_entry($courseid);
        } finally {
            $lock->release();
        }
    }
    /**
     * Reconciles existing state only; background processing never creates activities.
     *
     * @param int $courseid Course identifier.
     * @param bool $snapshot Return a manifest without sending it.
     * @return array|null
     */
    public static function run(int $courseid, bool $snapshot = false): ?array {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('course-assistant-' . $courseid, 0);
        if (!$lock) {
            throw new \RuntimeException('Course entry is being updated');
        }
        try {
            $row = self::find($courseid);
            if (!$row) {
                return null;
            }
            $course = $DB->get_record('course', ['id' => $courseid]);
            $cm = get_coursemodule_from_id('lti', (int)$row->lticmid, 0, false, IGNORE_MISSING);
            $valid = self::valid($row);
            if (!$valid) {
                $DB->set_field('local_videoq_courses', 'enabled', 0, ['id' => $row->id]);
                $row->enabled = 0;
            }
            // Hidden activities/courses still exist. Fresh per-user access, rather
            // than a global disable, governs administrators with hidden-view rights.
            $manifest = ['kind' => 'course', 'contextId' => (string)$courseid, 'resourceLinkId' => (string)$row->ltiinstance,
                'enabled' => (bool)$row->enabled, 'deleted' => !$valid,
                'title' => $course ? \core_text::substr(strip_tags($course->fullname), 0, 255) : 'Deleted course'];
            if ($course) {
                $manifest['courseTitle'] = \core_text::substr(strip_tags($course->fullname), 0, 255);
            }
            $fingerprint = hash('sha256', json_encode([$manifest, $course->visible ?? null,
                $cm->visible ?? null, $cm->availability ?? null], JSON_THROW_ON_ERROR));
            if ($row->fingerprint !== $fingerprint) {
                $generation = (int)$row->generation + ($row->fingerprint === '' ? 0 : 1);
                $DB->update_record('local_videoq_courses', (object)['id' => $row->id, 'generation' => $generation,
                    'fingerprint' => $fingerprint, 'status' => 'queued', 'timemodified' => time()]);
                $row = self::find($courseid);
            } else if (!$snapshot && in_array($row->status, ['completed', 'disabled'], true)) {
                return null;
            }
            $manifest['generation'] = (int)$row->generation;
            if ($snapshot) {
                return $manifest;
            }
            $result = (new client())->request('PUT', 'resources/course_' . $courseid, $manifest);
            if (
                ($result['generation'] ?? null) !== (int)$row->generation || !empty($result['upload'])
                || ($result['status'] ?? '') !== ($row->enabled ? 'completed' : 'disabled')
            ) {
                throw new \RuntimeException('Invalid course entry acknowledgement');
            }
            $DB->execute(
                'UPDATE {local_videoq_courses} SET status = ?, errorcode = ?, lastsynced = ? WHERE id = ? AND generation = ?',
                [$result['status'], '', time(), $row->id, $row->generation]
            );
            return null;
        } finally {
            $lock->release();
        }
    }
}
