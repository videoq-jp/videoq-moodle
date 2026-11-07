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

/**
 * Provides localized text for Moodle-owned learning screens.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Resolves native learning labels through the active Moodle language pack.
 */
final class ui_strings {
    /**
     * Returns public interface text.
     *
     * @return array
     */
    public static function data(): array {
        $maps = [
            'chat' => [
                'transcript' => 'nativechat_transcript',
                'material' => 'nativechat_material',
                'refreshMaterials' => 'nativechat_refreshmaterials',
                'moreTranscript' => 'nativechat_moretranscript',
                'emptyTranscript' => 'nativechat_emptytranscript',
                'materialError' => 'nativechat_materialerror',
                'materialPreparing' => 'nativechat_materialpreparing',
                'connectTitle' => 'nativechat_connecttitle',
                'connecting' => 'nativechat_connecting',
                'storageError' => 'nativechat_storageerror',
                'title' => 'nativechat_title',
                'chat' => 'nativechat_chat',
                'heading' => 'nativechat_heading',
                'conversation' => 'nativechat_conversation',
                'emptyTitle' => 'nativechat_emptytitle',
                'emptyBody' => 'nativechat_emptybody',
                'checking' => 'nativechat_checking',
                'label' => 'nativechat_label',
                'placeholder' => 'nativechat_placeholder',
                'send' => 'nativechat_send',
                'hint' => 'nativechat_hint',
                'you' => 'nativechat_you',
                'assistant' => 'nativechat_assistant',
                'feedbackError' => 'nativechat_feedbackerror',
                'interrupted' => 'nativechat_interrupted',
                'seek' => 'nativechat_seek',
                'playbackError' => 'nativechat_playbackerror',
                'missing' => 'nativechat_missing',
                'connectionError' => 'nativechat_connectionerror',
                'answering' => 'nativechat_answering',
                'expired' => 'nativechat_expired',
                'rateLimit' => 'nativechat_ratelimit',
                'answerError' => 'nativechat_answererror',
            ],
            'qa' => [
                'title' => 'nativeqa_title',
                'shared' => 'nativeqa_shared',
                'loading' => 'nativeqa_loading',
                'error' => 'nativeqa_error',
                'search' => 'nativeqa_search',
                'lesson' => 'nativeqa_lesson',
                'allLessons' => 'nativeqa_alllessons',
                'wholeCourse' => 'nativeqa_wholecourse',
                'unavailableLesson' => 'nativeqa_unavailablelesson',
                'unanswered' => 'nativeqa_unanswered',
                'sort' => 'nativeqa_sort',
                'recent' => 'nativeqa_recent',
                'votes' => 'nativeqa_votes',
                'apply' => 'nativeqa_apply',
                'refresh' => 'nativeqa_refresh',
                'ask' => 'nativeqa_ask',
                'questionTitle' => 'nativeqa_questiontitle',
                'questionBody' => 'nativeqa_questionbody',
                'publish' => 'nativeqa_publish',
                'reply' => 'nativeqa_reply',
                'replyBody' => 'nativeqa_replybody',
                'postReply' => 'nativeqa_postreply',
                'edit' => 'nativeqa_edit',
                'delete' => 'nativeqa_delete',
                'save' => 'nativeqa_save',
                'cancel' => 'nativeqa_cancel',
                'back' => 'nativeqa_back',
                'previous' => 'nativeqa_previous',
                'next' => 'nativeqa_next',
                'replies' => 'nativeqa_replies',
                'empty' => 'nativeqa_empty',
                'noReplies' => 'nativeqa_noreplies',
                'feature' => 'nativeqa_feature',
                'unfeature' => 'nativeqa_unfeature',
                'featured' => 'nativeqa_featured',
                'vote' => 'nativeqa_vote',
                'removeVote' => 'nativeqa_removevote',
                'participant' => 'nativeqa_participant',
                'instructor' => 'nativeqa_instructor',
                'deletedUser' => 'nativeqa_deleteduser',
                'readOnly' => 'nativeqa_readonly',
                'confirmTitle' => 'nativeqa_confirmtitle',
                'discardAction' => 'nativeqa_discardaction',
                'discard' => 'nativeqa_discard',
                'deleteQuestion' => 'nativeqa_deletequestion',
                'deleteReply' => 'nativeqa_deletereply',
            ],
            'personal' => [
                'title' => 'nativepersonal_title',
                'notice' => 'nativepersonal_notice',
                'new' => 'nativepersonal_new',
                'list' => 'nativepersonal_list',
                'recent' => 'nativepersonal_recent',
                'refresh' => 'nativepersonal_refresh',
                'previous' => 'nativepersonal_previous',
                'next' => 'nativepersonal_next',
                'open' => 'nativepersonal_open',
                'continue' => 'nativepersonal_continue',
                'older' => 'nativepersonal_older',
                'latest' => 'nativepersonal_latest',
                'export' => 'nativepersonal_export',
                'delete' => 'nativepersonal_delete',
                'confirm' => 'nativepersonal_confirm',
                'cancel' => 'nativepersonal_cancel',
                'empty' => 'nativepersonal_empty',
                'loading' => 'nativepersonal_loading',
                'error' => 'nativepersonal_error',
                'busy' => 'nativepersonal_busy',
                'unavailable' => 'nativepersonal_unavailable',
                'question' => 'nativepersonal_question',
                'answer' => 'nativepersonal_answer',
                'back' => 'nativepersonal_back',
            ],
            'management' => [
                'title' => 'nativemanagement_title',
                'notice' => 'nativemanagement_notice',
                'lesson' => 'nativemanagement_lesson',
                'description' => 'nativemanagement_description',
                'transcript' => 'nativemanagement_transcript',
                'save' => 'nativemanagement_save',
                'refresh' => 'nativemanagement_refresh',
                'reindex' => 'nativemanagement_reindex',
                'retry' => 'nativemanagement_retry',
                'close' => 'nativemanagement_close',
                'loading' => 'nativemanagement_loading',
                'saved' => 'nativemanagement_saved',
                'queued' => 'nativemanagement_queued',
                'unavailable' => 'nativemanagement_unavailable',
                'error' => 'nativemanagement_error',
                'conflict' => 'nativemanagement_conflict',
                'validation' => 'nativemanagement_validation',
                'discard' => 'nativemanagement_discard',
                'discardAction' => 'nativemanagement_discardaction',
                'clear' => 'nativemanagement_clear',
                'reindexConfirm' => 'nativemanagement_reindexconfirm',
                'retryConfirm' => 'nativemanagement_retryconfirm',
                'confirm' => 'nativemanagement_confirm',
                'pending' => 'nativemanagement_pending',
                'syncWaiting' => 'nativemanagement_syncwaiting',
                'olderJob' => 'nativemanagement_olderjob',
                'unsaved' => 'nativemanagement_unsaved',
                'changed' => 'nativemanagement_changed',
                'status' => 'nativemanagement_status',
                'job' => 'nativemanagement_job',
                'completed' => 'nativemanagement_completed',
                'indexing' => 'nativemanagement_indexing',
                'errorState' => 'nativemanagement_errorstate',
                'pendingState' => 'nativemanagement_pendingstate',
                'processing' => 'nativemanagement_processing',
                'uploading' => 'nativemanagement_uploading',
                'failed' => 'nativemanagement_failed',
                'queuedState' => 'nativemanagement_queuedstate',
                'running' => 'nativemanagement_running',
            ],
            'tags' => [
                'title' => 'nativetags_title',
                'notice' => 'nativetags_notice',
                'refresh' => 'nativetags_refresh',
                'close' => 'nativetags_close',
                'all' => 'nativetags_all',
                'untagged' => 'nativetags_untagged',
                'filter' => 'nativetags_filter',
                'search' => 'nativetags_search',
                'create' => 'nativetags_create',
                'edit' => 'nativetags_edit',
                'assign' => 'nativetags_assign',
                'save' => 'nativetags_save',
                'remove' => 'nativetags_remove',
                'name' => 'nativetags_name',
                'color' => 'nativetags_color',
                'vocabulary' => 'nativetags_vocabulary',
                'materials' => 'nativetags_materials',
                'open' => 'nativetags_open',
                'transcript' => 'nativetags_transcript',
                'empty' => 'nativetags_empty',
                'loading' => 'nativetags_loading',
                'saved' => 'nativetags_saved',
                'error' => 'nativetags_error',
                'conflict' => 'nativetags_conflict',
                'unavailable' => 'nativetags_unavailable',
                'discard' => 'nativetags_discard',
                'discardAction' => 'nativetags_discardaction',
                'confirmDelete' => 'nativetags_confirmdelete',
                'cancel' => 'nativetags_cancel',
                'unsaved' => 'nativetags_unsaved',
                'readOnly' => 'nativetags_readonly',
            ],
            'reports' => [
                'title' => 'nativereports_title',
                'notice' => 'nativereports_notice',
                'from' => 'nativereports_from',
                'to' => 'nativereports_to',
                'load' => 'nativereports_load',
                'csv' => 'nativereports_csv',
                'close' => 'nativereports_close',
                'questions' => 'nativereports_questions',
                'good' => 'nativereports_good',
                'bad' => 'nativereports_bad',
                'none' => 'nativereports_none',
                'date' => 'nativereports_date',
                'empty' => 'nativereports_empty',
                'loading' => 'nativereports_loading',
                'error' => 'nativereports_error',
                'unavailable' => 'nativereports_unavailable',
            ],
        ];
        $result = [];
        foreach ($maps as $group => $map) {
            foreach ($map as $name => $id) {
                $result[$group][$name] = get_string($id, 'local_videoq');
            }
        }
        $result['chat']['qa'] = $result['qa'];
        foreach (['personal', 'management', 'tags', 'reports'] as $group) {
            $result['chat'][$group] = $result[$group];
        }
        $result['chat']['feedbackGood'] = get_string('chatfeedbackgood', 'local_videoq');
        $result['chat']['feedbackBad'] = get_string('chatfeedbackbad', 'local_videoq');
        return $result['chat'];
    }
}
