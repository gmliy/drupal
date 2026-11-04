(function (Drupal, once) {
  Drupal.behaviors.xq = {
    attach(ctx) {
      const root = once('xq', '#xq-app', ctx)[0];
      if (!root) return;
      root.innerHTML = `
<input id="xq-title" placeholder="标题" maxlength="255" autocomplete="off">
<div class="xq-field"><input id="xq-model" placeholder="人物名字（选已有或直接输入新的）" autocomplete="off"></div>
<div id="xq-tagschips" class="xq-chips"></div>
<div class="xq-field"><input id="xq-tags" placeholder="标签：选已有或输入新的，回车添加" autocomplete="off"></div>
<div id="xq-catschips" class="xq-chips"></div>
<div class="xq-field"><input id="xq-cats" placeholder="分类栏目：选已有或输入新的，回车添加" autocomplete="off"></div>
<label class="xq-btn">选择照片<input id="xq-files" type="file" accept="image/*" multiple hidden></label>
<div id="xq-bar">已选 0 张</div>
<div id="xq-grid"></div>
<label><input id="xq-pub" type="checkbox"> 直接发布（不勾选则存草稿）</label>
<button id="xq-go" type="button" class="xq-btn xq-primary">上传并创建草稿</button>
<div id="xq-msg"></div>`;
      const $ = (s) => root.querySelector(s);
      const msg = (t) => { $('#xq-msg').textContent = t; };
      let items = [];
      let cover = null;
      let token = '';
      const sets = { tags: [], cats: [] };

      function chips(k) {
        const box = $('#xq-' + k + 'chips');
        box.replaceChildren();
        sets[k].forEach((n, i) => {
          const sp = document.createElement('span');
          sp.textContent = n + ' ×';
          sp.onclick = () => { sets[k].splice(i, 1); chips(k); };
          box.appendChild(sp);
        });
      }
      function addChip(k) {
        const inp = $('#xq-' + k);
        inp.value.split(/[,，]/).map((x) => x.trim()).filter(Boolean).forEach((n) => {
          if (!sets[k].includes(n)) sets[k].push(n);
        });
        inp.value = '';
        chips(k);
      }
      ['tags', 'cats'].forEach((k) => {
        const inp = $('#xq-' + k);
        inp.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') { e.preventDefault(); addChip(k); }
        });
      });

      function suggest(input, field, onPick) {
        const box = document.createElement('div');
        box.className = 'xq-suggest';
        box.hidden = true;
        input.insertAdjacentElement('afterend', box);
        let timer;
        let ctrl;
        const run = async () => {
          if (ctrl) ctrl.abort();
          ctrl = new AbortController();
          try {
            const r = await fetch('/admin/quick-gallery/suggest?field=' + field + '&q=' + encodeURIComponent(input.value.trim()), {
              signal: ctrl.signal,
              headers: { Accept: 'application/json' },
            });
            if (!r.ok) return;
            const names = await r.json();
            box.replaceChildren();
            (Array.isArray(names) ? names : []).forEach((name) => {
              const b = document.createElement('button');
              b.type = 'button';
              b.textContent = name;
              b.addEventListener('mousedown', (e) => {
                e.preventDefault();
                onPick(name);
                box.hidden = true;
              });
              box.appendChild(b);
            });
            box.hidden = !box.childElementCount;
          }
          catch (e) {
            if (e.name !== 'AbortError') box.hidden = true;
          }
        };
        input.addEventListener('input', () => {
          clearTimeout(timer);
          timer = setTimeout(run, 180);
        });
        input.addEventListener('focus', run);
        document.addEventListener('click', (e) => {
          if (e.target === input || box.contains(e.target)) return;
          box.hidden = true;
        });
      }
      suggest($('#xq-model'), 'models', (name) => { $('#xq-model').value = name; });
      suggest($('#xq-tags'), 'tags', (name) => {
        if (!sets.tags.includes(name)) sets.tags.push(name);
        $('#xq-tags').value = '';
        chips('tags');
      });
      suggest($('#xq-cats'), 'cats', (name) => {
        if (!sets.cats.includes(name)) sets.cats.push(name);
        $('#xq-cats').value = '';
        chips('cats');
      });

      function draw() {
        const g = $('#xq-grid');
        g.replaceChildren();
        const ok = items.filter((x) => x.st === 'ok').length;
        const selectedBytes = items.reduce((sum, it) => sum + (it.file ? it.file.size : 0), 0);
        const stateText = { wait: '等待上传', up: '正在上传', ok: '已完成', bad: '失败' };
        $('#xq-bar').textContent = (ok ? `已传 ${ok} / ${items.length}` : `已选 ${items.length} 张`) + ' · ' + bytesLabel(selectedBytes);
        items.forEach((it, i) => {
          const d = document.createElement('div');
          d.className = 'xq-it ' + (it.st || '') + (it.dup ? ' dup' : '');
          if (it.thumb) {
            const img = document.createElement('img');
            img.src = it.thumb;
            img.alt = '';
            d.appendChild(img);
          }
          else {
            const ph = document.createElement('span');
            ph.className = 'xq-ph';
            ph.textContent = '失败';
            d.appendChild(ph);
          }
          const num = document.createElement('b');
          num.textContent = (it.file && it.file.name ? it.file.name + ' · ' : '') + (stateText[it.st] || '未知状态') + (it.dup ? ' · 文件已存在，已复用' : '') + (it.st === 'up' && it.progress ? ' ' + it.progress + '%' : '');
          d.appendChild(num);
          if (it.err) {
            const note = document.createElement('span');
            note.className = 'xq-ph';
            note.textContent = it.err;
            d.appendChild(note);
          }
          if (it === cover) {
            const mark = document.createElement('u');
            mark.textContent = '封面';
            d.appendChild(mark);
          }
          const actions = document.createElement('div');
          actions.className = 'xq-actions';
          const controls = [['cover', '封面'], ['prev', '前移'], ['first', '最前'], ['del', '删除']];
          if (it.st === 'bad' && it.file) controls.unshift(['retry', '重试']);
          controls.forEach(([act, label]) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = label;
            b.addEventListener('click', (e) => {
              e.stopPropagation();
              actOn(it, act);
            });
            actions.appendChild(b);
          });
          d.appendChild(actions);
          g.appendChild(d);
        });
      }

      function actOn(it, act) {
        const i = items.indexOf(it);
        if (i < 0) return;
        if (act === 'retry') {
          it.st = 'wait';
          it.err = '';
          draw();
          csrf()
            .then(() => up(it, items.indexOf(it) + 1, $('#xq-title').value.trim() || 'gallery'))
            .catch((e) => {
              it.st = 'bad';
              it.err = e.message || '网络中断，服务器没有确认收到文件。';
            })
            .then(() => {
              draw();
              if (it.st === 'bad' && it.err) msg(it.err + ' 点图片上的重试，已成功的不会重传。');
            });
          return;
        }
        if (act === 'cover') cover = it;
        else if (act === 'del') {
          if (it.thumb) URL.revokeObjectURL(it.thumb);
          items.splice(i, 1);
          if (cover === it) cover = items[0] || null;
        }
        else if (act === 'prev' && i > 0) [items[i - 1], items[i]] = [items[i], items[i - 1]];
        else if (act === 'first') { items.splice(i, 1); items.unshift(it); }
        draw();
      }

      async function scaled(file, max, type, q) {
        const probe = await createImageBitmap(file);
        const ratio = Math.min(1, max / Math.max(probe.width, probe.height));
        const w = Math.max(1, Math.round(probe.width * ratio));
        const h = Math.max(1, Math.round(probe.height * ratio));
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(probe, 0, 0, w, h);
        probe.close();
        let blob = await new Promise((ok) => canvas.toBlob(ok, type, q));
        if (!blob) throw new Error('压缩失败');
        if (type === 'image/webp' && blob.type !== 'image/webp') {
          blob = await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.85));
        }
        if (!blob) throw new Error('压缩失败');
        return blob;
      }

      $('#xq-files').addEventListener('change', (e) => {
        const files = [...e.target.files];
        e.target.value = '';
        files.forEach((file) => {
          if (!file.type || file.type.indexOf('image/') !== 0) {
            items.push({ file, thumb: '', st: 'bad', err: file.name + ' 不是图片' });
            return;
          }
          items.push({ file, thumb: URL.createObjectURL(file), st: 'wait', err: '' });
        });
        if (!cover) cover = items.find((it) => it.thumb) || null;
        draw();
      });

      async function csrf() {
        if (token) return token;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
          const response = await fetch('/session/token', { signal: controller.signal });
          if (!response.ok) throw new Error('token');
          token = (await response.text()).trim();
          if (!token) throw new Error('token');
          return token;
        }
        catch (e) {
          token = '';
          throw new Error('网络中断，服务器没有确认收到文件。');
        }
        finally {
          clearTimeout(timer);
        }
      }
      async function postUpload(fd) {
        const headers = { 'X-CSRF-Token': await csrf() };
        let response = await fetch('/admin/quick-gallery/upload', { method: 'POST', body: fd, headers });
        if (response.status === 403) {
          token = '';
          headers['X-CSRF-Token'] = await csrf();
          response = await fetch('/admin/quick-gallery/upload', { method: 'POST', body: fd, headers });
        }
        return response;
      }

      async function sha256(blob) {
        const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
        return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
      }
      function bytesLabel(bytes) {
        if (!Number.isFinite(bytes) || bytes < 1024) return Math.max(0, Math.round(bytes || 0)) + ' B';
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
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
      function applySaved(it, json) {
        it.mid = json.mid;
        it.dup = !!json.dup;
        it.originalBytes = json.original_bytes || it.file.size;
        it.optimizedBytes = json.optimized_bytes || 0;
        it.st = 'ok';
        it.err = '';
        it.progress = 100;
      }
      async function sendChunks(it, title, hash) {
        const head = await sha256(it.file.slice(0, Math.min(it.file.size, 65536)));
        const ext = String(it.file.name.split('.').pop() || '').toLowerCase();
        const filename = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext) ? it.file.name : 'photo.jpg';
        const client = [...crypto.getRandomValues(new Uint8Array(16))].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        const headers = { 'X-CSRF-Token': token, 'Content-Type': 'application/json', Accept: 'application/json' };
        const created = await fetch('/admin/quick-gallery/upload/session', {
          method: 'POST',
          headers,
          body: JSON.stringify({
            filename,
            size: it.file.size,
            kind: 'quick',
            mime: it.file.type || '',
            head_sha256: head,
            file_sha256: hash,
            client_upload_id: client,
            title,
          }),
        });
        const session = await created.json().catch(() => ({}));
        if (!created.ok || !session.session_id) throw new Error(session.error || '分块会话没有创建');
        const base = '/admin/quick-gallery/upload/session/' + session.session_id;
        const chunkBytes = Number(session.chunk_bytes) || 5242880;
        try {
          for (let index = 0; index < Number(session.chunk_count); index += 1) {
            const start = index * chunkBytes;
            const blob = it.file.slice(start, Math.min(it.file.size, start + chunkBytes));
            const chunkHash = await sha256(blob);
            const sent = await new Promise((resolve, reject) => {
              const xhr = new XMLHttpRequest();
              xhr.open('POST', base + '/chunk/' + index);
              xhr.setRequestHeader('X-CSRF-Token', token);
              xhr.setRequestHeader('Content-Type', 'application/octet-stream');
              xhr.setRequestHeader('X-Chunk-Sha256', chunkHash);
              xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error('分块没有被确认')));
              xhr.onerror = () => reject(new Error('网络中断，服务器没有确认收到文件。'));
              xhr.send(blob);
            });
            void sent;
            it.st = 'up';
            it.progress = Math.round(((index + 1) / session.chunk_count) * 100);
            draw();
          }
          const done = await fetch(base + '/complete', {
            method: 'POST',
            headers,
            body: JSON.stringify({ file_sha256: hash }),
          });
          const body = await done.json().catch(() => ({}));
          if (!done.ok || !body.mid) throw new Error(body.error || '合并没有完成');
          return body;
        }
        catch (error) {
          await fetch(base + '/cancel', { method: 'POST', headers, body: '{}' }).catch(() => {});
          throw error;
        }
      }
      async function up(it, n, title) {
        const manager = Drupal.xedcUpload;
        if (manager) {
          try {
            await manager.start();
            if (!it.uploadId) {
              const created = await manager.enqueue([it.file], {
                kind: 'quick',
                entry: 'quick',
                title,
                maxImage: 26214400,
              });
              it.uploadId = created[0] && created[0].id;
            }
            else {
              await manager.retry(it.uploadId);
            }
            await new Promise((resolve, reject) => {
              let settled = false;
              const finish = (fn, value) => {
                if (settled) return;
                settled = true;
                stop();
                window.clearTimeout(timer);
                fn(value);
              };
              const stop = manager.subscribe((jobs) => {
                const job = jobs.find((row) => row.id === it.uploadId);
                if (!job) return;
                it.progress = job.bytes > 0 ? Math.round((job.confirmed / job.bytes) * 100) : (job.phase === 'completed' || job.phase === 'duplicate' ? 100 : it.progress || 0);
                it.st = ['failed', 'interrupted'].includes(job.phase) ? 'bad' : (job.mid || job.phase === 'duplicate' || job.phase === 'completed' ? 'ok' : 'up');
                it.err = job.error || '';
                draw();
                if (job.mid || job.phase === 'duplicate' || job.phase === 'completed') {
                  applySaved(it, {
                    mid: job.mid,
                    dup: job.phase === 'duplicate',
                    original_bytes: job.originalBytes || it.file.size,
                    optimized_bytes: job.optimizedBytes || 0,
                  });
                  finish(resolve);
                }
                else if (['failed', 'interrupted', 'cancelled'].includes(job.phase) && job.error) {
                  finish(reject, new Error(job.error));
                }
              });
              const timer = window.setTimeout(() => {
                const job = manager.list().find((row) => row.id === it.uploadId);
                if (job && (job.mid || job.phase === 'completed' || job.phase === 'duplicate')) {
                  applySaved(it, {
                    mid: job.mid,
                    dup: job.phase === 'duplicate',
                    original_bytes: job.originalBytes || it.file.size,
                    optimized_bytes: job.optimizedBytes || 0,
                  });
                  finish(resolve);
                  return;
                }
                finish(reject, new Error((job && job.error) || '上传超时，已保留可恢复的任务。'));
              }, 180000);
            });
            return;
          }
          catch (e) {
            const reason = String(e && e.message || e);
            if (/过大|大小限制|不是受支持|本地没有原文件/.test(reason)) {
              it.err = reason;
              it.st = 'bad';
              return;
            }
          }
        }
        let hash = '';
        try {
          hash = await sha256(it.file);
          const response = await fetch('/admin/quick-gallery/reuse', {
            method: 'POST',
            headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ sha256: hash, size: it.file.size, title, n }),
          });
          const known = await response.json().catch(() => ({}));
          if (response.ok && known.mid && known.reusable) {
            applySaved(it, known);
            it.dup = true;
            return;
          }
        }
        catch (e) { /* 查重失败时继续上传，服务器仍会校验。 */ }
        if (hash) {
          try {
            const saved = await sendChunks(it, title, hash);
            applySaved(it, saved);
            return;
          }
          catch (e) {
            const reason = String(e && e.message || e);
            if (/过大|大小限制|不是受支持/.test(reason)) {
              it.err = reason;
              it.st = 'bad';
              return;
            }
          }
        }
        for (let attempt = 0; attempt < 3; attempt++) {
          try {
            const json = await new Promise((resolve, reject) => {
              const fd = new FormData();
              fd.append('file', it.file, it.file.name);
              fd.append('title', title);
              fd.append('n', String(n));
              fd.append('orig', String(it.file.size));
              const xhr = new XMLHttpRequest();
              xhr.open('POST', '/admin/quick-gallery/upload');
              xhr.setRequestHeader('X-CSRF-Token', token);
              xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                it.progress = Math.round(event.loaded / event.total * 100);
                it.st = 'up';
                draw();
              };
              xhr.onload = () => {
                let body = {};
                try { body = JSON.parse(xhr.responseText || '{}'); }
                catch (e) { body = {}; }
                if (xhr.status >= 200 && xhr.status < 300 && body.mid) resolve(body);
                else reject(new Error(body.error || ('HTTP ' + xhr.status)));
              };
              xhr.onerror = () => reject(new Error('网络中断，服务器没有确认收到文件。'));
              xhr.send(fd);
            });
            it.mid = json.mid;
            it.dup = !!json.dup;
            it.originalBytes = json.original_bytes || it.file.size;
            it.optimizedBytes = json.optimized_bytes || 0;
            it.st = 'ok';
            it.err = '';
            it.progress = 100;
            return;
          }
          catch (e) {
            it.err = String(e.message || e);
          }
        }
        it.st = 'bad';
      }

      async function pool(list, limit, worker) {
        let index = 0;
        const run = async () => {
          while (index < list.length) {
            const current = index++;
            await worker(list[current], current);
          }
        };
        await Promise.all(Array.from({ length: Math.min(limit, list.length) }, run));
      }

      async function run(title) {
        const queue = items.filter((x) => x.st !== 'ok' && x.file);
        await pool(queue, 3, async (it) => {
          it.st = 'up';
          draw();
          await up(it, items.indexOf(it) + 1, title);
          draw();
        });
      }

      $('#xq-pub').addEventListener('change', () => {
        $('#xq-go').textContent = $('#xq-pub').checked ? '上传并发布' : '上传并创建草稿';
      });
      $('#xq-go').addEventListener('click', async () => {
        const btn = $('#xq-go');
        addChip('tags');
        addChip('cats');
        const title = $('#xq-title').value.trim();
        if (!title || !items.length) return msg('请填标题并选择照片');
        if (items.some((it) => !it.file)) return msg('有无法读取的图片，请删除后重试');
        if (btn.dataset.busy === '1') return;
        btn.dataset.busy = '1';
        btn.disabled = true;
        msg('上传中…');
        try {
          await csrf();
          await run(title);
        }
        catch (e) {
          const reason = e.message || '网络中断，服务器没有确认收到文件。';
          items.forEach((it) => {
            if (it.st !== 'ok') {
              it.st = 'bad';
              it.err = it.err || reason;
            }
          });
          draw();
          btn.disabled = false;
          btn.dataset.busy = '';
          return msg(reason + ' 点图片上的重试，已成功的不会重传。');
        }
        const dupes = items.filter((x) => x.dup).length;
        if (dupes) msg(dupes + ' 张已存在，已复用');
        if (items.some((x) => x.st !== 'ok')) {
          btn.disabled = false;
          btn.dataset.busy = '';
          return msg('有失败的图：' + items.filter((x) => x.st !== 'ok').map((x) => x.err).filter(Boolean).join(' ') + '。点图片上的重试，已成功的不会重传。');
        }
        try {
          const response = await fetch('/admin/quick-gallery/create', {
            method: 'POST',
            headers: { 'X-CSRF-Token': await csrf(), 'Content-Type': 'application/json' },
            body: JSON.stringify({
              title,
              model: $('#xq-model').value.trim(),
              tags: sets.tags,
              cats: sets.cats,
              mids: items.map((x) => x.mid),
              cover: (cover || items[0]).mid,
              publish: $('#xq-pub').checked,
            }),
          });
          const json = await response.json();
          if (response.ok && json.edit) {
            const state = json.published ? '已发布' : ('未发布' + (json.moderation ? ' / ' + json.moderation : ''));
            msg('处理完成。' + (json.count || items.length) + ' 张，原图 ' + (json.original_label || '未知') + '，优化后 ' + (json.optimized_label || '未知') + '。' + state + '。');
            const link = document.createElement('a');
            link.href = json.edit;
            link.textContent = '打开内容';
            $('#xq-msg').appendChild(document.createTextNode(' '));
            $('#xq-msg').appendChild(link);
            btn.disabled = false;
            btn.dataset.busy = '';
          }
          else {
            msg(json.error || '创建失败');
            btn.disabled = false;
            btn.dataset.busy = '';
          }
        }
        catch (e) {
          msg('创建失败');
          btn.disabled = false;
          btn.dataset.busy = '';
        }
      });
    },
  };
})(Drupal, once);
