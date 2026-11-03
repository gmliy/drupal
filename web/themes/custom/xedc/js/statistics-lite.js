/**
 * @file
 * Node view counter without a jQuery dependency.
 */
(function (drupalSettings) {
  window.setTimeout(function () {
    var statistics = drupalSettings.statistics;
    if (!statistics || !statistics.url) {
      return;
    }
    var body = new URLSearchParams(statistics.data || {});
    var isVideo = document.body && (document.body.classList.contains('page-node-type-video') || document.body.classList.contains('node-type--video'));
    if (isVideo) {
      var seenKey = 'xedc-video-view-' + (body.get('nid') || '');
      var seenAt = 0;
      try {
        seenAt = parseInt(window.localStorage.getItem(seenKey) || '0', 10);
      }
      catch (e) {}
      if (seenAt && Date.now() - seenAt < 1800000) {
        return;
      }
      try {
        window.localStorage.setItem(seenKey, String(Date.now()));
      }
      catch (e) {}
    }
    window.fetch(statistics.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      cache: 'no-store',
    });
  });
})(drupalSettings);
