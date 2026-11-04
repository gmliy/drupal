/**
 * @file
 * Persistent upload queue shared by the upload center, quick gallery and
 * the admin chrome. IndexedDB holds metadata and Blobs when the browser
 * allows it. BroadcastChannel elects one tab as the uploader.
 */
(function (Drupal, drupalSettings) {
  const DB_NAME = 'xedc-upload-v2';
  const DB_VERSION = 2;
  const STORE = 'jobs';
  const CHANNEL = 'xedc-upload-v2';
  const LEADER_KEY = 'xedcUploadLeader';
  const MAX_BLOB_BYTES = 220 * 1024 * 1024;
  const ACTIVE = ['pending', 'hashing', 'uploading', 'merging', 'validating', 'queued', 'processing', 'extracting', 'importing', 'retrying', 'interrupted', 'paused', 'open', 'needs_title'];
  const NEED_FILE = ['pending', 'hashing', 'uploading', 'merging', 'validating', 'retrying', 'interrupted', 'paused', 'open', 'needs_title'];
  const DONE = ['completed', 'duplicate', 'cancelled', 'canceled', 'success', 'review'];
  const CANCELABLE = ['pending', 'needs_title', 'hashing', 'uploading', 'merging', 'validating', 'retrying', 'interrupted', 'paused', 'open', 'failed', 'queued'];
  const TOMBSTONE_KEY = 'xedcUploadCancelled';

  const labels = {
    pending: '等待上传',
    needs_title: '等待填写标题',
    open: '等待上传',
    queued: '排队等待处理',
    uploading: '正在上传',
    uploaded: '上传完成',
    merging: '正在合并文件',
    validating: '正在校验',
    processing: '正在处理',
    extracting: '正在解压',
    importing: '正在导入',
    retrying: '正在重试',
    paused: '已暂停',
    completed: '已完成',
    success: '成功',
    duplicate: '文件已存在，已复用',
    skipped: '已跳过',
    failed: '失败',
    cancelled: '已取消',
    canceled: '已取消',
    interrupted: '上传中断',
    review: '待审核',
    expired: '已过期',
    hashing: '正在校验',
  };

  function statusText(value) {
    const key = String(value || '').trim();
    if (labels[key]) return labels[key];
    if (/[\u4e00-\u9fff]/.test(key)) return key;
    return '未知状态';
  }

  function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) return '0 B';
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    if (bytes < 1024) return Math.round(bytes) + ' B';
    let value = bytes;
    let index = 0;
    let rounded = value;
    while (index < units.length - 1) {
      value /= 1024;
      index += 1;
      rounded = Math.round(value * 100) / 100;
      if (rounded < 1024 || index === units.length - 1) break;
    }
    return rounded.toFixed(2) + ' ' + units[index];
  }

  function hex(buffer) {
    const bytes = buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : buffer;
    let out = '';
    for (let i = 0; i < bytes.length; i += 1) out += bytes[i].toString(16).padStart(2, '0');
    return out;
  }

  async function sha256Blob(blob) {
    if (!window.crypto || !crypto.subtle) throw new Error('浏览器不支持 SHA-256');
    return hex(await crypto.subtle.digest('SHA-256', await blob.arrayBuffer()));
  }

  function clientId() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return hex(bytes);
  }

  function now() {
    return Date.now();
  }

  function settings() {
    return Object.assign({
      sessionsUrl: '/admin/content/image-imports/upload/sessions',
      sessionUrl: '/admin/content/image-imports/upload/session',
      sessionBase: '/admin/content/image-imports/upload/session/',
      probeUrl: '/admin/content/image-imports/upload/probe',
      draftUrl: '/admin/content/image-imports/draft',
      closeUrl: '/admin/content/image-imports/close',
      progressBase: '/admin/content/image-imports/',
      syncUrl: '/admin/content/image-imports/sync',
      maxArchive: 209715200,
      maxImage: 52428800,
      chunkBytes: 5242880,
      headBytes: 65536,
      concurrency: 2,
      entry: 'admin',
    }, (drupalSettings && drupalSettings.xedcUpload) || {}, (drupalSettings && drupalSettings.xedcAsset) || {});
  }

  function openDb() {
    return new Promise((resolve, reject) => {
      if (!window.indexedDB) {
        reject(new Error('浏览器不支持 IndexedDB，上传任务无法持久化。'));
        return;
      }
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onerror = () => reject(request.error || new Error('无法打开本地上传数据库。'));
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE)) {
          const store = db.createObjectStore(STORE, { keyPath: 'id' });
          store.createIndex('sessionId', 'sessionId', { unique: false });
          store.createIndex('phase', 'phase', { unique: false });
          store.createIndex('updated', 'updated', { unique: false });
        }
      };
      request.onsuccess = () => resolve(request.result);
    });
  }

  async function withStore(mode, worker) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      let value;
      let settled = false;
      const tx = db.transaction(STORE, mode);
      const store = tx.objectStore(STORE);
      tx.oncomplete = () => {
        if (settled) return;
        settled = true;
        db.close();
        resolve(value);
      };
      tx.onerror = () => {
        if (settled) return;
        settled = true;
        db.close();
        reject(tx.error || new Error('本地上传数据库写入失败。'));
      };
      tx.onabort = () => {
        if (settled) return;
        settled = true;
        db.close();
        reject(tx.error || new Error('本地上传数据库事务已中止。'));
      };
      try {
        const result = worker(store);
        if (result && typeof result.then === 'function') {
          result.then((next) => { value = next; }).catch((error) => {
            try { tx.abort(); } catch (e) { /* ignore */ }
            if (!settled) {
              settled = true;
              db.close();
              reject(error);
            }
          });
        }
        else {
          value = result;
        }
      }
      catch (error) {
        try { tx.abort(); } catch (e) { /* ignore */ }
        if (!settled) {
          settled = true;
          db.close();
          reject(error);
        }
      }
    });
  }

  function requestToPromise(request) {
    return new Promise((resolve, reject) => {
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error || new Error('IndexedDB 请求失败'));
    });
  }

  function rowFromJob(job, includeBlob) {
    const row = {
      id: job.id,
      clientId: job.clientId,
      name: job.name,
      bytes: job.bytes,
      mime: job.mime || '',
      sha256: job.sha256 || '',
      head: job.head || '',
      kind: job.kind,
      entry: job.entry || 'admin',
      sessionId: job.sessionId || '',
      taskId: job.taskId || 0,
      mid: job.mid || 0,
      nid: job.nid || 0,
      originalBytes: job.originalBytes || 0,
      optimizedBytes: job.optimizedBytes || 0,
      confirmed: job.confirmed || 0,
      missing: job.missing || [],
      phase: job.phase,
      error: job.error || '',
      retries: job.retries || 0,
      created: job.created || now(),
      updated: now(),
      chunkBytes: job.chunkBytes || settings().chunkBytes,
      canAutoResume: !!(job.file || job.blob) || !!job.canAutoResume,
      persistWarned: !!job.persistWarned,
      title: job.title || '',
    };
    if (includeBlob && job.file && job.file.size <= MAX_BLOB_BYTES) {
      row.blob = job.file;
      row.canAutoResume = true;
    }
    else if (includeBlob && job.blob && job.blob.size <= MAX_BLOB_BYTES) {
      row.blob = job.blob;
      row.canAutoResume = true;
    }
    return row;
  }

  const listeners = new Set();
  const jobs = new Map();
  const activeXhrs = new Map();
  const aborted = new Set();
  let csrf = '';
  let leader = false;
  let leaderTimer = null;
  let tabId = clientId();
  let channel = null;
  let started = false;
  let persistOk = true;
  let persistError = '';
  let running = false;

  function readTombstones() {
    try {
      const rows = JSON.parse(localStorage.getItem(TOMBSTONE_KEY) || '[]');
      return Array.isArray(rows) ? rows.filter(Boolean) : [];
    }
    catch (e) {
      return [];
    }
  }

  function rememberTombstone(id) {
    if (!id) return;
    const rows = readTombstones().filter((item) => item !== id);
    rows.push(id);
    try { localStorage.setItem(TOMBSTONE_KEY, JSON.stringify(rows.slice(-200))); }
    catch (e) { /* ignore */ }
    aborted.add(id);
  }

  function isTombstoned(id, sessionId, clientIdValue) {
    const rows = readTombstones();
    return rows.includes(id) || (sessionId && rows.includes(sessionId)) || (clientIdValue && rows.includes(clientIdValue)) || aborted.has(id);
  }

  function emit() {
    const snapshot = list();
    listeners.forEach((fn) => {
      try { fn(snapshot); } catch (e) { /* listener errors stay local */ }
    });
    if (channel) {
      try { channel.postMessage({ type: 'state', tabId, jobs: snapshot.map((job) => ({ ...job, file: null, blob: null, preview: '' })) }); }
      catch (e) { /* ignore */ }
    }
  }

  function list() {
    return [...jobs.values()].sort((a, b) => (b.updated || 0) - (a.updated || 0));
  }

  function counts() {
    const all = list().filter((job) => job.phase !== 'cancelled' && job.phase !== 'canceled' && !aborted.has(job.id));
    const count = (names) => all.filter((job) => names.includes(job.phase)).length;
    return {
      total: all.length,
      uploading: count(['uploading', 'hashing', 'merging', 'validating', 'retrying']),
      waiting: count(['pending', 'needs_title', 'queued', 'paused', 'interrupted', 'open', 'processing', 'extracting', 'importing']),
      failed: count(['failed']),
      completed: count(['completed', 'duplicate', 'uploaded', 'success', 'review']),
    };
  }

  async function persist(job, includeBlob) {
    try {
      const row = rowFromJob(job, includeBlob !== false);
      await withStore('readwrite', (store) => requestToPromise(store.put(row)));
      persistOk = true;
      persistError = '';
      job.persistWarned = false;
      job.canAutoResume = !!(row.blob || job.file || job.blob);
    }
    catch (error) {
      persistOk = false;
      persistError = (error && error.message) || '本地持久化失败';
      job.persistWarned = true;
      job.canAutoResume = false;
      if (!job.error) job.error = '本地任务没有保存成功：' + persistError;
      throw error;
    }
  }

  async function removePersisted(id) {
    try {
      await withStore('readwrite', (store) => requestToPromise(store.delete(id)));
    }
    catch (e) { /* ignore */ }
  }

  async function loadPersisted() {
    const rows = await withStore('readonly', (store) => requestToPromise(store.getAll())) || [];
    for (const row of rows) {
      if (jobs.has(row.id)) continue;
      if (isTombstoned(row.id, row.sessionId, row.clientId) || row.phase === 'cancelled' || row.phase === 'canceled') {
        await removePersisted(row.id);
        continue;
      }
      const blob = row.blob || null;
      const job = Object.assign({}, row, {
        file: blob,
        blob,
        inflight: 0,
        preview: '',
        canAutoResume: !!blob,
      });
      // Only uploads that still need bytes require a local file. Server-side
      // processing continues without the browser Blob.
      const needsFile = NEED_FILE.includes(job.phase) && job.phase !== 'needs_title' && !job.taskId && !job.mid;
      if (needsFile && !job.file) {
        job.phase = 'interrupted';
        job.error = job.error || '请重新选择原来的文件以继续上传。';
        job.canAutoResume = false;
      }
      else if (job.file && NEED_FILE.includes(job.phase) && job.phase !== 'needs_title') {
        // Keep blob-backed jobs ready for the leader to resume after navigation.
        job.phase = job.phase === 'uploading' ? 'retrying' : job.phase;
      }
      else if (job.taskId && NEED_FILE.includes(job.phase)) {
        job.phase = 'queued';
        job.error = '';
      }
      jobs.set(job.id, job);
    }
  }

  async function sessionToken() {
    if (csrf) return csrf;
    const response = await fetch('/session/token', { credentials: 'same-origin' });
    if (!response.ok) throw new Error('无法获取上传凭证，请刷新页面后重试。');
    csrf = (await response.text()).trim();
    if (!csrf) throw new Error('无法获取上传凭证，请刷新页面后重试。');
    return csrf;
  }

  async function api(url, options) {
    const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
    if (options.method && options.method !== 'GET') headers['X-CSRF-Token'] = await sessionToken();
    const response = await fetch(url, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers,
      body: options.body,
    });
    let json = {};
    try { json = await response.json(); }
    catch (e) { json = {}; }
    if (!response.ok && !json.error && !json.message) {
      json.message = response.status >= 500 ? '服务器暂时无法完成上传，已保留已收到的分块。' : ('请求失败 HTTP ' + response.status);
    }
    return { response, json };
  }

  function sessionUrl(id, suffix) {
    return settings().sessionBase + id + (suffix || '');
  }

  function applyServer(job, json) {
    job.sessionId = json.session_id || job.sessionId;
    job.confirmed = Number(json.received_bytes || job.confirmed || 0);
    job.bytes = Number(json.total_bytes || job.bytes || 0);
    job.taskId = json.task_id || job.taskId || 0;
    job.nid = json.nid || job.nid || 0;
    job.mid = json.mid || job.mid || 0;
    if (json.original_bytes) job.originalBytes = Number(json.original_bytes);
    if (json.optimized_bytes) job.optimizedBytes = Number(json.optimized_bytes);
    job.chunkBytes = Number(json.chunk_bytes || job.chunkBytes || settings().chunkBytes);
    job.missing = Array.isArray(json.missing_chunks) ? json.missing_chunks.map(Number) : (job.missing || []);
    if (json.phase) job.phase = json.phase;
    if (json.error) job.error = json.error;
    if (json.message && ['duplicate', 'completed', 'queued'].includes(job.phase)) job.error = '';
    job.updated = now();
  }

  function rejectLocal(file, kind, cfg) {
    const extension = String(file.name || '').toLowerCase().split('.').pop();
    if (!file.size) return '空文件不能上传。';
    if (kind === 'archive' && !['zip', '7z'].includes(extension)) return '只接受 ZIP 和 7z。';
    if (kind === 'archive' && cfg.maxArchive && file.size > cfg.maxArchive) return '超过压缩包大小限制，没有上传。';
    if ((kind === 'image' || kind === 'quick') && !['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension) && !(file.type || '').startsWith('image/')) {
      return '只接受 JPEG、PNG、GIF 和 WebP。';
    }
    if ((kind === 'image' || kind === 'quick') && cfg.maxImage && file.size > cfg.maxImage) return '超过单张图片大小限制，没有上传。';
    return '';
  }

  async function probe(job, apply) {
    const cfg = settings();
    if (!cfg.probeUrl || !job.sha256) return false;
    const { response, json } = await api(cfg.probeUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        sha256: job.sha256,
        size: job.bytes,
        kind: job.kind === 'quick' ? 'image' : job.kind,
        filename: job.name,
        client_upload_id: job.clientId,
        nid: job.nid || 0,
        title: job.title || '',
        apply: !!apply,
      }),
    });
    if (!response.ok || !json.reusable || json.upload) return false;
    if (!apply && job.kind === 'image') return 'needs-draft';
    job.phase = 'duplicate';
    job.confirmed = job.bytes;
    job.taskId = json.task_id || 0;
    job.nid = json.nid || job.nid || 0;
    job.error = '';
    await persist(job, false);
    emit();
    if (job.taskId) pollTask(job);
    return true;
  }

  function sendChunk(job, index) {
    if (aborted.has(job.id) || job.phase === 'cancelled') {
      return Promise.reject(new Error('任务已取消'));
    }
    const size = job.chunkBytes;
    const start = index * size;
    const blob = job.file.slice(start, Math.min(job.file.size, start + size));
    return sha256Blob(blob).then((hash) => new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const startedAt = Date.now();
      const bucket = activeXhrs.get(job.id) || new Set();
      bucket.add(xhr);
      activeXhrs.set(job.id, bucket);
      xhr.open('POST', sessionUrl(job.sessionId, '/chunk/' + index));
      xhr.setRequestHeader('X-CSRF-Token', csrf);
      xhr.setRequestHeader('Content-Type', 'application/octet-stream');
      xhr.setRequestHeader('X-Chunk-Sha256', hash);
      xhr.upload.onprogress = (event) => {
        if (!event.lengthComputable || aborted.has(job.id)) return;
        job.inflight = event.loaded;
        const seconds = (Date.now() - startedAt) / 1000;
        job.speed = seconds > 0 ? event.loaded / seconds : 0;
        job.phase = 'uploading';
        job.updated = now();
        emit();
      };
      const clear = () => {
        const set = activeXhrs.get(job.id);
        if (set) {
          set.delete(xhr);
          if (!set.size) activeXhrs.delete(job.id);
        }
      };
      xhr.onload = () => {
        clear();
        job.inflight = 0;
        if (aborted.has(job.id)) {
          reject(new Error('任务已取消'));
          return;
        }
        let json = {};
        try { json = JSON.parse(xhr.responseText || '{}'); }
        catch (e) { json = {}; }
        if (xhr.status >= 200 && xhr.status < 300) {
          applyServer(job, json);
          persist(job, false).catch(() => {});
          emit();
          resolve(json);
          return;
        }
        const error = new Error(json.error || json.message || (xhr.status >= 500 ? '服务器暂时无法完成上传，已保留已收到的分块。' : ('HTTP ' + xhr.status)));
        error.status = xhr.status;
        reject(error);
      };
      xhr.onerror = () => {
        clear();
        reject(new Error(aborted.has(job.id) ? '任务已取消' : '网络中断，已保留服务器确认的分块。'));
      };
      xhr.onabort = () => {
        clear();
        reject(new Error('任务已取消'));
      };
      xhr.send(blob);
    }));
  }

  async function querySession(id) {
    const { response, json } = await api(sessionUrl(id), { method: 'GET' });
    if (!response.ok) throw new Error(json.error || json.message || '无法查询服务器上的上传进度。');
    return json;
  }

  async function uploadMissing(job) {
    const cfg = settings();
    let remote = await querySession(job.sessionId);
    applyServer(job, remote);
    await persist(job, false);
    emit();
    let missing = remote.missing_chunks || [];
    const limit = missing.length + 5;
    let guard = 0;
    while (missing.length && guard < limit) {
      guard += 1;
      const batch = missing.slice(0, Math.max(1, Math.min(3, cfg.concurrency || 2)));
      await Promise.all(batch.map(async (index) => {
        let attempt = 0;
        while (attempt < 3) {
          try {
            await sendChunk(job, index);
            return;
          }
          catch (error) {
            attempt += 1;
            let latest = null;
            try { latest = await querySession(job.sessionId); }
            catch (e) { latest = null; }
            if (latest && Array.isArray(latest.received_chunks) && latest.received_chunks.map(Number).includes(Number(index))) {
              applyServer(job, latest);
              await persist(job, false);
              emit();
              return;
            }
            if (attempt >= 3) throw error;
            await new Promise((done) => setTimeout(done, 500 * (2 ** attempt)));
          }
        }
      }));
      remote = await querySession(job.sessionId);
      applyServer(job, remote);
      await persist(job, false);
      emit();
      missing = remote.missing_chunks || [];
    }
    if (missing.length) throw new Error('仍有分块没有被服务器确认。已保留已收到的分块。');
  }

  async function finish(job, attempt) {
    job.phase = 'merging';
    emit();
    const { response, json } = await api(sessionUrl(job.sessionId, '/complete'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_sha256: job.sha256 || '' }),
    });
    if (response.status === 409 && (attempt || 0) < 20) {
      await new Promise((done) => setTimeout(done, 1500));
      return finish(job, (attempt || 0) + 1);
    }
    applyServer(job, json);
    if (!response.ok) {
      job.phase = 'failed';
      job.error = json.error || json.message || '完成上传失败';
      await persist(job, true);
      emit();
      return job;
    }
    job.phase = json.phase || (json.mid ? 'completed' : 'queued');
    job.taskId = json.task_id || job.taskId;
    job.mid = json.mid || job.mid || 0;
    if (json.original_bytes) job.originalBytes = Number(json.original_bytes);
    if (json.optimized_bytes) job.optimizedBytes = Number(json.optimized_bytes);
    job.error = '';
    // Keep blob until the caller confirms final content creation for quick uploads.
    if (job.kind !== 'quick') {
      job.file = null;
      job.blob = null;
    }
    await persist(job, job.kind === 'quick');
    emit();
    if (job.taskId && job.kind !== 'quick') {
      pollTask(job);
      maybeCloseBatch(job).catch(() => {});
    }
    return job;
  }

  async function maybeCloseBatch(job) {
    const cfg = settings();
    if (!cfg.closeUrl || !job.taskId || job.kind === 'archive' || job.kind === 'quick') return;
    // Close when every related image job already reached the server or failed locally.
    const siblings = list().filter((row) => row.kind === 'image' && row.nid && job.nid && row.nid === job.nid);
    const group = siblings.length ? siblings : [job];
    if (group.some((row) => !row.taskId && !['failed', 'cancelled', 'duplicate'].includes(row.phase))) return;
    await api(cfg.closeUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ task_id: job.taskId }),
    });
  }

  async function ensureDraft(job) {
    if (job.nid > 0) return job.nid;
    const cfg = settings();
    if (!cfg.draftUrl) return 0;
    const title = job.title || '';
    if (!title) throw new Error('请先填写标题');
    const { response, json } = await api(cfg.draftUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title }),
    });
    if (!response.ok || !json.nid) throw new Error(json.error || json.message || '草稿没有保存');
    job.nid = Number(json.nid);
    return job.nid;
  }

  async function runJob(job) {
    const cfg = settings();
    if (aborted.has(job.id) || job.phase === 'cancelled' || job.phase === 'needs_title') return;
    job.error = '';
    job.updated = now();
    emit();
    try {
      if (!csrf) await sessionToken();
      if (!job.file) throw new Error('本地没有原文件。请重新选择后继续。');
      const needsTitle = !!cfg.requireTitle || (job.entry === 'admin' && job.kind !== 'quick' && !(job.nid > 0) && !!cfg.draftUrl);
      if (needsTitle && !(job.title || '').trim()) {
        job.phase = 'needs_title';
        job.error = '请先填写作品标题，然后再上传。已选择的文件已保留，可取消或填写标题后继续。';
        await persist(job, true);
        emit();
        return;
      }
      if (!job.sha256) {
        job.phase = 'hashing';
        emit();
        job.head = await sha256Blob(job.file.slice(0, Math.min(job.file.size, cfg.headBytes || 65536)));
        try { job.sha256 = await sha256Blob(job.file); }
        catch (e) { job.sha256 = ''; }
        await persist(job, true);
        emit();
      }
      const known = await probe(job, job.kind !== 'image');
      if (known === true) return;
      if ((!job.nid || job.nid < 1) && !job.sessionId && job.kind === 'image') await ensureDraft(job);
      if (known === 'needs-draft' && await probe(job, true)) return;
      if (!job.sessionId) {
        const { response, json } = await api(cfg.sessionUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            filename: job.name,
            size: job.bytes,
            kind: job.kind === 'quick' ? 'quick' : job.kind,
            mime: job.mime || (job.file && job.file.type) || '',
            head_sha256: job.head,
            file_sha256: job.sha256,
            client_upload_id: job.clientId,
            nid: job.nid || 0,
            title: job.title || '',
          }),
        });
        if (!response.ok || !json.session_id) throw new Error(json.error || json.message || '创建上传会话失败');
        applyServer(job, json);
        await persist(job, true);
        emit();
      }
      job.phase = 'uploading';
      await uploadMissing(job);
      await finish(job);
    }
    catch (error) {
      job.phase = job.sessionId ? 'interrupted' : 'failed';
      job.error = (error && error.message) || '上传中断。服务器已确认的分块已保留。';
      job.retries = (job.retries || 0) + 1;
      if (job.sessionId) {
        try {
          applyServer(job, await querySession(job.sessionId));
        }
        catch (e) { /* keep local state */ }
      }
      await persist(job, true).catch(() => {});
      emit();
    }
  }

  async function pollTask(job) {
    const cfg = settings();
    if (!job.taskId || !cfg.progressBase || job.phase === 'duplicate') return;
    const startedAt = Date.now();
    while (Date.now() - startedAt < 6 * 60 * 60 * 1000) {
      await new Promise((done) => setTimeout(done, 2500));
      if (!jobs.has(job.id)) return;
      try {
        const response = await fetch(cfg.progressBase + job.taskId + '/progress', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;
        const json = await response.json();
        job.phase = json.label || json.status || job.phase;
        job.error = json.error || '';
        job.updated = now();
        await persist(job, false);
        emit();
        if (json.done) {
          if (!DONE.includes(job.phase)) job.phase = 'completed';
          await persist(job, false);
          emit();
          return;
        }
      }
      catch (e) {
        return;
      }
    }
  }

  async function pump() {
    if (!leader || running) return;
    running = true;
    try {
      const queue = list().filter((job) => job.file && ['pending', 'retrying', 'interrupted', 'paused', 'uploading'].includes(job.phase) && !aborted.has(job.id));
      for (const job of queue) {
        if (!leader) break;
        if (!job.file || aborted.has(job.id) || job.phase === 'needs_title' || job.phase === 'cancelled') continue;
        if (['completed', 'duplicate', 'cancelled', 'failed'].includes(job.phase) && job.phase !== 'failed') continue;
        await runJob(job);
      }
    }
    finally {
      running = false;
    }
  }

  function claimLeadership() {
    leader = true;
    try { localStorage.setItem(LEADER_KEY, JSON.stringify({ tabId, at: now() })); }
    catch (e) { /* ignore */ }
    if (channel) {
      try { channel.postMessage({ type: 'leader', tabId }); }
      catch (e) { /* ignore */ }
    }
    pump();
  }

  function maybeLead() {
    let current = null;
    try { current = JSON.parse(localStorage.getItem(LEADER_KEY) || 'null'); }
    catch (e) { current = null; }
    if (!current || !current.tabId || (now() - (current.at || 0) > 8000) || current.tabId === tabId) {
      claimLeadership();
      return;
    }
    leader = current.tabId === tabId;
  }

  async function syncSessions() {
    const cfg = settings();
    if (!cfg.sessionsUrl) return;
    try {
      const { response, json } = await api(cfg.sessionsUrl, { method: 'GET' });
      if (!response.ok || !Array.isArray(json.sessions)) return;
      json.sessions.forEach((session) => {
        if (isTombstoned(session.session_id, session.session_id, session.client_upload_id)) return;
        const existing = [...jobs.values()].find((job) => job.sessionId === session.session_id || (session.client_upload_id && job.clientId === session.client_upload_id));
        if (existing) {
          if (aborted.has(existing.id) || existing.phase === 'cancelled') return;
          const hadFile = !!(existing.file || existing.blob);
          applyServer(existing, session);
          if (!hadFile && !existing.file && NEED_FILE.includes(existing.phase) && !existing.taskId && !existing.mid) {
            existing.phase = 'interrupted';
            existing.error = existing.error || '请重新选择原来的文件以继续上传。';
            existing.canAutoResume = false;
          }
          else if (hadFile && NEED_FILE.includes(existing.phase) && !existing.taskId) {
            existing.canAutoResume = true;
            if (['interrupted', 'paused', 'uploading'].includes(existing.phase)) {
              existing.phase = 'retrying';
              existing.error = '';
            }
          }
          else if (existing.taskId && NEED_FILE.includes(existing.phase)) {
            existing.phase = session.phase || session.status || 'queued';
            existing.error = '';
          }
          persist(existing, hadFile && !existing.taskId).catch(() => {});
          return;
        }
        const job = {
          id: session.client_upload_id || session.session_id,
          clientId: session.client_upload_id || session.session_id,
          sessionId: session.session_id,
          name: session.filename,
          bytes: Number(session.total_bytes || 0),
          confirmed: Number(session.received_bytes || 0),
          kind: session.kind === 'quick' ? 'quick' : session.kind,
          entry: session.kind === 'quick' ? 'quick' : 'admin',
          head: session.head_sha256,
          sha256: session.file_sha256,
          phase: ['failed', 'open', 'uploading', 'merging'].includes(session.status) ? 'interrupted' : session.status,
          error: session.error || '请重新选择原来的文件以继续上传。',
          taskId: session.task_id || 0,
          nid: session.nid || 0,
          chunkBytes: Number(session.chunk_bytes || settings().chunkBytes),
          inflight: 0,
          created: now(),
          updated: now(),
          canAutoResume: false,
          file: null,
          blob: null,
        };
        jobs.set(job.id, job);
        persist(job, false).catch(() => {});
      });
      emit();
    }
    catch (e) { /* sync is best-effort */ }
  }

  async function enqueue(files, options) {
    const cfg = Object.assign({}, settings(), options || {});
    const kind = cfg.kind || 'image';
    const selected = [...files].filter(Boolean);
    const created = [];
    const title = String(cfg.title || '').trim();
    const needsTitle = cfg.requireTitle !== false
      && cfg.entry !== 'quick'
      && kind !== 'quick'
      && !(Number(cfg.nid) > 0)
      && !!cfg.draftUrl
      && title === '';
    await sessionToken();
    for (const file of selected) {
      const reason = rejectLocal(file, kind, cfg);
      const id = clientId();
      const waitingTitle = !reason && needsTitle;
      const job = {
        id,
        clientId: id,
        file,
        blob: file,
        name: file.name,
        bytes: file.size,
        mime: file.type || '',
        confirmed: 0,
        kind,
        entry: cfg.entry || (kind === 'quick' ? 'quick' : 'admin'),
        phase: reason ? 'failed' : (waitingTitle ? 'needs_title' : 'pending'),
        error: reason || (waitingTitle ? '请先填写作品标题。文件已保留，填写标题后可继续，也可取消。' : ''),
        inflight: 0,
        preview: (kind === 'image' || kind === 'quick') && !reason ? URL.createObjectURL(file) : '',
        nid: cfg.nid || 0,
        title,
        created: now(),
        updated: now(),
        retries: 0,
        canAutoResume: true,
      };
      jobs.set(id, job);
      created.push(job);
      try {
        await persist(job, true);
      }
      catch (error) {
        job.error = '本地持久化失败：' + ((error && error.message) || '未知错误') + '。页面刷新后可能无法自动恢复。';
        job.persistWarned = true;
      }
      emit();
    }
    if (!needsTitle) {
      if (leader) pump();
      else maybeLead();
    }
    return created;
  }

  async function applyTitle(title) {
    const value = String(title || '').trim();
    if (!value) return 0;
    let changed = 0;
    for (const job of list()) {
      if (job.phase !== 'needs_title' || aborted.has(job.id)) continue;
      job.title = value;
      job.phase = 'pending';
      job.error = '';
      await persist(job, true);
      changed += 1;
    }
    if (changed) {
      emit();
      if (leader) pump();
      else claimLeadership();
    }
    return changed;
  }

  async function retry(id) {
    const job = jobs.get(id);
    if (!job) return;
    if (DONE.includes(job.phase) && job.phase !== 'failed') return;
    if (!job.file) {
      job.phase = 'interrupted';
      job.error = '请重新选择原来的文件。服务器会核对文件大小和 SHA-256。';
      emit();
      return;
    }
    job.phase = 'retrying';
    job.error = '';
    job.retries = (job.retries || 0) + 1;
    await persist(job, true);
    emit();
    if (leader) pump();
    else claimLeadership();
  }

  async function retryFailed(ids) {
    const targets = ids && ids.length ? ids : list().filter((job) => job.phase === 'failed' || job.phase === 'interrupted').map((job) => job.id);
    for (const id of targets) await retry(id);
  }

  async function attachFile(id, file) {
    const job = jobs.get(id);
    if (!job || !file) return false;
    const sha = await sha256Blob(file);
    const head = await sha256Blob(file.slice(0, Math.min(file.size, settings().headBytes || 65536)));
    if (file.size !== Number(job.bytes) || (job.sha256 && sha !== job.sha256) || (job.head && head !== job.head)) {
      job.error = '这个文件和服务器上的未完成上传对不上。请重新选择原来的文件。';
      emit();
      return false;
    }
    job.file = file;
    job.blob = file;
    job.sha256 = sha;
    job.head = head;
    job.canAutoResume = true;
    job.phase = 'retrying';
    job.error = '';
    job.preview = (job.kind === 'image' || job.kind === 'quick') ? URL.createObjectURL(file) : '';
    await persist(job, true);
    emit();
    if (leader) pump();
    else claimLeadership();
    return true;
  }

  async function cancel(id) {
    const job = jobs.get(id);
    if (!job) return { ok: false, error: '任务不存在' };
    if (job.taskId && ['queued', 'processing', 'extracting', 'importing', 'review', 'completed', 'success', 'duplicate'].includes(job.phase)) {
      return { ok: false, error: '任务已交给服务器处理，不能通过上传面板取消导入。' };
    }
    aborted.add(job.id);
    rememberTombstone(job.id);
    if (job.sessionId) rememberTombstone(job.sessionId);
    if (job.clientId) rememberTombstone(job.clientId);
    const xhrs = activeXhrs.get(job.id);
    if (xhrs) {
      xhrs.forEach((xhr) => {
        try { xhr.abort(); } catch (e) { /* ignore */ }
      });
      activeXhrs.delete(job.id);
    }
    job.phase = 'cancelled';
    job.error = '';
    job.inflight = 0;
    if (job.preview) {
      try { URL.revokeObjectURL(job.preview); } catch (e) { /* ignore */ }
    }
    job.preview = '';
    job.file = null;
    job.blob = null;
    let serverOk = true;
    let serverError = '';
    if (job.sessionId) {
      try {
        const { response, json } = await api(sessionUrl(job.sessionId, '/cancel'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: '{}',
        });
        if (!response.ok) {
          serverOk = false;
          serverError = json.error || json.message || '服务器会话没有取消成功。';
          job.error = serverError + ' 本地任务已停止，不会继续上传。';
        }
      }
      catch (error) {
        serverOk = false;
        serverError = (error && error.message) || '服务器会话取消失败。';
        job.error = serverError + ' 本地任务已停止，不会继续上传。';
      }
    }
    await removePersisted(job.id);
    jobs.delete(job.id);
    if (channel) {
      try { channel.postMessage({ type: 'cancelled', tabId, id: job.id, sessionId: job.sessionId || '', clientId: job.clientId || '' }); }
      catch (e) { /* ignore */ }
    }
    emit();
    return { ok: true, serverOk, error: serverError };
  }

  function canCancel(job) {
    if (!job || aborted.has(job.id) || job.phase === 'cancelled') return false;
    if (job.taskId && ['queued', 'processing', 'extracting', 'importing', 'review', 'completed', 'success', 'duplicate'].includes(job.phase)) {
      return false;
    }
    return CANCELABLE.includes(job.phase) || !job.sessionId;
  }

  function subscribe(fn) {
    listeners.add(fn);
    fn(list());
    return () => listeners.delete(fn);
  }

  async function start() {
    if (started) return;
    started = true;
    try {
      await loadPersisted();
      persistOk = true;
    }
    catch (error) {
      persistOk = false;
      persistError = (error && error.message) || '本地上传数据库不可用';
    }
    try {
      channel = 'BroadcastChannel' in window ? new BroadcastChannel(CHANNEL) : null;
      if (channel) {
        channel.onmessage = (event) => {
          const data = event.data || {};
          if (data.type === 'leader' && data.tabId !== tabId) {
            leader = false;
          }
          if (data.type === 'need-leader') maybeLead();
          if (data.type === 'cancelled' && data.tabId !== tabId) {
            rememberTombstone(data.id);
            if (data.sessionId) rememberTombstone(data.sessionId);
            if (data.clientId) rememberTombstone(data.clientId);
            const local = jobs.get(data.id);
            if (local) {
              aborted.add(data.id);
              const xhrs = activeXhrs.get(data.id);
              if (xhrs) {
                xhrs.forEach((xhr) => { try { xhr.abort(); } catch (e) { /* ignore */ } });
                activeXhrs.delete(data.id);
              }
              jobs.delete(data.id);
              removePersisted(data.id).catch(() => {});
              listeners.forEach((fn) => {
                try { fn(list()); } catch (e) { /* ignore */ }
              });
            }
          }
          if (data.type === 'state' && data.tabId !== tabId && Array.isArray(data.jobs)) {
            // Followers refresh metadata from the leader without taking over uploads.
            data.jobs.forEach((remote) => {
              if (isTombstoned(remote.id, remote.sessionId, remote.clientId)) return;
              const local = jobs.get(remote.id);
              if (!local) {
                jobs.set(remote.id, Object.assign({}, remote, { file: null, blob: null, inflight: 0 }));
                return;
              }
              if (!leader) {
                Object.assign(local, remote, { file: local.file, blob: local.blob, preview: local.preview });
              }
            });
            listeners.forEach((fn) => {
              try { fn(list()); } catch (e) { /* ignore */ }
            });
          }
        };
      }
    }
    catch (e) {
      channel = null;
    }
    maybeLead();
    leaderTimer = window.setInterval(() => {
      if (leader) {
        try { localStorage.setItem(LEADER_KEY, JSON.stringify({ tabId, at: now() })); }
        catch (e) { /* ignore */ }
      }
      else maybeLead();
    }, 3000);
    window.addEventListener('storage', (event) => {
      if (event.key === LEADER_KEY) maybeLead();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        syncSessions().then(() => { if (leader) pump(); });
      }
    });
    await syncSessions();
    if (leader) pump();
    emit();
  }

  Drupal.xedcUpload = {
    start,
    enqueue,
    applyTitle,
    retry,
    retryFailed,
    attachFile,
    cancel,
    canCancel,
    subscribe,
    list,
    counts,
    statusText,
    formatBytes,
    syncSessions,
    persistOk: () => persistOk,
    persistError: () => persistError,
    isLeader: () => leader,
    settings,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      if (drupalSettings && (drupalSettings.xedcUpload || drupalSettings.xedcAsset)) start();
    });
  }
  else if (drupalSettings && (drupalSettings.xedcUpload || drupalSettings.xedcAsset)) {
    start();
  }
})(Drupal, drupalSettings);
