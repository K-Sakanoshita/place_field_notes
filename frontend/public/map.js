(() => {
  if (window.pmtiles && !window.__pfnPmtilesProtocol) {
    const protocol = new pmtiles.Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);
    window.__pfnPmtilesProtocol = protocol;
  }

  class PfnMap {
    constructor(container, styleUrl = '/tiles/japan-20260101.json', options = {}) {
      this.bbox = null;
      this.bboxClicks = [];
      this.diff = null;
      this.places = [];
      this.markers = [];
      this.map = new maplibregl.Map({
        container,
        style: styleUrl,
        center: options.center || [135.5, 34.7],
        zoom: options.zoom || 11,
        attributionControl: true,
      });
      this.map.addControl(new maplibregl.NavigationControl(), 'top-right');
      this.map.on('style.load', () => this.restoreOverlays());
    }

    async ready() {
      if (this.map.loaded()) return;
      await new Promise(resolve => this.map.once('load', resolve));
    }

    startBboxSelection(onComplete) {
      this.bboxClicks = [];
      this.map.getCanvas().style.cursor = 'crosshair';
      const handler = e => {
        this.bboxClicks.push([e.lngLat.lng, e.lngLat.lat]);
        if (this.bboxClicks.length < 2) return;
        this.map.off('click', handler);
        this.map.getCanvas().style.cursor = '';
        const [[x1, y1], [x2, y2]] = this.bboxClicks;
        this.setBBox([Math.min(x1, x2), Math.min(y1, y2), Math.max(x1, x2), Math.max(y1, y2)]);
        if (onComplete) onComplete(this.bbox);
      };
      this.map.on('click', handler);
    }

    setBBox(bbox) {
      this.bbox = bbox.map(Number);
      this.drawBbox();
    }

    getBBox() { return this.bbox; }

    fitBbox(bbox = this.bbox) {
      if (!bbox) return;
      this.map.fitBounds([[bbox[0], bbox[1]], [bbox[2], bbox[3]]], { padding: 40, duration: 0 });
    }

    async setStyle(styleUrl) {
      this.map.setStyle(styleUrl);
      await new Promise(resolve => this.map.once('style.load', resolve));
    }

    addDiff(geojson) {
      this.diff = geojson || { type: 'FeatureCollection', features: [] };
      this.drawDiff();
    }

    setPlaces(places) {
      this.places = places || [];
      this.drawPlaces();
    }

    pickPoint() {
      this.map.getCanvas().style.cursor = 'crosshair';
      return new Promise(resolve => {
        const handler = e => {
          this.map.off('click', handler);
          this.map.getCanvas().style.cursor = '';
          resolve([e.lngLat.lng, e.lngLat.lat]);
        };
        this.map.on('click', handler);
      });
    }

    restoreOverlays() {
      if (this.bbox) this.drawBbox();
      if (this.diff) this.drawDiff();
      if (this.places.length) this.drawPlaces();
    }

    drawBbox() {
      if (!this.map.isStyleLoaded() || !this.bbox) return;
      const [w, s, e, n] = this.bbox;
      const data = { type: 'Feature', geometry: { type: 'Polygon', coordinates: [[[w,s],[e,s],[e,n],[w,n],[w,s]]] }, properties: {} };
      if (this.map.getSource('pfn-bbox')) { this.map.getSource('pfn-bbox').setData(data); return; }
      this.map.addSource('pfn-bbox', { type: 'geojson', data });
      this.map.addLayer({ id: 'pfn-bbox-fill', type: 'fill', source: 'pfn-bbox', paint: { 'fill-color': '#635bff', 'fill-opacity': 0.08 } });
      this.map.addLayer({ id: 'pfn-bbox-line', type: 'line', source: 'pfn-bbox', paint: { 'line-color': '#332ca8', 'line-width': 2, 'line-dasharray': [2,2] } });
    }

    drawDiff() {
      if (!this.map.isStyleLoaded() || !this.diff) return;
      if (this.map.getSource('pfn-diff')) { this.map.getSource('pfn-diff').setData(this.diff); return; }
      this.map.addSource('pfn-diff', { type: 'geojson', data: this.diff });
      const actions = [['added', '#087f5b', null, 0.25], ['modified', '#1864ab', [4,2], 0.18], ['deleted', '#c92a2a', [1,2], 0.12]];
      for (const [action, color, dash, opacity] of actions) {
        const filter = ['==', ['get', 'action'], action];
        this.map.addLayer({ id: `pfn-${action}-fill`, type: 'fill', source: 'pfn-diff', filter: ['all', filter, ['==', ['geometry-type'], 'Polygon']], paint: { 'fill-color': color, 'fill-opacity': opacity, 'fill-outline-color': color } });
        const linePaint = { 'line-color': color, 'line-width': action === 'deleted' ? 3 : 4, 'line-opacity': 0.9 };
        if (dash) linePaint['line-dasharray'] = dash;
        this.map.addLayer({ id: `pfn-${action}-line`, type: 'line', source: 'pfn-diff', filter, paint: linePaint });
        this.map.addLayer({ id: `pfn-${action}-point`, type: 'circle', source: 'pfn-diff', filter: ['all', filter, ['==', ['geometry-type'], 'Point']], paint: { 'circle-color': color, 'circle-radius': action === 'added' ? 7 : 6, 'circle-stroke-color': '#ffffff', 'circle-stroke-width': 2, 'circle-opacity': action === 'deleted' ? 0.6 : 0.95 } });
      }
    }

    drawPlaces() {
      for (const marker of this.markers) marker.remove();
      this.markers = [];
      for (const place of this.places) {
        if (place.lat == null || place.lon == null) continue;
        const el = document.createElement('button');
        el.className = 'place-marker'; el.type = 'button'; el.title = place.title || ''; el.setAttribute('aria-label', place.title || 'Place'); el.textContent = '●';
        const popup = new maplibregl.Popup({ offset: 18 }).setText(place.title || '');
        const marker = new maplibregl.Marker({ element: el }).setLngLat([Number(place.lon), Number(place.lat)]).setPopup(popup).addTo(this.map);
        this.markers.push(marker);
      }
    }
  }

  window.PfnMap = PfnMap;
})();
