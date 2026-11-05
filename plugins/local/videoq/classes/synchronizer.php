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
 * Synchronizes source videos and their assistant activities.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;
/**
 * Synchronizes source videos and their assistant activities.
 */
final class synchronizer {
    /**
     * One stable, course-wide entry, independent of any source activity's lifetime.
     */
    public static function ensure_course_entry(int $courseid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('course-entry-' . $courseid, 10);
        if (!$lock) {
            throw new \RuntimeException('Course entry is being configured');
        }
        try {
            $url = $CFG->wwwroot . '/local/videoq/view.php?course=' . $courseid;
            if (!$DB->record_exists('url', ['course' => $courseid, 'externalurl' => $url])) {
                add_moduleinfo((object)[
                    'modulename' => 'url', 'module' => $DB->get_field('modules', 'id', ['name' => 'url']),
                    'course' => $courseid, 'section' => 0, 'visible' => 1,
                    'name' => get_string('courseassistant', 'local_videoq'),
                    'intro' => '', 'introformat' => FORMAT_HTML, 'externalurl' => $url,
                    'display' => 0, 'printintro' => 0, 'parameters' => [],
                ], get_course($courseid));
            }
        } finally {
            $lock->release();
        }
    }
    /**
     * Keep the transport and per-video shortcuts out of the course activity list.
     */
    private static function hide_transport_entries(int $courseid, array $cmids): void {
        global $CFG;
        if (empty($CFG->allowstealth)) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        $format = course_get_format($courseid);
        $info = get_fast_modinfo($courseid);
        foreach ($cmids as $cmid) {
            $cm = $info->get_cm($cmid);
            if ($format->allow_stealth_module_visibility($cm, $cm->get_section_info())) {
                set_coursemodule_visible($cmid, (int)$cm->visible, 0);
            }
        }
    }
    /**
     * Finds the source video file belonging to a Moodle File activity.
     *
     * @param int $cmid Source course-module identifier.
     * @return \stored_file|null
     */
    public static function video_file(int $cmid): ?\stored_file {
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        $files = get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
        $file = reset($files);
        return $file && str_starts_with($file->get_mimetype(), 'video/') ? $file : null;
    }
    /**
     * Checks that the configured LTI tool matches the VideoQ connection.
     *
     * @return \stdClass
     */
    public static function check_connection(): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $type = $DB->get_record('lti_types', ['id' => (int)get_config('local_videoq', 'typeid')], '*', MUST_EXIST);
        if (get_config('local_videoq', 'servicemode') === 'lti') {
            if (
                $type->ltiversion !== LTI_VERSION_1P3
                || rtrim(
                    $type->baseurl,
                    '/'
                ) !== rtrim(
                    (string)get_config(
                        'local_videoq',
                        'apiurl'
                    ),
                    '/'
                ) . '/api/lti/launch'
            ) {
                throw new \RuntimeException('Invalid LTI connection');
            }
            return $type;
        }
        $connection = (new client())->request('GET', 'connection');
        if (
            $type->ltiversion !== LTI_VERSION_1P3 || ($connection['issuer'] ?? '') !== $CFG->wwwroot
                || ($connection['clientId'] ?? '') !== $type->clientid || ($connection['deploymentId'] ?? '') !== (string)$type->id
                || rtrim($type->baseurl, '/') !== $connection['toolOrigin'] . '/api/lti/launch'
        ) {
            throw new \moodle_exception('invalidconfiguration', 'local_videoq');
        }
        return $type;
    }
    /**
     * Enables questions and creates the material binding for a source activity.
     *
     * @param int $cmid Source course-module identifier.
     * @return void
     */
    public static function enable(int $cmid): void {
        self::configure($cmid, false);
    }
    /**
     * Internal restore task preserves a teacher's explicit disable and any completed initialization.
     */
    public static function restore_source(int $cmid): void {
        self::configure($cmid, true);
    }
    /**
     * Disables synchronization and its generated assistant activities.
     *
     * @param int $cmid Source course-module identifier.
     * @return void
     */
    public static function disable(int $cmid): void {
        global $DB;
        require_capability('moodle/course:manageactivities', \context_module::instance($cmid));
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('enable-' . $cmid, 10);
        if (!$lock) {
            throw new \RuntimeException('Activity is being configured');
        }
        try {
            // Serialize with repair so a teacher's disable cannot be overwritten
            // by a repair that was already creating replacement activities.
            $DB->execute(
                'UPDATE {local_videoq_sync} SET enabled = 0, generation = generation + 1, status = ?, timemodified = ?'
                    . ' WHERE cmid = ?',
                ['queued', time(), $cmid]
            );
        } finally {
            $lock->release();
        }
    }
    /**
     * Repair only mappings still enabled; never override an explicit teacher disable.
     */
    private static function configure(int $cmid, bool $repair): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'resource');
        if (!$repair) {
            require_capability('moodle/course:manageactivities', \context_module::instance($cmid));
        }
        if ($cm->deletioninprogress) {
            throw new \moodle_exception('unknownlesson', 'local_videoq');
        }
        if (!self::video_file($cmid)) {
            throw new \moodle_exception('novideofile', 'local_videoq');
        }
        $type = self::check_connection();
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('enable-' . $cmid, 10);
        if (!$lock) {
            throw new \RuntimeException('Activity is being configured');
        }
        try {
            $row = $DB->get_record('local_videoq_sync', ['cmid' => $cmid]);
            if ($repair && (!$row || !$row->enabled)) {
                return;
            }
            if (
                $row && $DB->record_exists('course_modules', ['id' => $row->lticmid, 'deletioninprogress' => 0])
                    && $DB->record_exists('course_modules', ['id' => $row->linkcmid, 'deletioninprogress' => 0])
            ) {
                if ($repair) {
                    return;
                }
                $DB->execute(
                    'UPDATE {local_videoq_sync}'
                        . ' SET enabled = 1, typeid = ?, generation = generation + 1, status = ?, timemodified = ?'
                        . ' WHERE id = ?',
                    [(int)$type->id, 'queued', time(), $row->id]
                );
                self::hide_transport_entries((int)$course->id, [(int)$row->lticmid, (int)$row->linkcmid]);
                self::ensure_course_entry((int)$course->id);
                return;
            }
            $transaction = $DB->start_delegated_transaction();
            $common = ['course' => $course->id, 'section' => $cm->sectionnum, 'visible' => $cm->visible,
                'availability' => $cm->availability, 'intro' => '', 'introformat' => FORMAT_HTML];
            $lti = add_moduleinfo((object)array_merge($common, [
                'modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti']),
                'name' => $cm->name . ' — VideoQ', 'typeid' => $type->id, 'toolurl' => $type->baseurl,
                'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, 'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0,
                'instructorchoicesendname' => 0, 'instructorchoicesendemailaddr' => 0, 'instructorchoiceacceptgrades' => 0,
            ]), $course);
            $link = add_moduleinfo((object)array_merge($common, [
                'modulename' => 'url', 'module' => $DB->get_field('modules', 'id', ['name' => 'url']),
                'name' => $cm->name . ' — ' . get_string('questions', 'local_videoq'),
                'externalurl' => $CFG->wwwroot . '/local/videoq/view.php?course=' . $course->id . '&video=' . $cmid,
                'display' => 0, 'printintro' => 0, 'parameters' => [],
            ]), $course);
            $values = (object)['typeid' => (int)$type->id,
                 'cmid' => $cmid,
                 'courseid' => $course->id,
                 'lticmid' => $lti->coursemodule,

                'ltiinstance' => $lti->instance,
                     'linkcmid' => $link->coursemodule,
                     'enabled' => 1,
                     'status' => 'queued',
                     'timemodified' => time()];
            if ($row) {
                $values->id = $row->id;
                $values->generation = $row->generation + 1;
                $values->fingerprint = '';
                $DB->update_record('local_videoq_sync', $values);
            } else {
                $DB->insert_record('local_videoq_sync', $values);
            }
            $transaction->allow_commit();
            // A partial deletion can leave one former companion behind. It no
            // longer belongs to the mapping and must not remain an alternate launch.
            if ($row) {
                foreach ([$row->lticmid, $row->linkcmid] as $oldid) {
                    $old = get_coursemodule_from_id('', $oldid, $course->id, false, IGNORE_MISSING);
                    if ($old && in_array($old->modname, ['lti', 'url'], true)) {
                        course_delete_module($old->id, false);
                    }
                }
            }
            self::hide_transport_entries((int)$course->id, [(int)$lti->coursemodule, (int)$link->coursemodule]);
            self::ensure_course_entry((int)$course->id);
        } finally {
            $lock->release();
        }
    }
    /**
     * Reconciles a material binding with its current Moodle source activity.
     *
     * @param int $id Material binding or lesson identifier.
     * @param bool $snapshot Whether to return a manifest without starting a transfer.
     * @return array|null
     */
    public static function run(int $id, bool $snapshot = false): ?array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('sync-' . $id, 0);
        if (!$lock) {
            if ($snapshot) {
                throw new \RuntimeException('Material is being updated');
            }
            return null;
        }
        try {
            $row = $DB->get_record('local_videoq_sync', ['id' => $id], '*', MUST_EXIST);
            $cm = get_coursemodule_from_id('resource', $row->cmid, 0, false, IGNORE_MISSING);
            $course = $DB->get_record('course', ['id' => $row->courseid]);
            $lti = get_coursemodule_from_id('lti', $row->lticmid, 0, false, IGNORE_MISSING);
            $link = get_coursemodule_from_id('url', $row->linkcmid, 0, false, IGNORE_MISSING);
            if (!$cm || $cm->deletioninprogress || !$course || (int)$cm->course !== (int)$row->courseid) {
                $DB->set_field('local_videoq_sync', 'enabled', 0, ['id' => $id]);
                $row->enabled = 0;
            }
            // Only the post-restore task may activate an unfinished course restore.
            if ($row->enabled && $row->status === 'restoring') {
                return null;
            }
            if ($row->enabled && (!$lti || $lti->deletioninprogress || !$link || $link->deletioninprogress)) {
                // Section cleanup or deletion of an internal activity must not
                // delete the source's processed media, transcript or embeddings.
                // Failure to repair is retryable; only source deletion/explicit
                // disable below may send a tombstone to VideoQ.
                self::configure((int)$row->cmid, true);
                $row = $DB->get_record('local_videoq_sync', ['id' => $id], '*', MUST_EXIST);
                $lti = get_coursemodule_from_id('lti', $row->lticmid, 0, false, IGNORE_MISSING);
                $link = get_coursemodule_from_id('url', $row->linkcmid, 0, false, IGNORE_MISSING);
                if ($row->enabled && (!$lti || $lti->deletioninprogress || !$link || $link->deletioninprogress)) {
                    throw new \RuntimeException('Managed activities are being repaired');
                }
            }
            if (!$row->enabled) {
                // These activities were created and managed by this mapping.
                // Do not leave broken course links after disable/source deletion.
                foreach ([$lti, $link] as $companion) {
                    if ($companion && (int)$companion->course === (int)$row->courseid) {
                        course_delete_module($companion->id, false);
                    }
                }
                $lti = $link = null;
                // Core deletion events can advance the generation while removing
                // the companions; send the latest tombstone, not the old snapshot.
                $row = $DB->get_record('local_videoq_sync', ['id' => $id], '*', MUST_EXIST);
            }
            // Managed companion activities must not become an alternate route
            // around the source video's updated availability restrictions.
            if ($cm && !$cm->deletioninprogress && (int)$cm->course === (int)$row->courseid) {
                $changed = false;
                if ($link) {
                    $target = $CFG->wwwroot . '/local/videoq/view.php?course=' . $course->id . '&video=' . $cm->id;
                    if ($DB->get_field('url', 'externalurl', ['id' => $link->instance]) !== $target) {
                        $DB->set_field('url', 'externalurl', $target, ['id' => $link->instance]);
                        $changed = true;
                    }
                }
                foreach ([$lti, $link] as $companion) {
                    if (!$companion || $companion->deletioninprogress || (int)$companion->course !== (int)$cm->course) {
                        continue;
                    }
                    if ((int)$companion->visible !== (int)$cm->visible) {
                        set_coursemodule_visible($companion->id, (int)$cm->visible, (int)$companion->visibleoncoursepage, false);
                        $companion->visible = $cm->visible;
                        $changed = true;
                    }
                    if ((int)$companion->section !== (int)$cm->section) {
                        $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
                        moveto_module($companion, $section);
                        $changed = true;
                    }
                    if (($companion->availability ?? null) !== ($cm->availability ?? null)) {
                        $DB->set_field('course_modules', 'availability', $cm->availability, ['id' => $companion->id]);
                        $companion->availability = $cm->availability;
                        $changed = true;
                    }
                }
                if ($changed) {
                    rebuild_course_cache($row->courseid, true);
                }
            }
            $file = $cm && !$cm->deletioninprogress ? self::video_file((int)$row->cmid) : null;
            $enabled = (bool)($row->enabled && $file && $course && $course->visible && $cm && $cm->visible
                && (int)$cm->course === (int)$row->courseid && $lti && (int)$lti->course === (int)$row->courseid
                && $link && (int)$link->course === (int)$row->courseid
                && !$cm->deletioninprogress && $lti && $lti->visible && !$lti->deletioninprogress
                && $link && $link->visible && !$link->deletioninprogress);
            $manifest = ['contextId' => (string)$row->courseid,
                 'courseTitle' => $course ? $course->fullname : 'Moodle',
                 'resourceLinkId' => (string)$row->ltiinstance,

                'deleted' => (bool)(!$row->enabled || !$file || !$cm || $cm->deletioninprogress || !$course
                    || !$lti || $lti->deletioninprogress || !$link || $link->deletioninprogress),
                'enabled' => $enabled, 'title' => $cm ? \core_text::substr(strip_tags($cm->name), 0, 255) : 'Deleted video'];
            if ($file) {
                $manifest['file'] = ['filename' => $file->get_filename(), 'contentType' => $file->get_mimetype(),
                'size' => (int)$file->get_filesize(), 'contentHash' => $file->get_contenthash()];
            }
            $fingerprint = hash(
                'sha256',
                json_encode([$manifest,
                 $cm->availability ?? null,
                 $lti->availability ?? null,
                $link->availability ?? null])
            );
            if ($row->fingerprint !== $fingerprint) {
                $DB->execute(
                    'UPDATE {local_videoq_sync} SET generation = generation + 1, fingerprint = ?, status = ? WHERE id = ?',
                    [$fingerprint,
                     'queued',
                    $id]
                );
                $row = $DB->get_record('local_videoq_sync', ['id' => $id], '*', MUST_EXIST);
            } else if (!$snapshot && in_array($row->status, ['completed', 'disabled'], true)) {
                return null;
            }
            $manifest['generation'] = (int)$row->generation;
            if ($snapshot) {
                return $manifest;
            }
            $client = new client();
            $path = 'resources/' . $row->cmid;
            $result = $client->request('PUT', $path, $manifest);
            if (!empty($result['upload'])) {
                if (!$file) {
                    throw new \RuntimeException('Video source is missing');
                }
                $client->upload($result['upload'], $file);
                $result = $client->request('POST', $path . '/confirm', ['generation' => (int)$row->generation]);
            }
            $status = in_array(
                $result['status'] ?? '',
                ['disabled', 'uploading', 'pending', 'processing', 'indexing', 'completed', 'error', 'blocked', 'awaiting_upload'],
                true
            ) ? $result['status'] : 'error';
            $error = in_array(
                $result['error'] ?? '',
                ['STORAGE_LIMIT_EXCEEDED',
                 'MONTHLY_UPLOAD_LIMIT_EXCEEDED'],
                true
            ) ? $result['error'] : '';
            $DB->execute(
                'UPDATE {local_videoq_sync} SET status = ?, errorcode = ?, lastsynced = ? WHERE id = ? AND generation = ?',
                [$status, $error, time(), $id, $row->generation]
            );
        } finally {
            $lock->release();
        }
        return null;
    }
}
