(function (Drupal, once, drupalSettings) {
  const settings = drupalSettings.xedcAsset || {};
  const storeKey = 'xedcUploadSessions';
  const headBytes = Number(settings.headBytes) || 65536;
  const concurrency = Math.max(1, Math.min(3, Number(settings.concurrency) || 2));

  function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) return '0 B';
    const units = ['B', 'KiB', 'MiB', 'GiB'];
    let value = bytes;
    let index = 0;
    while (value >= 1024 && index < units.length - 1) {
      value /= 1024;
      index += 1;
    }
    return (index === 0 ? value.toFixed(0) : value.toFixed(2)) + ' ' + units[index];
  }

  function hex(buffer) {
    return [...new Uint8Array(buffer)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  async function sha256Blob(blob) {
    if (!window.crypto || !crypto.subtle) {
      throw new Error('浏览器不支持 SHA256，无法校验分块。');
    }
    return hex(await crypto.subtle.digest('SHA-256', await blob.arrayBuffer()));
  }

  function readStore() {
    try {
      const rows = JSON.parse(localStorage.getItem(storeKey) || '[]');
      return Array.isArray(rows) ? rows : [];
    }
    catch (e) {
      return [];
    }
  }

  function rememberLocal(row) {
    const rows = readStore().filter((item) => item.sessionId !== row.sessionId);
    rows.push(row);
    localStorage.setItem(storeKey, JSON.stringify(rows.slice(-40)));
  }

  Drupal.behaviors.xedcAssetUpload = {
    attach(context) {
      once('xedc-asset-upload', '[data-xedc-asset-upload]', context).forEach((root) => {
        const list = root.querySelector('[data-xedc-list]');
        const note = root.querySelector('[data-xedc-note]');
        const resumeBox = root.querySelector('[data-xedc-resume]');
        const titleInput = root.querySelector('[data-xedc-title]');
        const archiveInput = root.querySelector('[data-xedc-archive]');
        const imageInput = root.querySelector('[data-xedc-images]');
        let nid = Number(settings.nid || root.getAttribute('data-nid') || 0);
        let editUrl = '';
        let csrf = '';
        const jobs = [];
        let serverSessions = [];

        function say(text) {
          if (note) note.textContent = text;
        }

        function titleValue() {
          if (titleInput && titleInput.value.trim()) return titleInput.value.trim();
          const field = document.querySelector(settings.titleSelector || '#edit-title-0-value');
          return field ? field.value.trim() : '';
        }

        async function sessionToken() {
          if (csrf) return csrf;
          const controller = new AbortController();
          const timer = setTimeout(() => controller.abort(), 15000);
          try {
            const response = await fetch('/session/token', { credentials: 'same-origin', signal: controller.signal });
            if (!response.ok) throw new Error('token');
            csrf = (await response.text()).trim();
            if (!csrf) throw new Error('token');
            return csrf;
          }
          catch (e) {
            csrf = '';
            throw new Error('无法获取上传凭证，请刷新页面后重试。');
          }
          finally {
            clearTimeout(timer);
          }
        }

        async function api(url, options) {
          const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
          if (options.method && options.method !== 'GET') {
            headers['X-CSRF-Token'] = await sessionToken();
          }
          const response = await fetch(url, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers,
            body: options.body,
          });
          let json = {};
          try { json = await response.json(); }
          catch (e) { json = {}; }
          return { response, json };
        }

        function sessionUrl(id, suffix) {
          return settings.sessionBase + id + (suffix || '');
        }

        function rowView(job) {
          if (!list) return;
          const key = job.token || job.sessionId;
          let line = list.querySelector('[data-token="' + key + '"]');
          if (!line) {
            line = document.createElement('li');
            line.dataset.token = key;
            list.appendChild(line);
          }
          line.replaceChildren();
          const confirmed = Number.isFinite(job.confirmed) ? job.confirmed : 0;
          const total = Number.isFinite(job.bytes) ? job.bytes : 0;
          const title = document.createElement('div');
          title.textContent = job.name + ' · ' + formatBytes(total) + ' · ' + (job.phase || '');
          line.appendChild(title);
          const meta = document.createElement('div');
          meta.className = 'xedc-upload__meta';
          meta.textContent = '服务器已确认 ' + formatBytes(confirmed) + ' / ' + formatBytes(total)
            + (job.speed ? ' · ' + formatBytes(job.speed) + '/s' : '');
          line.appendChild(meta);
          const bar = document.createElement('div');
          bar.className = 'xedc-upload__bar';
          const fill = document.createElement('span');
          const shown = total > 0 ? Math.min(100, Math.round(((confirmed + (job.inflight || 0)) / total) * 100)) : 0;
          fill.style.width = shown + '%';
          bar.appendChild(fill);
          line.appendChild(bar);
          if (job.error) {
            const error = document.createElement('div');
            error.textContent = job.error;
            line.appendChild(error);
          }
          if (job.sessionId && (job.phase === 'failed' || job.phase === 'paused')) {
            const again = document.createElement('button');
            again.type = 'button';
            again.textContent = '继续上传';
            again.addEventListener('click', () => resumeJob(job));
            line.appendChild(again);
          }
          if (job.sessionId && job.phase !== 'completed' && job.phase !== 'cancelled') {
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.textContent = '取消';
            cancel.addEventListener('click', () => cancelJob(job));
            line.appendChild(cancel);
          }
        }

        async function ensureDraft() {
          if (nid > 0) return nid;
          const title = titleValue();
          if (!title) throw new Error('请先填写标题');
          const { response, json } = await api(settings.draftUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title }),
          });
          if (!response.ok || !json.nid) throw new Error(json.error || json.message || '草稿没有保存');
          nid = Number(json.nid);
          editUrl = json.edit || '';
          root.setAttribute('data-nid', String(nid));
          say('草稿 #' + nid + ' 已保存。已确认的分块会保留在服务器上。');
          return nid;
        }

        async function headOf(file) {
          return sha256Blob(file.slice(0, Math.min(file.size, headBytes)));
        }

        function extensionOf(name) {
          const parts = String(name).toLowerCase().split('.');
          return parts.length > 1 ? parts.pop() : '';
        }

        function rejectLocal(file, kind) {
          const extension = extensionOf(file.name);
          if (!file.size) return '空文件不能上传。';
          if (kind === 'archive' && !['zip', '7z'].includes(extension)) return '只接受 ZIP 和 7z，这个文件没有上传。';
          if (kind === 'archive' && settings.maxArchive && file.size > settings.maxArchive) return '超过压缩包大小限制，没有上传。';
          if (kind === 'image' && !['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) return '只接受 JPEG、PNG、GIF 和 WebP，这个文件没有上传。';
          if (kind === 'image' && settings.maxImage && file.size > settings.maxImage) return '超过单张图片大小限制，没有上传。';
          return '';
        }

        async function refreshSessions() {
          if (!settings.sessionsUrl) return [];
          const { response, json } = await api(settings.sessionsUrl, { method: 'GET' });
          serverSessions = response.ok && Array.isArray(json.sessions) ? json.sessions : [];
          drawResume();
          return serverSessions;
        }

        function drawResume() {
          if (!resumeBox) return;
          resumeBox.replaceChildren();
          const open = serverSessions.filter((session) => ['open', 'uploading', 'failed', 'merging'].includes(session.status));
          if (!open.length) return;
          const heading = document.createElement('h3');
          heading.textContent = '未完成的上传';
          resumeBox.appendChild(heading);
          open.forEach((session) => {
            const article = document.createElement('article');
            const text = document.createElement('div');
            text.textContent = session.filename + ' · 服务器已确认 ' + formatBytes(session.received_bytes) + ' / ' + formatBytes(session.total_bytes);
            article.appendChild(text);
            const picker = document.createElement('input');
            picker.type = 'file';
            picker.addEventListener('change', () => {
              const file = picker.files && picker.files[0];
              picker.value = '';
              if (file) matchAndResume(session, file);
            });
            article.appendChild(picker);
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.textContent = '取消';
            cancel.addEventListener('click', () => cancelSession(session.session_id));
            article.appendChild(cancel);
            resumeBox.appendChild(article);
          });
        }

        async function querySession(id) {
          const { response, json } = await api(sessionUrl(id), { method: 'GET' });
          if (response.status === 410) {
            throw new Error(json.error || '上传会话已过期，请重新选择文件。');
          }
          if (!response.ok) throw new Error(json.error || json.message || '无法查询服务器上的上传进度。');
          return json;
        }

        function applyServer(job, json) {
          job.sessionId = json.session_id || job.sessionId;
          job.confirmed = Number(json.received_bytes || 0);
          job.bytes = Number(json.total_bytes || job.bytes || 0);
          job.taskId = json.task_id || job.taskId || 0;
          job.nid = json.nid || job.nid || nid;
          job.chunkBytes = Number(json.chunk_bytes || job.chunkBytes || settings.chunkBytes || 5242880);
          job.missing = Array.isArray(json.missing_chunks) ? json.missing_chunks.map(Number) : [];
          job.status = json.status || job.status;
          if (json.error) job.error = json.error;
          rememberLocal({
            sessionId: job.sessionId,
            name: job.name,
            size: job.bytes,
            head: job.head,
            kind: job.kind,
            nid: job.nid || nid,
          });
          rowView(job);
          return json;
        }

        async function createSession(file, kind, head) {
          const body = {
            filename: file.name,
            size: file.size,
            kind,
            mime: file.type || '',
            head_sha256: head,
            nid,
            title: titleValue(),
          };
          const { response, json } = await api(settings.sessionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          });
          if (!response.ok || !json.session_id) {
            throw new Error(json.error || json.message || ('创建上传会话失败 HTTP ' + response.status));
          }
          return json;
        }

        function sendChunk(job, index) {
          const size = job.chunkBytes;
          const start = index * size;
          const blob = job.file.slice(start, Math.min(job.file.size, start + size));
          return sha256Blob(blob).then((hash) => new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const started = Date.now();
            xhr.open('POST', sessionUrl(job.sessionId, '/chunk/' + index));
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.setRequestHeader('X-Chunk-Sha256', hash);
            xhr.upload.onprogress = (event) => {
              if (!event.lengthComputable) return;
              job.inflight = event.loaded;
              const seconds = (Date.now() - started) / 1000;
              job.speed = seconds > 0 ? event.loaded / seconds : 0;
              job.phase = 'uploading';
              rowView(job);
            };
            xhr.onload = () => {
              job.inflight = 0;
              let json = {};
              try { json = JSON.parse(xhr.responseText || '{}'); }
              catch (e) { json = {}; }
              if (xhr.status >= 200 && xhr.status < 300) {
                applyServer(job, json);
                resolve(json);
                return;
              }
              const error = new Error(json.error || json.message || ('HTTP ' + xhr.status));
              error.status = xhr.status;
              error.json = json;
              reject(error);
            };
            xhr.onerror = () => {
              job.inflight = 0;
              reject(new Error('网络中断'));
            };
            xhr.send(blob);
          }));
        }

        async function uploadMissing(job) {
          let remote = await querySession(job.sessionId);
          applyServer(job, remote);
          let missing = remote.missing_chunks || [];
          let guard = 0;
          const limit = missing.length + 5;
          while (missing.length && guard < limit) {
            guard += 1;
            const batch = missing.slice(0, concurrency);
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
                    return;
                  }
                  if (attempt >= 3) throw error;
                  await new Promise((done) => setTimeout(done, 500 * (2 ** attempt)));
                }
              }
            }));
            remote = await querySession(job.sessionId);
            applyServer(job, remote);
            missing = remote.missing_chunks || [];
          }
          if (missing.length) {
            throw new Error('仍有分块没有被服务器确认。');
          }
        }

        async function finish(job, attempt) {
          const { response, json } = await api(sessionUrl(job.sessionId, '/complete'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
          });
          if (response.status === 409 && json.status === 'merging' && (attempt || 0) < 20) {
            await new Promise((done) => setTimeout(done, 1500));
            return finish(job, (attempt || 0) + 1);
          }
          applyServer(job, json);
          if (!response.ok || !json.task_id) {
            job.phase = 'failed';
            job.error = json.error || json.message || ('完成上传失败 HTTP ' + response.status);
            rowView(job);
            return job;
          }
          job.phase = json.phase || 'queued';
          job.error = '';
          job.taskId = json.task_id;
          nid = json.nid || nid;
          rowView(job);
          if (job.kind === 'archive') poll(job);
          return job;
        }

        async function run(job) {
          job.phase = 'uploading';
          job.error = '';
          rowView(job);
          try {
            if (!csrf) await sessionToken();
            await uploadMissing(job);
            await finish(job);
          }
          catch (error) {
            job.phase = 'paused';
            job.error = (error && error.message) || '上传中断。服务器已确认的分块已保留。';
            try {
              const latest = await querySession(job.sessionId);
              applyServer(job, latest);
            }
            catch (e) {
              rowView(job);
            }
            say(job.error);
          }
        }

        async function resumeJob(job) {
          if (!job.file) {
            say('请重新选择原来的文件。服务器会用文件名、大小和开头校验值确认是不是同一个文件。');
            return;
          }
          await run(job);
          await maybeClose();
        }

        async function matchAndResume(session, file) {
          const head = await headOf(file);
          if (file.name !== session.filename || file.size !== Number(session.total_bytes) || head !== session.head_sha256) {
            say('这个文件和服务器上的未完成上传对不上。请重新选择原来的文件。');
            return;
          }
          const job = {
            sessionId: session.session_id,
            token: session.session_id,
            file,
            name: file.name,
            bytes: file.size,
            confirmed: Number(session.received_bytes || 0),
            kind: session.kind,
            head,
            phase: 'uploading',
            error: '',
            chunkBytes: Number(session.chunk_bytes || settings.chunkBytes || 5242880),
            nid: session.nid || nid,
            inflight: 0,
          };
          if (session.nid) nid = Number(session.nid);
          jobs.push(job);
          rowView(job);
          say('正在从服务器缺少的分块继续。');
          await run(job);
          await maybeClose();
          if (job.phase === 'failed' && job.error) say(job.error);
          else if (job.kind !== 'image') say('已按服务器确认的进度续传。');
          await refreshSessions();
        }

        async function cancelSession(id) {
          const { response, json } = await api(sessionUrl(id, '/cancel'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
          });
          say(response.ok ? '上传已取消。' : (json.error || '没有取消。'));
          await refreshSessions();
        }

        async function cancelJob(job) {
          if (!job.sessionId) return;
          job.phase = 'cancelled';
          await cancelSession(job.sessionId);
          rowView(job);
        }

        async function poll(job) {
          if (!job.taskId) return;
          const started = Date.now();
          while (Date.now() - started < 6 * 60 * 60 * 1000) {
            await new Promise((done) => setTimeout(done, 2000));
            try {
              const response = await fetch(settings.progressBase + job.taskId + '/progress', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
              });
              if (!response.ok) return;
              const json = await response.json();
              job.phase = json.label || json.status;
              job.error = json.error || '';
              rowView(job);
              if (json.done) return;
            }
            catch (e) {
              return;
            }
          }
        }

        async function maybeClose() {
          const pending = jobs.some((job) => job.kind === 'image' && !['completed', 'queued', 'cancelled', 'failed'].includes(job.phase) && !job.taskId);
          const taskId = jobs.map((job) => job.taskId).find((id) => Number(id) > 0);
          if (pending || !taskId || !settings.closeUrl) return;
          const imageDone = jobs.some((job) => job.kind === 'image' && job.taskId);
          if (!imageDone) return;
          await api(settings.closeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: taskId }),
          });
          say('图片已交给后台。未完成的分块仍可在重新打开页面后继续。');
          poll({ token: 'batch-' + taskId, name: '图片批次', taskId, kind: 'image', phase: 'queued', bytes: 0, confirmed: 0, error: '' });
        }

        async function take(files, kind) {
          const selected = [...files].filter(Boolean);
          if (!selected.length) return;
          const createdHere = nid < 1;
          try {
            await sessionToken();
            if (kind === 'image' || nid < 1) await ensureDraft();
            await refreshSessions();
          }
          catch (error) {
            say(error.message || String(error));
            return;
          }
          for (const file of selected) {
            const reason = rejectLocal(file, kind);
            const job = {
              token: 'local-' + file.name + '-' + file.size + '-' + file.lastModified,
              file,
              name: file.name,
              bytes: file.size,
              confirmed: 0,
              kind,
              phase: reason ? 'failed' : 'pending',
              error: reason,
              inflight: 0,
            };
            jobs.push(job);
            rowView(job);
            if (reason) continue;
            try {
              const head = await headOf(file);
              job.head = head;
              const existing = serverSessions.find((session) => session.kind === kind && session.filename === file.name && Number(session.total_bytes) === file.size && session.head_sha256 === head && ['open', 'uploading', 'failed'].includes(session.status));
              const created = existing || await createSession(file, kind, head);
              applyServer(job, created);
              job.sessionId = created.session_id;
              await run(job);
            }
            catch (error) {
              job.phase = 'failed';
              job.error = error.message || String(error);
              rowView(job);
            }
          }
          await maybeClose();
          await refreshSessions();
          if (createdHere && editUrl && settings.titleSelector === '#edit-title-0-value') {
            window.location.href = editUrl;
          }
        }

        if (archiveInput) {
          archiveInput.addEventListener('change', () => {
            const files = [...archiveInput.files];
            archiveInput.value = '';
            take(files, 'archive');
          });
        }
        if (imageInput) {
          imageInput.addEventListener('change', () => {
            const files = [...imageInput.files];
            imageInput.value = '';
            take(files, 'image');
          });
        }

        refreshSessions().catch(() => {
          say('暂时无法查询未完成的上传。');
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
