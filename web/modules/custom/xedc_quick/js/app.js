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
        $('#xq-bar').textContent = ok ? `已传 ${ok} / ${items.length}` : `已选 ${items.length} 张`;
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
          num.textContent = it.st === 'up' && it.progress ? String(it.progress) + '%' : String(i + 1);
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

      async function up(it, n, title) {
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
