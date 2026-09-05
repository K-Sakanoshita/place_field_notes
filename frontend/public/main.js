// main.js – 画面制御 + イベントハンドラ
document.addEventListener('DOMContentLoaded', async () => {
  await i18n.load();                    // i18n 初期化
  i18n.translatePage();                  // 文字列置換
  document.title = i18n.t('Place Field Notes');

  const form = document.getElementById('project-form');
  const bboxInput = document.getElementById('bbox-input');
  const diffInfo = document.getElementById('diff-info');
  const saveBtn = document.getElementById('save-btn');

  // ① BBOX 描画マップを初期化
  // BBOX を描くマップ。PMTiles は "Tiles" フォルダに入っている想定
  const bboxMap = new Mapbox({
    container: 'map-container',
    pmtilesUrl: '/tiles/osmfj_poi.json', // Base map JSON
  });

  // ② フォーム送信時に差分取得
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // フォーム入力を取得
    const formData = new FormData(form);
    const bbox = bboxMap.getBBox(); // JSON
    if (!bbox) { alert(i18n.t('please_draw_bbox')); return; }

    formData.set('bbox', JSON.stringify(bbox));

    // 1. プロジェクト作成（必要ならここで作る）
    // Derive base_map from start date (YYYY-MM)
    const startDate = new Date(formData.get('start_at'));
    const baseMap = startDate.toISOString().slice(0, 7); // e.g. 2026-01
    formData.set('base_map', baseMap);
    formData.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone);
    const projResp = await api.createProject(Object.fromEntries(formData));

    // 2. 差分取得
    const diffResp = await api.getDiff({
      bbox: bbox,
      start_at: formData.get('start_at'),
      end_at: formData.get('end_at'),
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    });

    // 3. 差分プレビュー表示
    diffInfo.style.display = 'block';
    document.getElementById('diff-title').textContent = i18n.t('diff_preview');

    // 4. GeoJSON をマップへ描画
    const mainMap = new Mapbox({
      container: 'main-map',
      pmtilesUrl: '/tiles/osmfj_poi.json', // Same base map JSON
    });
    mainMap.addGeoJson(diffResp.geojson);

    // 5. 成果候補リスト
    renderCandidateList(diffResp.candidates, document.getElementById('candidate-list'));

    // 6. 保存ボタン
    saveBtn.onclick = async () => {
      await api.attachDiff(projResp.public_id, diffResp.diff_id);
      alert(i18n.t('project_saved'));
      window.location.href = `/view/${projResp.public_id}`;
    };
  });
});

function renderCandidateList(candidates, container) {
  container.innerHTML = '';
  candidates.forEach(c => {
    const div = document.createElement('div');
    div.style.borderBottom = '1px solid #ccc';
    div.style.padding = '8px 0';

    const label = document.createElement('label');
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.id = `candidate-${c.id}`;
    label.appendChild(cb);
    label.append(` ${c.name}`);

    const links = document.createElement('div');
    links.style.marginLeft = '20px';
    if (c.wikipedia) {
      const a = document.createElement('a');
      a.href = c.wikipedia; a.target='_blank'; a.textContent = 'Wikipedia';
      links.appendChild(a); links.append(' | ');
    }
    if (c.wikidata) {
      const a = document.createElement('a');
      a.href = c.wikidata; a.target='_blank'; a.textContent = 'Wikidata';
      links.appendChild(a); links.append(' | ');
    }
    if (c.commons) {
      const a = document.createElement('a');
      a.href = c.commons; a.target='_blank'; a.textContent = 'Commons';
      links.appendChild(a);
    }

    const textarea = document.createElement('textarea');
    textarea.placeholder = i18n.t('enter_comment');
    textarea.style.width = '100%';
    textarea.style.marginTop = '4px';

    div.appendChild(label);
    div.appendChild(links);
    div.appendChild(textarea);
    container.appendChild(div);
  });
}
