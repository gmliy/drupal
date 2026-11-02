/**
 * @file
 * Refreshes scan progress from Drupal. It does not scan by itself.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.xedcFileScan = {
    attach(context) {
      once('xedc-files', '[data-xedc-scan]', context).forEach(function (root) {
        const settings = drupalSettings.xedcFiles || {};
        if (!settings.statusUrl || root.getAttribute('data-status') !== 'running') {
          return;
        }
        const label = root.querySelector('[data-xedc-scan-label]');
        const processed = root.querySelector('[data-xedc-scan-processed]');
        const updated = root.querySelector('[data-xedc-scan-updated]');
        const message = root.querySelector('[data-xedc-scan-message]');
        const timer = window.setInterval(function () {
          fetch(settings.statusUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }
              return response.json();
            })
            .then(function (job) {
              if (label && job.status_label) {
                label.textContent = job.status_label;
              }
              if (processed && job.processed !== undefined) {
                processed.textContent = String(job.processed);
              }
              if (updated && job.updated) {
                updated.textContent = job.updated;
              }
              if (message && job.message) {
                message.textContent = job.message;
              }
              if (job.status && job.status !== 'running') {
                window.clearInterval(timer);
                window.location.reload();
              }
            })
            .catch(function () {
              window.clearInterval(timer);
            });
        }, 5000);
      });
    },
  };

  Drupal.behaviors.xedcFileTables = {
    attach(context) {
      once('xedc-pageable', '[data-xedc-pageable]', context).forEach(function (wrap) {
        const table = wrap.querySelector('table');
        if (!table) {
          return;
        }
        const body = table.querySelector('tbody');
        if (!body) {
          return;
        }
        const rows = Array.from(body.querySelectorAll('tr'));
        if (rows.length <= 15) {
          return;
        }
        const size = 15;
        let page = 0;
        const bar = document.createElement('div');
        bar.className = 'xedc-ops-pager';
        const label = document.createElement('span');
        const prev = document.createElement('button');
        const next = document.createElement('button');
        prev.type = 'button';
        next.type = 'button';
        prev.textContent = '上一页';
        next.textContent = '下一页';
        prev.className = 'xedc-ops-button';
        next.className = 'xedc-ops-button';
        function paint() {
          const pages = Math.ceil(rows.length / size);
          rows.forEach(function (row, index) {
            row.hidden = index < page * size || index >= (page + 1) * size;
          });
          label.textContent = (page + 1) + ' / ' + pages;
          prev.disabled = page === 0;
          next.disabled = page + 1 >= pages;
        }
        prev.addEventListener('click', function () {
          page = Math.max(0, page - 1);
          paint();
        });
        next.addEventListener('click', function () {
          page += 1;
          paint();
        });
        bar.append(prev, label, next);
        wrap.before(bar);
        paint();
      });
    },
  };
})(Drupal, drupalSettings, once);
