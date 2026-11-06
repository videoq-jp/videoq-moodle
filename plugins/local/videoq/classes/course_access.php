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
 * Produces and verifies user-bound course material access proofs.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Stateless, expiring proof bound to one Moodle user, course and LTI activity.
 */
final class course_access {
    /**
     * Loads and validates the site key used to sign course access proofs.
     *
     * @return string
     */
    private static function secret(): string {
        $key = (string)get_config('local_videoq', 'accesssigningkey');
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \RuntimeException('Access key not initialized');
        }
        return hex2bin($key);
    }
    /**
     * Lists synchronized or legacy material bindings for a course.
     *
     * @param int $courseid Moodle course identifier.
     * @return array
     */
    public static function lessons(int $courseid): array {
        global $DB;
        $items = [];
        foreach (
            $DB->get_records(
                'local_videoq_sync',
                ['courseid' => $courseid,
                'enabled' => 1]
            ) as $row
        ) {
            $items[] = ['id' => 'sync_' . $row->cmid, 'videoCmid' => (int)$row->cmid, 'ltiCmid' => (int)$row->lticmid,
                'generation' => (int)$row->generation, 'syncStatus' => $row->status];
        }
        // The materials service grants only explicitly synchronized sources.
        // Legacy/manual associations have no external material identity and
        // must not break or widen a migrated course's access manifest.
        if (get_config('local_videoq', 'servicemode') === 'lti') {
            return $items;
        }
        foreach (lesson::parse(get_config('local_videoq', 'lessons') ?: '[]') as $item) {
            // A source migrated to automatic synchronization has one current authority.
            if ($DB->record_exists('local_videoq_sync', ['cmid' => $item['videoCmid']])) {
                continue;
            }
            $cm = get_coursemodule_from_id('resource', $item['videoCmid'], 0, false, IGNORE_MISSING);
            if ($cm && (int)$cm->course === $courseid) {
                $items[] = $item;
            }
        }
        return $items;
    }
    /**
     * Creates an expiring proof bound to a learner and registered launch activity.
     *
     * @param int $ltiid Launch activity instance identifier.
     * @param int $userid Moodle learner identifier.
     * @return array
     */
    public static function launch(int $ltiid, int $userid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $cm = get_coursemodule_from_instance('lti', $ltiid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return [];
        }
        $typeid = (int)$DB->get_field('lti', 'typeid', ['id' => $ltiid], MUST_EXIST);
        $type = $DB->get_record('lti_types', ['id' => $typeid]);
        if (!$type || (int)$type->state !== LTI_TOOL_STATE_CONFIGURED || $type->ltiversion !== LTI_VERSION_1P3) {
            return [];
        }
        $entry = course_entry::for_launch($ltiid);
        if ($entry) {
            if (!$entry->enabled || !course_entry::valid($entry)) {
                return [];
            }
            return self::sign(['user' => $userid, 'course' => (int)$cm->course, 'lti' => $ltiid,
                'type' => $typeid, 'entryGeneration' => (int)$entry->generation]);
        }
        foreach (self::lessons((int)$cm->course) as $item) {
            $associated = get_coursemodule_from_id('lti', $item['ltiCmid'], $cm->course, false, IGNORE_MISSING);
            if (
                !$associated || (int)$associated->instance !== $ltiid
                || (int)$DB->get_field('lti', 'typeid', ['id' => $associated->instance]) !== $typeid
            ) {
                continue;
            }
            return self::sign(['user' => $userid, 'course' => (int)$cm->course, 'lti' => $ltiid, 'type' => $typeid]);
        }
        return [];
    }
    /**
     * Signs an expiring launch-specific proof without storing a learner record.
     *
     * @param array $claims Verified launch identifiers.
     * @return array
     */
    private static function sign(array $claims): array {
        $claims += ['exp' => time() + 3600, 'nonce' => bin2hex(random_bytes(16))];
        $payload = bin2hex(json_encode($claims, JSON_THROW_ON_ERROR));
        return ['custom_videoq_access' => $payload . '.' . hash_hmac('sha256', $payload, self::secret())];
    }
    /**
     * Verifies a course access proof and rechecks current learner permissions.
     *
     * @param string $token Signed access proof.
     * @param string $subject Moodle user identifier from the LTI launch.
     * @param string $context Course identifier bound to the proof.
     * @param string $resource Launch resource identifier.
     * @return array
     */
    public static function resolve(string $token, string $subject, string $context, string $resource): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        if (
            !preg_match('/^([a-f0-9]{1,2048})\.([a-f0-9]{64})$/D', $token, $m)
                || !hash_equals(hash_hmac('sha256', $m[1], self::secret()), $m[2])
        ) {
            throw new \RuntimeException('Invalid access');
        }
        $proof = json_decode(hex2bin($m[1]), true, 8, JSON_THROW_ON_ERROR);
        $type = $DB->get_record('lti_types', ['id' => (int)($proof['type'] ?? 0)]);
        if (!$type || (int)$type->state !== LTI_TOOL_STATE_CONFIGURED || $type->ltiversion !== LTI_VERSION_1P3) {
            throw new \RuntimeException('Tool unavailable');
        }
        if (
            ($proof['exp'] ?? 0) <= time() || (string)$proof['user'] !== $subject
                || (string)$proof['course'] !== $context || (string)$proof['lti'] !== $resource
        ) {
            throw new \RuntimeException('Invalid access');
        }
        $user = $DB->get_record('user', ['id' => $proof['user'], 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);
        $course = get_course($proof['course']);
        $coursecontext = \context_course::instance($course->id);
        if (
            (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $coursecontext, $user))
                || (!is_enrolled($coursecontext, $user, '', true) && !has_capability('moodle/course:view', $coursecontext, $user))
        ) {
            throw new \RuntimeException('Invalid access');
        }
        $modinfo = get_fast_modinfo($course, $user->id);
        $currentcm = get_coursemodule_from_instance('lti', (int)$proof['lti'], $course->id, false, MUST_EXIST);
        $current = $modinfo->cms[$currentcm->id];
        if (
            !$current->uservisible || $current->deletioninprogress
                || !has_capability('mod/lti:view', \context_module::instance($current->id), $user)
                || (int)$DB->get_field('lti', 'typeid', ['id' => $current->instance]) !== $proof['type']
        ) {
            throw new \RuntimeException('Invalid access');
        }
        $entry = course_entry::for_launch((int)$proof['lti']);
        $entryproof = isset($proof['entryGeneration']);
        if ($entryproof || $entry) {
            if (
                !$entryproof || !$entry || !$entry->enabled || !course_entry::valid($entry)
                || (int)$entry->courseid !== (int)$course->id || (int)$entry->typeid !== (int)$proof['type']
                || (int)$entry->generation !== $proof['entryGeneration']
            ) {
                throw new \RuntimeException('Invalid course entry');
            }
        }
        $allowed = [];
        $managecontent = has_capability('local/videoq:managecontent', $coursecontext, $user);
        foreach (self::lessons((int)$course->id) as $item) {
            if (!isset($modinfo->cms[$item['videoCmid']], $modinfo->cms[$item['ltiCmid']])) {
                continue;
            }
            $video = $modinfo->cms[$item['videoCmid']];
            $lti = $modinfo->cms[$item['ltiCmid']];
            if ((int)$DB->get_field('lti', 'typeid', ['id' => $lti->instance]) !== $proof['type']) {
                continue;
            }
            if (
                $video->modname !== 'resource' || $lti->modname !== 'lti' || !$video->uservisible || !$lti->uservisible
                    || $video->deletioninprogress || $lti->deletioninprogress
                    || !has_capability('mod/resource:view', \context_module::instance($video->id), $user)
                    || !has_capability('mod/lti:view', \context_module::instance($lti->id), $user)
            ) {
                continue;
            }
            $management = $managecontent && isset($item['generation'])
                && has_capability('moodle/course:manageactivities', \context_module::instance($video->id), $user);
            $learning = !isset($item['syncStatus']) || $item['syncStatus'] === 'completed';
            if (!$learning && !$management) {
                continue;
            }
            $material = ['resourceLinkId' => (string)$lti->instance, 'lesson' => $item['id'], 'title' => $video->name];
            if (isset($item['generation'])) {
                $material['generation'] = $item['generation'];
            }
            if (!$learning) {
                $material['learning'] = false;
            }
            if ($management) {
                $material['management'] = ['generation' => $item['generation']];
            }
            $allowed[] = $material;
        }
        if (!$allowed && !$entryproof) {
            throw new \RuntimeException('Invalid access');
        }
        return [
            'contextId' => (string)$course->id,
            'resourceLinkId' => $resource,
            'resources' => $allowed,
            ...($entryproof ? ['courseEntry' => ['generation' => (int)$entry->generation]] : []),
            'permissions' => [
                'manageContent' => $managecontent,
                'manageOwnHistory' => has_capability('local/videoq:manageownhistory', $coursecontext, $user),
                'viewTranscript' => has_capability('local/videoq:viewtranscript', $coursecontext, $user),
                'viewQa' => has_capability('local/videoq:viewqa', $coursecontext, $user),
                'postQa' => has_capability('local/videoq:postqa', $coursecontext, $user),
                'voteQa' => has_capability('local/videoq:voteqa', $coursecontext, $user),
                'deleteOwnQa' => has_capability('local/videoq:deleteownqa', $coursecontext, $user),
                'moderateQa' => has_capability('local/videoq:moderateqa', $coursecontext, $user),
                'instructorQa' => has_capability('local/videoq:instructorqa', $coursecontext, $user),
            ],
        ];
    }
}
