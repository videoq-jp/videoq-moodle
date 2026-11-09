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
$string['managecourseentry'] = 'Manage VideoQ course entry';
$string['courseentrydescription'] = 'Enable a VideoQ entry for this course, including courses without videos. Course Q&A, personal history and tags use current Moodle permissions. AI chat requires available processed video material. Disabling this entry retains shared course data and video activities.';
$string['courseentryerror'] = 'The course entry could not be updated. Check the VideoQ connection and that the backend supports course entries, then try again.';
$string['courseentry_enable'] = 'Enable course entry';
$string['courseentry_disable'] = 'Disable course entry';
$string['courseentry_repair'] = 'Repair and synchronize entry';
$string['courseentrypending'] = 'The course entry is synchronizing. Refresh this page shortly.';
$string['courseentryempty'] = 'No video material is currently available here. Course Q&A and your history are available according to your permissions. AI chat becomes available when readable video material is ready.';
$string['courseentryrefresh'] = 'Refresh course entry';
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
$string['privacy:metadata:materialmanagement'] = 'Teacher edits to AI descriptions and SRT transcripts, processing requests, and identifier-only audit receipts containing a pseudonymous Moodle actor key, material identifiers, action, revisions and time, stored by VideoQ.';
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
$string['managesync'] = 'VideoQ';
$string['synchronize'] = 'Synchronize VideoQ lessons';
$string['novideofile'] = 'This File activity needs a supported video file.';
$string['syncdescription'] = 'Ask AI questions about this video.';
$string['syncstatus'] = 'Status';
$string['syncerror'] = 'Synchronization needs attention. Check the connection and account allowance, then retry.';
$string['sync_enable'] = 'Enable questions';
$string['sync_disable'] = 'Disable';
$string['sync_retry'] = 'Retry';
$string['openlesson'] = 'Open video and questions';
$string['refreshstatus'] = 'Refresh';
$string['state_off'] = 'Not enabled';
$string['state_queued'] = 'Queued';
$string['state_restoring'] = 'Restoring';
$string['state_disabled'] = 'Disabled';
$string['state_uploading'] = 'Uploading';
$string['state_pending'] = 'Queued';
$string['state_processing'] = 'Transcribing';
$string['state_indexing'] = 'Indexing';
$string['state_completed'] = 'Ready';
$string['state_error'] = 'Preparation failed';
$string['state_blocked'] = 'Allowance exceeded';
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
$string['videoq:managecontent'] = 'Manage derived course material content';
$string['materialmanagement'] = 'Manage course materials';
$string['sourcematerialactions'] = 'Manage Moodle source activities';
$string['sourceedit'] = 'Edit title, file and visibility';
$string['sourcesync'] = 'Manage VideoQ synchronization';
$string['sourcecourse'] = 'Open course activity controls (including deletion)';
$string['materialmanagementunavailable'] = 'Material management is unavailable. Check your current permissions or relaunch.';
$string['personalconversations'] = 'My AI conversations';
$string['personalconversationsunavailable'] = 'Saved personal conversations are unavailable. Relaunch from the course or contact the administrator.';

$string['accountmanagement'] = 'Manage the connected VideoQ account';
$string['accountmanagementhelp'] = 'VideoQ account settings, billing and system administration require a separate VideoQ sign-in and the corresponding account permissions.';
$string['accountunavailable'] = 'The VideoQ account management page is unavailable. Check the connection and update the VideoQ server if needed.';

$string['coursetags'] = 'Course tags and materials';
$string['coursetagsunavailable'] = 'Course tags are unavailable. Check your current Moodle permissions or relaunch.';
$string['privacy:metadata:coursetags'] = 'Course-specific tag names, colors, material assignments and pseudonymous operation receipts are stored by VideoQ.';

$string['privacyunavailable'] = 'The external privacy request could not be completed. Ask the administrator to check the approved VideoQ connection and privacy service, then retry.';
$string['privacyexportscope'] = 'Personal records in the approved Moodle context, including model memory and pseudonymous operation receipts. Shared materials and other participants’ content remain. Records created before subject attribution was introduced, backups and AI provider records require separate operator review. Concurrent changes may require retrying the export. Technical hashes of operation identifiers remain to prevent duplicate operations; they contain no actor key, request contents or timestamp.';

$string['privacy:metadata:operationbarriers'] = 'A namespaced SHA-256 hash of a random operation identifier, retained by VideoQ to prevent a removed operation receipt from being executed again. These barriers have no actor, course, action, request contents or timestamp columns.';

$string['coursecatalog'] = 'Manage VideoQ course materials';
$string['cataloghelp'] = 'Upload videos as Moodle File activities, then enable VideoQ for each video. Use the course editing controls to move activities or change sections; VideoQ follows that order. Visibility, availability and participants are controlled by Moodle. Deleting a Moodle course revokes its launches; external history follows the separate privacy and retention process.';
$string['catalogempty'] = 'There are no video File activities you can manage in this course. Add a video File, save it, then enable VideoQ from this list.';
$string['catalogorganize'] = 'Organize course activities';
$string['catalogupload'] = 'Add a video File';
$string['catalogyoutube'] = 'Add a YouTube URL';
$string['invalidyoutubeurl'] = 'Use a supported URL identifying a single YouTube video.';
$string['youtubedisabled'] = 'YouTube integration is unavailable in the connected VideoQ deployment.';
$string['sourceactivity'] = 'Open Moodle activity';
$string['sourceupdated'] = 'This source has changed. Reload this Moodle page to use the updated video.';
$string['youtubeplaybackerror'] = 'The YouTube video could not be loaded. Open the original Moodle activity to check availability.';
$string['youtubeautoplay'] = 'Select Play in the YouTube player to continue at this citation.';
$string['privacy:metadata:youtube'] = 'Playing an enabled YouTube URL activity loads the YouTube player and its JavaScript API in the viewer browser. VideoQ credentials and conversation text are not sent to that player.';
$string['privacy:metadata:youtubeplayback'] = 'YouTube receives the requested video ID, network/browser information and playback interactions when its player is loaded or used. The configured VideoQ service separately obtains transcripts using the account owner’s provider configuration.';
$string['catalogcourseedit'] = 'Edit course settings';
$string['catalogparticipants'] = 'Course participants';
$string['catalogenrolments'] = 'Manage enrolment methods';
$string['catalogcoursecreate'] = 'Create a Moodle course';
$string['catalogcoursedelete'] = 'Delete Moodle course…';

$string['videoq:viewreports'] = 'View aggregate AI question usage for currently accessible course materials';
$string['coursereports'] = 'AI question usage';
$string['nativeunavailable'] = 'The learning service is unavailable or your current Moodle permissions do not allow this action.';
$string['coursereportsunavailable'] = 'Course reports are unavailable. Check your current Moodle permissions or relaunch.';
$string['privacy:metadata:coursereports'] = 'VideoQ stores verified course provenance for AI-answer attribution and identifier-only report access receipts (period, material-scope hash/count and time). Reports contain aggregate counts, without private message text or learner identities.';

// Native learning interface.
$string['nativechat_transcript'] = 'Lesson transcripts';
$string['nativechat_material'] = 'Lesson';
$string['nativechat_refreshmaterials'] = 'Refresh lessons';
$string['nativechat_moretranscript'] = 'Show more';
$string['nativechat_emptytranscript'] = 'No transcript is available yet.';
$string['nativechat_materialerror'] = 'Unable to load this lesson. Check your access and connection, then refresh the lesson list.';
$string['nativechat_materialpreparing'] = 'Materials are processing. Questions become available when a material is ready. Check progress in material management.';
$string['nativechat_connecttitle'] = 'VideoQ connection';
$string['nativechat_connecting'] = 'Connecting to your LMS…';
$string['nativechat_storageerror'] = 'Unable to save connection details. Open this tool in a separate window from your LMS.';
$string['nativechat_title'] = 'VideoQ lesson questions';
$string['nativechat_chat'] = 'Lesson chat';
$string['nativechat_heading'] = 'Ask about this course';
$string['nativechat_conversation'] = 'Conversation';
$string['nativechat_emptytitle'] = 'Ask about this course';
$string['nativechat_emptybody'] = 'Ask questions across the videos in this course.';
$string['nativechat_checking'] = 'Checking your connection…';
$string['nativechat_label'] = 'Your question about the lesson';
$string['nativechat_placeholder'] = 'Ask about the lesson…';
$string['nativechat_send'] = 'Ask a question';
$string['nativechat_hint'] = 'Enter to send · Shift+Enter for a new line. Check AI answers against the lesson.';
$string['nativechat_you'] = 'You';
$string['nativechat_assistant'] = 'AI Assistant';
$string['nativechat_feedbackerror'] = 'Unable to save feedback. Please try again.';
$string['nativechat_interrupted'] = 'The answer was interrupted. Please try again.';
$string['nativechat_seek'] = 'Jump to this point';
$string['nativechat_playbackerror'] = 'Check the video connection and playback position.';
$string['nativechat_missing'] = 'Connection details are missing. Reopen this tool from the lesson link in your LMS.';
$string['nativechat_connectionerror'] = 'Unable to connect. Check the lesson integration settings and reopen from your LMS.';
$string['nativechat_answering'] = 'Checking the lesson and preparing an answer…';
$string['nativechat_expired'] = 'Your session has expired. Reopen this tool from your LMS.';
$string['nativechat_ratelimit'] = 'Too many questions. Please wait a moment and try again.';
$string['nativechat_answererror'] = 'Unable to get an answer. Please try again later.';
$string['nativeqa_title'] = 'Course Q&A';
$string['nativeqa_shared'] = 'Questions and replies are shared with people who can view this course\'s Q&A.';
$string['nativeqa_loading'] = 'Loading course Q&A…';
$string['nativeqa_error'] = 'Unable to load Q&A. Check your access and connection, then try again.';
$string['nativeqa_search'] = 'Search questions';
$string['nativeqa_lesson'] = 'Lesson';
$string['nativeqa_alllessons'] = 'All lessons';
$string['nativeqa_wholecourse'] = 'Entire course';
$string['nativeqa_unavailablelesson'] = 'This lesson is no longer accessible. Choose a lesson again.';
$string['nativeqa_unanswered'] = 'Unanswered questions';
$string['nativeqa_sort'] = 'Sort order';
$string['nativeqa_recent'] = 'Most recent';
$string['nativeqa_votes'] = 'Votes';
$string['nativeqa_apply'] = 'Search';
$string['nativeqa_refresh'] = 'Refresh';
$string['nativeqa_ask'] = 'Ask the course';
$string['nativeqa_questiontitle'] = 'Question title';
$string['nativeqa_questionbody'] = 'Question details';
$string['nativeqa_publish'] = 'Publish question';
$string['nativeqa_reply'] = 'Reply';
$string['nativeqa_replybody'] = 'Reply details';
$string['nativeqa_postreply'] = 'Post reply';
$string['nativeqa_edit'] = 'Edit';
$string['nativeqa_delete'] = 'Delete';
$string['nativeqa_save'] = 'Save';
$string['nativeqa_cancel'] = 'Cancel';
$string['nativeqa_back'] = 'Back to questions';
$string['nativeqa_previous'] = 'Previous';
$string['nativeqa_next'] = 'Next';
$string['nativeqa_replies'] = 'Replies';
$string['nativeqa_empty'] = 'No questions yet.';
$string['nativeqa_noreplies'] = 'No replies yet.';
$string['nativeqa_feature'] = 'Feature question';
$string['nativeqa_unfeature'] = 'Unfeature question';
$string['nativeqa_featured'] = 'Featured';
$string['nativeqa_vote'] = 'Vote';
$string['nativeqa_removevote'] = 'Remove vote';
$string['nativeqa_participant'] = 'Participant';
$string['nativeqa_instructor'] = 'Instructor';
$string['nativeqa_deleteduser'] = 'Deleted user';
$string['nativeqa_readonly'] = 'Posting is currently unavailable. You can copy your draft text.';
$string['nativeqa_confirmtitle'] = 'Confirm action';
$string['nativeqa_discardaction'] = 'Discard changes';
$string['nativeqa_discard'] = 'Discard your unpublished changes?';
$string['nativeqa_deletequestion'] = 'Delete this question and all of its replies?';
$string['nativeqa_deletereply'] = 'Delete this reply?';
$string['nativepersonal_title'] = 'My AI conversations';
$string['nativepersonal_notice'] = 'Personal AI conversations are separate from shared course Q&A. The connected VideoQ account owner can manage stored logs.';
$string['nativepersonal_new'] = 'New conversation';
$string['nativepersonal_list'] = 'Saved conversations';
$string['nativepersonal_recent'] = 'Earlier recent questions';
$string['nativepersonal_refresh'] = 'Refresh';
$string['nativepersonal_previous'] = 'Previous';
$string['nativepersonal_next'] = 'Next';
$string['nativepersonal_open'] = 'Read';
$string['nativepersonal_continue'] = 'Continue this conversation';
$string['nativepersonal_older'] = 'Older messages';
$string['nativepersonal_latest'] = 'Latest messages';
$string['nativepersonal_export'] = 'Download conversation';
$string['nativepersonal_delete'] = 'Delete conversation';
$string['nativepersonal_confirm'] = 'Delete this conversation, its answer logs and model memory? This cannot be undone.';
$string['nativepersonal_cancel'] = 'Cancel';
$string['nativepersonal_empty'] = 'No saved conversations yet.';
$string['nativepersonal_loading'] = 'Loading…';
$string['nativepersonal_error'] = 'Unable to load saved conversations. Refresh or relaunch from Moodle.';
$string['nativepersonal_busy'] = 'Wait for the current answer to finish.';
$string['nativepersonal_unavailable'] = 'Saved personal conversations are unavailable.';
$string['nativepersonal_question'] = 'You';
$string['nativepersonal_answer'] = 'VideoQ';
$string['nativepersonal_back'] = 'Back to saved conversations';
$string['nativemanagement_title'] = 'Manage course materials';
$string['nativemanagement_notice'] = 'Edit the AI description and SRT transcript here. Change the source title, file, visibility and deletion in Moodle.';
$string['nativemanagement_lesson'] = 'Material to manage';
$string['nativemanagement_description'] = 'AI description';
$string['nativemanagement_transcript'] = 'Transcript (SRT)';
$string['nativemanagement_save'] = 'Save changes';
$string['nativemanagement_refresh'] = 'Refresh materials';
$string['nativemanagement_reindex'] = 'Rebuild search index';
$string['nativemanagement_retry'] = 'Retry processing';
$string['nativemanagement_close'] = 'Return to questions';
$string['nativemanagement_loading'] = 'Loading…';
$string['nativemanagement_saved'] = 'Changes saved.';
$string['nativemanagement_queued'] = 'Processing queued. AI search uses this material again after indexing finishes.';
$string['nativemanagement_unavailable'] = 'Material management is unavailable. Check your current Moodle permissions or relaunch.';
$string['nativemanagement_error'] = 'Unable to complete this operation. Your draft has been kept. Refresh or relaunch from Moodle.';
$string['nativemanagement_conflict'] = 'The material changed or is busy. Your draft has been kept. Copy your changes before refreshing.';
$string['nativemanagement_validation'] = 'Use valid SRT up to 2 MiB and a description up to 20,000 characters and 40 KB. Your draft has been kept.';
$string['nativemanagement_discard'] = 'Discard the unsaved changes and load the current material?';
$string['nativemanagement_discardaction'] = 'Discard changes';
$string['nativemanagement_clear'] = 'Clear the transcript and remove this material from AI search? The Moodle source file is kept.';
$string['nativemanagement_reindexconfirm'] = 'Rebuild the search index? This material is unavailable to AI search until processing finishes.';
$string['nativemanagement_retryconfirm'] = 'Retry processing? Processing limits still apply.';
$string['nativemanagement_confirm'] = 'Continue';
$string['nativemanagement_pending'] = 'Processing is in progress. Editing becomes available when it finishes.';
$string['nativemanagement_syncwaiting'] = 'Waiting for Moodle synchronization before questions can use this material.';
$string['nativemanagement_olderjob'] = 'For this processing failure, use Retry synchronization in Moodle.';
$string['nativemanagement_unsaved'] = 'Unsaved changes';
$string['nativemanagement_changed'] = 'The saved material has changed. Your draft is preserved.';
$string['nativemanagement_status'] = 'Status';
$string['nativemanagement_job'] = 'Processing';
$string['nativemanagement_completed'] = 'Ready';
$string['nativemanagement_indexing'] = 'Updating search index';
$string['nativemanagement_errorstate'] = 'Processing failed';
$string['nativemanagement_pendingstate'] = 'Queued';
$string['nativemanagement_processing'] = 'Processing';
$string['nativemanagement_uploading'] = 'Transferring';
$string['nativemanagement_failed'] = 'Failed';
$string['nativemanagement_queuedstate'] = 'Queued';
$string['nativemanagement_running'] = 'Running';
$string['nativetags_title'] = 'Course tags and materials';
$string['nativetags_notice'] = 'These tags belong to this Moodle course. Global VideoQ tags are managed separately.';
$string['nativetags_refresh'] = 'Refresh tags';
$string['nativetags_close'] = 'Return to questions';
$string['nativetags_all'] = 'All materials';
$string['nativetags_untagged'] = 'Without tags';
$string['nativetags_filter'] = 'Filter materials';
$string['nativetags_search'] = 'Search material titles';
$string['nativetags_create'] = 'Create tag';
$string['nativetags_edit'] = 'Edit tag';
$string['nativetags_assign'] = 'Edit material tags';
$string['nativetags_save'] = 'Save tags';
$string['nativetags_remove'] = 'Delete tag';
$string['nativetags_name'] = 'Tag name';
$string['nativetags_color'] = 'Color';
$string['nativetags_vocabulary'] = 'Course tags';
$string['nativetags_materials'] = 'Materials';
$string['nativetags_open'] = 'Open material';
$string['nativetags_transcript'] = 'Read transcript';
$string['nativetags_empty'] = 'No matching materials.';
$string['nativetags_loading'] = 'Loading…';
$string['nativetags_saved'] = 'Tags saved.';
$string['nativetags_error'] = 'Unable to complete this operation. Your draft is preserved. Refresh or relaunch from Moodle.';
$string['nativetags_conflict'] = 'Tags changed. Your draft is preserved. Refresh before saving.';
$string['nativetags_unavailable'] = 'Course tags are unavailable with your current Moodle permissions.';
$string['nativetags_discard'] = 'Discard the unsaved tag changes?';
$string['nativetags_discardaction'] = 'Discard changes';
$string['nativetags_confirmdelete'] = 'Delete this course tag and remove it from its materials?';
$string['nativetags_cancel'] = 'Cancel editing';
$string['nativetags_unsaved'] = 'Unsaved changes';
$string['nativetags_readonly'] = 'You can change this material’s tags, but cannot rename or delete a tag used by materials you cannot manage.';
$string['nativereports_title'] = 'AI question usage';
$string['nativereports_notice'] = 'Counts include verified Moodle AI answers whose complete original material scope is currently readable by you. Older answers without verified course provenance are excluded. Dates use UTC. Private questions, answers and learner identities are not included.';
$string['nativereports_from'] = 'From';
$string['nativereports_to'] = 'To';
$string['nativereports_load'] = 'Refresh report';
$string['nativereports_csv'] = 'Download aggregate CSV';
$string['nativereports_close'] = 'Return to questions';
$string['nativereports_questions'] = 'Questions';
$string['nativereports_good'] = 'Helpful';
$string['nativereports_bad'] = 'Not helpful';
$string['nativereports_none'] = 'Unrated';
$string['nativereports_date'] = 'Date';
$string['nativereports_empty'] = 'No matching AI questions in this period and material scope.';
$string['nativereports_loading'] = 'Loading…';
$string['nativereports_error'] = 'The report is unavailable. Check the dates and your current Moodle permissions.';
$string['nativereports_unavailable'] = 'Course reports are unavailable. Check your current Moodle permissions or relaunch.';
$string['nativeaccessdenied'] = 'Your current Moodle permissions do not allow this action.';

$string['syncpreparing'] = 'Questions will be available when preparation finishes.';

$string['askvideo'] = 'Ask a question';
