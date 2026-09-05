function updatePhotoSourceFields() {
  const selected = $('photo-source-type').value;
  document.querySelectorAll('.photo-source').forEach(el => { el.hidden = !el.classList.contains(selected); });
}

function rebuildAssociationOptions(project) {
  const fill = (select, items, label) => {
    select.replaceChildren();
    const none = document.createElement('option'); none.value = ''; none.textContent = i18n.t('none'); select.append(none);
    items.forEach(item => { const opt = document.createElement('option'); opt.value = item.id; opt.textContent = label(item); select.append(opt); });
  };
  fill($('photo-entry'), project.entries || [], item => (item.body || '').slice(0, 50));
  fill($('photo-place'), project.place_results || [], item => item.title || String(item.id));
  fill($('photo-feature'), project.featured_objects || [], item => item.name || `${item.osm_type}/${item.osm_id}`);
}

function photoMetadataFromCard(card) {
  const cards = [...document.querySelectorAll('#photo-list .photo-card')];
  return {
    caption: card.querySelector('.photo-caption').value, creator: card.querySelector('.photo-creator').value,
    credit: card.querySelector('.photo-credit').value, license: card.querySelector('.photo-license').value,
    lat: card.querySelector('.photo-lat').value || null, lon: card.querySelector('.photo-lon').value || null,
    entry_id: card.querySelector('.photo-entry-id').value || null, place_result_id: card.querySelector('.photo-place-id').value || null,
    featured_object_id: card.querySelector('.photo-feature-id').value || null, sort_order: Math.max(0, cards.indexOf(card)),
    commons_file: card.dataset.sourceType === 'commons' ? card.dataset.commonsFile : undefined,
    source_url: card.dataset.sourceType === 'url' ? card.dataset.sourceUrl : undefined,
  };
}

async function persistPhotoOrder() {
  const cards = [...document.querySelectorAll('#photo-list .photo-card')];
  await Promise.all(cards.map(card => api.updatePhoto(editorPublicId, Number(card.dataset.photoId), photoMetadataFromCard(card))));
  setStatus(i18n.t('saved'), 'success');
}

function renderPhotoEditor(project) {
  rebuildAssociationOptions(project);
  const list = $('photo-list'); list.replaceChildren();
  for (const photo of project.photos || []) {
    const card = node('div', 'photo-card'); card.dataset.photoId = String(photo.id); card.dataset.sourceType = photo.source_type; card.dataset.commonsFile = photo.commons_file || ''; card.dataset.sourceUrl = photo.source_url || '';
    if (photo.thumbnail_url || photo.image_url) { const img = document.createElement('img'); img.src = photo.thumbnail_url || photo.image_url; img.alt = photo.caption || ''; img.loading = 'lazy'; card.append(img); }
    else if (photo.source_url) { const a = node('a', '', photo.source_url); a.href = photo.source_url; a.target = '_blank'; a.rel = 'noopener noreferrer'; card.append(a); }
    const mkInput = (cls, value, placeholder, type='text') => { const input = document.createElement('input'); input.className=cls; input.value=value ?? ''; input.placeholder=placeholder; input.type=type; return input; };
    const caption = mkInput('photo-caption', photo.caption, i18n.t('caption'));
    const creator = mkInput('photo-creator', photo.creator, i18n.t('creator'));
    const credit = mkInput('photo-credit', photo.credit, i18n.t('credit'));
    const lat = mkInput('photo-lat', photo.lat, i18n.t('latitude'), 'number'); lat.step='any';
    const lon = mkInput('photo-lon', photo.lon, i18n.t('longitude'), 'number'); lon.step='any';
    const license = document.createElement('select'); license.className='photo-license'; ['CC BY 4.0','CC BY-SA 4.0','CC0 1.0'].forEach(v => { const o=document.createElement('option');o.value=v;o.textContent=v;license.append(o); }); license.value=photo.license;
    const cloneSelect = (source, cls, selected) => { const s=source.cloneNode(true); s.id=''; s.className=cls; s.value=selected || ''; return s; };
    const entry = cloneSelect($('photo-entry'),'photo-entry-id',photo.entry_id);
    const place = cloneSelect($('photo-place'),'photo-place-id',photo.place_result_id);
    const feature = cloneSelect($('photo-feature'),'photo-feature-id',photo.featured_object_id);
    const save = node('button','secondary',i18n.t('save_photo')); save.type='button'; save.addEventListener('click', async()=>{ try { await api.updatePhoto(editorPublicId, photo.id, photoMetadataFromCard(card)); setStatus(i18n.t('saved'),'success'); } catch(e){ showError(e); } });
    const move = addMoveControls(card, list, async () => { try { await persistPhotoOrder(); } catch(e) { showError(e); } });
    const del = node('button','danger-link',i18n.t('delete_photo')); del.type='button'; del.addEventListener('click', async()=>{ if(!confirm(i18n.t('delete_photo'))) return; try { await api.deletePhoto(editorPublicId, photo.id); card.remove(); } catch(e){ showError(e); } });
    card.append(caption,creator,credit,license,lat,lon,entry,place,feature,node('div','photo-actions')); card.lastChild.append(move,save,del); list.append(card);
  }
}

function makePhotoFormData(form, file = null) {
  const fd = new FormData();
  for (const name of ['source_type','commons_file','source_url','caption','creator','credit','license','lat','lon','entry_id','place_result_id','featured_object_id']) {
    const field = form.elements[name]; if (field && field.value !== '') fd.append(name, field.value);
  }
  if (file) fd.append('file', file, file.name);
  return fd;
}

async function submitPhoto(event) {
  event.preventDefault();
  try {
    const form = event.currentTarget; const type = form.elements.source_type.value;
    if (type === 'upload') {
      const files = [...form.elements.files.files]; if (!files.length) throw new Error(i18n.t('choose_files'));
      for (const file of files) await api.createPhoto(editorPublicId, makePhotoFormData(form, file));
    } else await api.createPhoto(editorPublicId, makePhotoFormData(form));
    form.reset(); updatePhotoSourceFields();
    const project = await api.getProject(editorPublicId, true); editorProject = project; renderPhotoEditor(project); setStatus(i18n.t('saved'),'success');
  } catch (error) { showError(error); }
}

function linkUrl(link) {
  if (link.source_url) return link.source_url;
  const key = String(link.source_key || '');
  if (link.source_type === 'wikidata') return `https://www.wikidata.org/wiki/${encodeURIComponent(key)}`;
  if (link.source_type === 'commons') return `https://commons.wikimedia.org/wiki/${encodeURIComponent(key.replaceAll(' ','_'))}`;
  if (link.source_type === 'wikipedia') { const match = key.match(/^([a-z0-9-]+):(.+)$/i); return match ? `https://${match[1]}.wikipedia.org/wiki/${encodeURIComponent(match[2].replaceAll(' ','_'))}` : null; }
  if (link.source_type === 'osm') { const match = key.match(/^(node|way|relation)[:/]([0-9]+)$/i); return match ? `https://www.openstreetmap.org/${match[1].toLowerCase()}/${match[2]}` : null; }
  return null;
}

function renderPublicFeatured(items) {
  const container = $('public-featured'); container.replaceChildren();
  for (const item of items || []) {
    const card = node('article','result-card'); card.append(node('h3','',item.name || `${item.osm_type}/${item.osm_id}`));
    const links=node('div','link-row'); [candidateLink('Wikipedia',item.wikipedia),candidateLink('Wikidata',item.wikidata),candidateLink('Commons',item.wikimedia_commons)].filter(Boolean).forEach(x=>links.append(x)); card.append(links);
    if(item.comment) card.append(node('p','',item.comment)); container.append(card);
  }
  $('public-featured-section').hidden = !items?.length;
}

function renderPublicPlaces(project) {
  const container = $('public-places'); container.replaceChildren();
  for (const place of project.place_results || []) {
    const card=node('article','result-card'); card.append(node('h3','',place.title));
    const links=node('div','link-list');
    for(const link of place.links||[]){ const url=linkUrl(link); const row=node('div','result-link'); row.append(node('strong','',link.source_type)); if(url){const a=node('a','',link.source_key);a.href=url;a.target='_blank';a.rel='noopener noreferrer';row.append(a);}else row.append(node('span','',link.source_key)); links.append(row); }
    card.append(links); if(place.comment) card.append(node('p','',place.comment)); container.append(card);
  }
  const s=project.result_summary||{}; const text=[]; const w=s.wikipedia||{}; if((w.new||0)+(w.expanded||0)+(w.other||0)) text.push(`Wikipedia: ${w.new||0} new / ${w.expanded||0} expanded`); if(s.wikidata) text.push(`Wikidata: ${s.wikidata}`); const c=s.commons||{}; if((c.files||0)+(c.categories||0)+(c.other||0)) text.push(`Commons: ${c.files||0} files / ${c.categories||0} categories`); if(s.osm) text.push(`OSM: ${s.osm}`); $('public-result-summary').textContent=text.join(' · ');
  $('public-places-section').hidden = !(project.place_results||[]).length;
}

function renderPublicPhotos(photos) {
  const container=$('public-photos'); container.replaceChildren();
  for(const photo of photos||[]){ const figure=node('figure','photo-card public'); if(photo.image_url){const img=document.createElement('img');img.src=photo.thumbnail_url||photo.image_url;img.alt=photo.caption||'';img.loading='lazy';figure.append(img);} const cap=node('figcaption'); if(photo.caption)cap.append(node('strong','',photo.caption)); const details=[photo.creator,photo.credit,photo.license].filter(Boolean).join(' · '); if(details)cap.append(node('small','',details)); if(photo.source_url){const a=node('a','',i18n.t('source'));a.href=photo.source_url;a.target='_blank';a.rel='noopener noreferrer';cap.append(a);} figure.append(cap);container.append(figure); }
  $('public-photos-section').hidden = !photos?.length;
}

async function initPublic(publicId) {
  $('public-view').hidden=false; setStatus(i18n.t('loading'));
  try {
    const project=await api.getProject(publicId); document.title=`${project.title} - ${i18n.t('app_name')}`;
    $('public-title').textContent=project.title; $('public-description').textContent=project.description||'';
    $('public-meta').textContent=`${i18n.t('project_period')}: ${new Date(project.start_at_local).toLocaleString()} — ${new Date(project.end_at_local).toLocaleString()} (${project.timezone})`;
    $('public-base-map').textContent=`${i18n.t('base_map')}: ${project.base_map}`;
    const map=new PfnMap('public-map',styleForBaseMap(project.base_map)); await map.ready(); map.setBBox(project.bbox); map.addDiff(project.geojson); map.setPlaces(project.place_results); map.fitBbox(project.bbox);
    renderSummary($('public-summary'),project.summary); renderPublicFeatured(project.featured_objects); renderPublicPlaces(project);
    const entries=$('public-entries');entries.replaceChildren();(project.entries||[]).forEach(entry=>entries.append(node('article','note-card',entry.body)));$('public-entries-section').hidden=!(project.entries||[]).length;
    renderPublicPhotos(project.photos); setStatus('');
  } catch(error){ showError(error); }
}

async function initEditor(publicId = null) {
  $('editor-view').hidden=false; mode=publicId?'edit':'create'; editorPublicId=publicId;
  editorMap=new PfnMap('editor-map',SNAPSHOTS[SNAPSHOTS.length-1].style); await editorMap.ready();
  $('select-bbox').addEventListener('click',()=>editorMap.startBboxSelection(bbox=>{ $('bbox-text').textContent=i18n.t('bbox_selected',{bbox:formatBbox(bbox)}); invalidateDiff(); }));
  $('preview-diff').addEventListener('click',previewDiff); $('add-entry').addEventListener('click',()=>addEntryRow()); $('add-place').addEventListener('click',()=>addPlaceRow()); $('save-project').addEventListener('click',saveProject);
  $('photo-source-type').addEventListener('change',updatePhotoSourceFields); $('photo-form').addEventListener('submit',submitPhoto);
  ['start_at','end_at'].forEach(name=>$('project-form').elements[name].addEventListener('change',invalidateDiff));
  $('activity-type').addEventListener('change',()=>{ const type=$('activity-type').value; $('preview-row').hidden=type==='wikipedia'; if(type==='wikipedia'){$('diff-section').hidden=true;currentDiff=null;} });

  if (!publicId) { addEntryRow(); return; }
  $('editor-kicker').textContent=i18n.t('edit_mode'); $('editor-title').textContent=i18n.t('edit_mode'); $('save-project').textContent=i18n.t('update_project'); $('fixed-range-note').hidden=false; $('preview-row').hidden=true; $('select-bbox').hidden=true; $('photo-editor').hidden=false;
  const params=new URLSearchParams(location.search); const token=params.get('token');
  try {
    if(token){ await api.establishEditSession(publicId,token); history.replaceState({},'',`/edit/${encodeURIComponent(publicId)}`); }
    const project=await api.getProject(publicId,true); editorProject=project;
    const form=$('project-form'); form.elements.title.value=project.title||''; form.elements.description.value=project.description||''; form.elements.activity_type.value=project.activity_type||'osm'; form.elements.start_at.value=String(project.start_at_local).slice(0,16); form.elements.end_at.value=String(project.end_at_local).slice(0,16); form.elements.start_at.disabled=true; form.elements.end_at.disabled=true;
    editorMap.setBBox(project.bbox);editorMap.fitBbox(project.bbox);$('bbox-text').textContent=i18n.t('bbox_selected',{bbox:formatBbox(project.bbox)});
    populateEditorLists(project,true); renderPhotoEditor(project);
    $('diff-section').hidden=false;$('base-map-label').textContent=`${i18n.t('base_map')}: ${project.base_map}`;diffMap=new PfnMap('diff-map',styleForBaseMap(project.base_map));await diffMap.ready();diffMap.setBBox(project.bbox);diffMap.addDiff(project.geojson);diffMap.fitBbox(project.bbox);renderSummary($('diff-summary'),project.summary);
  } catch(error){ showError(error); }
}

document.addEventListener('DOMContentLoaded',async()=>{
  await i18n.load();i18n.translatePage();$('language-select').value=i18n.lang;$('language-select').addEventListener('change',()=>{localStorage.setItem('pfn-lang',$('language-select').value);location.reload();});
  const match=location.pathname.match(/^\/(view|edit)\/([A-Za-z0-9_-]+)$/); if(match?.[1]==='view') await initPublic(match[2]); else if(match?.[1]==='edit') await initEditor(match[2]); else await initEditor();
});
