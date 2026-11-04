<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace ltisource_videoq\privacy;
defined('MOODLE_INTERNAL') || die();
final class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_external_location_link('videoq', [
            'userid' => 'privacy:metadata:userid',
            'courseid' => 'privacy:metadata:courseid',
            'resourceid' => 'privacy:metadata:resourceid',
        ], 'privacy:metadata:videoq');
        return $collection;
    }
}
