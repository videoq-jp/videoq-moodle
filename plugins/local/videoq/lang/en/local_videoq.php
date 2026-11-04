<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
$string['pluginname'] = 'VideoQ companion';
$string['lessons'] = 'Video and question tool associations';
$string['lessons_desc'] = 'JSON list. Each entry requires id, title, videoCmid (a File activity containing an MP4), and ltiCmid (a VideoQ LTI 1.3 activity in the same course). Example: [{"id":"lesson-1","title":"Lesson 1","videoCmid":10,"ltiCmid":11}]. Open /local/videoq/view.php?lesson=lesson-1. Access restrictions on both activities still apply.';
$string['invalidconfiguration'] = 'The video and question tool association is invalid. Contact the site administrator.';
$string['unknownlesson'] = 'This lesson is not configured.';
$string['invalidmodules'] = 'The lesson must use a File activity and an embedded LTI 1.3 activity from the same course.';
$string['video'] = 'Video lesson';
$string['questions'] = 'Ask about this lesson';
$string['privacy:metadata'] = 'This plugin stores lesson mappings and synchronization state, with no learner data. Video lessons with questions enabled are transferred to VideoQ.';
$string['privacy:metadata:video'] = 'The administrator-associated VideoQ video identifier.';
$string['privacy:metadata:position'] = 'The current playback position, which the learner can choose to attach to a question.';
$string['privacy:metadata:videoq'] = 'The companion sends playback hints to the associated VideoQ LTI tool. It stores no learner records locally. The LTI tool manages its own question and session data.';
$string['apiurl'] = 'VideoQ API origin';
$string['synctoken'] = 'Synchronization key';
$string['typeid'] = 'Registered LTI tool type ID';
$string['managesync'] = 'VideoQ questions';
$string['synchronize'] = 'Synchronize VideoQ lessons';
$string['novideofile'] = 'This File activity needs a supported video file.';
$string['syncdescription'] = 'Enable questions for this video. Video changes are synchronized in the background by Moodle cron.';
$string['syncstatus'] = 'Status';
$string['syncerror'] = 'Synchronization needs attention. Check the connection and account allowance, then retry.';
$string['sync_enable'] = 'Enable questions';
$string['sync_disable'] = 'Disable questions';
$string['sync_retry'] = 'Retry synchronization';
$string['openlesson'] = 'Open video and questions';
$string['refreshstatus'] = 'Refresh status';
$string['state_off'] = 'Not enabled';
$string['state_queued'] = 'Waiting for synchronization';
$string['state_disabled'] = 'Disabled';
$string['state_uploading'] = 'Transferring video';
$string['state_pending'] = 'Waiting for processing';
$string['state_processing'] = 'Processing video';
$string['state_indexing'] = 'Preparing answers';
$string['state_completed'] = 'Ready for questions';
$string['state_error'] = 'Processing failed. Please retry.';
$string['state_blocked'] = 'Account allowance exceeded';
$string['state_awaiting_upload'] = 'Waiting for video transfer';
$string['privacy:metadata:videofile'] = 'The source video file for an activity with questions enabled.';
$string['privacy:metadata:title'] = 'The synchronized lesson title and Moodle course/activity identifiers.';

$string['privacy:metadata:access'] = 'A short-lived proof bound to the Moodle user, course and launch activity, used to recheck permitted videos before each question.';

$string['coursequestions'] = 'Ask about this course';
$string['chatempty'] = 'Ask questions across the videos in this course.';
$string['chatconnecting'] = 'Connecting to VideoQ…';
$string['chathint'] = 'Enter to send · Shift+Enter for a new line. Check AI answers against the lesson.';
$string['chatconnectionerror'] = 'Unable to connect. Reload this page.';
$string['chatseek'] = ' — jump to this point';

$string['privacy:metadata:conversation'] = 'Question text and the displayed AI answer exchanged between the native Moodle interface and the registered VideoQ tool. Question records remain in VideoQ.';

$string['assistant'] = 'AI Assistant';
$string['courseassistant'] = 'AI Assistant — ask about this course';
$string['nocoursevideos'] = 'No video lessons are currently available for questions. You can use the assistant once processing is complete.';

$string['connecttitle'] = 'Connect to VideoQ';
$string['connectioncode'] = 'Connection code';
$string['connectbutton'] = 'Connect';
$string['connectionsuccess'] = 'Connected to VideoQ.';
$string['connectionready'] = 'VideoQ is connected.';
$string['connectionunavailable'] = 'Could not verify the connection. Check VideoQ availability and connection settings. Existing settings were preserved.';
$string['connectionnext'] = 'Add a video File activity to a course and enable VideoQ questions in its settings.';
$string['connectioninstructions'] = 'In VideoQ Settings → Connect Moodle, generate a code for this Moodle URL and paste it here. LTI and synchronization are configured automatically.';
$string['connectionfailed'] = 'Could not connect. Check that both VideoQ plugins are installed, the Moodle URL matches, and the code has not expired. After a network error, retry with the same code. Existing connection settings were preserved.';
$string['cancelconnection'] = 'Cancel incomplete setup and start again';
$string['advancedsettings'] = 'VideoQ advanced settings (manual connection)';
