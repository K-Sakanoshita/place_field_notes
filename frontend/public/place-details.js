(() => {
  function sourceLabel(type) {
    const labels = {
      wikipedia: 'Wikipedia',
      wikidata: 'Wikidata',
      commons: 'Wikimedia Commons',
      osm: 'OpenStreetMap',
      url: i18n.t('source'),
    };
    return labels[type] || type;
  }

  function placePopupContent(place) {
    const root = document.createElement('div');
    root.className = 'place-popup';
    const title = document.createElement('strong');
    title.className = 'place-popup-title';
    title.textContent = place.title || i18n.t('place_results');
    root.append(title);

    const links = Array.isArray(place.links) ? place.links : [];
    if (links.length) {
      const list = document.createElement('ul');
      list.className = 'place-popup-links';
      for (const link of links) {
        const item = document.createElement('li');
        const label = document.createElement('span');
        label.className = 'place-popup-source';
        label.textContent = `${sourceLabel(link.source_type)}: `;
        item.append(label);
        if (link.source_url) {
          const anchor = document.createElement('a');
          anchor.href = link.source_url;
          anchor.target = '_blank';
          anchor.rel = 'noopener noreferrer';
          anchor.textContent = link.source_key || link.source_url;
          item.append(anchor);
        } else {
          item.append(document.createTextNode(link.source_key || ''));
        }
        list.append(item);
      }
      root.append(list);
    }

    if (place.comment) {
      const comment = document.createElement('p');
      comment.className = 'place-popup-comment';
      comment.textContent = place.comment;
      root.append(comment);
    }
    return root;
  }

  if (window.PfnMap) {
    window.PfnMap.prototype.drawPlaces = function drawDetailedPlaces() {
      for (const marker of this.markers) marker.remove();
      this.markers = [];
      for (const place of this.places || []) {
        if (place.lat == null || place.lon == null) continue;
        const el = document.createElement('button');
        el.className = 'place-marker';
        el.type = 'button';
        el.title = place.title || '';
        el.setAttribute('aria-label', place.title || i18n.t('place_results'));
        el.textContent = '●';
        const popup = new maplibregl.Popup({ offset: 18, maxWidth: '340px' })
          .setDOMContent(placePopupContent(place));
        const marker = new maplibregl.Marker({ element: el })
          .setLngLat([Number(place.lon), Number(place.lat)])
          .setPopup(popup)
          .addTo(this.map);
        this.markers.push(marker);
      }
    };
  }

  function extractQid(value) {
    const match = String(value || '').match(/\bQ\d+\b/i);
    return match ? match[0].toUpperCase() : null;
  }

  function qidFromPlaceCard(card) {
    for (const row of card.querySelectorAll('.link-editor')) {
      if (row.querySelector('.link-type')?.value !== 'wikidata') continue;
      const qid = extractQid(row.querySelector('.link-key')?.value)
        || extractQid(row.querySelector('.link-url')?.value);
      if (qid) return qid;
    }
    return null;
  }

  async function fetchWikidataCoordinate(qid) {
    const params = new URLSearchParams({
      action: 'wbgetentities',
      ids: qid,
      props: 'claims',
      format: 'json',
      origin: '*',
    });
    const response = await fetch(`https://www.wikidata.org/w/api.php?${params}`);
    if (!response.ok) throw new Error(`Wikidata HTTP ${response.status}`);
    const data = await response.json();
    const values = data?.entities?.[qid]?.claims?.P625 || [];
    for (const claim of values) {
      const value = claim?.mainsnak?.datavalue?.value;
      if (Number.isFinite(Number(value?.latitude)) && Number.isFinite(Number(value?.longitude))) {
        return [Number(value.longitude), Number(value.latitude)];
      }
    }
    return null;
  }

  function installWikidataCoordinateButton(card) {
    if (!card || card.querySelector('.wikidata-coordinate-button')) return;
    const position = card.querySelector('.position-row');
    if (!position) return;
    const button = node('button', 'secondary small wikidata-coordinate-button', i18n.t('use_wikidata_coordinate'));
    button.type = 'button';
    button.addEventListener('click', async () => {
      try {
        const qid = qidFromPlaceCard(card);
        if (!qid) throw new Error(i18n.t('wikidata_qid_required'));
        setStatus(i18n.t('loading_wikidata_coordinate'));
        const coordinate = await fetchWikidataCoordinate(qid);
        if (!coordinate) throw new Error(i18n.t('wikidata_coordinate_not_found'));
        const [lon, lat] = coordinate;
        const message = i18n.t('confirm_wikidata_coordinate', {
          qid,
          lat: lat.toFixed(7),
          lon: lon.toFixed(7),
        });
        if (confirm(message)) {
          card.querySelector('.place-lon').value = lon.toFixed(7);
          card.querySelector('.place-lat').value = lat.toFixed(7);
          setStatus(i18n.t('wikidata_coordinate_applied'), 'success');
        } else {
          setStatus('');
        }
      } catch (error) {
        showError(error);
      }
    });
    position.append(button);
  }

  const baseAddPlaceRow = addPlaceRow;
  addPlaceRow = function addPlaceRowWithCoordinateCandidate(place = {}) {
    const result = baseAddPlaceRow(place);
    const card = $('place-list').lastElementChild;
    installWikidataCoordinateButton(card);
    return result;
  };

  document.querySelectorAll('#place-list .place-editor').forEach(installWikidataCoordinateButton);
})();
