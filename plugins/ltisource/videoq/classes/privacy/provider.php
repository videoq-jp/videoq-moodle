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
 * @package ltisource_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace ltisource_videoq\privacy;

use core_privacy\local\metadata\collection;

/**
 * Metadata provider for the VideoQ integration.
 *
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describes information transferred to VideoQ.
     *
     * @param collection $collection Metadata collected by Moodle.
     * @return collection The metadata including the external service.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('videoq', [
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'resourceid' => 'privacy:metadata:resourceid',
        ], 'privacy:metadata:videoq');
        return $collection;
    }
}
