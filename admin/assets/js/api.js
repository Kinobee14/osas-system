// Tiny fetch wrapper for the PHP API
const api = (() => {
  const BASE = 'api/';

  // Never redirect to the login page while already on it — that is an endless reload loop.
  function toLogin() {
    if (!/login\.html$/i.test(location.pathname)) location.replace('login.html');
  }

  async function request(method, url, data, opts = {}) {
    const res = await fetch(BASE + url, {
      method,
      credentials: 'same-origin',
      headers: data ? { 'Content-Type': 'application/json' } : {},
      body: data ? JSON.stringify(data) : undefined,
    });

    let json;
    try {
      json = await res.json();
    } catch {
      throw new Error('Server returned an invalid response. Is Apache/MySQL running?');
    }

    if (res.status === 401 && !opts.silent) {
      toLogin();
    }
    if (!json.success) throw new Error(json.message || 'Request failed');
    return json;
  }

  /**
   * POSTs a FormData (files included). Can't reuse request() because the
   * browser has to set its own multipart Content-Type with the boundary —
   * setting it by hand is the classic way to break a file upload.
   */
  async function upload(url, formData) {
    const res = await fetch(BASE + url, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    });

    let json;
    try {
      json = await res.json();
    } catch {
      // A 413 from Apache/PHP arrives as an HTML error page, not JSON.
      if (res.status === 413) throw new Error('Those files are too large for the server to accept. Try fewer or smaller photos.');
      throw new Error('Server returned an invalid response. Is Apache/MySQL running?');
    }

    if (res.status === 401) toLogin();
    if (!json.success) throw new Error(json.message || 'Upload failed');
    return json;
  }

  return {
    get: (url, opts) => request('GET', url, null, opts).then((r) => r.data),
    post: (url, data, opts) => request('POST', url, data || {}, opts),
    put: (url, data, opts) => request('PUT', url, data, opts),
    del: (url, opts) => request('DELETE', url, null, opts),
    upload,
  };
})();