<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();
function local_videoq_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE;
    if ($context->contextlevel !== CONTEXT_MODULE || !$PAGE->cm || $PAGE->cm->modname !== 'resource'
            || !has_capability('moodle/course:manageactivities', $context)) return;
    $node = $nav->find('modulesettings', navigation_node::TYPE_SETTING) ?: $nav;
    $node->add(get_string('managesync', 'local_videoq'), new moodle_url('/local/videoq/manage.php', ['cmid' => $PAGE->cm->id]), navigation_node::TYPE_SETTING);
}
