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

/**
 * Resolves current Moodle authority for server-side learning requests.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Resolves a private server context that must never enter a browser response.
 */
final class native_context {
    /**
     * Resolves a course entry, with a legacy lesson anchor when needed.
     *
     * @param int $courseid Moodle course identifier.
     * @param string $lessonid Optional legacy lesson identifier.
     * @return array Private connection, actor and current course authority.
     */
    public static function resolve(int $courseid, string $lessonid = ''): array {
        global $CFG, $DB, $USER;
        if ($courseid < 1 || $courseid === SITEID || ($lessonid !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/D', $lessonid))) {
            throw new \moodle_exception('nativeunavailable', 'local_videoq');
        }
        $course = get_course($courseid);
        require_login($course);
        if (empty($USER->id) || isguestuser() || !empty($USER->deleted) || !empty($USER->suspended)) {
            throw new \moodle_exception('nativeaccessdenied', 'local_videoq');
        }
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $info = get_fast_modinfo($course, $USER->id);
        $entry = course_entry::find($courseid);
        $cm = null;
        $generation = 0;
        if ($entry && $entry->enabled && course_entry::valid($entry)) {
            $candidate = $info->cms[$entry->lticmid] ?? null;
            if (
                $candidate && !$candidate->deletioninprogress && $candidate->uservisible &&
                has_capability('mod/lti:view', \context_module::instance($candidate->id))
            ) {
                $cm = $candidate;
                $generation = (int)$entry->generation;
            }
        }
        if (!$cm) {
            $lesson = $lessonid !== '' ? lesson::find($lessonid) : lesson::for_course($courseid);
            $source = $lesson ? ($info->cms[$lesson['videoCmid']] ?? null) : null;
            $candidate = $lesson ? ($info->cms[$lesson['ltiCmid']] ?? null) : null;
            if (
                !$source || !$candidate || $source->deletioninprogress || $candidate->deletioninprogress ||
                !$source->uservisible || !$candidate->uservisible || !source::matches($source, $lesson, true) ||
                !has_capability(source::view_capability($source), \context_module::instance($source->id)) ||
                !has_capability('mod/lti:view', \context_module::instance($candidate->id))
            ) {
                throw new \moodle_exception('nativeaccessdenied', 'local_videoq');
            }
            $cm = $candidate;
            foreach (course_access::lessons($courseid) as $mapping) {
                if ($mapping['id'] === $lesson['id']) {
                    $generation = (int)($mapping['generation'] ?? 0);
                    break;
                }
            }
        }
        $lti = $DB->get_record('lti', ['id' => $cm->instance, 'course' => $courseid], '*', MUST_EXIST);
        $type = $DB->get_record('lti_types', ['id' => $lti->typeid], '*', MUST_EXIST);
        $origin = rtrim((string)get_config('local_videoq', 'apiurl'), '/');
        $expectedtype = (int)get_config('local_videoq', 'typeid');
        if (
            client::origin($origin) !== $origin || $type->ltiversion !== LTI_VERSION_1P3 ||
            (int)$type->state !== LTI_TOOL_STATE_CONFIGURED || empty($type->clientid) ||
            ($expectedtype && (int)$type->id !== $expectedtype) ||
            rtrim((string)$type->baseurl, '/') !== $origin . '/api/lti/launch' ||
            (!empty($lti->toolurl) && rtrim($lti->toolurl, '/') !== $origin . '/api/lti/launch')
        ) {
            throw new \moodle_exception('nativeunavailable', 'local_videoq');
        }
        $launch = course_access::launch((int)$lti->id, (int)$USER->id);
        $proof = $launch['custom_videoq_access'] ?? '';
        $scope = course_access::resolve($proof, (string)$USER->id, (string)$courseid, (string)$lti->id, true);
        return ['origin' => $origin, 'issuer' => rtrim($CFG->wwwroot, '/'), 'clientId' => (string)$type->clientid,
            'deploymentId' => (string)$type->id, 'subject' => (string)$USER->id,
            'contextId' => (string)$courseid, 'resourceLinkId' => (string)$lti->id,
            'accessProof' => $proof, 'generation' => $generation, 'scope' => $scope];
    }

    /**
     * Checks Moodle capabilities before signing a learning operation.
     *
     * @param array $context Fresh private context.
     * @param string $operation Allowlisted operation.
     * @param array $input Operation input, never an actor or owner grant.
     * @return void
     */
    public static function require_operation(array $context, string $operation, array $input): void {
        $permissions = $context['scope']['permissions'] ?? [];
        $action = $input['action'] ?? '';
        $required = match ($operation) {
            'transcript' => ['viewTranscript'],
            'conversations' => ['manageOwnHistory'],
            'management' => ['manageContent'],
            'reports' => ['viewReports'],
            'tags' => $action === 'list' ? [] : ['manageContent'],
            'qa' => ['viewQa'],
            default => [],
        };
        foreach ($required as $permission) {
            if (($permissions[$permission] ?? false) !== true) {
                throw new \moodle_exception('nativeaccessdenied', 'local_videoq');
            }
        }
        if ($operation === 'qa') {
            $allowed = match ($action) {
                'ask', 'reply', 'updateQuestion', 'updateReply' => !empty($permissions['postQa']),
                'vote' => !empty($permissions['voteQa']),
                'feature' => !empty($permissions['moderateQa']),
                'deleteQuestion', 'deleteReply' => !empty($permissions['deleteOwnQa']) || !empty($permissions['moderateQa']),
                default => true,
            };
            if (!$allowed) {
                throw new \moodle_exception('nativeaccessdenied', 'local_videoq');
            }
        }
        if (in_array($operation, ['messages', 'messages/stream'], true)) {
            $learning = array_filter($context['scope']['resources'], static fn($item) => ($item['learning'] ?? true) === true);
            if (!$learning) {
                throw new \moodle_exception('nativeaccessdenied', 'local_videoq');
            }
        }
    }
}
