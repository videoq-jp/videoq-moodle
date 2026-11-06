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
     * Returns no contexts because this component stores no per-user records locally.
     *
     * @param int $userid User whose local contexts are requested.
     * @return \core_privacy\local\request\contextlist Empty local context list.
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        return new \core_privacy\local\request\contextlist();
    }

    /**
     * Exports no local records; external VideoQ history is managed by its account owner.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved export request.
     * @return void
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist): void {
        // Shared course materials are owned by their Moodle activity, not this component.
    }

    /**
     * Leaves shared course materials intact when a context expires.
     *
     * @param \context $context Context whose local personal data would be removed.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        // No locally stored per-user data belongs to this component.
    }

    /**
     * Deletes no local records for a user; external requests require the VideoQ owner.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved deletion request.
     * @return void
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        // Do not remove shared video bindings or source activities for an individual request.
    }

    /**
     * Adds no users because this component holds no per-user local records.
     *
     * @param \core_privacy\local\request\userlist $userlist Context user list to populate.
     * @return void
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        // Moodle core owns session, task and activity data; VideoQ owns external history.
    }

    /**
     * Deletes no local records for the requested users.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist Approved bulk deletion request.
     * @return void
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        // No automatic deletion of externally stored VideoQ history is implied.
    }
}
