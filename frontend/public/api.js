// api.js – fetch 封装
const api = {
  createProject: async (data) => {
    const res = await fetch('/api/projects', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(data),
    });
    if (!res.ok) throw await res.json();
    return res.json();
  },

  getDiff: async (body) => {
    const res = await fetch('/api/osm-diff', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body),
    });
    // For debugging: log raw response body
    const raw = await res.text();
    console.log('[api.getDiff] raw response:', raw);
    if (!res.ok) {
      try {
        const err = JSON.parse(raw);
        throw err;
      } catch {
        throw new Error(`Unexpected response: ${raw}`);
      }
    }
    try {
      return JSON.parse(raw);
    } catch (e) {
      throw new Error(`Failed to parse JSON: ${raw}`);
    }
  },

  attachDiff: async (publicId, diffId) => {
    const res = await fetch(`/api/projects/${publicId}/save-diff`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ diff_id: diffId }),
    });
    if (!res.ok) throw await res.json();
    return res.json();
  },
};
