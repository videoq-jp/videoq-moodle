# VideoQ for Moodle

Moodle上で動画を見ながら、講座全体の教材についてVideoQのAIアシスタントに質問するためのプラグインです。
VideoQ本体とは独立したリポジトリとして、Moodle側の実装・配布・検証を管理します。

## 導入

1. このリポジトリのCI成果物、または下記のビルドで作成した `local_videoq.zip` と
   `ltisource_videoq.zip` を、Moodleのプラグインインストール画面から順に導入します。
2. 学校のVideoQアカウントで **設定 → Moodleと連携** を開き、MoodleのURLを入力して接続コードを発行します。
3. Moodleの **サイト管理 → プラグイン → ローカルプラグイン → VideoQと接続** にコードを貼り付けます。
4. 動画を「ファイル」教材として登録し、教材の **VideoQの質問機能** を有効にします。

Moodle 5.1.8+ / Boostテーマで検証しています。LTI認証取得済みではありません。
詳しくは [日本語の導入ガイド](docs/setup.ja.md) / [English setup guide](docs/setup.md) を参照してください。

## 対応するVideoQサーバー

`/api/lti/*` と `/api/lms/*`、接続コード発行・解除に対応したVideoQが必要です。
切り出し時点の対応APIはVideoQの開発ワークツリーにあり、[移行元コミット](docs/extraction.md)だけを
取得したビルドでは利用できません。対応APIを含むサーバーへ接続してください。
このリポジトリにVideoQ本体のAPIを複製することはありません。

## 開発・配布ZIP

Node.jsは `.nvmrc` を参照してください。プラグインのZIP作成と通常テストにはVideoQ本体のチェックアウトは不要です。

```bash
npm ci
npm run check
npm test
npm run package:plugins
```

`dist/local_videoq.zip` と `dist/ltisource_videoq.zip` を生成します。
両方のZIPは、Moodleが求める `videoq/` ディレクトリから始まります。
CIでも同じチェックとPHP 8.3の構文確認を行い、ZIPを成果物として保存します。

## リポジトリの役割分担

| このリポジトリ | VideoQ本体 |
|---|---|
| `plugins/local/videoq`：同期、動画・チャット画面、接続設定 | LTI認証・セッション、接続登録・解除API |
| `plugins/ltisource/videoq`：LTI起動時の閲覧権限証明 | 動画管理、文字起こし、検索、AI回答 |
| `scripts/`：配布ZIP作成・チェック | 学校アカウント・利用枠・ストレージ・ワーカー |
| `lab/`：Moodleとの結合検証環境 | API・データベースのマイグレーション |

VideoQ本体のソースを実行時に取り込むことはありません。HTTPSのLTI・同期APIを通じて接続します。
接続コードの発行と解除画面は、アカウントを管理するVideoQ本体にあります。

## 結合検証

[検証環境の手順](lab/README.md)を参照してください。結合検証のみ、別途
[VideoQ本体](https://github.com/yukiharada1228/videoq)のチェックアウトとビルドが必要です。

`lab/compose.env.example` を参考に `VIDEOQ_ROOT` に本体、`MOODLE_INTEGRATION_ROOT` にこのリポジトリの
絶対パスを指定します。Composeはこのリポジトリからプラグインを、本体からAPIとワーカーをマウントします。
検証環境には公開のテスト用認証情報が含まれます。本番環境では使用しないでください。
実APIキー、教材、検証結果、バックアップはこのリポジトリに含めません。

## ライセンスと移行元

Moodleプラグインは既存の **GPL-3.0-or-later** を維持します。各プラグインの `LICENSE` を参照してください。
検証スクリプトとドキュメントは、移行元の [PolyForm Noncommercial 1.0.0](LICENSE) を維持します。
VideoQ本体のライセンスを変更するものではありません。

[移行記録](docs/extraction.md)に元のリポジトリと切り出し範囲を記録しています。
