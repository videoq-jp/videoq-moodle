<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();
$observers = [];
foreach (['course_module_updated', 'course_module_deleted', 'course_updated', 'course_deleted'] as $name) {
    $observers[] = ['eventname' => '\\core\\event\\' . $name, 'callback' => '\local_videoq\observer::changed'];
}
