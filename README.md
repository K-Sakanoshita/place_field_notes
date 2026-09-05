# まちの記録帖 / Place Field Notes

OpenStreetMap や Wikipedia Town などの地域活動の成果を、地図・写真・コメントとともに「まちの記録」として残し、共有するためのWebアプリケーションです。

Place Field Notes documents and shares local open-data activities through maps, photos, place results, and field notes.

## コンセプト

OpenStreetMap の changeset 数や個人の編集件数ではなく、**「その場所で何が調べられ、何が記録され、何が変わったか」**を中心に見せます。

個人マッピング、マッピングパーティー、Wikipedia Town、フィールドワークなどを1つの作品形式で扱えます。成果地点（Place Result）には OpenStreetMap / Wikipedia / Wikidata / Wikimedia Commons / 写真 / コメントをまとめて紐付けられます。

## 実装範囲

GitHub Issues #1〜#4 を基準に実装しています。

### #1 バックエンド基盤・OSM差分

- PHP 8.2+ / PDO / MySQL
- MySQL は `utf8mb4` + InnoDB
- 閲覧用 `public_id` とランダムな編集トークンを作品ごとに発行
- 編集トークンは `password_hash()` した値だけをDBへ保存
- 編集URLのトークンを HttpOnly / SameSite Cookie の編集セッションへ交換
- BBOX、開始日時、終了日時、IANAタイムゾーンを検証し、DBではUTC保存
- `OVERPASS_HISTORY_ENDPOINT` で履歴対応 Overpass API を切替可能
- Overpass QL の `adiff` を使い create / modify / delete を取得
- Augmented Diff XML を GeoJSON に変換
- 同一条件の差分を `diff_id` でキャッシュ
- 作品保存時にプレビュー済み差分を永続化し、公開閲覧時は Overpass を再実行しない
- 店舗・施設 / 建物 / 出入口 / 道路・通路 / バリアフリー / その他を自動集計
- `wikipedia=*` / `wikidata=*` / `wikimedia_commons=*` を成果候補として抽出

### #2 フロントエンド・作品作成・公開・再編集

- タイトル、説明、活動タイプ、開始/終了日時
- MapLibre 上で対角2点をクリックしてBBOXを指定
- **差分確認 → 成果選択 → 作品保存** の順で作成
- 追加 / 変更 / 削除を色・線種・透明度等で区別
- 活動開始日以前の利用可能な年次 PMTiles を比較元として選択
- 比較元PMTilesの日付を公開ページに表示
- Wikimedia関連候補の掲載チェックと編集者コメント
- 複数の活動記録（Field Notes）
- 閲覧用URL / 編集用URL
- 編集URLから既存作品を再編集
- 日本語 / English のUIリソース分離
- モバイル向けレスポンシブ表示

初期実装では、BBOXと活動日時を保存後に変更しません。変更が必要な場合は新しい作品として作成します。

### #3 写真

編集者のみ、次の3方式で写真情報を追加できます。

1. JPEG / PNG / WebP のローカルアップロード
2. Wikimedia Commons の `File:` 名
3. 一般URL

ローカル画像はPHP GDでWebPへ再エンコードし、公開用画像とサムネイルを生成します。この処理でEXIF等のメタデータは公開画像へ引き継ぎません。元ファイル名は公開パスに使用しません。

写真ごとにキャプション、作者 / 撮影者、クレジット、ライセンス、明示的に指定した緯度・経度、活動記録 / 成果地点 / OSM成果候補への関連付け、並び順を保持できます。

ライセンス候補は `CC BY 4.0` / `CC BY-SA 4.0` / `CC0 1.0` のみです。`All rights reserved` は受け付けません。EXIF GPS は自動公開しません。一般URLの画像は自動転載・キャッシュしません。

### #4 Wikipedia Town等の成果地点

作品の活動タイプ:

- OpenStreetMap mapping
- Wikipedia Town
- OpenStreetMap + Wikipedia Town
- Other / Mixed

成果地点ごとに、地点名・位置・コメントと Wikipedia / Wikidata / Wikimedia Commons / OpenStreetMap / 一般URL の複数成果リンクを登録できます。Wikipedia の成果区分は `新規作成 / 加筆 / その他` を編集者が指定できます。公開ページでは Wikipedia / Wikidata / Commons / OSM を作品単位で集計します。

## 技術構成

- Backend: PHP 8.2+
- Database: MySQL
- Frontend: Vanilla JavaScript
- Map: MapLibre GL JS
- Historical base maps: PMTiles
- OSM diff: history-enabled Overpass API / `adiff`
- Image processing: PHP GD

MapLibre で `pmtiles://` ソースを読むため、PMTiles JavaScript protocol を登録しています。

## MySQLテーブル

起動時に `CREATE TABLE IF NOT EXISTS` を実行します。

- `projects`
- `diffs`
- `edit_sessions`
- `featured_objects`
- `entries`
- `place_results`
- `result_links`
- `photos`

試作時に使っていたSQLite DB、PIDファイル、ログファイル、簡易テストファイルはリポジトリ管理対象から外しています。

## 設定

共有レンタルサーバーでは `config.example.php` を参考に設定してください。

```bash
cp config.example.php config.local.php
```

`config.local.php` は `.gitignore` 対象です。可能ならWeb公開ディレクトリ外に置き、`PFN_CONFIG_FILE` で絶対パスを指定してください。

```php
<?php
return [
    'database' => [
        'host' => 'mysql.example.ne.jp',
        'port' => 3306,
        'name' => 'account_place_field_notes',
        'user' => 'account',
        'password' => '********',
    ],
    'overpass_history_endpoint' => 'https://example.net/api/interpreter',
    'upload_dir' => '/home/account/private/place-field-notes/uploads',
];
```

環境変数は設定ファイルより優先されます。

| 変数 | 用途 | 既定値 |
|---|---|---|
| `PFN_CONFIG_FILE` | 外部設定PHPの絶対パス | `./config.local.php` が存在すれば使用 |
| `PFN_DB_HOST` | MySQL host | `127.0.0.1` |
| `PFN_DB_PORT` | MySQL port | `3306` |
| `PFN_DB_NAME` | DB名 | `place_field_notes` |
| `PFN_DB_USER` | DBユーザー | 空（設定必須） |
| `PFN_DB_PASSWORD` | DBパスワード | 空 |
| `OVERPASS_HISTORY_ENDPOINT` | attic対応Overpass interpreter | `https://overpass-api.de/api/interpreter` |
| `PFN_UPLOAD_DIR` | アップロード保存先 | `storage/uploads` |
| `PFN_DIFF_CACHE_TTL` | 差分一時キャッシュ秒数 | `3600` |
| `PFN_EDIT_SESSION_TTL` | 編集セッション秒数 | `604800` |
| `PFN_MAX_ACTIVITY_HOURS` | 最大活動期間 | `168` |
| `PFN_MAX_BBOX_DEGREES` | BBOXの最大緯度/経度幅 | `1.0` |
| `PFN_MAX_UPLOAD_BYTES` | 1画像の最大容量 | `12582912` |
| `PFN_MAX_PHOTOS_PER_PROJECT` | 1作品の最大写真数 | `100` |
| `PFN_AUTO_MIGRATE` | 自動テーブル作成 | `1` |

## さくらのレンタルサーバーへの配置

1. コントロールパネルでMySQLデータベースを作成する。
2. PHP 8.2以降を選択する。
3. PDO MySQL / mbstring / SimpleXML / fileinfo / GD が利用可能か確認する。
4. リポジトリを配置する。
5. `config.local.php` またはWeb公開領域外の設定ファイルにMySQL接続情報を設定する。
6. ローカル写真を使う場合、`upload_dir` をPHPから書き込み可能にする。可能ならWeb公開領域外を指定する。
7. `/api/health` が `{"status":"ok","database":"mysql"}` を返すことを確認する。

DBパスワードや編集用トークンをGitへコミットしないでください。

## Overpass API

`adiff` は履歴（attic data）を持つOverpass APIが必要です。履歴非対応やエラー応答の場合、空差分として成功扱いせずAPIエラーを返します。

同一 BBOX / 開始 / 終了 / タイムゾーンは一時キャッシュを再利用します。`adiff` は開始時点と終了時点の差を返すため、期間中の中間バージョンを全件列挙するものではありません。

## ローカル開発

Docker Compose でも本番に合わせて MySQL 8.4 を使います。

```bash
./scripts/test-env-docker.sh start
./scripts/test-env-docker.sh test
```

既定URLは `http://127.0.0.1:8082/` です。停止は `./scripts/test-env-docker.sh stop`、DBとアップロード領域の再作成は `./scripts/test-env-docker.sh reset --yes` を使います。

`test` は外部Overpass APIを呼ばず、MySQL接続と Wikipedia Town 型作品の作成APIをスモークテストします。

## API

```text
GET    /api/health
POST   /api/osm-diff
POST   /api/projects
GET    /api/projects/{public_id}
GET    /api/projects/{public_id}?editor=1
PATCH  /api/projects/{public_id}
POST   /api/projects/{public_id}/edit-session
DELETE /api/edit-session
POST   /api/projects/{public_id}/photos
PATCH  /api/projects/{public_id}/photos/{photo_id}
DELETE /api/projects/{public_id}/photos/{photo_id}
GET    /media/{public_id}/{photo_id}/image
GET    /media/{public_id}/{photo_id}/thumb
```

作品更新と写真変更APIは認証済み編集セッションからのみ利用できます。

## 意図的な制限

- Wikipedia / Wikidata / Commons の編集履歴をBBOXと期間から自動探索しません。
- Wikimedia APIによる作者・ライセンス自動補完はまだ行いません。
- Wikimedia各サービスへの編集・投稿は行いません。
- 一般URLのコンテンツを自動転載・恒久キャッシュしません。
- 活動開始時点を厳密に再現したタイルではなく、活動開始日前の利用可能な年次PMTilesを比較元にします。
- ユーザー登録、OAuth、一般閲覧者コメント、編集者ランキング、SNS機能は対象外です。

## License

MIT License. See [LICENSE](LICENSE).
