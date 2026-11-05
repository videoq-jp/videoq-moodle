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

namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

/** Stateless, expiring proof bound to one Moodle user, course and LTI activity. */
final class course_access {
    private static function secret(): string {
        $key = (string)get_config('local_videoq', 'accesssigningkey');
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \RuntimeException('Access key not initialized');
        }
        return hex2bin($key);
    }
    public static function lessons(int $courseid): array {
        global $DB;
        $items = [];
        foreach ($DB->get_records('local_videoq_sync', ['courseid' => $courseid, 'enabled' => 1, 'status' => 'completed']) as $row) {
            $items[] = ['id' => 'sync_' . $row->cmid, 'videoCmid' => (int)$row->cmid, 'ltiCmid' => (int)$row->lticmid];
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
    public static function launch(int $ltiid, int $userid): array {
        global $DB;
        $cm = get_coursemodule_from_instance('lti', $ltiid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return [];
        }
        $typeid = (int)$DB->get_field('lti', 'typeid', ['id' => $ltiid], MUST_EXIST);
        foreach (self::lessons((int)$cm->course) as $item) {
            $associated = get_coursemodule_from_id('lti', $item['ltiCmid'], $cm->course, false, IGNORE_MISSING);
            if (!$associated || (int)$DB->get_field('lti', 'typeid', ['id' => $associated->instance]) !== $typeid) {
                continue;
            }
            $payload = bin2hex(json_encode(['user' => $userid, 'course' => (int)$cm->course,
                'lti' => $ltiid, 'type' => $typeid, 'exp' => time() + 3600, 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));
            return ['custom_videoq_access' => $payload . '.' . hash_hmac('sha256', $payload, self::secret())];
        }
        return [];
    }
    public static function resolve(string $token, string $subject, string $context, string $resource): array {
        global $DB;
        if (
            !preg_match('/^([a-f0-9]{1,2048})\.([a-f0-9]{64})$/D', $token, $m)
                || !hash_equals(hash_hmac('sha256', $m[1], self::secret()), $m[2])
        ) {
            throw new \RuntimeException('Invalid access');
        }
        $proof = json_decode(hex2bin($m[1]), true, 8, JSON_THROW_ON_ERROR);
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
        $allowed = [];
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
            $allowed[] = ['resourceLinkId' => (string)$lti->instance, 'lesson' => $item['id'], 'title' => $video->name];
        }
        if (!$allowed) {
            throw new \RuntimeException('Invalid access');
        }
        return ['contextId' => (string)$course->id, 'resourceLinkId' => $resource, 'resources' => $allowed];
    }
}
