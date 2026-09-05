// map.js – MapLibre + Draw
class Mapbox {
  constructor({container, pmtilesUrl}) {
    this.map = new maplibregl.Map({
      container,
      // Use the PMTiles style JSON passed in pmtilesUrl
      style: pmtilesUrl,
      center: [139.7, 35.68],
      zoom: 14,
    });

    // Use Mapbox GL Draw (MapboxDraw) instead of maplibre-gl-draw
    // Show default toolbar (draw rectangle, point, line, polygon)
    this.draw = new MapboxDraw({displayControlsDefault: true});
    this.map.addControl(this.draw, 'top-left');

    // No need to add a source here – the style JSON already defines layers.
    // If you need to add custom layers later, you can do so in this callback.
    this.map.on('load', () => {
      // Placeholder for any post‑load logic.
    });
  }

  getBBox() {
    const features = this.draw.getAll().features;
    if (!features.length) return null;
    const f = features[0];
    const coords = f.geometry.coordinates[0];
    const minLon = Math.min(...coords.map(c => c[0]));
    const maxLon = Math.max(...coords.map(c => c[0]));
    const minLat = Math.min(...coords.map(c => c[1]));
    const maxLat = Math.max(...coords.map(c => c[1]));
    return {minLon, minLat, maxLon, maxLat};
  }

  addGeoJson(geojson) {
    if (this.map.getSource('diff')) {
      this.map.getSource('diff').setData(geojson);
      return;
    }
    this.map.addSource('diff', {type: 'geojson', data: geojson});
    this.map.addLayer({
      id: 'added',
      type: 'line',
      source: 'diff',
      filter: ['==', ['get', 'action'], 'added'],
      layout: {'line-width': 2},
      paint: {'line-color': '#00FF00', 'line-opacity': 0.8},
    });
    this.map.addLayer({
      id: 'modified',
      type: 'line',
      source: 'diff',
      filter: ['==', ['get', 'action'], 'modified'],
      layout: {'line-width': 2},
      paint: {'line-color': '#0000FF', 'line-opacity': 0.8},
    });
    this.map.addLayer({
      id: 'deleted',
      type: 'line',
      source: 'diff',
      filter: ['==', ['get', 'action'], 'deleted'],
      layout: {'line-width': 2},
      paint: {'line-color': '#FF0000', 'line-opacity': 0.8},
    });
  }
}
