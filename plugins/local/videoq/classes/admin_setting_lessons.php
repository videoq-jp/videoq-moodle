<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

final class admin_setting_lessons extends \admin_setting_configtextarea {
    public function validate($data) {
        try {
            lesson::parse($data);
            return true;
        } catch (\Throwable $error) {
            return get_string('invalidconfiguration', 'local_videoq');
        }
    }
}
