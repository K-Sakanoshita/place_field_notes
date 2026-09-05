const api = {
  async request(url, options = {}) {
    const response = await fetch(url, options);
    const text = await response.text();
    let data = {};
    if (text) {
      try { data = JSON.parse(text); }
      catch { data = { error: text }; }
    }
    if (!response.ok) {
      const error = new Error(data.error || `HTTP ${response.status}`);
      error.status = response.status;
      error.data = data;
      throw error;
    }
    return data;
  },

  previewDiff(data) {
    return this.request('/api/osm-diff', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },

  createProject(data) {
    return this.request('/api/projects', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },

  getProject(publicId, editor = false) {
    return this.request(`/api/projects/${encodeURIComponent(publicId)}${editor ? '?editor=1' : ''}`);
  },

  establishEditSession(publicId, token) {
    return this.request(`/api/projects/${encodeURIComponent(publicId)}/edit-session`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token }),
    });
  },

  updateProject(publicId, data) {
    return this.request(`/api/projects/${encodeURIComponent(publicId)}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },

  createPhoto(publicId, formData) {
    return this.request(`/api/projects/${encodeURIComponent(publicId)}/photos`, { method: 'POST', body: formData });
  },

  updatePhoto(publicId, photoId, data) {
    return this.request(`/api/projects/${encodeURIComponent(publicId)}/photos/${Number(photoId)}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  },

  deletePhoto(publicId, photoId) {
    return this.request(`/api/projects/${encodeURIComponent(publicId)}/photos/${Number(photoId)}`, { method: 'DELETE' });
  },
};
