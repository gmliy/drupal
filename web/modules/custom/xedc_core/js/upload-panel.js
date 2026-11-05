/**
 * @file
 * Admin-only upload status entry and lightweight task panel.
 */
(function (Drupal, once, drupalSettings) {
  function ensureRoot() {
    let root = document.querySelector('[data-xedc-upload-panel]');
    if (root) return root;
    // Never mount the admin panel on the public theme chrome.
    const header = document.querySelector('.xedc-admin-header');
    if (!header) return null;
    root = document.createElement('div');
    root.className = 'xedc-uppanel';
    root.setAttribute('data-xedc-upload-panel', '1');
    root.hidden = true;
    root.innerHTML = [
      '<button type="button" class="xedc-uppanel__toggle" data-xedc-uppanel-toggle aria-expanded="false" aria-controls="xedc-uppanel-drawer">',
      '<span class="xedc-uppanel__label">上传</span>',
      '<span class="xedc-uppanel__badge" data-xedc-uppanel-badge hidden>0</span>',
      '</button>',
      '<div class="xedc-uppanel__drawer" id="xedc-uppanel-drawer" data-xedc-uppanel-drawer hidden>',
      '<header class="xedc-uppanel__head"><strong>上传任务</strong>',
      '<a class="xedc-uppanel__link" data-xedc-uppanel-center href="/admin/content/image-imports">上传中心</a>',
      '<button type="button" class="xedc-uppanel__close" data-xedc-uppanel-close aria-label="关闭">关闭</button></header>',
      '<p class="xedc-uppanel__summary" data-xedc-uppanel-summary></p>',
      '<p class="xedc-uppanel__note" data-xedc-uppanel-note hidden></p>',
      '<div class="xedc-uppanel__actions"><button type="button" data-xedc-uppanel-retry-all>重试失败</button></div>',
      '<div class="xedc-uppanel__list" data-xedc-uppanel-list></div>',
      '</div>',
    ].join('');
    const quiet = header.querySelector('.xedc-admin-header__quiet');
    if (quiet) header.insertBefore(root, quiet);
    else header.appendChild(root);
    return root;
  }

  function bind(root) {
    const manager = Drupal.xedcUpload;
    if (!manager || !root) return;
    const toggle = root.querySelector('[data-xedc-uppanel-toggle]');
    const drawer = root.querySelector('[data-xedc-uppanel-drawer]');
    const badge = root.querySelector('[data-xedc-uppanel-badge]');
    const summary = root.querySelector('[data-xedc-uppanel-summary]');
    const note = root.querySelector('[data-xedc-uppanel-note]');
    const list = root.querySelector('[data-xedc-uppanel-list]');
    const center = root.querySelector('[data-xedc-uppanel-center]');
    const cfg = manager.settings();
    if (center && cfg.centerUrl) center.href = cfg.centerUrl;

    function setOpen(open) {
      drawer.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', () => setOpen(drawer.hidden));
    root.querySelector('[data-xedc-uppanel-close]').addEventListener('click', () => setOpen(false));
    root.querySelector('[data-xedc-uppanel-retry-all]').addEventListener('click', () => manager.retryFailed());

    manager.subscribe((jobs) => {
      const counts = manager.counts();
      const visible = jobs.filter((job) => job.phase !== 'cancelled' && job.phase !== 'canceled');
      const active = counts.uploading + counts.waiting + counts.failed;
      const show = active > 0 || counts.completed > 0;
      root.hidden = !show;
      if (!show) setOpen(false);
      badge.hidden = active < 1;
      badge.textContent = String(active);
      summary.textContent = '上传 ' + counts.uploading
        + ' · 等待 ' + counts.waiting
        + ' · 失败 ' + counts.failed
        + ' · 完成 ' + counts.completed;
      if (!manager.persistOk()) {
        note.hidden = false;
        note.textContent = manager.persistError() || '文件暂存失败：无法保存所选文件，请重新选择文件后重试。';
      }
      else {
        note.hidden = true;
        note.textContent = '';
      }
      list.replaceChildren();
      visible.slice(0, 40).forEach((job) => {
        const item = document.createElement('article');
        item.className = 'xedc-uppanel__item is-' + (job.phase || 'pending');
        const title = document.createElement('strong');
        title.textContent = job.name || '未命名文件';
        const meta = document.createElement('p');
        const total = Number(job.bytes) || 0;
        const confirmed = Number(job.confirmed) || 0;
        meta.textContent = (job.kind === 'archive' ? '压缩包' : '图片')
          + ' · ' + manager.formatBytes(confirmed) + ' / ' + manager.formatBytes(total)
          + ' · ' + manager.statusText(job.phase);
        item.append(title, meta);
        if (job.error) {
          const error = document.createElement('p');
          error.className = 'xedc-uppanel__error';
          error.textContent = job.error;
          item.appendChild(error);
        }
        const actions = document.createElement('div');
        actions.className = 'xedc-uppanel__row-actions';
        if (['failed', 'interrupted', 'paused', 'needs_title', 'cancel_pending'].includes(job.phase)) {
          if (job.phase === 'needs_title' || job.phase === 'cancel_pending') {
            // Title is entered on the upload center; cancel_pending only needs retry cancel.
          }
          else if (job.file || job.blob) {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.textContent = '重试';
            retry.addEventListener('click', () => manager.retry(job.id));
            actions.appendChild(retry);
          }
          else {
            const picker = document.createElement('input');
            picker.type = 'file';
            picker.title = '重新选择原文件';
            picker.addEventListener('change', () => {
              const file = picker.files && picker.files[0];
              picker.value = '';
              if (file) manager.attachFile(job.id, file);
            });
            actions.appendChild(picker);
          }
        }
        if (manager.canCancel && manager.canCancel(job)) {
          const cancel = document.createElement('button');
          cancel.type = 'button';
          cancel.textContent = '取消';
          cancel.addEventListener('click', () => {
            if (window.confirm('取消后会删除尚未合并的分块和本地恢复数据。已经导入的内容不会删除。')) {
              manager.cancel(job.id);
            }
          });
          actions.appendChild(cancel);
        }
        if (job.taskId && cfg.progressBase) {
          const link = document.createElement('a');
          link.href = cfg.progressBase + job.taskId;
          link.textContent = '任务';
          actions.appendChild(link);
        }
        item.appendChild(actions);
        list.appendChild(item);
      });
    });
  }

  Drupal.behaviors.xedcUploadPanel = {
    attach(context) {
      if (!Drupal.xedcUpload) return;
      const settings = (drupalSettings && drupalSettings.xedcUpload) || {};
      if (settings.adminOnly === false) return;
      if (!document.querySelector('.xedc-admin-header') && !document.querySelector('.xedc-admin-shell')) {
        return;
      }
      once('xedc-upload-panel', 'body', context).forEach(() => {
        Drupal.xedcUpload.start();
        const root = ensureRoot();
        if (root) bind(root);
      });
    },
  };
})(Drupal, once, drupalSettings);
