<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
$string['pluginname'] = 'VideoQ 動画・質問画面';
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
$string['privacy:metadata:videoq'] = '紐付けたVideoQのLTIツールへ再生位置を送信します。このプラグインは受講者の記録をローカル保存しません。質問とセッションはLTIツールが管理します。';
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

$string['privacy:metadata:access'] = 'Moodleの受講者・コース・起動した活動に紐づく一時的な証明です。質問のたびに閲覧できる動画を再確認するために使用します。';

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
$string['connectionfailed'] = '接続できませんでした。両方のVideoQプラグイン、接続先URL、コードの有効期限を確認してください。通信エラーの場合は同じコードで再試行してください。既存の接続設定は変更していません。';
$string['cancelconnection'] = '未完了の設定を中止してやり直す';
$string['cancelconnectionhint'] = '未使用のローカル設定を取り消します。VideoQ側に接続が表示されている場合は、そちらでも接続を解除してください。';
$string['advancedsettings'] = 'VideoQ 詳細設定（手動接続）';

$string['connectionconfigured'] = 'LTI接続が設定されています。接続状態はVideoQの設定画面で確認できます。';

$string['updateconnection'] = 'VideoQの接続を更新・再接続する';
