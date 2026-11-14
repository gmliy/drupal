(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.xedcComicPageView = {
    attach(context) {
      once('xedc-comic-pages-view', '.xedc-comic-pages', context).forEach((root) => {
        const grid = root.querySelector('[data-pages-grid]');
        const list = root.querySelector('[data-pages-list]');
        const buttons = root.querySelectorAll('[data-view]');
        if (!grid || !list) {
          return;
        }
        buttons.forEach((btn) => {
          btn.addEventListener('click', () => {
            const view = btn.getAttribute('data-view');
            buttons.forEach((b) => b.classList.toggle('is-active', b === btn));
            const showGrid = view === 'grid';
            grid.hidden = !showGrid;
            list.hidden = showGrid;
          });
        });
      });
    },
  };

  Drupal.behaviors.xedcComicPageSort = {
    attach(context) {
      once('xedc-comic-pages-sort', '.xedc-comic-pages', context).forEach((root) => {
        const tbody = root.querySelector('.xedc-comic-pages__sortable');
        const orderField = document.querySelector('textarea[name="order"]');
        if (!tbody || !orderField) {
          return;
        }
        let dragRow = null;
        tbody.querySelectorAll('tr[data-page-id]').forEach((row) => {
          row.setAttribute('draggable', 'true');
          row.addEventListener('dragstart', () => {
            dragRow = row;
            row.classList.add('is-dragging');
          });
          row.addEventListener('dragend', () => {
            row.classList.remove('is-dragging');
            syncOrder();
          });
          row.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!dragRow || dragRow === row) {
              return;
            }
            const rect = row.getBoundingClientRect();
            const after = e.clientY - rect.top > rect.height / 2;
            tbody.insertBefore(dragRow, after ? row.nextSibling : row);
          });
        });

        function syncOrder() {
          const ids = [];
          tbody.querySelectorAll('tr[data-page-id]').forEach((tr) => {
            ids.push(tr.getAttribute('data-page-id'));
          });
          orderField.value = ids.join(',');
        }
      });
    },
  };
})(Drupal, once);