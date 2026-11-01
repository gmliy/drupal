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
})(Drupal, drupalSettings, once);
