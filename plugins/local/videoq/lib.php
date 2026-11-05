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

defined('MOODLE_INTERNAL') || die();
function local_videoq_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE;
    if (
        $context->contextlevel !== CONTEXT_MODULE || !$PAGE->cm || $PAGE->cm->modname !== 'resource'
            || !has_capability('moodle/course:manageactivities', $context)
    ) {
        return;
    }
    $node = $nav->find('modulesettings', navigation_node::TYPE_SETTING) ?: $nav;
    $node->add(get_string('managesync', 'local_videoq'), new moodle_url('/local/videoq/manage.php', ['cmid' => $PAGE->cm->id]), navigation_node::TYPE_SETTING);
}
