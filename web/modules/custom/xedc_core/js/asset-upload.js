/**
 * @file
 * Upload center UI bound to the shared persistent upload manager.
 */
(function (Drupal, once, drupalSettings) {
  Drupal.behaviors.xedcAssetUpload = {
    attach(context) {
      once('xedc-asset-upload', '[data-xedc-asset-upload]', context).forEach((root) => {
        const manager = Drupal.xedcUpload;
        if (!manager) {
          const note = root.querySelector('[data-xedc-note]');
          if (note) note.textContent = '上传管理器没有加载。请刷新页面。';
          return;
        }
        manager.start();
        const list = root.querySelector('[data-xedc-list]');
        const note = root.querySelector('[data-xedc-note]');
        const stats = root.querySelector('[data-xedc-stats]');
        const titleInput = root.querySelector('[data-xedc-title]');
        const archiveInput = root.querySelector('[data-xedc-archive]');
        const imageInput = root.querySelector('[data-xedc-images]');
        let nid = Number((drupalSettings.xedcAsset || {}).nid || root.getAttribute('data-nid') || 0);
        let syncTimer = null;
        let lastGesture = '';
        let lastGestureAt = 0;

        function say(text) {
          if (note) note.textContent = text || '';
        }

        function titleValue() {
          if (titleInput && titleInput.value.trim()) return titleInput.value.trim();
          const field = document.querySelector((drupalSettings.xedcAsset || {}).titleSelector || '');
          return field ? field.value.trim() : '';
        }

        function drawStats(jobs) {
          if (!stats) return;
          const counts = manager.counts();
          const cards = [
            ['当前任务', jobs.length],
            ['正在上传', counts.uploading],
            ['等待处理', counts.waiting],
            ['已完成', counts.completed],
            ['失败', counts.failed],
          ];
          stats.replaceChildren();
          cards.forEach(([label, value]) => {
            const card = document.createElement('div');
            card.className = 'xedc-upload__stat';
            const name = document.createElement('span');
            name.textContent = label;
            const strong = document.createElement('strong');
            strong.textContent = String(value);
            card.append(name, strong);
            stats.appendChild(card);
          });
        }

        function rowView(job) {
          if (!list) return;
          const key = job.id || job.clientId || job.sessionId;
          let line = list.querySelector('[data-key="' + key + '"]');
          if (!line) {
            line = document.createElement('article');
            line.className = 'xedc-upload__item';
            line.dataset.key = key;
            list.appendChild(line);
          }
          line.className = 'xedc-upload__item is-' + (job.phase || 'pending');
          line.replaceChildren();
          const icon = document.createElement(job.preview ? 'img' : 'span');
          icon.className = 'xedc-upload__icon';
          if (job.preview) {
            icon.src = job.preview;
            icon.alt = '';
          }
          else icon.textContent = job.kind === 'archive' ? 'ZIP' : '图片';
          const body = document.createElement('div');
          body.className = 'xedc-upload__body';
          const name = document.createElement('strong');
          name.textContent = job.name || '未命名文件';
          const meta = document.createElement('p');
          const total = Number(job.bytes) || 0;
          const confirmed = Number(job.confirmed) || 0;
          const remain = Math.max(0, total - confirmed);
          meta.textContent = manager.formatBytes(total)
            + ' · 已上传 ' + manager.formatBytes(confirmed)
            + ' · 剩余 ' + manager.formatBytes(remain)
            + (job.speed ? ' · ' + manager.formatBytes(job.speed) + '/s' : '');
          const bar = document.createElement('div');
          bar.className = 'xedc-upload__bar';
          const fill = document.createElement('span');
          const shown = total > 0 ? Math.min(100, Math.round(((confirmed + (job.inflight || 0)) / total) * 100)) : (job.phase === 'duplicate' || job.phase === 'completed' ? 100 : 0);
          fill.style.width = shown + '%';
          bar.appendChild(fill);
          const badge = document.createElement('em');
          badge.textContent = manager.statusText(job.phase);
          body.append(name, meta, bar, badge);
          if (job.error) {
            const error = document.createElement('p');
            error.className = 'xedc-upload__error';
            error.textContent = job.error;
            body.appendChild(error);
          }
          if (job.taskId) {
            const link = document.createElement('a');
            link.href = ((drupalSettings.xedcAsset || {}).progressBase || '/admin/content/image-imports/') + job.taskId;
            link.textContent = '查看任务';
            body.appendChild(link);
          }
          const actions = document.createElement('div');
          actions.className = 'xedc-upload__actions';
          if (!job.file && !job.blob && job.sessionId && ['paused', 'interrupted', 'failed', 'open', 'uploading'].includes(job.phase)) {
            const picker = document.createElement('input');
            picker.type = 'file';
            picker.addEventListener('change', () => {
              const file = picker.files && picker.files[0];
              picker.value = '';
              if (file) manager.attachFile(job.id, file).then((ok) => {
                say(ok ? '正在从服务器缺少的分块继续。' : (manager.list().find((row) => row.id === job.id) || {}).error || '');
              });
            });
            actions.appendChild(picker);
          }
          if ((job.file || job.blob) && ['paused', 'interrupted', 'failed', 'needs_title'].includes(job.phase)) {
            if (job.phase === 'needs_title') {
              const start = document.createElement('button');
              start.type = 'button';
              start.textContent = '填写标题后继续';
              start.addEventListener('click', () => {
                const title = titleValue();
                if (!title) {
                  say('请先填写作品标题。');
                  return;
                }
                manager.applyTitle(title).then((count) => {
                  say(count ? '已使用标题继续上传。' : '请先填写作品标题。');
                });
              });
              actions.appendChild(start);
            }
            else {
              const again = document.createElement('button');
              again.type = 'button';
              again.textContent = '继续上传';
              again.addEventListener('click', () => manager.retry(job.id));
              actions.appendChild(again);
            }
          }
          if (manager.canCancel && manager.canCancel(job)) {
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.textContent = '取消';
            cancel.addEventListener('click', () => {
              if (window.confirm('取消后会删除这次尚未合并的分块和本地恢复数据。已经导入的内容不会删除。')) {
                manager.cancel(job.id).then((result) => {
                  if (!result) return;
                  if (result.ok) say(result.error || '上传已取消。');
                  else if (result.outcome === 'handed_off' || result.outcome === 'already_completed') {
                    say(result.error || '上传已交给导入任务，不能取消。');
                  }
                  else if (result.outcome === 'completing') {
                    say(result.error || '服务器正在完成交接，请稍后查看状态。');
                  }
                  else say(result.error || '取消状态待确认，本地已停止上传。');
                });
              }
            });
            actions.appendChild(cancel);
          }
          line.append(icon, body, actions);
        }

        function render(jobs) {
          const visible = jobs.filter((job) => job.entry !== 'quick' || root.hasAttribute('data-xedc-include-quick'));
          const keys = new Set(visible.map((job) => job.id || job.clientId || job.sessionId));
          if (list) {
            [...list.querySelectorAll('[data-key]')].forEach((node) => {
              if (!keys.has(node.getAttribute('data-key'))) node.remove();
            });
          }
          visible.forEach(rowView);
          drawStats(visible);
          if (!manager.persistOk()) {
            say(manager.persistError() || '文件暂存失败：无法保存所选文件，请重新选择文件后重试。');
          }
        }

        async function take(files, kind) {
          const selected = [...files].filter(Boolean);
          if (!selected.length) return;
          const signature = kind + ':' + selected.map((file) => [file.name, file.size, file.lastModified].join(':')).join('|');
          const stamp = Date.now();
          if (signature === lastGesture && stamp - lastGestureAt < 800) return;
          lastGesture = signature;
          lastGestureAt = stamp;
          const title = titleValue();
          try {
            const created = await manager.enqueue(selected, {
              kind,
              entry: 'admin',
              nid,
              title,
              requireTitle: !(nid > 0),
            });
            if (created.some((job) => job.phase === 'needs_title')) {
              say('请先填写作品标题。文件已保留，可取消，或填写标题后继续。');
            }
            else {
              say('');
            }
          }
          catch (error) {
            say((error && error.message) || '无法开始上传');
          }
        }

        if (titleInput) {
          titleInput.addEventListener('change', () => {
            const title = titleValue();
            if (title) manager.applyTitle(title);
          });
          titleInput.addEventListener('blur', () => {
            const title = titleValue();
            if (title) manager.applyTitle(title);
          });
        }

        function bindPicker(input, kind) {
          if (!input) return;
          input.addEventListener('change', () => {
            const files = [...input.files];
            input.value = '';
            take(files, kind);
          });
        }
        bindPicker(archiveInput, 'archive');
        bindPicker(imageInput, 'image');
        root.querySelectorAll('[data-xedc-drop]').forEach((zone) => {
          const kind = zone.getAttribute('data-xedc-drop');
          zone.addEventListener('dragover', (event) => {
            event.preventDefault();
            zone.classList.add('is-drag');
          });
          zone.addEventListener('dragleave', () => zone.classList.remove('is-drag'));
          zone.addEventListener('drop', (event) => {
            event.preventDefault();
            zone.classList.remove('is-drag');
            take(event.dataTransfer ? event.dataTransfer.files : [], kind);
          });
        });

        const refresh = root.querySelector('[data-xedc-refresh]');
        if (refresh) {
          refresh.addEventListener('click', () => {
            manager.syncSessions().then(() => say('已同步服务器任务。')).catch(() => say('暂时无法查询未完成的上传。'));
            syncImportRows();
          });
        }

        function syncDelay() {
          const counts = manager.counts();
          const busy = counts.uploading + counts.waiting + counts.failed > 0;
          if (document.visibilityState === 'hidden') return busy ? 15000 : 30000;
          return busy ? 4000 : 20000;
        }

        async function syncImportRows() {
          const cfg = Object.assign({}, drupalSettings.xedcAsset || {}, manager.settings());
          const table = document.querySelector('[data-xedc-import-table]');
          if (!table || !cfg.syncUrl) return;
          try {
            const response = await fetch(cfg.syncUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const json = await response.json();
            const rows = Array.isArray(json.tasks) ? json.tasks : [];
            rows.forEach((task) => {
              const row = table.querySelector('[data-task-id="' + task.id + '"]');
              if (!row) return;
              const percent = row.querySelector('[data-xedc-sync-percent]');
              const fill = row.querySelector('[data-xedc-sync-fill]');
              const status = row.querySelector('[data-xedc-sync-status]');
              const detail = row.querySelector('[data-xedc-sync-detail]');
              if (percent) percent.textContent = String(task.progress || 0) + '%';
              if (fill) fill.style.width = String(task.progress || 0) + '%';
              if (status) status.textContent = task.label || task.status || '';
              if (detail) detail.textContent = task.detail || '';
            });
          }
          catch (e) { /* keep current rows */ }
        }

        function scheduleSync() {
          window.clearTimeout(syncTimer);
          syncTimer = window.setTimeout(async () => {
            await manager.syncSessions().catch(() => {});
            await syncImportRows();
            scheduleSync();
          }, syncDelay());
        }

        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState === 'visible') {
            manager.syncSessions().catch(() => {});
            syncImportRows();
            scheduleSync();
          }
        });

        manager.subscribe(render);
        scheduleSync();
        drawStats([]);
      });
    },
  };
})(Drupal, once, drupalSettings);
