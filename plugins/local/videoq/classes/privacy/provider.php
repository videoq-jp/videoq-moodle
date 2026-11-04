<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq\privacy;
defined('MOODLE_INTERNAL') || die();

/** No learner records are stored locally; position hints are sent to the LTI tool. */
final class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_external_location_link('videoq', [
            'conversation' => 'privacy:metadata:conversation',
            'access' => 'privacy:metadata:access',
            'video' => 'privacy:metadata:video',
            'position' => 'privacy:metadata:position',
            'videofile' => 'privacy:metadata:videofile',
            'title' => 'privacy:metadata:title',
        ], 'privacy:metadata:videoq');
        return $collection;
    }
}
