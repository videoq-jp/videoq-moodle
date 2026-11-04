<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage('local_videoq_connect', get_string('connecttitle', 'local_videoq'),
        new moodle_url('/local/videoq/connect.php')));
    $settings = new admin_settingpage('local_videoq', get_string('advancedsettings', 'local_videoq'));
    $settings->add(new admin_setting_configtext('local_videoq/apiurl', get_string('apiurl', 'local_videoq'), '', '', PARAM_URL));
    $settings->add(new admin_setting_configpasswordunmask('local_videoq/synctoken', get_string('synctoken', 'local_videoq'), '', ''));
    $settings->add(new admin_setting_configtext('local_videoq/typeid', get_string('typeid', 'local_videoq'), '', '', PARAM_INT));
    $settings->add(new \local_videoq\admin_setting_lessons(
        'local_videoq/lessons', get_string('lessons', 'local_videoq'),
        get_string('lessons_desc', 'local_videoq'), '[]', PARAM_RAW, 100, 12
    ));
    $ADMIN->add('localplugins', $settings);
}
