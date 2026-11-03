(function (Drupal, once) {
  Drupal.behaviors.xedcImportProgress = {
    attach(context) {
      once('xedc-import-progress', '[data-xedc-progress-url]', context).forEach((element) => {
        const url = element.getAttribute('data-xedc-progress-url');
        const fill = element.querySelector('[data-xedc-progress-fill]');
        const label = element.querySelector('[data-xedc-progress-label]');
        const detail = element.querySelector('[data-xedc-progress-detail]');
        if (!url) {
          return;
        }
        const tick = () => {
          fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
          })
            .then((response) => response.json())
            .then((data) => {
              const progress = Number(data.percent != null ? data.percent : (data.progress || 0));
              if (fill) {
                fill.style.width = progress + '%';
              }
              if (label) {
                label.textContent = (data.label || '') + ' ' + progress + '%';
              }
              if (detail) {
                detail.textContent = data.detail || '';
              }
              if (data.done) {
                window.setTimeout(() => window.location.reload(), 700);
              }
              else {
                window.setTimeout(tick, 3000);
              }
            })
            .catch(() => {
              if (detail) {
                detail.textContent = '网络中断，仍显示服务器上次确认的进度。';
              }
              window.setTimeout(tick, 5000);
            });
        };
        window.setTimeout(tick, 1200);
      });
    },
  };
})(Drupal, once);
