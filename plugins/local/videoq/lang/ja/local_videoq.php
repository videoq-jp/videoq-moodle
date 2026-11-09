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
$string['managecourseentry'] = 'VideoQのコース入口を管理';
$string['courseentrydescription'] = '動画がないコースにもVideoQの入口を作成できます。講座のQ&A・自分の履歴・タグには、現在のMoodle権限が適用されます。AIへの質問には、閲覧可能で処理済みの動画が必要です。この入口を無効にしても、共有コースのデータや動画活動は保持します。';
$string['courseentryerror'] = 'コースの入口を更新できませんでした。VideoQとの接続と、本体がコース入口に対応していることを確認して再試行してください。';
$string['courseentry_enable'] = 'コースの入口を有効にする';
$string['courseentry_disable'] = 'コースの入口を無効にする';
$string['courseentry_repair'] = '入口を修復して同期する';
$string['courseentrypending'] = 'コースの入口を同期しています。少し待ってからこのページを更新してください。';
$string['courseentryempty'] = '現在、この画面で利用できる動画教材はありません。権限に応じて講座のQ&Aや自分の履歴を利用できます。閲覧可能な動画の処理が完了すると、AIに質問できます。';
$string['courseentryrefresh'] = 'コースの入口を更新';
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
$string['privacy:metadata:materialmanagement'] = '教師が編集するAI用説明・SRT文字起こしと再処理の要求、およびMoodle利用者の仮名キー・教材識別子・操作・版・時刻を含む監査記録です。VideoQに保存し、監査記録には編集本文を含めません。';
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
$string['managesync'] = 'VideoQ';
$string['synchronize'] = 'VideoQ教材の同期';
$string['novideofile'] = 'このファイル活動に対応する動画を登録してください。';
$string['syncdescription'] = 'この動画についてAIに質問できます。';
$string['syncstatus'] = '処理状況';
$string['syncerror'] = '同期に問題があります。接続設定と利用枠を確認して再試行してください。';
$string['sync_enable'] = '質問を有効にする';
$string['sync_disable'] = '無効にする';
$string['sync_retry'] = '再試行';
$string['openlesson'] = '動画と質問画面を開く';
$string['refreshstatus'] = '更新';
$string['state_off'] = '未設定';
$string['state_queued'] = '準備待ち';
$string['state_restoring'] = '復元中';
$string['state_disabled'] = '無効';
$string['state_uploading'] = '転送中';
$string['state_pending'] = '処理待ち';
$string['state_processing'] = '文字起こし中';
$string['state_indexing'] = '検索準備中';
$string['state_completed'] = '準備完了';
$string['state_error'] = '準備に失敗しました';
$string['state_blocked'] = '利用上限に達しました';
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

$string['videoq:manageownhistory'] = '自分の保存済みAI会話を管理する';
$string['videoq:managecontent'] = '講座教材の派生コンテンツを管理する';
$string['materialmanagement'] = '教材を管理';
$string['sourcematerialactions'] = 'Moodleの元教材を管理';
$string['sourceedit'] = '名前・ファイル・公開状態を編集';
$string['sourcesync'] = 'VideoQとの同期を管理';
$string['sourcecourse'] = 'コースで教材を操作（削除など）';
$string['materialmanagementunavailable'] = '教材管理を利用できません。現在の権限を確認するか、開き直してください。';
$string['personalconversations'] = '自分のAI会話';
$string['personalconversationsunavailable'] = '保存済み会話を利用できません。コースから開き直すか管理者にお問い合わせください。';

$string['accountmanagement'] = '接続先のVideoQアカウントを管理';
$string['accountmanagementhelp'] = 'VideoQのアカウント設定・課金・システム管理には、VideoQへの別途ログインと、対応するアカウントの権限が必要です。';
$string['accountunavailable'] = 'VideoQのアカウント管理画面を開けません。接続を確認し、必要に応じてVideoQサーバーを更新してください。';

$string['coursetags'] = 'コースのタグと教材';
$string['coursetagsunavailable'] = 'コースのタグを利用できません。現在のMoodle権限を確認するか、開き直してください。';
$string['privacy:metadata:coursetags'] = 'コース専用のタグ名・色・教材との対応と、仮名化した操作記録をVideoQに保存します。';

$string['privacyunavailable'] = '外部サービスへの個人データ要求を完了できませんでした。管理者に承認済みのVideoQ接続と個人データ要求サービスの確認を依頼し、再実行してください。';
$string['privacyexportscope'] = '承認されたMoodleコンテキスト内の本人の記録です。モデルメモリと仮名化した操作記録を含みます。共有教材と他の参加者の投稿は残ります。本人との紐付けを導入する前の記録、バックアップ、AIプロバイダーの記録は運用者による別途の確認が必要です。同時編集がある場合は出力の再実行が必要です。 二重実行を防ぐため、操作識別子の技術的なハッシュは残ります。本人キー・要求本文・時刻は含みません。';

$string['privacy:metadata:operationbarriers'] = '削除した操作記録と同じ要求が再実行されないようVideoQに残す、ランダムな操作識別子の名前空間付きSHA-256ハッシュです。この再送防止用の記録には、利用者・コース・操作種別・要求本文・時刻の列はありません。';

$string['coursecatalog'] = 'VideoQのコース教材を管理';
$string['cataloghelp'] = '動画をMoodleのファイル活動として追加・保存してから、各動画のVideoQ連携を有効にします。活動の移動やセクションの変更はコースの編集モードで行います。VideoQの教材一覧もその順に並びます。公開状態・利用条件・参加者はMoodleで管理します。Moodleコースの削除で起動権限は失効します。外部の履歴は別途プライバシー・保存期限の手続きに従います。';
$string['catalogempty'] = 'このコースには管理できる動画のファイル活動がありません。動画のファイルを追加・保存し、この一覧からVideoQ連携を有効にしてください。';
$string['catalogorganize'] = 'コースの活動を整理';
$string['catalogupload'] = '動画のファイルを追加';
$string['catalogcourseedit'] = 'コース設定を編集';
$string['catalogparticipants'] = 'コースの参加者';
$string['catalogenrolments'] = '登録方法を管理';
$string['catalogcoursecreate'] = 'Moodleコースを作成';
$string['catalogcoursedelete'] = 'Moodleコースを削除…';

$string['videoq:viewreports'] = '現在閲覧できる教材のAI質問利用状況を集計して表示する';
$string['coursereports'] = 'AI質問の利用状況';
$string['nativeunavailable'] = '学習サービスに接続できないか、現在のMoodleの権限ではこの操作を行えません。';
$string['catalogyoutube'] = 'YouTubeのURLを追加';
$string['invalidyoutubeurl'] = 'YouTube動画を1本指定する、対応形式のURLを入力してください。';
$string['youtubedisabled'] = '接続先のVideoQ環境ではYouTube連携を利用できません。';
$string['sourceactivity'] = 'Moodleの元教材を開く';
$string['sourceupdated'] = '元教材が変更されました。更新後の動画を使うには、このMoodleページを再読み込みしてください。';
$string['youtubeplaybackerror'] = 'YouTube動画を読み込めませんでした。Moodleの元教材を開いて、動画を利用できるか確認してください。';
$string['youtubeautoplay'] = 'この引用箇所から再生を続けるには、YouTubeプレーヤーの再生ボタンを押してください。';
$string['privacy:metadata:youtube'] = '連携を有効にしたYouTubeのURL教材を再生すると、閲覧者のブラウザでYouTubeプレーヤーとJavaScript APIを読み込みます。そのプレーヤーへVideoQの認証情報や会話本文を送信しません。';
$string['privacy:metadata:youtubeplayback'] = 'プレーヤーの読み込みや操作に伴い、動画ID、ネットワーク・ブラウザ情報、再生操作に関する情報がYouTubeへ送信されます。接続先のVideoQサービスは、アカウント所有者のプロバイダー設定を使い、別途字幕を取得します。';
$string['coursereportsunavailable'] = 'レポートを利用できません。現在のMoodle権限を確認するか、開き直してください。';
$string['privacy:metadata:coursereports'] = 'VideoQはAI回答の所属コース情報と、レポート閲覧の期間・教材範囲ハッシュ・件数・時刻を含む識別情報のみの監査記録を保存します。レポートには個人の質問・回答本文や学習者の識別情報を表示しません。';

// Native learning interface.
$string['nativechat_transcript'] = '教材の文字起こし';
$string['nativechat_material'] = '教材';
$string['nativechat_refreshmaterials'] = '教材一覧を更新';
$string['nativechat_moretranscript'] = '続きを表示';
$string['nativechat_emptytranscript'] = '文字起こしはまだありません。';
$string['nativechat_materialerror'] = '教材を取得できません。権限と接続を確認し、教材一覧を更新してください。';
$string['nativechat_materialpreparing'] = '教材を処理中です。準備ができるまで質問は利用できません。教材管理から状況を確認できます。';
$string['nativechat_connecttitle'] = 'VideoQ 接続';
$string['nativechat_connecting'] = '学習システムに接続しています…';
$string['nativechat_storageerror'] = '接続情報を保存できません。LMSから別ウィンドウで開いてください。';
$string['nativechat_title'] = 'VideoQ 教材に質問';
$string['nativechat_chat'] = '教材チャット';
$string['nativechat_heading'] = '講座に質問';
$string['nativechat_conversation'] = '会話';
$string['nativechat_emptytitle'] = 'この講座について聞いてみましょう';
$string['nativechat_emptybody'] = '講座内の動画をまとめて質問できます。';
$string['nativechat_checking'] = '接続を確認しています…';
$string['nativechat_label'] = '教材について聞きたいこと';
$string['nativechat_placeholder'] = '教材について質問する…';
$string['nativechat_send'] = '質問する';
$string['nativechat_hint'] = 'Enterで送信・Shift＋Enterで改行。AIの回答は教材とあわせて確認してください。';
$string['nativechat_you'] = 'あなた';
$string['nativechat_assistant'] = 'AIアシスタント';
$string['nativechat_feedbackerror'] = '評価を保存できませんでした。もう一度お試しください。';
$string['nativechat_interrupted'] = '回答の受信が中断されました。もう一度お試しください。';
$string['nativechat_seek'] = 'へ移動';
$string['nativechat_playbackerror'] = '動画の接続と再生位置を確認してください。';
$string['nativechat_missing'] = '接続情報がありません。LMSの教材リンクから開き直してください。';
$string['nativechat_connectionerror'] = '接続できません。教材の連携設定を確認し、LMSから開き直してください。';
$string['nativechat_answering'] = '教材を確認して回答しています…';
$string['nativechat_expired'] = '接続の有効期限が切れました。LMSから開き直してください。';
$string['nativechat_ratelimit'] = '質問が集中しています。少し待って再試行してください。';
$string['nativechat_answererror'] = '回答を取得できませんでした。時間をおいて再試行してください。';
$string['nativeqa_title'] = '講座のQ&A';
$string['nativeqa_shared'] = '投稿した質問と返信は、この講座のQ&Aを閲覧できる利用者に共有されます。';
$string['nativeqa_loading'] = 'Q&Aを読み込んでいます…';
$string['nativeqa_error'] = 'Q&Aを取得できません。権限と接続を確認し、もう一度お試しください。';
$string['nativeqa_search'] = '質問を検索';
$string['nativeqa_lesson'] = '教材';
$string['nativeqa_alllessons'] = 'すべての教材';
$string['nativeqa_wholecourse'] = '講座全体';
$string['nativeqa_unavailablelesson'] = '選択した教材を閲覧できません。教材を選び直してください。';
$string['nativeqa_unanswered'] = '返信のない質問';
$string['nativeqa_sort'] = '並び順';
$string['nativeqa_recent'] = '新しい順';
$string['nativeqa_votes'] = '投票';
$string['nativeqa_apply'] = '検索する';
$string['nativeqa_refresh'] = '更新';
$string['nativeqa_ask'] = '質問を投稿';
$string['nativeqa_questiontitle'] = '質問のタイトル';
$string['nativeqa_questionbody'] = '質問の内容';
$string['nativeqa_publish'] = '投稿する';
$string['nativeqa_reply'] = '返信する';
$string['nativeqa_replybody'] = '返信の内容';
$string['nativeqa_postreply'] = '返信を投稿';
$string['nativeqa_edit'] = '編集';
$string['nativeqa_delete'] = '削除';
$string['nativeqa_save'] = '保存';
$string['nativeqa_cancel'] = 'キャンセル';
$string['nativeqa_back'] = '質問一覧に戻る';
$string['nativeqa_previous'] = '前へ';
$string['nativeqa_next'] = '次へ';
$string['nativeqa_replies'] = '返信';
$string['nativeqa_empty'] = '質問はまだありません。';
$string['nativeqa_noreplies'] = '返信はまだありません。';
$string['nativeqa_feature'] = '注目に指定';
$string['nativeqa_unfeature'] = '注目を解除';
$string['nativeqa_featured'] = '注目';
$string['nativeqa_vote'] = '投票する';
$string['nativeqa_removevote'] = '投票を取り消す';
$string['nativeqa_participant'] = '参加者';
$string['nativeqa_instructor'] = '教師';
$string['nativeqa_deleteduser'] = '削除済み利用者';
$string['nativeqa_readonly'] = '現在は閲覧のみ可能です。入力した文章をコピーできます。';
$string['nativeqa_confirmtitle'] = '操作の確認';
$string['nativeqa_discardaction'] = '変更を破棄';
$string['nativeqa_discard'] = '投稿・保存していない変更を破棄しますか？';
$string['nativeqa_deletequestion'] = 'この質問を削除しますか？返信もすべて削除されます。';
$string['nativeqa_deletereply'] = 'この返信を削除しますか？';
$string['nativepersonal_title'] = '自分のAI会話';
$string['nativepersonal_notice'] = '自分のAI会話はコースQ&Aには公開されません。接続先VideoQのアカウント所有者は保存ログを管理できます。';
$string['nativepersonal_new'] = '新しい会話';
$string['nativepersonal_list'] = '保存済み会話';
$string['nativepersonal_recent'] = '以前の直近の質問';
$string['nativepersonal_refresh'] = '更新';
$string['nativepersonal_previous'] = '前へ';
$string['nativepersonal_next'] = '次へ';
$string['nativepersonal_open'] = '読む';
$string['nativepersonal_continue'] = 'この会話を続ける';
$string['nativepersonal_older'] = '以前のメッセージ';
$string['nativepersonal_latest'] = '最新のメッセージ';
$string['nativepersonal_export'] = '会話をダウンロード';
$string['nativepersonal_delete'] = '会話を削除';
$string['nativepersonal_confirm'] = 'この会話と回答ログ、AIの内部履歴を削除しますか？元に戻せません。';
$string['nativepersonal_cancel'] = 'キャンセル';
$string['nativepersonal_empty'] = '保存済みの会話はありません。';
$string['nativepersonal_loading'] = '読み込み中…';
$string['nativepersonal_error'] = '保存済み会話を読み込めません。更新するかMoodleから開き直してください。';
$string['nativepersonal_busy'] = '回答が終わってから操作してください。';
$string['nativepersonal_unavailable'] = '保存済み会話を利用できません。';
$string['nativepersonal_question'] = 'あなた';
$string['nativepersonal_answer'] = 'VideoQ';
$string['nativepersonal_back'] = '保存済み会話に戻る';
$string['nativemanagement_title'] = '教材を管理';
$string['nativemanagement_notice'] = 'AI用の説明とSRT文字起こしを編集できます。元教材の名前・ファイル・公開状態・削除はMoodleで変更してください。';
$string['nativemanagement_lesson'] = '管理する教材';
$string['nativemanagement_description'] = 'AI用の説明';
$string['nativemanagement_transcript'] = '文字起こし（SRT）';
$string['nativemanagement_save'] = '変更を保存';
$string['nativemanagement_refresh'] = '教材情報を更新';
$string['nativemanagement_reindex'] = '検索索引を再作成';
$string['nativemanagement_retry'] = '処理を再試行';
$string['nativemanagement_close'] = '質問画面に戻る';
$string['nativemanagement_loading'] = '読み込み中…';
$string['nativemanagement_saved'] = '変更を保存しました。';
$string['nativemanagement_queued'] = '処理を受け付けました。索引の更新が終わると、この教材をAI検索で再び利用できます。';
$string['nativemanagement_unavailable'] = '教材管理を利用できません。現在のMoodle権限を確認するか、開き直してください。';
$string['nativemanagement_error'] = '操作を完了できません。下書きは保持しています。更新するかMoodleから開き直してください。';
$string['nativemanagement_conflict'] = '教材が変更されたか、処理中です。下書きは保持しています。必要な変更をコピーしてから更新してください。';
$string['nativemanagement_validation'] = '文字起こしはSRT形式で2MiB以内、説明は2万文字かつ40KB以内にしてください。下書きは保持しています。';
$string['nativemanagement_discard'] = '未保存の変更を破棄して、現在の教材情報を読み込みますか？';
$string['nativemanagement_discardaction'] = '変更を破棄';
$string['nativemanagement_clear'] = '文字起こしを消去し、この教材をAI検索から外しますか？Moodleの元ファイルは残ります。';
$string['nativemanagement_reindexconfirm'] = '検索索引を再作成しますか？処理が終わるまで、この教材はAI検索の対象から外れます。';
$string['nativemanagement_retryconfirm'] = '処理を再試行しますか？処理量の上限は引き続き適用されます。';
$string['nativemanagement_confirm'] = '続ける';
$string['nativemanagement_pending'] = '処理中です。処理が終わると編集できます。';
$string['nativemanagement_syncwaiting'] = 'この教材を質問で使えるよう、Moodleへの同期反映を待っています。';
$string['nativemanagement_olderjob'] = 'この処理エラーは、Moodleの「同期を再試行」からやり直してください。';
$string['nativemanagement_unsaved'] = '未保存の変更があります';
$string['nativemanagement_changed'] = '保存済みの教材が変更されました。下書きは保持しています。';
$string['nativemanagement_status'] = '状態';
$string['nativemanagement_job'] = '処理状況';
$string['nativemanagement_completed'] = '準備完了';
$string['nativemanagement_indexing'] = '検索索引を更新中';
$string['nativemanagement_errorstate'] = '処理エラー';
$string['nativemanagement_pendingstate'] = '処理待ち';
$string['nativemanagement_processing'] = '処理中';
$string['nativemanagement_uploading'] = '転送中';
$string['nativemanagement_failed'] = '失敗';
$string['nativemanagement_queuedstate'] = '待機中';
$string['nativemanagement_running'] = '実行中';
$string['nativetags_title'] = 'コースのタグと教材';
$string['nativetags_notice'] = 'このタグはMoodleのコース内で使います。VideoQ全体のタグは別途管理します。';
$string['nativetags_refresh'] = 'タグを更新';
$string['nativetags_close'] = '質問画面に戻る';
$string['nativetags_all'] = 'すべての教材';
$string['nativetags_untagged'] = 'タグなし';
$string['nativetags_filter'] = '教材を絞り込む';
$string['nativetags_search'] = '教材名を検索';
$string['nativetags_create'] = 'タグを作成';
$string['nativetags_edit'] = 'タグを編集';
$string['nativetags_assign'] = '教材のタグを編集';
$string['nativetags_save'] = 'タグを保存';
$string['nativetags_remove'] = 'タグを削除';
$string['nativetags_name'] = 'タグ名';
$string['nativetags_color'] = '色';
$string['nativetags_vocabulary'] = 'コースのタグ';
$string['nativetags_materials'] = '教材';
$string['nativetags_open'] = '教材を開く';
$string['nativetags_transcript'] = '文字起こしを読む';
$string['nativetags_empty'] = '該当する教材がありません。';
$string['nativetags_loading'] = '読み込み中…';
$string['nativetags_saved'] = 'タグを保存しました。';
$string['nativetags_error'] = '操作を完了できません。下書きは保持しています。更新するかMoodleから開き直してください。';
$string['nativetags_conflict'] = 'タグが変更されています。下書きは保持しています。更新してから保存してください。';
$string['nativetags_unavailable'] = '現在のMoodle権限ではコースのタグを利用できません。';
$string['nativetags_discard'] = 'タグの未保存の変更を破棄しますか？';
$string['nativetags_discardaction'] = '変更を破棄';
$string['nativetags_confirmdelete'] = 'このコースのタグを削除し、教材から外しますか？';
$string['nativetags_cancel'] = '編集をキャンセル';
$string['nativetags_unsaved'] = '未保存の変更があります';
$string['nativetags_readonly'] = '教材へのタグ付けを変更できます。管理できない教材でも使われているタグの名前変更・削除はできません。';
$string['nativereports_title'] = 'AI質問の利用状況';
$string['nativereports_notice'] = '元の教材範囲を現在すべて閲覧できる、所属コース確認済みのMoodle経由のAI回答を集計します。所属コースを確認できない過去の回答は含みません。日付はUTCです。個人の質問・回答本文や学習者の識別情報は表示しません。';
$string['nativereports_from'] = '開始日';
$string['nativereports_to'] = '終了日';
$string['nativereports_load'] = 'レポートを更新';
$string['nativereports_csv'] = '集計CSVをダウンロード';
$string['nativereports_close'] = '質問画面に戻る';
$string['nativereports_questions'] = '質問数';
$string['nativereports_good'] = '役に立った';
$string['nativereports_bad'] = '役に立たなかった';
$string['nativereports_none'] = '未評価';
$string['nativereports_date'] = '日付';
$string['nativereports_empty'] = 'この期間と教材範囲に該当するAI質問はありません。';
$string['nativereports_loading'] = '読み込み中…';
$string['nativereports_error'] = 'レポートを取得できません。日付と現在のMoodle権限を確認してください。';
$string['nativereports_unavailable'] = 'レポートを利用できません。現在のMoodle権限を確認するか、開き直してください。';
$string['nativeaccessdenied'] = '現在のMoodleの権限では、この操作を行えません。';

$string['syncpreparing'] = '準備が終わると質問できます。';

$string['askvideo'] = '質問する';
