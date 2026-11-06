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
 * Defines language strings for the VideoQ course assistant.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'VideoQ companion';
$string['videoq:viewtranscript'] = 'View transcripts of accessible VideoQ lessons';
$string['videoq:viewqa'] = 'View shared course Q&A';
$string['videoq:postqa'] = 'Post and edit own course questions and replies';
$string['videoq:voteqa'] = 'Vote on course questions';
$string['videoq:deleteownqa'] = 'Delete own course questions and replies';
$string['videoq:moderateqa'] = 'Delete other participants\' posts and feature course questions';
$string['videoq:instructorqa'] = 'Display the instructor badge when posting or editing course Q&A';
$string['courseqa'] = 'Course Q&A';
$string['courseqaunavailable'] = 'Course Q&A is unavailable. Check your access and reopen the course page.';
$string['privacy:metadata:courseqa'] = 'Published course questions, replies, votes and moderation actions stored by VideoQ with course-specific Moodle pseudonyms. This shared forum is separate from private AI conversation history.';
$string['transcript'] = 'Lesson transcripts';
$string['transcriptlesson'] = 'Lesson';
$string['transcriptrefresh'] = 'Refresh lessons';
$string['transcriptmore'] = 'Show more';
$string['transcriptempty'] = 'No transcript is available yet.';
$string['transcripterror'] = 'Unable to load this lesson. Check your access and connection, then refresh the lesson list.';
$string['privacy:metadata:transcript'] = 'Transcript text and timestamps of currently accessible lessons, returned by VideoQ for display in Moodle.';
$string['lessons'] = 'Video and question tool associations';
$string['lessons_desc'] = 'JSON list. Each entry requires id, title, videoCmid (a File activity containing an MP4), and ltiCmid (a VideoQ LTI 1.3 activity in the same course). Example: [{"id":"lesson-1","title":"Lesson 1","videoCmid":10,"ltiCmid":11}]. Open /local/videoq/view.php?lesson=lesson-1. Access restrictions on both activities still apply.';
$string['invalidconfiguration'] = 'The video and question tool association is invalid. Contact the site administrator.';
$string['unknownlesson'] = 'This lesson is not configured.';
$string['invalidmodules'] = 'The lesson must use a File activity and an embedded LTI 1.3 activity from the same course.';
$string['video'] = 'Video lesson';
$string['questions'] = 'Ask about this lesson';
$string['privacy:metadata'] = 'This plugin stores lesson mappings and synchronization state, with no learner data. Video lessons with questions enabled are transferred to VideoQ.';
$string['privacy:metadata:video'] = 'The administrator-associated VideoQ video identifier.';
$string['privacy:metadata:position'] = 'The current playback position attached to a question when available.';
$string['privacy:metadata:videoq'] = 'The plugin exchanges questions, AI answers, answer feedback, playback position and course access proofs with the configured VideoQ service, and transfers enabled video files and lesson metadata. Its own database tables contain synchronization state rather than learner conversations. Moodle manages login sessions; VideoQ manages question history and AI processing.';
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
$string['state_restoring'] = 'Preparing restored material';
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

$string['privacy:metadata:access'] = 'A short-lived proof bound to the Moodle user, course and launch activity, used to recheck permitted videos and current course capabilities before questions and transcript requests.';

$string['coursequestions'] = 'Ask about this course';
$string['chatempty'] = 'Ask questions across the videos in this course.';
$string['chatconnecting'] = 'Connecting to VideoQ…';
$string['chathint'] = 'Enter to send · Shift+Enter for a new line. Check AI answers against the lesson.';
$string['chatconnectionerror'] = 'Unable to connect. Reload this page.';
$string['chatseek'] = '— jump to this point';

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
$string['connectionfailed'] = 'Could not connect. Check both VideoQ plugins, the selected URL and server availability. Use Retry if available, or start again from Moodle. Existing materials were preserved.';
$string['cancelconnection'] = 'Cancel incomplete setup and start again';
$string['cancelconnectionhint'] = 'Cancel removes an unused local setup. If the connection already appears in VideoQ, disconnect it there as well.';
$string['advancedsettings'] = 'VideoQ advanced settings (manual connection)';

$string['connectionconfigured'] = 'LTI connection is configured. Check the connection status in your VideoQ account settings.';

$string['updateconnection'] = 'Update or reconnect VideoQ';
$string['videoqurl'] = 'VideoQ URL';
$string['browserinstructions'] = 'Enter your cloud or on-premises VideoQ URL. Sign in and allow the connection in VideoQ to return here.';
$string['browserconnect'] = 'Connect to VideoQ';
$string['manualconnection'] = 'Connect using a code (legacy method)';
$string['connectioncancelled'] = 'Connection cancelled.';
$string['retryconnection'] = 'Retry connection';

$string['privacy:metadata:feedback'] = 'The learner’s Good/Bad rating or removal of a rating for an identified AI answer, sent to and stored by VideoQ.';
$string['privacy:metadata:core'] = 'Moodle core manages temporary administrator connection sessions, configuration audit records and restore tasks executed as the initiating user. Its Privacy API handles those core records.';
$string['chatfeedbackgood'] = 'Good';
$string['chatfeedbackbad'] = 'Bad';

$string['videoq:manageownhistory'] = 'Manage own saved AI conversations';
$string['personalconversations'] = 'My AI conversations';
$string['personalconversationsunavailable'] = 'Saved personal conversations are unavailable. Relaunch from the course or contact the administrator.';
