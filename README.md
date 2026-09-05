# まちの記録帖 / Place Field Notes

OpenStreetMap や Wikipedia Town などの地域活動の成果を、地図・写真・コメントとともに「まちの記録」として残し、共有するためのWebアプリケーションです。

Place Field Notes is a web application for documenting and sharing the results of local open-data activities such as OpenStreetMap mapping and Wikipedia Town events through maps, photos, and field notes.

## コンセプト

OpenStreetMap の changeset や編集件数そのものではなく、**「その場所で何が調べられ、何が記録され、何が変わったか」**を分かりやすく見せることを目的としています。

個人のマッピングだけでなく、マッピングパーティー、Wikipedia Town、フィールドワークなど、複数人で行う活動の成果発表にも利用できる設計を目指します。

## 主な機能（予定）

- 活動場所を地図上の範囲（BBOX）で指定
- 活動の開始日時・終了日時を記録
- Overpass API の履歴差分（`adiff`）を利用した OSM 変更内容の取得
- 過去の PMTiles と活動期間中の差分を重ねた成果マップ
- 追加・変更・削除された地物の可視化
- 主な変更点の自動集計
- Wikipedia / Wikidata / Wikimedia Commons に関連する成果の表示
- 成果地点ごとに OSM / Wikipedia / Wikidata / Commons をまとめて表示
- 写真のアップロード
- Wikimedia Commons の `File:` 名による写真指定
- 一般URLによる写真・資料の参照
- 写真のキャプション、作者・クレジット、ライセンス表示
- 活動記録（Field Notes）の作成
- 日本語 / English 対応

## 編集モデル

ユーザー登録やログインは設けず、作品ごとに次のURLを発行する想定です。

- **閲覧用URL**: 誰でも閲覧可能な読み取り専用ページ
- **編集用URL**: 編集トークンを持つ人だけが編集可能

合同イベントでは編集用URLを参加者・運営者間で共有して利用できます。

## OSM差分表示

活動終了後に作品を作成できることを前提としています。

1. 活動範囲（BBOX）と開始・終了日時を指定
2. 履歴対応 Overpass API から `adiff` を取得
3. GeoJSONへ変換して保存
4. 活動開始日以前の比較用 PMTiles と重ねて表示

比較用PMTilesは活動開始時点を厳密に再現するものではないため、公開ページでは利用したスナップショットの日付を明示します。

## Wikimediaとの連携

OpenStreetMapだけでなく、Wikipedia Town等の成果も同じ作品内で扱えるようにします。

1つの成果地点（Place）に、例えば以下をまとめて紐付けられる構造を想定しています。

- OpenStreetMap
- Wikipedia
- Wikidata
- Wikimedia Commons
- 写真
- コメント / 活動記録

## 写真とライセンス

ローカルアップロードのほか、Wikimedia Commons の File 名や一般URLによる参照に対応する予定です。

写真ライセンスの初期候補:

- CC BY 4.0
- CC BY-SA 4.0
- CC0 1.0

`All rights reserved` は選択肢に含めません。


## 開発状況

現在は設計・初期実装段階です。仕様や実装タスクは GitHub Issues で管理しています。

- [Issues](https://github.com/K-Sakanoshita/place_field_notes/issues)

地図・写真・Wikipedia / Wikimedia / OpenStreetMap由来のデータやコンテンツについては、それぞれの出典・ライセンスが別途適用されます。


## 実装内容（PHP, SQLite, FastAPI 風）

   ファイル                     役割
  ━━━━━━━━━━━━━━━━━━━━━━━━━━━  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   index.php                    ルーティング・リクエストハンドラ。/api/projects（作成・取得）、/api/osm-
                                diff（差分プレビュー）、/api/projects/{id}/save-diff（差分保存）を処理。
  ───────────────────────────  ─────────────────────────────────────────────────────────────────────────────────
   src/Database.php             SQLite データベース初期化と PDO 取得。projects と diffs テーブルを作成。
  ───────────────────────────  ─────────────────────────────────────────────────────────────────────────────────
   src/Utils.php                共通ヘルパー。短い公開 ID、編集トークン生成・ハッシュ化、タイムゾーン→UTC変換、
                                JSON レスポンス。
  ───────────────────────────  ─────────────────────────────────────────────────────────────────────────────────
   src/ProjectController.php    作品の作成・取得。編集トークンはハッシュのみ保存。
  ───────────────────────────  ─────────────────────────────────────────────────────────────────────────────────
   src/OsmDiffController.php    Overpass adiff 実行、結果のキャッシュ（diffs テーブル）と作品への差分紐付。
  ───────────────────────────  ─────────────────────────────────────────────────────────────────────────────────
   composer.json                PHP 8.0 要件と Utils.php の自動読み込み。

### 主要ポイント

  1. 公開 ID / 編集トークン
      - generatePublicId() → 6文字 Base64(4バイト)
      - generateEditToken() → 64 hex 文字
      - ハッシュは hash('sha256', salt . token) で保存。

  2. タイムゾーン処理
      - フロントから送られた start_at, end_at と timezone を toUtc() で UTC ISO8601 へ変換。

  3. Overpass 差分取得
      - 環境変数 OVERPASS_HISTORY_ENDPOINT で履歴対応 API を指定。
      - adiff クエリを POST で送信し、失敗時は 400 エラー。
      - 成功結果は JSON 文字列として diffs テーブルに diff_id（md5 キー）で保存。TTL は 1 時間。

  4. 差分プレビュー & キャッシュ再利用
      - 同一 BBOX・期間・タイムゾーンのリクエストはキャッシュを返却。
      - diff_id をフロントへ返却し、後続で save-diff エンドポイントで作品に紐付。

  5. SQLite での永続化
      - projects テーブルに BBOX（JSON）、日時、PMTiles ID 等を保持。
      - changes_file に diff_id を格納し、後で差分データを取得。

  ———

## 次に行うべきこと

   機能                                         現状        追加実装
  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  ━━━━━━━━━━  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   GeoJSON 生成                                 未実装      Overpass の JSON を GeoJSON 形式へ変換し、
                                                            summary_json へ格納。
  ───────────────────────────────────────────  ──────────  ─────────────────────────────────────────────────────
   主な変更点集計                               未実装      差分から「施設」「道路」等のカテゴリを算出。
  ───────────────────────────────────────────  ──────────  ─────────────────────────────────────────────────────
   Wikipedia / Wikidata / Commons の候補抽出    未実装      osm_type・osm_id を featured_objects に登録し、フロ
                                                            ントで選択できるように。
  ───────────────────────────────────────────  ──────────  ─────────────────────────────────────────────────────
   画像・エントリー等の追加                     未実装      entries テーブルと画像アップロードロジック。
  ───────────────────────────────────────────  ──────────  ─────────────────────────────────────────────────────
   テスト                                       未実装      PHPUnit テストで API と Overpass 呼び出しをモックし
                                                            て検証。
  ───────────────────────────────────────────  ──────────  ─────────────────────────────────────────────────────
   エラーハンドリングの拡張                     いくつか    adiff が非履歴 API で失敗した場合に明示的にエラー返
                                                            却。
  ───────────────────────────────────────────  ──────────  ─────────────────────────────────────────────────────
   セキュリティ                                 低          edit_token をクッキーに設定し、URL から除去。

  ———

## 使い方（簡易デモ）

### Docker テスト環境

Docker Compose を使い、専用の SQLite テストDBと PHP Webサーバを起動できます。
テストDBは通常用の `place_field_notes.sqlite` とは分離され、停止後も保持されます。

```bash
./scripts/test-env-docker.sh start
./scripts/test-env-docker.sh test
```

Web API は既定で `http://127.0.0.1:8082/` から利用できます。
停止は `./scripts/test-env-docker.sh stop`、DBを作り直す場合は
`./scripts/test-env-docker.sh reset --yes` を実行してください。
利用できるコマンドとポート変更方法は `./scripts/test-env-docker.sh help` で確認できます。

### ホスト上での簡易起動

  1. 依存関係インストール

     composer install

  2. サーバ起動

     php -S 0.0.0.0:8000 index.php

  3. サンプルリクエスト

     # 作品作成
     curl -X POST http://localhost:8000/api/projects \
          -H "Content-Type: application/json" \
          -d '{"title":"Test","bbox":[139.700,35.680,139.710,35.690],"start_at":"2026-09-
          20T13:00:00","end_at":"2026-09-20T15:00:00","timezone":"Asia/Tokyo","base_map":"2026-09"}'

     # 差分プレビュー
     curl -X POST http://localhost:8000/api/osm-diff \
          -H "Content-Type: application/json" \
          -d '{"bbox":[139.700,35.680,139.710,35.690],"start_at":"2026-09-20T13:00:00","end_at":"2026-09-
          20T15:00:00","timezone":"Asia/Tokyo"}'

  ———

これで 作品作成・公開 ID・編集トークン発行、Overpass adiff 呼び出し・キャッシュ までの基本機能が動作します。
残りの機能（GeoJSON 生成・集計・候補抽出など）は上記テーブルを基に追加実装していただければと思います。
