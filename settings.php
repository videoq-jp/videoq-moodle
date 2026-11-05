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

/**
 * Registers VideoQ administration settings and the connection page.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_videoq_connect',
        get_string('connecttitle', 'local_videoq'),
        new moodle_url('/local/videoq/connect.php')
    ));
    $settings = new admin_settingpage('local_videoq', get_string('advancedsettings', 'local_videoq'));
    $settings->add(new admin_setting_configtext('local_videoq/apiurl', get_string('apiurl', 'local_videoq'), '', '', PARAM_URL));
    if (get_config('local_videoq', 'servicemode') !== 'lti') {
        $settings->add(new admin_setting_configpasswordunmask(
            'local_videoq/synctoken',
            get_string(
                'synctoken',
                'local_videoq'
            ),
            '',
            ''
        ));
    }
    $settings->add(new admin_setting_configtext('local_videoq/typeid', get_string('typeid', 'local_videoq'), '', '', PARAM_INT));
    if (get_config('local_videoq', 'servicemode') !== 'lti') {
        $settings->add(new \local_videoq\admin_setting_lessons(
            'local_videoq/lessons',
            get_string('lessons', 'local_videoq'),
            get_string('lessons_desc', 'local_videoq'),
            '[]',
            PARAM_RAW,
            100,
            12
        ));
    }
    $ADMIN->add('localplugins', $settings);
}
