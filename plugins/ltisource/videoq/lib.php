<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();
/** Moodle signs this custom claim as part of the normal LTI 1.3 launch. */
function ltisource_videoq_before_launch($instance, $endpoint, $params) {
    global $USER;
    try {
        return \local_videoq\course_access::launch((int)$instance->id, (int)$USER->id);
    } catch (\Throwable $error) {
        // A VideoQ configuration error must not disrupt unrelated LTI tools.
        // VideoQ registrations requiring a proof will reject the launch safely.
        return [];
    }
}
