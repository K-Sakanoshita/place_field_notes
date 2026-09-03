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

## License

Source code is licensed under the [MIT License](LICENSE).

地図・写真・Wikipedia / Wikimedia / OpenStreetMap由来のデータやコンテンツについては、それぞれの出典・ライセンスが別途適用されます。
