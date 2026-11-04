<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('local_videoq_connect');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$error = false;
if (data_submitted()) {
    require_sesskey();
    try {
        if (optional_param('action', '', PARAM_ALPHA) === 'cancel') {
            \local_videoq\connection_setup::cancel_pending();
            redirect(new moodle_url('/local/videoq/connect.php'));
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
if ($error) echo $OUTPUT->notification(get_string('connectionfailed', 'local_videoq'), 'error');
if (get_config('local_videoq', 'synctoken') || get_config('local_videoq', 'servicemode') === 'lti') {
    try {
        if (get_config('local_videoq', 'servicemode') === 'lti') \local_videoq\service_auth::type();
        else (new \local_videoq\client())->request('GET', 'connection');
        echo $OUTPUT->notification(get_string(get_config('local_videoq', 'servicemode') === 'lti' ? 'connectionconfigured' : 'connectionready', 'local_videoq'), 'success');
    } catch (\Throwable $e) {
        echo $OUTPUT->notification(get_string('connectionunavailable', 'local_videoq'), 'warning');
    }
    echo html_writer::tag('p', s((string)get_config('local_videoq', 'apiurl')));
    echo html_writer::tag('p', get_string('connectionnext', 'local_videoq'));
}
$folded = get_config('local_videoq', 'servicemode') === 'lti';
if ($folded) {
    echo html_writer::start_tag('details', ['class' => 'mt-3']);
    echo html_writer::tag('summary', get_string('updateconnection', 'local_videoq'));
}
echo html_writer::tag('p', get_string('connectioninstructions', 'local_videoq'));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/videoq/connect.php')]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('label', get_string('connectioncode', 'local_videoq'), ['for' => 'videoq-connectioncode', 'class' => 'form-label']);
echo html_writer::tag('textarea', '', ['id' => 'videoq-connectioncode', 'name' => 'connectioncode', 'rows' => 5,
    'maxlength' => 8192, 'required' => 'required', 'autocomplete' => 'off', 'spellcheck' => 'false', 'class' => 'form-control mb-3']);
echo html_writer::tag('button', get_string('connectbutton', 'local_videoq'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
if (get_config('local_videoq', 'pendingconnection')) {
    echo $OUTPUT->single_button(new moodle_url('/local/videoq/connect.php', ['action' => 'cancel']),
        get_string('cancelconnection', 'local_videoq'), 'post');
}
if ($folded) echo html_writer::end_tag('details');
echo $OUTPUT->footer();
