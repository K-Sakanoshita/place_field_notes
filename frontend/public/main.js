const SNAPSHOTS = [
  ['2010-01-06', '/tiles/japan-20100106.json'],
  ['2012-01-04', '/tiles/japan-20120104.json'],
  ['2014-01-01', '/tiles/japan-20140101.json'],
  ['2016-01-01', '/tiles/japan-20160101.json'],
  ['2018-01-01', '/tiles/japan-20180101.json'],
  ['2020-01-01', '/tiles/japan-20200101.json'],
  ['2022-01-01', '/tiles/japan-20220101.json'],
  ['2023-01-01', '/tiles/japan-20230101.json'],
  ['2024-01-01', '/tiles/japan-20240101.json'],
  ['2025-10-13', '/tiles/japan-20251013.json'],
  ['2026-01-01', '/tiles/japan-20260101.json'],
].map(([id, style]) => ({ id, style }));

let editorMap = null;
let diffMap = null;
let currentDiff = null;
let editorProject = null;
let editorPublicId = null;
let mode = 'create';

const $ = id => document.getElementById(id);
const node = (tag, className = '', text = '') => {
  const el = document.createElement(tag);
  if (className) el.className = className;
  if (text) el.textContent = text;
  return el;
};

function snapshotForStart(value) {
  const day = String(value || '').slice(0, 10);
  let selected = SNAPSHOTS[0];
  for (const snapshot of SNAPSHOTS) {
    if (snapshot.id <= day) selected = snapshot;
    else break;
  }
  return selected;
}

function styleForBaseMap(id) {
  return SNAPSHOTS.find(s => s.id === id)?.style || SNAPSHOTS[SNAPSHOTS.length - 1].style;
}

function setStatus(message, kind = 'info') {
  const box = $('global-status');
  box.textContent = message;
  box.className = `status ${kind}`;
  box.hidden = !message;
  if (message) box.scrollIntoView({ block: 'nearest' });
}

function showError(error) {
  console.error(error);
  setStatus(`${i18n.t('error')}: ${error?.message || String(error)}`, 'error');
}

function requiresDiff(activityType) { return ['osm', 'mixed'].includes(activityType); }
function formatBbox(bbox) { return bbox ? bbox.map(v => Number(v).toFixed(5)).join(', ') : ''; }
function formValue(name) { return $('project-form').elements[name].value; }

function activityInputForDiff() {
  const bbox = editorMap.getBBox();
  if (!bbox) throw new Error(i18n.t('select_bbox_help'));
  const start = formValue('start_at');
  const end = formValue('end_at');
  if (!start || !end || new Date(start) >= new Date(end)) throw new Error(`${i18n.t('start_label')} < ${i18n.t('end_label')}`);
  return { bbox, start_at: start, end_at: end, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' };
}

function invalidateDiff() {
  if (mode !== 'create') return;
  currentDiff = null;
  $('diff-section').hidden = true;
}

function renderSummary(container, summary) {
  container.replaceChildren();
  if (!summary) return;
  const grid = node('div', 'summary-grid');
  const actions = summary.actions || {};
  for (const key of ['added', 'modified', 'deleted']) {
    const item = node('div', 'summary-item');
    item.append(node('strong', '', String(actions[key] || 0)), node('span', '', i18n.t(key)));
    grid.append(item);
  }
  const categories = summary.categories || {};
  for (const key of ['facilities','buildings','entrances','roads_paths','accessibility','other']) {
    const item = node('div', 'summary-item subtle');
    item.append(node('strong', '', String(categories[key] || 0)), node('span', '', i18n.t(key)));
    grid.append(item);
  }
  container.append(grid);
}

function candidateLink(label, href) {
  if (!href) return null;
  const a = node('a', 'out-link', label);
  a.href = href; a.target = '_blank'; a.rel = 'noopener noreferrer';
  return a;
}

function renderCandidates(items = [], editing = false) {
  const list = $('candidate-list');
  list.replaceChildren();
  if (!items.length) { list.append(node('p', 'muted', i18n.t('no_changes'))); return; }
  items.forEach((item, index) => {
    const card = node('div', 'candidate card-inset');
    card.dataset.itemId = String(item.id); card.dataset.osmType = item.osm_type || ''; card.dataset.osmId = String(item.osm_id || '');
    const top = node('div', 'candidate-top');
    const label = node('label', 'check-label');
    const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.className = 'candidate-include'; checkbox.checked = Boolean(item.include_in_results);
    label.append(checkbox, document.createTextNode(` ${i18n.t('include_result')}`));
    top.append(node('strong', '', item.name || `${item.osm_type}/${item.osm_id}`), label); card.append(top);
    const links = node('div', 'link-row');
    [candidateLink('Wikipedia', item.wikipedia), candidateLink('Wikidata', item.wikidata), candidateLink('Commons', item.commons || item.wikimedia_commons)].filter(Boolean).forEach(a => links.append(a));
    card.append(links);
    const textarea = document.createElement('textarea'); textarea.className = 'candidate-comment'; textarea.rows = 2; textarea.placeholder = i18n.t('comment'); textarea.value = item.comment || ''; card.append(textarea);
    card.dataset.order = String(item.sort_order ?? index); if (editing) card.dataset.editing = '1'; list.append(card);
  });
}

function collectCandidates() {
  return [...document.querySelectorAll('#candidate-list .candidate')].map((card, index) => ({
    id: card.dataset.itemId, osm_type: card.dataset.osmType, osm_id: Number(card.dataset.osmId || 0),
    include_in_results: card.querySelector('.candidate-include').checked, comment: card.querySelector('.candidate-comment').value, sort_order: index,
  }));
}

function addMoveControls(item, container, afterMove = null) {
  const controls = node('span', 'move-controls');
  const up = node('button', 'secondary small', '↑'); up.type = 'button'; up.title = 'Move up';
  const down = node('button', 'secondary small', '↓'); down.type = 'button'; down.title = 'Move down';
  up.addEventListener('click', async () => { const previous = item.previousElementSibling; if (previous) container.insertBefore(item, previous); if (afterMove) await afterMove(); });
  down.addEventListener('click', async () => { const next = item.nextElementSibling; if (next) container.insertBefore(next, item); if (afterMove) await afterMove(); });
  controls.append(up, down); return controls;
}

function addEntryRow(entry = {}) {
  const row = node('div', 'entry-row card-inset'); if (entry.id) row.dataset.id = String(entry.id);
  const textarea = document.createElement('textarea'); textarea.rows = 3; textarea.placeholder = i18n.t('entry_placeholder'); textarea.value = entry.body || '';
  const remove = node('button', 'danger-link', i18n.t('remove')); remove.type = 'button'; remove.addEventListener('click', () => row.remove());
  const list = $('entry-list'); row.append(textarea, addMoveControls(row, list), remove); list.append(row);
}

function collectEntries() {
  return [...document.querySelectorAll('#entry-list .entry-row')].map((row, index) => ({ id: row.dataset.id ? Number(row.dataset.id) : undefined, body: row.querySelector('textarea').value, sort_order: index })).filter(x => x.body.trim());
}

function addLinkRow(container, link = {}) {
  const row = node('div', 'link-editor');
  const type = document.createElement('select'); type.className = 'link-type';
  ['wikipedia','wikidata','commons','osm','url'].forEach(value => { const opt = document.createElement('option'); opt.value = value; opt.textContent = value; type.append(opt); }); type.value = link.source_type || 'wikipedia';
  const key = document.createElement('input'); key.className = 'link-key'; key.placeholder = i18n.t('source_key'); key.value = link.source_key || '';
  const url = document.createElement('input'); url.className = 'link-url'; url.type = 'url'; url.placeholder = i18n.t('source_url'); url.value = link.source_url || '';
  const resultType = document.createElement('select'); resultType.className = 'link-result-type';
  [['', i18n.t('result_other')], ['new', i18n.t('result_new')], ['expanded', i18n.t('result_expanded')]].forEach(([value,label]) => { const opt = document.createElement('option'); opt.value = value; opt.textContent = label; resultType.append(opt); }); resultType.value = link.result_type || '';
  const remove = node('button', 'danger-link', i18n.t('remove')); remove.type = 'button'; remove.addEventListener('click', () => row.remove());
  row.append(type, key, url, resultType, remove); container.append(row);
}

function addPlaceRow(place = {}) {
  const card = node('div', 'place-editor card-inset'); if (place.id) card.dataset.id = String(place.id);
  const title = document.createElement('input'); title.className = 'place-title'; title.placeholder = i18n.t('place_title'); title.value = place.title || '';
  const lat = document.createElement('input'); lat.className = 'place-lat'; lat.type = 'number'; lat.step = 'any'; lat.placeholder = i18n.t('latitude'); lat.value = place.lat ?? '';
  const lon = document.createElement('input'); lon.className = 'place-lon'; lon.type = 'number'; lon.step = 'any'; lon.placeholder = i18n.t('longitude'); lon.value = place.lon ?? '';
  const pick = node('button', 'secondary', i18n.t('pick_position')); pick.type = 'button';
  pick.addEventListener('click', async () => { setStatus(i18n.t('pick_position')); const [lng, latitude] = await editorMap.pickPoint(); lon.value = lng.toFixed(7); lat.value = latitude.toFixed(7); setStatus(''); });
  const comment = document.createElement('textarea'); comment.className = 'place-comment'; comment.rows = 2; comment.placeholder = i18n.t('comment'); comment.value = place.comment || '';
  const links = node('div', 'place-links stack'); (place.links || []).forEach(link => addLinkRow(links, link));
  const addLink = node('button', 'secondary small', i18n.t('add_link')); addLink.type = 'button'; addLink.addEventListener('click', () => addLinkRow(links));
  const remove = node('button', 'danger-link', i18n.t('remove')); remove.type = 'button'; remove.addEventListener('click', () => card.remove());
  const position = node('div', 'position-row'); position.append(lat, lon, pick);
  const list = $('place-list'); card.append(title, position, comment, node('div', 'field-label', i18n.t('external_results')), links, addLink, addMoveControls(card, list), remove); list.append(card);
}

function collectPlaces() {
  return [...document.querySelectorAll('#place-list .place-editor')].map((card, index) => ({
    id: card.dataset.id ? Number(card.dataset.id) : undefined, title: card.querySelector('.place-title').value,
    lat: card.querySelector('.place-lat').value || null, lon: card.querySelector('.place-lon').value || null,
    comment: card.querySelector('.place-comment').value, sort_order: index,
    links: [...card.querySelectorAll('.link-editor')].map((row, linkIndex) => ({
      source_type: row.querySelector('.link-type').value, source_key: row.querySelector('.link-key').value,
      source_url: row.querySelector('.link-url').value || null, result_type: row.querySelector('.link-result-type').value || null, sort_order: linkIndex,
    })).filter(link => link.source_key.trim()),
  })).filter(place => place.title.trim());
}

async function previewDiff() {
  try {
    setStatus(i18n.t('previewing'));
    const request = activityInputForDiff(); const snapshot = snapshotForStart(request.start_at);
    currentDiff = await api.previewDiff(request); $('diff-section').hidden = false; $('base-map-label').textContent = `${i18n.t('base_map')}: ${snapshot.id}`;
    if (!diffMap) diffMap = new PfnMap('diff-map', snapshot.style); else await diffMap.setStyle(snapshot.style);
    await diffMap.ready(); diffMap.setBBox(request.bbox); diffMap.addDiff(currentDiff.geojson); diffMap.fitBbox(request.bbox);
    renderSummary($('diff-summary'), currentDiff.summary); renderCandidates(currentDiff.candidates || [], false);
    setStatus(currentDiff.summary?.total ? '' : i18n.t('no_changes'));
  } catch (error) { showError(error); }
}

function projectPayloadForCreate() {
  const request = activityInputForDiff(); const activityType = formValue('activity_type');
  if (requiresDiff(activityType) && !currentDiff?.diff_id) throw new Error(i18n.t('preview_diff'));
  const snapshot = snapshotForStart(request.start_at);
  return { title: formValue('title'), description: formValue('description'), activity_type: activityType, ...request, base_map: snapshot.id, diff_id: currentDiff?.diff_id || null, featured_objects: currentDiff ? collectCandidates() : [], entries: collectEntries(), place_results: collectPlaces() };
}

function projectPayloadForUpdate() {
  return { title: formValue('title'), description: formValue('description'), activity_type: formValue('activity_type'), featured_objects: collectCandidates(), entries: collectEntries(), place_results: collectPlaces() };
}

function displaySavedLinks(result) {
  const box = $('saved-links'); box.replaceChildren(); box.hidden = false;
  const publicLabel = node('p'); publicLabel.append(node('strong', '', `${i18n.t('public_url')}: `)); const publicLink = node('a', '', result.public_url); publicLink.href = result.public_url; publicLabel.append(publicLink);
  const editLabel = node('p'); editLabel.append(node('strong', '', `${i18n.t('edit_url')}: `)); const editLink = node('a', '', result.edit_url); editLink.href = result.edit_url; editLabel.append(editLink);
  box.append(publicLabel, editLabel, node('p', 'warning', i18n.t('edit_url_warning'))); box.scrollIntoView({ behavior: 'smooth' });
}

async function saveProject() {
  try {
    setStatus(i18n.t('saving'));
    if (mode === 'create') {
      const result = await api.createProject(projectPayloadForCreate()); displaySavedLinks(result); setStatus(i18n.t('saved'), 'success');
    } else {
      const result = await api.updateProject(editorPublicId, projectPayloadForUpdate()); editorProject = result.project; populateEditorLists(editorProject, true); renderPhotoEditor(editorProject); setStatus(i18n.t('saved'), 'success');
    }
  } catch (error) { showError(error); }
}

function populateEditorLists(project, editing) {
  $('entry-list').replaceChildren(); (project.entries || []).forEach(addEntryRow);
  $('place-list').replaceChildren(); (project.place_results || []).forEach(addPlaceRow);
  renderCandidates(project.featured_objects || [], editing);
}
