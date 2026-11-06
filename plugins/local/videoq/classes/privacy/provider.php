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
 * Declares data exchanged with the configured VideoQ service.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq\privacy;

use core_privacy\local\metadata\collection;

/**
 * Metadata provider for the VideoQ integration.
 *
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes information transferred to VideoQ.
     *
     * @param collection $collection Metadata collected by Moodle.
     * @return collection The metadata including the external service.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('videoq', [
            'conversation' => 'privacy:metadata:conversation',
            'feedback' => 'privacy:metadata:feedback',
            'transcript' => 'privacy:metadata:transcript',
            'courseqa' => 'privacy:metadata:courseqa',
            'materialmanagement' => 'privacy:metadata:materialmanagement',
            'coursetags' => 'privacy:metadata:coursetags',
            'access' => 'privacy:metadata:access',
            'video' => 'privacy:metadata:video',
            'position' => 'privacy:metadata:position',
            'videofile' => 'privacy:metadata:videofile',
            'title' => 'privacy:metadata:title',
        ], 'privacy:metadata:videoq');
        $collection->add_subsystem_link('core', [], 'privacy:metadata:core');
        return $collection;
    }

    /**
     * Finds external records even after the subject is no longer enrolled.
     *
     * @param int $userid Subject supplied by Moodle core.
     * @return \core_privacy\local\request\contextlist Contexts containing the subject's data.
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $list = new \core_privacy\local\request\contextlist();
        foreach (\local_videoq\privacy_api::servers() as $server) {
            foreach (\local_videoq\privacy_api::contexts($server, $userid) as $id) {
                $context = \local_videoq\privacy_api::context($id);
                $list->add_from_sql('SELECT id FROM {context} WHERE id = :contextid', ['contextid' => $context->id]);
            }
        }
        return $list;
    }

    /**
     * Exports only the contexts approved by Moodle's privacy workflow.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Core-approved request.
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        $approved = array_map('intval', $contextlist->get_contextids());
        if (!$approved) {
            return;
        }
        foreach (\local_videoq\privacy_api::servers() as $server) {
            foreach (\local_videoq\privacy_api::targets($server, $userid, $approved) as $id) {
                \local_videoq\privacy_export::write($server, $userid, $id);
            }
        }
    }

    /**
     * Erases personal data in an expired context, retaining shared course structures.
     *
     * @param \context $context Core-selected expired context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        foreach (\local_videoq\privacy_api::servers() as $server) {
            $targets = \local_videoq\privacy_api::targets($server, null, [(int)$context->id]);
            foreach (self::users($server, $targets) as $userid) {
                self::erase($server, $userid, $targets);
            }
        }
    }

    /**
     * Deletes the subject's data only in the approved contexts.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Core-approved request.
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        $approved = array_map('intval', $contextlist->get_contextids());
        if (!$approved) {
            return;
        }
        foreach (\local_videoq\privacy_api::servers() as $server) {
            self::erase($server, $userid, \local_videoq\privacy_api::targets($server, $userid, $approved));
        }
    }

    /**
     * Includes former and deleted users whose external records remain in a context.
     *
     * @param \core_privacy\local\request\userlist $userlist Core-owned context user list.
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        foreach (\local_videoq\privacy_api::servers() as $server) {
            $targets = \local_videoq\privacy_api::targets($server, null, [(int)$userlist->get_context()->id]);
            foreach (self::users($server, $targets) as $userid) {
                $userlist->add_user($userid);
            }
        }
    }

    /**
     * Deletes only users and the context supplied by core's approved user list.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist Core-approved bulk request.
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        foreach (\local_videoq\privacy_api::servers() as $server) {
            $targets = \local_videoq\privacy_api::targets($server, null, [(int)$userlist->get_context()->id]);
            foreach ($userlist->get_userids() as $userid) {
                self::erase($server, (int)$userid, $targets);
            }
        }
    }

    /**
     * Finds matching local identities with bounded signed requests.
     *
     * @param array $server Remembered connection.
     * @param array $targets Core-selected external contexts.
     * @return \Generator Matching Moodle user IDs.
     */
    private static function users(array $server, array $targets): \Generator {
        if (!$targets) {
            return;
        }
        foreach (\local_videoq\privacy_api::user_batches() as $batch) {
            $found = [];
            foreach (array_chunk($targets, 100) as $contexts) {
                $result = \local_videoq\privacy_api::request($server, [
                    'action' => 'users', 'subjects' => $batch, 'contexts' => $contexts]);
                if (
                    !isset($result['subjects']) || !is_array($result['subjects']) ||
                        array_diff($result['subjects'], $batch) || count($result['subjects']) > count($batch)
                ) {
                    throw new \moodle_exception('privacyunavailable', 'local_videoq');
                }
                foreach ($result['subjects'] as $id) {
                    $found[(int)$id] = true;
                }
            }
            foreach (array_keys($found) as $userid) {
                yield $userid;
            }
        }
    }

    /**
     * Invalidates selected sessions and erases subject records atomically at the service.
     *
     * @param array $server Remembered connection.
     * @param int $userid Core-approved subject.
     * @param array $targets Core-approved remote contexts.
     */
    private static function erase(array $server, int $userid, array $targets): void {
        foreach (array_chunk($targets, 100) as $contexts) {
            $result = \local_videoq\privacy_api::request($server, [
                'action' => 'erase', 'subject' => (string)$userid, 'contexts' => $contexts]);
            if (!isset($result['erased']) || !is_array($result['erased'])) {
                throw new \moodle_exception('privacyunavailable', 'local_videoq');
            }
        }
    }
}
