(() => {
  const mapRegistry = new Map();
  let localPlaceSequence = 0;
  let previewObjectUrls = [];

  function geometryPoint(geometry) {
    if (!geometry || !geometry.type) return null;
    if (geometry.type === 'Point') return geometry.coordinates?.slice(0, 2) || null;
    const points = [];
    const collect = value => {
      if (!Array.isArray(value)) return;
      if (value.length >= 2 && Number.isFinite(Number(value[0])) && Number.isFinite(Number(value[1]))) {
        points.push([Number(value[0]), Number(value[1])]);
        return;
      }
      value.forEach(collect);
    };
    collect(geometry.coordinates);
    if (!points.length) return null;
    const xs = points.map(point => point[0]);
    const ys = points.map(point => point[1]);
    return [(Math.min(...xs) + Math.max(...xs)) / 2, (Math.min(...ys) + Math.max(...ys)) / 2];
  }

  function candidateFeature(item) {
    const collections = [];
    if (typeof currentDiff !== 'undefined' && currentDiff?.geojson) collections.push(currentDiff.geojson);
    if (typeof editorProject !== 'undefined' && editorProject?.geojson) collections.push(editorProject.geojson);
    for (const collection of collections) {
      const feature = (collection.features || []).find(candidate => {
        const props = candidate.properties || {};
        return props.osm_type === item.osm_type && Number(props.osm_id) === Number(item.osm_id);
      });
      if (feature) return feature;
    }
    return null;
  }

  function wikipediaKey(item) {
    if (item.wikipedia_tag) return item.wikipedia_tag;
    if (!item.wikipedia) return null;
    try {
      const url = new URL(item.wikipedia);
      const match = url.hostname.match(/^([a-z0-9-]+)\.wikipedia\.org$/i);
      const path = decodeURIComponent(url.pathname.replace(/^\/wiki\//, '')).replaceAll('_', ' ');
      return match && path ? `${match[1].toLowerCase()}:${path}` : item.wikipedia;
    } catch { return item.wikipedia; }
  }

  function wikidataKey(item) {
    if (item.wikidata_tag) return item.wikidata_tag;
    if (!item.wikidata) return null;
    const match = String(item.wikidata).match(/\b(Q\d+)\b/i);
    return match ? match[1].toUpperCase() : item.wikidata;
  }

  function commonsKey(item) {
    if (item.commons_tag) return item.commons_tag;
    const value = item.commons || item.wikimedia_commons;
    if (!value) return null;
    try {
      const url = new URL(value);
      return decodeURIComponent(url.pathname.replace(/^\/wiki\//, '')).replaceAll('_', ' ') || value;
    } catch { return value; }
  }

  function candidateResultLinks(item) {
    const links = [{
      source_type: 'osm',
      source_key: `${item.osm_type}:${item.osm_id}`,
      source_url: `https://www.openstreetmap.org/${encodeURIComponent(item.osm_type)}/${Number(item.osm_id)}`,
      result_type: null,
    }];
    const wikipedia = wikipediaKey(item);
    if (wikipedia) links.push({ source_type: 'wikipedia', source_key: wikipedia, source_url: item.wikipedia || null, result_type: null });
    const wikidata = wikidataKey(item);
    if (wikidata) links.push({ source_type: 'wikidata', source_key: wikidata, source_url: item.wikidata || null, result_type: null });
    const commons = commonsKey(item);
    if (commons) links.push({ source_type: 'commons', source_key: commons, source_url: item.commons || item.wikimedia_commons || null, result_type: null });
    return links;
  }

  function linkFingerprint(link) {
    return `${String(link.source_type || '').toLowerCase()}|${String(link.source_key || '').trim().toLowerCase()}`;
  }

  function placeCardLinks(card) {
    return [...card.querySelectorAll('.link-editor')].map(row => ({
      source_type: row.querySelector('.link-type')?.value || '',
      source_key: row.querySelector('.link-key')?.value || '',
    }));
  }

  function ensurePlaceLocalKey(card) {
    if (!card.dataset.localKey) {
      card.dataset.localKey = card.dataset.id ? `db-${card.dataset.id}` : `tmp-${++localPlaceSequence}`;
    }
    return card.dataset.localKey;
  }

  function placeLabel(card, index) {
    const title = card.querySelector('.place-title')?.value.trim();
    return title || `${i18n.t('place_results')} ${index + 1}`;
  }

  function refreshCandidatePlaceSelects() {
    const places = [...document.querySelectorAll('#place-list .place-editor')];
    places.forEach(ensurePlaceLocalKey);
    document.querySelectorAll('.candidate-place-select').forEach(select => {
      const selected = select.value;
      select.replaceChildren();
      const blank = document.createElement('option');
      blank.value = '';
      blank.textContent = i18n.t('select_existing_place');
      select.append(blank);
      places.forEach((card, index) => {
        const option = document.createElement('option');
        option.value = ensurePlaceLocalKey(card);
        option.textContent = placeLabel(card, index);
        select.append(option);
      });
      if ([...select.options].some(option => option.value === selected)) select.value = selected;
    });
  }

  function candidateHasDuplicateElsewhere(targetCard, links) {
    const wanted = new Set(links.filter(link => link.source_type !== 'osm').map(linkFingerprint));
    if (!wanted.size) return false;
    return [...document.querySelectorAll('#place-list .place-editor')].some(card => {
      if (card === targetCard) return false;
      return placeCardLinks(card).some(link => wanted.has(linkFingerprint(link)));
    });
  }

  function applyCandidateToPlace(targetCard, item) {
    const links = candidateResultLinks(item);
    if (candidateHasDuplicateElsewhere(targetCard, links) && !confirm(i18n.t('duplicate_result_confirm'))) return false;

    const linksContainer = targetCard.querySelector('.place-links');
    const existing = new Set(placeCardLinks(targetCard).map(linkFingerprint));
    for (const link of links) {
      if (!existing.has(linkFingerprint(link))) addLinkRow(linksContainer, link);
    }

    const title = targetCard.querySelector('.place-title');
    if (title && !title.value.trim()) title.value = item.name || `${item.osm_type}/${item.osm_id}`;

    const point = geometryPoint(candidateFeature(item)?.geometry);
    if (point) {
      const lon = targetCard.querySelector('.place-lon');
      const lat = targetCard.querySelector('.place-lat');
      if (lon && lat && !lon.value && !lat.value) {
        lon.value = point[0].toFixed(7);
        lat.value = point[1].toFixed(7);
      }
    }
    return true;
  }

  function markCandidateIncluded(card) {
    const checkbox = card.querySelector('.candidate-include');
    if (checkbox) checkbox.checked = true;
  }

  function createPlaceFromCandidate(item, candidateCard) {
    const point = geometryPoint(candidateFeature(item)?.geometry);
    addPlaceRow({
      title: item.name || `${item.osm_type}/${item.osm_id}`,
      lon: point?.[0] ?? null,
      lat: point?.[1] ?? null,
      links: candidateResultLinks(item),
      comment: '',
    });
    const target = $('place-list').lastElementChild;
    if (target) {
      ensurePlaceLocalKey(target);
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      markCandidateIncluded(candidateCard);
      setStatus(i18n.t('candidate_place_created'), 'success');
    }
    refreshCandidatePlaceSelects();
  }

  function addCandidateToExistingPlace(item, candidateCard, select) {
    const target = [...document.querySelectorAll('#place-list .place-editor')]
      .find(card => ensurePlaceLocalKey(card) === select.value);
    if (!target) {
      setStatus(i18n.t('select_existing_place'), 'error');
      return;
    }
    if (applyCandidateToPlace(target, item)) {
      markCandidateIncluded(candidateCard);
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setStatus(i18n.t('candidate_place_linked'), 'success');
    }
  }

  const baseRenderCandidates = renderCandidates;
  renderCandidates = function enhancedRenderCandidates(items = [], editing = false) {
    baseRenderCandidates(items, editing);
    const cards = [...document.querySelectorAll('#candidate-list .candidate')];
    cards.forEach((card, index) => {
      const item = items[index];
      if (!item || !item.osm_type || !item.osm_id) return;
      const tools = node('div', 'candidate-place-tools');
      const create = node('button', 'secondary small', i18n.t('create_place_from_candidate'));
      create.type = 'button';
      create.addEventListener('click', () => createPlaceFromCandidate(item, card));
      const select = document.createElement('select');
      select.className = 'candidate-place-select';
      const attach = node('button', 'secondary small', i18n.t('add_to_existing_place'));
      attach.type = 'button';
      attach.addEventListener('click', () => addCandidateToExistingPlace(item, card, select));
      tools.append(create, select, attach);
      card.append(tools);
    });
    refreshCandidatePlaceSelects();
  };

  const baseAddPlaceRow = addPlaceRow;
  addPlaceRow = function enhancedAddPlaceRow(place = {}) {
    baseAddPlaceRow(place);
    const card = $('place-list').lastElementChild;
    if (card?.classList.contains('place-editor')) ensurePlaceLocalKey(card);
    refreshCandidatePlaceSelects();
    return card;
  };

  const placeList = $('place-list');
  if (placeList) {
    new MutationObserver(refreshCandidatePlaceSelects).observe(placeList, { childList: true });
    placeList.addEventListener('input', event => {
      if (event.target?.classList.contains('place-title')) refreshCandidatePlaceSelects();
    });
  }

  if (window.PfnMap) {
    const BasePfnMap = window.PfnMap;
    class EnhancedPfnMap extends BasePfnMap {
      constructor(container, ...args) {
        super(container, ...args);
        this.diffMode = 'result';
        this.photoMarkers = [];
        mapRegistry.set(typeof container === 'string' ? container : container.id, this);
      }

      drawDiff() {
        super.drawDiff();
        this.ensureDimLayer();
        this.applyDiffMode();
      }

      ensureDimLayer() {
        if (!this.map.isStyleLoaded() || !this.diff) return;
        const sourceId = 'pfn-dim-world';
        const layerId = 'pfn-dim';
        if (!this.map.getSource(sourceId)) {
          this.map.addSource(sourceId, {
            type: 'geojson',
            data: { type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [[[-180,-85],[180,-85],[180,85],[-180,85],[-180,-85]]] } },
          });
        }
        if (!this.map.getLayer(layerId)) {
          const before = this.map.getLayer('pfn-added-fill') ? 'pfn-added-fill' : undefined;
          this.map.addLayer({ id: layerId, type: 'fill', source: sourceId, paint: { 'fill-color': '#ffffff', 'fill-opacity': 0.66 } }, before);
        }
      }

      setDiffMode(mode) {
        this.diffMode = ['base', 'result', 'changes'].includes(mode) ? mode : 'result';
        this.applyDiffMode();
      }

      applyDiffMode() {
        if (!this.map.isStyleLoaded()) return;
        const showDiff = this.diffMode !== 'base';
        for (const action of ['added', 'modified', 'deleted']) {
          for (const kind of ['fill', 'line', 'point']) {
            const id = `pfn-${action}-${kind}`;
            if (this.map.getLayer(id)) this.map.setLayoutProperty(id, 'visibility', showDiff ? 'visible' : 'none');
          }
        }
        if (this.map.getLayer('pfn-dim')) this.map.setLayoutProperty('pfn-dim', 'visibility', this.diffMode === 'changes' ? 'visible' : 'none');
      }

      setPhotos(photos) {
        this.photos = photos || [];
        this.drawPhotoPoints();
      }

      drawPhotoPoints() {
        for (const marker of this.photoMarkers) marker.remove();
        this.photoMarkers = [];
        for (const photo of this.photos || []) {
          if (photo.lat == null || photo.lon == null) continue;
          const el = document.createElement('button');
          el.className = 'photo-marker';
          el.type = 'button';
          el.title = photo.caption || i18n.t('photos');
          el.setAttribute('aria-label', el.title);
          el.textContent = '◆';
          const popup = new maplibregl.Popup({ offset: 18 }).setText(photo.caption || i18n.t('photos'));
          const marker = new maplibregl.Marker({ element: el })
            .setLngLat([Number(photo.lon), Number(photo.lat)])
            .setPopup(popup)
            .addTo(this.map);
          this.photoMarkers.push(marker);
        }
      }
    }
    window.PfnMap = EnhancedPfnMap;
  }

  function installMapModeControls(map, containerId) {
    if (!map) return;
    const mapElement = document.getElementById(containerId);
    if (!mapElement || document.querySelector(`[data-map-modes="${containerId}"]`)) return;
    const controls = node('div', 'map-mode-controls');
    controls.dataset.mapModes = containerId;
    const modes = [
      ['base', i18n.t('map_mode_base')],
      ['result', i18n.t('map_mode_result')],
      ['changes', i18n.t('map_mode_changes')],
    ];
    for (const [modeName, label] of modes) {
      const button = node('button', 'secondary small', label);
      button.type = 'button';
      button.dataset.mode = modeName;
      button.setAttribute('aria-pressed', modeName === 'result' ? 'true' : 'false');
      button.addEventListener('click', () => {
        map.setDiffMode(modeName);
        controls.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      });
      controls.append(button);
    }
    mapElement.before(controls);
    map.setDiffMode('result');
  }

  const basePreviewDiff = previewDiff;
  previewDiff = async function enhancedPreviewDiff(...args) {
    await basePreviewDiff(...args);
    installMapModeControls(diffMap, 'diff-map');
  };

  function installPhotoFormExtras() {
    const form = $('photo-form');
    if (!form || $('photo-position-actions')) return;
    const actions = node('div', 'span-2 photo-position-actions');
    actions.id = 'photo-position-actions';
    const pick = node('button', 'secondary small', i18n.t('pick_photo_position'));
    pick.type = 'button';
    pick.addEventListener('click', async () => {
      try {
        setStatus(i18n.t('pick_position'));
        const [lon, lat] = await editorMap.pickPoint();
        form.elements.lon.value = lon.toFixed(7);
        form.elements.lat.value = lat.toFixed(7);
        setStatus('');
      } catch (error) { showError(error); }
    });
    const clear = node('button', 'secondary small', i18n.t('clear_photo_position'));
    clear.type = 'button';
    clear.addEventListener('click', () => { form.elements.lat.value = ''; form.elements.lon.value = ''; });
    actions.append(pick, clear);

    const progressWrap = node('div', 'span-2 upload-progress');
    progressWrap.id = 'photo-upload-progress-wrap';
    progressWrap.hidden = true;
    const progress = document.createElement('progress');
    progress.id = 'photo-upload-progress';
    progress.max = 100;
    progress.value = 0;
    const label = node('span', 'muted');
    label.id = 'photo-upload-progress-label';
    progressWrap.append(progress, label);

    const preview = node('div', 'span-2 photo-upload-preview');
    preview.id = 'photo-upload-preview';
    form.append(actions, preview, progressWrap);

    const fileInput = form.elements.files;
    fileInput?.addEventListener('change', () => {
      previewObjectUrls.forEach(URL.revokeObjectURL);
      previewObjectUrls = [];
      preview.replaceChildren();
      for (const file of [...(fileInput.files || [])]) {
        const url = URL.createObjectURL(file);
        previewObjectUrls.push(url);
        const figure = node('figure', 'upload-preview-item');
        const img = document.createElement('img');
        img.src = url;
        img.alt = file.name;
        figure.append(img, node('figcaption', '', file.name));
        preview.append(figure);
      }
    });
  }

  function setUploadProgress(value, labelText = '') {
    const wrap = $('photo-upload-progress-wrap');
    const bar = $('photo-upload-progress');
    const label = $('photo-upload-progress-label');
    if (!wrap || !bar || !label) return;
    wrap.hidden = false;
    bar.value = Math.max(0, Math.min(100, value));
    label.textContent = labelText;
  }

  function xhrCreatePhoto(publicId, formData, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', `/api/projects/${encodeURIComponent(publicId)}/photos`);
      xhr.responseType = 'text';
      xhr.upload.addEventListener('progress', event => {
        if (event.lengthComputable) onProgress?.(event.loaded / event.total);
      });
      xhr.addEventListener('load', () => {
        let data = {};
        if (xhr.responseText) {
          try { data = JSON.parse(xhr.responseText); }
          catch { data = { error: xhr.responseText }; }
        }
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else {
          const error = new Error(data.error || `HTTP ${xhr.status}`);
          error.status = xhr.status;
          error.data = data;
          reject(error);
        }
      });
      xhr.addEventListener('error', () => reject(new Error('Network error')));
      xhr.send(formData);
    });
  }

  submitPhoto = async function enhancedSubmitPhoto(event) {
    event.preventDefault();
    try {
      const form = event.currentTarget;
      const type = form.elements.source_type.value;
      if (type === 'upload') {
        const files = [...form.elements.files.files];
        if (!files.length) throw new Error(i18n.t('choose_files'));
        for (let index = 0; index < files.length; index++) {
          const file = files[index];
          await xhrCreatePhoto(editorPublicId, makePhotoFormData(form, file), ratio => {
            const aggregate = ((index + ratio) / files.length) * 100;
            setUploadProgress(aggregate, i18n.t('uploading_photo_progress', { current: index + 1, total: files.length }));
          });
        }
      } else {
        setUploadProgress(10, i18n.t('uploading_photo'));
        await xhrCreatePhoto(editorPublicId, makePhotoFormData(form), ratio => setUploadProgress(Math.max(10, ratio * 100), i18n.t('uploading_photo')));
      }
      setUploadProgress(100, i18n.t('upload_complete'));
      form.reset();
      updatePhotoSourceFields();
      previewObjectUrls.forEach(URL.revokeObjectURL);
      previewObjectUrls = [];
      $('photo-upload-preview')?.replaceChildren();
      const project = await api.getProject(editorPublicId, true);
      editorProject = project;
      renderPhotoEditor(project);
      setStatus(i18n.t('saved'), 'success');
    } catch (error) { showError(error); }
  };

  const baseRenderPhotoEditor = renderPhotoEditor;
  renderPhotoEditor = function enhancedRenderPhotoEditor(project) {
    baseRenderPhotoEditor(project);
    document.querySelectorAll('#photo-list .photo-card').forEach(card => {
      const actions = card.querySelector('.photo-actions');
      if (!actions || actions.querySelector('.photo-pick-position')) return;
      const pick = node('button', 'secondary small photo-pick-position', i18n.t('pick_photo_position'));
      pick.type = 'button';
      pick.addEventListener('click', async () => {
        try {
          const [lon, lat] = await editorMap.pickPoint();
          card.querySelector('.photo-lon').value = lon.toFixed(7);
          card.querySelector('.photo-lat').value = lat.toFixed(7);
        } catch (error) { showError(error); }
      });
      const clear = node('button', 'secondary small', i18n.t('clear_photo_position'));
      clear.type = 'button';
      clear.addEventListener('click', () => {
        card.querySelector('.photo-lon').value = '';
        card.querySelector('.photo-lat').value = '';
      });
      actions.prepend(pick, clear);
    });
    if (typeof editorMap !== 'undefined' && editorMap?.setPhotos) editorMap.setPhotos(project.photos || []);
  };

  function associatedPhotoElement(photo) {
    if (photo.thumbnail_url || photo.image_url) {
      const link = document.createElement('a');
      link.href = photo.image_url || photo.source_url || '#';
      if (link.href !== '#') { link.target = '_blank'; link.rel = 'noopener noreferrer'; }
      const img = document.createElement('img');
      img.src = photo.thumbnail_url || photo.image_url;
      img.alt = photo.caption || '';
      img.loading = 'lazy';
      link.append(img);
      return link;
    }
    if (photo.source_url) {
      const link = node('a', 'associated-photo-link', photo.caption || i18n.t('source'));
      link.href = photo.source_url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      return link;
    }
    return null;
  }

  function appendAssociatedPhotos(container, photos) {
    if (!container || !photos.length) return;
    const strip = node('div', 'associated-photo-strip');
    for (const photo of photos) {
      const element = associatedPhotoElement(photo);
      if (element) strip.append(element);
    }
    if (strip.childElementCount) container.append(strip);
  }

  function augmentPublicAssociations(project) {
    const photos = project.photos || [];
    [...$('public-places').children].forEach((card, index) => {
      const place = project.place_results?.[index];
      if (place) appendAssociatedPhotos(card, photos.filter(photo => Number(photo.place_result_id) === Number(place.id)));
    });
    [...$('public-entries').children].forEach((card, index) => {
      const entry = project.entries?.[index];
      if (entry) appendAssociatedPhotos(card, photos.filter(photo => Number(photo.entry_id) === Number(entry.id)));
    });
    [...$('public-featured').children].forEach((card, index) => {
      const item = project.featured_objects?.[index];
      if (item) appendAssociatedPhotos(card, photos.filter(photo => Number(photo.featured_object_id) === Number(item.id)));
    });

    [...$('public-photos').querySelectorAll('.photo-card')].forEach((figure, index) => {
      const photo = photos[index];
      const img = figure.querySelector('img');
      if (!photo || !img || img.closest('a')) return;
      const href = photo.image_url || photo.source_url;
      if (!href) return;
      const link = document.createElement('a');
      link.href = href;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      img.replaceWith(link);
      link.append(img);
    });
  }

  const baseInitPublic = initPublic;
  initPublic = async function enhancedInitPublic(publicId) {
    await baseInitPublic(publicId);
    try {
      const project = await api.getProject(publicId);
      const map = mapRegistry.get('public-map');
      installMapModeControls(map, 'public-map');
      map?.setPhotos?.(project.photos || []);
      augmentPublicAssociations(project);
    } catch (error) { showError(error); }
  };

  const baseInitEditor = initEditor;
  initEditor = async function enhancedInitEditor(publicId = null) {
    await baseInitEditor(publicId);
    installPhotoFormExtras();
    if (publicId) {
      installMapModeControls(diffMap, 'diff-map');
      const activity = $('activity-type');
      if (activity) {
        activity.disabled = true;
        activity.title = i18n.t('fixed_activity_type');
      }
    }
  };
})();
