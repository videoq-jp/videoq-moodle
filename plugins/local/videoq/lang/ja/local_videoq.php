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

$string['pluginname'] = 'VideoQ 動画・質問画面';
$string['videoq:viewtranscript'] = 'アクセス可能なVideoQ教材の文字起こしを閲覧する';
$string['videoq:viewqa'] = '講座で共有されるQ&Aを閲覧する';
$string['videoq:postqa'] = '講座の質問・返信を投稿し、自分の文章を編集する';
$string['videoq:voteqa'] = '講座の質問に投票する';
$string['videoq:deleteownqa'] = '自分が投稿した講座の質問・返信を削除する';
$string['videoq:moderateqa'] = '他の受講者の投稿を削除し、講座の質問を注目に指定する';
$string['videoq:instructorqa'] = '講座のQ&Aを投稿・編集するときに教師バッジを表示する';
$string['courseqa'] = '講座のQ&A';
$string['courseqaunavailable'] = '講座のQ&Aを表示できません。閲覧権限を確認してコースのページを開き直してください。';
$string['privacy:metadata:courseqa'] = '講座で公開される質問・返信・投票と管理操作の記録です。VideoQにコースごとのMoodle仮名で保存します。この共有Q&Aと、非公開のAI会話履歴は別に扱います。';
$string['transcript'] = '教材の文字起こし';
$string['transcriptlesson'] = '教材';
$string['transcriptrefresh'] = '教材一覧を更新';
$string['transcriptmore'] = '続きを表示';
$string['transcriptempty'] = '文字起こしはまだありません。';
$string['transcripterror'] = '教材を取得できません。権限と接続を確認し、教材一覧を更新してください。';
$string['privacy:metadata:transcript'] = '現在アクセスできる教材の文字起こし本文と時刻です。Moodle内で表示するためにVideoQから取得します。';
$string['lessons'] = '動画と質問教材の紐付け';
$string['lessons_desc'] = 'id・title・videoCmid（MP4を登録したファイル活動）・ltiCmid（同じコースのVideoQ LTI 1.3活動）を持つJSON配列を指定します。例：[{"id":"lesson-1","title":"第1回","videoCmid":10,"ltiCmid":11}]。/local/videoq/view.php?lesson=lesson-1 から開けます。両方の活動の閲覧制限が適用されます。';
$string['invalidconfiguration'] = '動画と質問教材の紐付けが正しくありません。管理者にお問い合わせください。';
$string['unknownlesson'] = 'この教材は設定されていません。';
$string['invalidmodules'] = '同じコース内のファイル活動と、埋め込み表示のLTI 1.3活動を指定してください。';
$string['video'] = '動画教材';
$string['questions'] = '教材に質問';
$string['privacy:metadata'] = 'このプラグインは教材の紐付けと同期状態を保存し、受講者の情報は保存しません。質問機能を有効にした教材はVideoQへ転送されます。';
$string['privacy:metadata:video'] = '管理者が紐付けたVideoQ動画の識別子。';
$string['privacy:metadata:position'] = '取得できる場合に質問へ添えられる、現在の動画の再生位置。';
$string['privacy:metadata:videoq'] = '設定したVideoQと質問文・AI回答・回答評価・再生位置・教材の閲覧権限の証明を受け渡し、質問機能を有効にした動画ファイルと教材情報を転送します。プラグイン独自のテーブルには同期状態を保存し、受講者の会話は保存しません。ログインセッションはMoodle、質問履歴とAI処理はVideoQが管理します。';
$string['apiurl'] = 'VideoQのAPIオリジン';
$string['synctoken'] = '同期用接続キー';
$string['typeid'] = '登録済みLTIツールのタイプID';
$string['managesync'] = 'VideoQの質問機能';
$string['synchronize'] = 'VideoQ教材の同期';
$string['novideofile'] = 'このファイル活動に対応する動画を登録してください。';
$string['syncdescription'] = 'この動画の質問機能を有効にします。動画の変更はMoodleの定期処理で自動同期されます。';
$string['syncstatus'] = '処理状況';
$string['syncerror'] = '同期に問題があります。接続設定と利用枠を確認して再試行してください。';
$string['sync_enable'] = '質問機能を有効にする';
$string['sync_disable'] = '質問機能を無効にする';
$string['sync_retry'] = '同期を再試行する';
$string['openlesson'] = '動画と質問画面を開く';
$string['refreshstatus'] = '処理状況を更新';
$string['state_off'] = '未設定';
$string['state_queued'] = '同期待ち';
$string['state_restoring'] = '復元した教材を準備中';
$string['state_disabled'] = '無効';
$string['state_uploading'] = '動画を転送しています';
$string['state_pending'] = '動画の処理待ち';
$string['state_processing'] = '動画を処理しています';
$string['state_indexing'] = '回答の準備をしています';
$string['state_completed'] = '質問できます';
$string['state_error'] = '処理に失敗しました。再試行してください。';
$string['state_blocked'] = '利用枠を超えています';
$string['state_awaiting_upload'] = '動画の転送待ち';
$string['privacy:metadata:videofile'] = '質問機能を有効にした動画教材のファイル。';
$string['privacy:metadata:title'] = '同期対象の教材名と、Moodleのコース・活動の識別情報。';

$string['privacy:metadata:access'] = 'Moodleの受講者・コース・起動した活動に紐づく一時的な証明です。質問や文字起こしの取得のたびに閲覧できる動画と現在のコース権限を再確認するために使用します。';

$string['coursequestions'] = '講座に質問';
$string['chatempty'] = '講座内の動画をまとめて質問できます。';
$string['chatconnecting'] = 'VideoQに接続しています…';
$string['chathint'] = 'Enterで送信・Shift＋Enterで改行。AIの回答は教材とあわせて確認してください。';
$string['chatconnectionerror'] = '接続を確認できません。ページを再読み込みしてください。';
$string['chatseek'] = 'へ移動';

$string['privacy:metadata:conversation'] = 'Moodleの質問画面と登録済みVideoQツールの間で受け渡す質問文とAI回答です。質問の記録はVideoQで管理します。';

$string['assistant'] = 'AIアシスタント';
$string['courseassistant'] = 'AIアシスタント（講座全体に質問）';
$string['nocoursevideos'] = '現在、質問できる動画教材はありません。教材の処理が完了すると利用できます。';

$string['connecttitle'] = 'VideoQと接続';
$string['connectioncode'] = '接続コード';
$string['connectbutton'] = '接続';
$string['connectionsuccess'] = 'VideoQとの接続が完了しました。';
$string['connectionready'] = 'VideoQに接続されています。';
$string['connectionunavailable'] = '接続を確認できませんでした。VideoQの稼働状況と接続設定を確認してください。既存の設定は保持されています。';
$string['connectionnext'] = '講座に動画をファイル教材として追加し、教材の「VideoQの質問機能」を有効にすると質問できます。';
$string['connectioninstructions'] = 'VideoQの「設定 → Moodleと連携」で、このMoodleのURLを指定して発行した接続コードを貼り付けてください。LTIと同期設定を自動で登録します。';
$string['connectionfailed'] = '接続できませんでした。両方のVideoQプラグイン、接続先URL、VideoQの稼働状況を確認してください。再試行ボタンがない場合は、Moodleから接続をやり直してください。既存の教材は保持されています。';
$string['cancelconnection'] = '未完了の設定を中止してやり直す';
$string['cancelconnectionhint'] = '未使用のローカル設定を取り消します。VideoQ側に接続が表示されている場合は、そちらでも接続を解除してください。';
$string['advancedsettings'] = 'VideoQ 詳細設定（手動接続）';

$string['connectionconfigured'] = 'LTI接続が設定されています。接続状態はVideoQの設定画面で確認できます。';

$string['updateconnection'] = 'VideoQの接続を更新・再接続する';
$string['videoqurl'] = '接続先のVideoQ URL';
$string['browserinstructions'] = 'クラウド版またはオンプレミス版のVideoQ URLを入力してください。VideoQでログインして接続を許可すると、この画面に戻ります。';
$string['browserconnect'] = 'VideoQに接続';
$string['manualconnection'] = '接続コードで接続（従来の方法）';
$string['connectioncancelled'] = '接続をキャンセルしました。';
$string['retryconnection'] = '接続を再試行';

$string['privacy:metadata:feedback'] = '回答を識別する情報とともにVideoQへ送信・保存する、受講者のGood/Bad評価または評価の解除。';
$string['privacy:metadata:core'] = '管理者の接続用一時セッション、設定変更の記録、復元を開始した利用者として実行するタスクはMoodle本体が管理します。これらの記録へのデータ要求はMoodle本体のPrivacy APIが処理します。';
$string['chatfeedbackgood'] = 'Good';
$string['chatfeedbackbad'] = 'Bad';
