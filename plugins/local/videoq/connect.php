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
 * Connects Moodle to an administrator-approved VideoQ server.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('local_videoq_connect');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$error = false;
if (optional_param('state', '', PARAM_RAW) !== '') {
    try {
        $connected = \local_videoq\browser_connection::finish(
            required_param('state', PARAM_RAW),
            optional_param('code', '', PARAM_RAW),
            optional_param('error', '', PARAM_RAW)
        );
        redirect(
            new moodle_url('/local/videoq/connect.php'),
            get_string(
                $connected ? 'connectionsuccess' : 'connectioncancelled',
                'local_videoq'
            )
        );
    } catch (\Throwable $e) {
        // Drop callback credentials from the address bar before rendering errors.
        redirect(new moodle_url('/local/videoq/connect.php', ['failed' => 1]));
    }
}
$error = optional_param('failed', 0, PARAM_BOOL);
if (data_submitted()) {
    require_sesskey();
    try {
        if (optional_param('action', '', PARAM_ALPHA) === 'cancel') {
            \local_videoq\connection_setup::cancel_pending();
            unset($SESSION->local_videoq_browser);
            redirect(new moodle_url('/local/videoq/connect.php'));
        }
        if (optional_param('action', '', PARAM_ALPHA) === 'browser') {
            redirect(\local_videoq\browser_connection::start(required_param('videoqurl', PARAM_RAW_TRIMMED)));
        }
        if (optional_param('action', '', PARAM_ALPHA) === 'retry') {
            \local_videoq\browser_connection::retry();
            redirect(new moodle_url('/local/videoq/connect.php'), get_string('connectionsuccess', 'local_videoq'));
        }
        \local_videoq\connection_setup::connect(required_param('connectioncode', PARAM_RAW_TRIMMED));
        redirect(new moodle_url('/local/videoq/connect.php'), get_string('connectionsuccess', 'local_videoq'));
    } catch (\Throwable $e) {
        // Never render exception details: database/HTTP errors can contain credentials.
        $error = true;
    }
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connecttitle', 'local_videoq'));
if ($error) {
    echo $OUTPUT->notification(get_string('connectionfailed', 'local_videoq'), 'error');
}
if (!empty($SESSION->local_videoq_browser['code']) && $SESSION->local_videoq_browser['expires'] > time()) {
    echo $OUTPUT->single_button(
        new moodle_url('/local/videoq/connect.php', ['action' => 'retry']),
        get_string('retryconnection', 'local_videoq'),
        'post'
    );
}
if (get_config('local_videoq', 'synctoken') || get_config('local_videoq', 'servicemode') === 'lti') {
    try {
        if (get_config('local_videoq', 'servicemode') === 'lti') {
            \local_videoq\service_auth::type();
        } else {
            (new \local_videoq\client())->request('GET', 'connection');
        }
        echo $OUTPUT->notification(
            get_string(
                get_config(
                    'local_videoq',
                    'servicemode'
                ) === 'lti' ? 'connectionconfigured' : 'connectionready',
                'local_videoq'
            ),
            'success'
        );
    } catch (\Throwable $e) {
        echo $OUTPUT->notification(get_string('connectionunavailable', 'local_videoq'), 'warning');
    }
    echo html_writer::tag('p', s((string)get_config('local_videoq', 'apiurl')));
    echo html_writer::tag('p', get_string('connectionnext', 'local_videoq'));
    echo html_writer::tag('p', get_string('accountmanagementhelp', 'local_videoq'));
    echo html_writer::link(
        new moodle_url('/local/videoq/account.php'),
        get_string('accountmanagement', 'local_videoq'),
        ['class' => 'btn btn-outline-primary mb-3', 'target' => '_blank', 'rel' => 'noopener noreferrer']
    );
}
$folded = get_config('local_videoq', 'servicemode') === 'lti';
if ($folded) {
    echo html_writer::start_tag('details', ['class' => 'mt-3']);
    echo html_writer::tag('summary', get_string('updateconnection', 'local_videoq'));
}
echo html_writer::tag('p', get_string('browserinstructions', 'local_videoq'));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/videoq/connect.php')]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'browser']);
echo html_writer::tag('label', get_string('videoqurl', 'local_videoq'), ['for' => 'videoq-url', 'class' => 'form-label']);
echo html_writer::empty_tag(
    'input',
    ['type' => 'url',
     'name' => 'videoqurl',
     'id' => 'videoq-url',
     'value' => get_config(
         'local_videoq',
         'apiurl'
     ) ?: 'https://videoq.jp',

    'maxlength' => 2048,
    'required' => 'required',
    'class' => 'form-control mb-3']
);
echo html_writer::tag('button', get_string('browserconnect', 'local_videoq'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::start_tag('details', ['class' => 'mt-3']);
echo html_writer::tag('summary', get_string('manualconnection', 'local_videoq'));
echo html_writer::tag('p', get_string('connectioninstructions', 'local_videoq'));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/videoq/connect.php')]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag(
    'label',
    get_string(
        'connectioncode',
        'local_videoq'
    ),
    ['for' => 'videoq-connectioncode',
    'class' => 'form-label']
);
echo html_writer::tag('textarea', '', ['id' => 'videoq-connectioncode', 'name' => 'connectioncode', 'rows' => 5,
    'maxlength' => 8192,
         'required' => 'required',
         'autocomplete' => 'off',
         'spellcheck' => 'false',
         'class' => 'form-control mb-3']);
echo html_writer::tag('button', get_string('connectbutton', 'local_videoq'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::end_tag('details');
if (
    (get_config('local_videoq', 'pendingconnection') ||
    get_config('local_videoq', 'pendinglticonnection')) &&
        !get_config(
            'local_videoq',
            'typeid'
        ) &&
            !get_config(
                'local_videoq',
                'synctoken'
            ) &&
            get_config(
                'local_videoq',
                'servicemode'
            ) !== 'lti'
) {
    echo html_writer::tag('p', get_string('cancelconnectionhint', 'local_videoq'));
    echo $OUTPUT->single_button(
        new moodle_url('/local/videoq/connect.php', ['action' => 'cancel']),
        get_string('cancelconnection', 'local_videoq'),
        'post'
    );
}
if ($folded) {
    echo html_writer::end_tag('details');
}
echo $OUTPUT->footer();
