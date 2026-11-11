/**
 * @file
 * Attaches suggestions to existing search fields without moving them.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var cfg = drupalSettings.xedcSearch || {};
  var seq = 0;
  var tokenPromise = null;
  var HISTORY_LEGACY = 'xedc_search_history_v1';
  var VISITOR = 'xedc_search_vid';
  var SESSION_UID = 'xedc_search_uid';
  var IDLE_HISTORY = 5;
  var IDLE_HOT = 3;

  function endpoint(name) {
    return (cfg.endpoints && cfg.endpoints[name]) || '';
  }

  function storageContext(mode) {
    return !mode || mode === 'all' ? 'global' : mode;
  }

  function apiType(mode) {
    return !mode || mode === 'global' ? 'all' : mode;
  }

  function historyStorageKey(mode) {
    return 'xedc_search_history_' + storageContext(mode);
  }

  function migrateLegacyHistory() {
    try {
      var legacy = localStorage.getItem(HISTORY_LEGACY);
      if (!legacy) {
        return;
      }
      var items = JSON.parse(legacy);
      if (!Array.isArray(items)) {
        localStorage.removeItem(HISTORY_LEGACY);
        return;
      }
      items.forEach(function (item) {
        var mode = item.type || 'all';
        var bucket = readHistory(mode);
        if (!bucket.some(function (row) { return row.q === item.q; })) {
          bucket.unshift({ q: item.q, type: apiType(mode), t: item.t || Date.now() });
        }
        writeHistory(mode, bucket);
      });
      localStorage.removeItem(HISTORY_LEGACY);
    }
    catch (e) {}
  }

  function watchAccount() {
    try {
      var last = sessionStorage.getItem(SESSION_UID);
      var now = String(cfg.uid || 0);
      sessionStorage.setItem(SESSION_UID, now);
      if (last !== null && last !== now) {
        tokenPromise = null;
      }
    }
    catch (e) {}
  }

  function visitor() {
    try {
      var id = localStorage.getItem(VISITOR);
      if (!id) {
        id = Math.random().toString(36).slice(2) + Date.now().toString(36);
        localStorage.setItem(VISITOR, id);
      }
      return id.slice(0, 80);
    }
    catch (e) {
      return '';
    }
  }

  function readHistory(mode) {
    try {
      var data = JSON.parse(localStorage.getItem(historyStorageKey(mode)) || '[]');
      return Array.isArray(data) ? data : [];
    }
    catch (e) {
      return [];
    }
  }

  function writeHistory(mode, items) {
    try {
      localStorage.setItem(historyStorageKey(mode), JSON.stringify(items.slice(0, 20)));
    }
    catch (e) {}
  }

  function remember(q, type) {
    q = (q || '').trim();
    if (!q || cfg.loggedIn) {
      return;
    }
    var mode = type || 'all';
    var items = readHistory(mode).filter(function (item) {
      return item.q !== q;
    });
    items.unshift({ q: q, type: apiType(mode), t: Date.now() });
    writeHistory(mode, items);
  }

  function csrf() {
    if (!cfg.loggedIn) {
      return Promise.resolve('');
    }
    if (!tokenPromise) {
      tokenPromise = fetch(endpoint('token'), { credentials: 'same-origin' })
        .then(function (response) { return response.text(); })
        .catch(function () { tokenPromise = null; return ''; });
    }
    return tokenPromise;
  }

  function post(url, body) {
    return csrf().then(function (token) {
      var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
      if (token) {
        headers['X-CSRF-Token'] = token;
      }
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify(body),
      });
    });
  }

  function track(q, type) {
    q = (q || '').trim();
    if (!cfg.enabled || q.length < (cfg.minLength || 1) || !endpoint('track')) {
      return;
    }
    var body = { q: q, type: type || 'all', visitor: visitor(), history: !!cfg.loggedIn && !!cfg.history };
    if (navigator.sendBeacon && !cfg.loggedIn) {
      try {
        navigator.sendBeacon(endpoint('track'), new Blob([JSON.stringify(body)], { type: 'application/json' }));
        return;
      }
      catch (e) {}
    }
    post(endpoint('track'), body).catch(function () {});
  }

  function safeImage(url) {
    try {
      var parsed = new URL(url, window.location.origin);
      if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
        return parsed.href;
      }
    }
    catch (e) {}
    return '';
  }

  function safeUrl(url) {
    if (!url) {
      return '';
    }
    try {
      var parsed = new URL(url, window.location.origin);
      if (parsed.origin !== window.location.origin) {
        return '';
      }
      return parsed.pathname + parsed.search + parsed.hash;
    }
    catch (e) {
      return '';
    }
  }

  function fillMatch(parent, label, query, suffixRest) {
    var text = label || '';
    var q = (query || '').trim();
    if (!q) {
      parent.textContent = text;
      return;
    }
    var folded = text.toLowerCase();
    var needle = q.toLowerCase();
    var index = text.indexOf(q) === 0 ? 0 : folded.indexOf(needle);
    if (index < 0) {
      parent.textContent = text;
      return;
    }
    if (index > 0) {
      parent.appendChild(document.createTextNode(text.slice(0, index)));
    }
    var mark = document.createElement('mark');
    mark.className = 'xs-mark';
    mark.textContent = text.slice(index, index + q.length);
    parent.appendChild(mark);
    var rest = text.slice(index + q.length);
    if (suffixRest && index === 0 && rest) {
      var suffix = document.createElement('span');
      suffix.className = 'xs-suffix';
      suffix.textContent = rest;
      parent.appendChild(suffix);
    }
    else if (rest) {
      parent.appendChild(document.createTextNode(rest));
    }
  }

  function typedValue(input) {
    if (input.selectionStart !== null && input.selectionEnd !== null && input.selectionEnd > input.selectionStart) {
      return input.value.slice(0, input.selectionStart);
    }
    return input.value;
  }

  function fieldMeta(input) {
    var form = input.form || input.closest('form');
    var mode = 'all';
    var target = 'search';
    if (form) {
      if (form.classList.contains('xv-search')) {
        mode = 'video';
        target = 'form';
      }
      else if (form.classList.contains('xa-search')) {
        mode = 'article';
        target = 'form';
      }
      else if (form.classList.contains('xt-toolbar')) {
        mode = 'tag';
        target = 'form';
      }
      else if (form.classList.contains('sr-form')) {
        var hidden = form.querySelector('input[name="type"]');
        mode = hidden && hidden.value ? hidden.value : 'all';
        target = 'search';
      }
      else if (form.classList.contains('search-form') || form.id === 'search-block-form') {
        mode = 'all';
        target = 'search';
      }
    }
    if (input.closest('.xm-search')) {
      mode = 'model';
      target = 'local';
    }
    else if (input.closest('.photo-drop__search')) {
      mode = 'photo';
      target = 'local';
    }
    return { form: form, mode: mode, target: target };
  }

  function ensureWrap(input) {
    var parent = input.parentElement;
    if (parent && parent.classList.contains('xs-wrap')) {
      return parent;
    }
    var wrap = document.createElement('div');
    wrap.className = 'xs-wrap';
    if (parent) {
      parent.insertBefore(wrap, input);
    }
    wrap.appendChild(input);
    var navForm = input.closest('form.xn-search, form.xn-msearch');
    if (navForm) {
      var icon = navForm.querySelector(':scope > svg');
      if (icon) {
        icon.classList.add('xn-search-mark');
        wrap.insertBefore(icon, input);
      }
    }
    return wrap;
  }

  function resolveMount(input, wrap) {
    var shell = input.closest('.sr-shell');
    if (shell) {
      shell.classList.add('xs-mount');
      return shell;
    }
    var field = input.closest('.sr-field');
    if (field) {
      field.classList.add('xs-mount');
      return field;
    }
    if (!wrap.classList.contains('xs-wrap')) {
      wrap.classList.add('xs-wrap');
    }
    return wrap;
  }

  function panelNeedsFixed(mount) {
    var el = mount.parentElement;
    while (el && el !== document.body) {
      var style = window.getComputedStyle(el);
      var clip = function (value) {
        return value === 'hidden' || value === 'clip' || value === 'auto' || value === 'scroll';
      };
      if (clip(style.overflow) || clip(style.overflowX) || clip(style.overflowY)) {
        if (el.closest('.photo-drop') || style.overflow === 'hidden' || style.overflowY === 'hidden') {
          return true;
        }
      }
      el = el.parentElement;
    }
    return false;
  }

  function place(panel, input, mount, useFixed) {
    var rect = mount.getBoundingClientRect();
    if (!rect.width && !rect.height) {
      panel.hidden = true;
      return;
    }
    var spaceBelow = window.innerHeight - rect.bottom;
    var spaceAbove = rect.top;
    var openUp = spaceBelow < 160 && spaceAbove > spaceBelow;
    panel.style.boxSizing = 'border-box';
    if (useFixed) {
      panel.classList.add('xs-panel--fixed');
      panel.style.left = rect.left + 'px';
      panel.style.width = rect.width + 'px';
      panel.style.right = 'auto';
      if (openUp) {
        panel.classList.add('xs-panel--up');
        panel.style.top = 'auto';
        panel.style.bottom = (window.innerHeight - rect.top) + 'px';
        panel.style.maxHeight = Math.max(120, Math.min(spaceAbove, window.innerHeight * 0.5)) + 'px';
      }
      else {
        panel.classList.remove('xs-panel--up');
        panel.style.top = rect.bottom + 'px';
        panel.style.bottom = 'auto';
        panel.style.maxHeight = Math.max(120, Math.min(spaceBelow, window.innerHeight * 0.7)) + 'px';
      }
      return;
    }
    panel.classList.remove('xs-panel--fixed');
    panel.style.left = '';
    panel.style.right = '';
    panel.style.width = '';
    panel.style.top = '';
    panel.style.bottom = '';
    if (openUp) {
      panel.classList.add('xs-panel--up');
      panel.style.maxHeight = Math.max(120, Math.min(spaceAbove, window.innerHeight * 0.5)) + 'px';
    }
    else {
      panel.classList.remove('xs-panel--up');
      panel.style.maxHeight = Math.max(120, Math.min(spaceBelow, window.innerHeight * 0.7)) + 'px';
    }
  }

  function clearNode(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function status(panel, text, isError) {
    clearNode(panel);
    var row = document.createElement('div');
    row.className = 'xs-status' + (isError ? ' is-error' : '');
    row.textContent = text;
    panel.appendChild(row);
    panel.hidden = false;
  }

  Drupal.behaviors.xedcSearch = {
    attach: function (context) {
      if (!cfg.enabled) {
        return;
      }
      migrateLegacyHistory();
      watchAccount();
      var selector = [
        'form.xn-search input[type="search"]',
        'form.xn-msearch input[type="search"]',
        'form.sr-form input.sr-input',
        'form.xv-search input[type="search"]',
        'form.xa-search input[type="search"]',
        'form.xt-toolbar input[type="search"]',
        'form.search-form input[type="search"]',
        '.xm-search input[type="search"]',
        '.photo-drop__search input[type="search"]',
      ].join(',');
      once('xedc-search', selector, context).forEach(bind);
    },
  };

  function bind(input) {
    var meta = fieldMeta(input);
    var wrap = ensureWrap(input);
    var mount = resolveMount(input, wrap);
    var useFixed = panelNeedsFixed(mount);
    var panel = document.createElement('div');
    panel.className = 'xs-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'listbox');
    mount.appendChild(panel);
    var items = [];
    var active = -1;
    var composing = false;
    var syncClear = function () {};

    function setPanelOpen(on) {
      mount.classList.toggle('is-open', on);
    }

    function close() {
      panel.hidden = true;
      active = -1;
      setPanelOpen(false);
      input.setAttribute('aria-expanded', 'false');
    }

    function openPanel() {
      panel.hidden = false;
      setPanelOpen(true);
      input.setAttribute('aria-expanded', 'true');
      place(panel, input, mount, useFixed);
    }

    function markActive() {
      Array.prototype.forEach.call(panel.querySelectorAll('.xs-item, .xs-hot-tag'), function (el, index) {
        var on = index === active;
        el.classList.toggle('is-active', on);
        if (on) {
          var top = el.offsetTop;
          var bottom = top + el.offsetHeight;
          if (top < panel.scrollTop) {
            panel.scrollTop = top;
          }
          else if (bottom > panel.scrollTop + panel.clientHeight) {
            panel.scrollTop = bottom - panel.clientHeight;
          }
        }
      });
    }

    function dropCompletion() {
      if (input.selectionEnd === input.value.length && input.selectionEnd > input.selectionStart) {
        input.value = typedValue(input);
        if (input.setSelectionRange) {
          input.setSelectionRange(input.value.length, input.value.length);
        }
      }
    }

    function activate(item) {
      if (!item) {
        return;
      }
      var label = item.label || item.q || '';
      if (item.kind === 'history' && item.remove) {
        return;
      }
      remember(label, meta.mode);
      track(label, meta.mode);
      if (meta.target === 'local' || item.kind === 'model' || item.kind === 'category' || item.kind === 'tag') {
        var url = safeUrl(item.url);
        if (url) {
          window.location.href = url;
          return;
        }
      }
      input.value = label;
      if (meta.target === 'form' && meta.form) {
        if (meta.form.requestSubmit) {
          meta.form.requestSubmit();
        }
        else {
          meta.form.submit();
        }
        return;
      }
      var search = safeUrl(item.url) || (endpoint('search') + '?q=' + encodeURIComponent(label) + (meta.mode && meta.mode !== 'all' && meta.mode !== 'tag' ? '&type=' + encodeURIComponent(meta.mode) : ''));
      window.location.href = search;
    }

    function render(historyItems, hotItems) {
      clearNode(panel);
      items = [];
      active = -1;

      function appendHistory(afterPrev) {
        var rows = (historyItems || []).slice(0, IDLE_HISTORY);
        if (!rows.length) {
          return false;
        }
        var section = document.createElement('div');
        section.className = 'xs-section xs-section--history' + (afterPrev ? ' xs-section--sep' : '');
        section.setAttribute('role', 'group');
        var bar = document.createElement('div');
        bar.className = 'xs-section-bar';
        var clearAll = document.createElement('button');
        clearAll.type = 'button';
        clearAll.className = 'xs-clear-all';
        clearAll.textContent = Drupal.t('清空');
        clearAll.setAttribute('aria-label', Drupal.t('清空当前场景搜索历史'));
        clearAll.addEventListener('click', function (event) {
          event.preventDefault();
          clearAllHistory();
        });
        bar.appendChild(clearAll);
        section.appendChild(bar);
        var list = document.createElement('div');
        list.className = 'xs-history';
        rows.forEach(function (item) {
          var row = document.createElement('div');
          row.className = 'xs-item';
          var button = document.createElement('button');
          button.type = 'button';
          button.textContent = item.q || item.label;
          button.addEventListener('click', function () { activate(item); });
          var remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'xs-del';
          remove.textContent = '×';
          remove.setAttribute('aria-label', Drupal.t('删除'));
          remove.addEventListener('click', function (event) {
            event.stopPropagation();
            forget(item);
            openIdle();
          });
          row.appendChild(button);
          row.appendChild(remove);
          list.appendChild(row);
          items.push(item);
        });
        section.appendChild(list);
        panel.appendChild(section);
        return true;
      }

      function appendHot(afterHistory) {
        var tags = (hotItems || []).slice(0, IDLE_HOT);
        if (!tags.length) {
          return false;
        }
        var section = document.createElement('div');
        section.className = 'xs-section xs-section--hot' + (afterHistory ? ' xs-section--sep' : '');
        section.setAttribute('role', 'group');
        var wrap = document.createElement('div');
        wrap.className = 'xs-hot';
        tags.forEach(function (item) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'xs-hot-tag';
          button.textContent = item.label;
          button.addEventListener('click', function () { activate(item); });
          wrap.appendChild(button);
          items.push(item);
        });
        section.appendChild(wrap);
        panel.appendChild(section);
        return true;
      }

      var hasHistory = appendHistory(false);
      var hasHot = appendHot(hasHistory);
      if (!hasHistory && !hasHot) {
        close();
        return;
      }
      openPanel();
    }

    function clearAllHistory() {
      if (cfg.loggedIn && cfg.history) {
        var clearUrl = endpoint('history') + '?type=' + encodeURIComponent(apiType(meta.mode));
        csrf().then(function (token) {
          fetch(clearUrl, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: token ? { 'X-CSRF-Token': token } : {},
          }).finally(function () { openIdle(); });
        });
        return;
      }
      writeHistory(meta.mode, []);
      openIdle();
    }

    function forget(item) {
      if (item.id && cfg.loggedIn) {
        csrf().then(function (token) {
          fetch(endpoint('history') + '/' + item.id, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: token ? { 'X-CSRF-Token': token } : {},
          }).catch(function () {});
        });
      }
      writeHistory(meta.mode, readHistory(meta.mode).filter(function (row) { return row.q !== (item.q || item.label); }));
    }

    function historyUrl(item) {
      var type = item.type || apiType(meta.mode);
      var query = '?q=' + encodeURIComponent(item.q || item.label);
      if (type && type !== 'all') {
        query += '&type=' + encodeURIComponent(type);
      }
      return endpoint('search') + query;
    }

    function openIdle() {
      var q = typedValue(input).trim();
      if (q.length > 0) {
        close();
        return;
      }
      var local = cfg.history && !cfg.loggedIn ? readHistory(meta.mode).slice(0, IDLE_HISTORY).map(function (item) {
        return { q: item.q, label: item.q, type: item.type, kind: 'history', url: historyUrl(item) };
      }) : [];
      if (cfg.loggedIn && cfg.history) {
        var historyUrlApi = endpoint('history') + '?type=' + encodeURIComponent(apiType(meta.mode)) + '&limit=' + IDLE_HISTORY;
        fetch(historyUrlApi, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
          .then(function (response) { return response.json(); })
          .then(function (json) {
            var remote = json && json.ok && json.data && json.data.storage === 'account' ? json.data.items : [];
            remote.forEach(function (item) {
              item.kind = 'history';
              item.label = item.q;
              if (!item.url) {
                item.url = historyUrl(item);
              }
            });
            loadHot(remote);
          })
          .catch(function () { loadHot([]); });
      }
      else {
        loadHot(local);
      }
    }

    function loadHot(historyItems) {
      if (!cfg.hot) {
        render(historyItems, []);
        return;
      }
      var hotUrl = endpoint('hot') + '?type=' + encodeURIComponent(apiType(meta.mode)) + '&limit=' + IDLE_HOT;
      fetch(hotUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          render(historyItems, json && json.ok ? json.data.items : []);
        })
        .catch(function () { render(historyItems, []); });
    }

    function schedule() {
      var q = typedValue(input).trim();
      if (q.length > 0) {
        close();
        return;
      }
      if (document.activeElement === input) {
        openIdle();
      }
      else {
        close();
      }
    }

    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.addEventListener('compositionstart', function () { composing = true; });
    input.addEventListener('compositionend', function () { composing = false; schedule(); });
    input.addEventListener('input', function () {
      syncClear();
      if (!composing) {
        schedule();
      }
    });
    input.addEventListener('focus', function () { schedule(); });
    input.addEventListener('keydown', function (event) {
      if (panel.hidden) {
        if (event.key === 'Enter' && meta.target === 'local') {
          var value = typedValue(input).trim();
          if (value.length >= (cfg.minLength || 1)) {
            event.preventDefault();
            remember(value, meta.mode);
            track(value, meta.mode);
            window.location.href = endpoint('search') + '?q=' + encodeURIComponent(value) + '&type=' + encodeURIComponent(meta.mode === 'model' ? 'model' : 'photo');
          }
        }
        return;
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!items.length) {
          return;
        }
        active = event.key === 'ArrowDown' ? (active + 1) % items.length : (active - 1 + items.length) % items.length;
        markActive();
      }
      else if (event.key === 'Enter') {
        if (active >= 0) {
          event.preventDefault();
          activate(items[active]);
        }
        else if (meta.target === 'local') {
          var pending = typedValue(input).trim();
          if (pending.length >= (cfg.minLength || 1)) {
            event.preventDefault();
            remember(pending, meta.mode);
            track(pending, meta.mode);
            window.location.href = endpoint('search') + '?q=' + encodeURIComponent(pending) + '&type=' + encodeURIComponent(meta.mode === 'model' ? 'model' : 'photo');
          }
        }
      }
      else if (event.key === 'Escape') {
        dropCompletion();
        syncClear();
        close();
      }
    });
    panel.addEventListener('mousedown', function (event) { event.preventDefault(); });

    if (meta.form) {
      meta.form.addEventListener('submit', function () {
        var q = input.value.trim();
        remember(q, meta.mode);
        track(q, meta.mode);
      });
    }

    if (!input.classList.contains('sr-input') && !wrap.querySelector('.xs-clear')) {
      var clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'xs-clear';
      clear.setAttribute('aria-label', Drupal.t('清空'));
      clear.textContent = '×';
      clear.addEventListener('click', function () {
        input.value = '';
        syncClear();
        input.focus();
        openIdle();
      });
      wrap.appendChild(clear);
      syncClear = function () {
        var on = input.value.length > 0;
        clear.classList.toggle('is-on', on);
        input.classList.toggle('xs-pad', on);
      };
      syncClear();
    }

    if (input.value && meta.form && (meta.form.classList.contains('sr-form') || meta.form.classList.contains('xv-search') || meta.form.classList.contains('xa-search') || meta.form.classList.contains('xt-toolbar'))) {
      var stamp = 'xedc-search-tracked:' + window.location.pathname + window.location.search;
      try {
        if (!sessionStorage.getItem(stamp)) {
          sessionStorage.setItem(stamp, '1');
          track(input.value, meta.mode);
        }
      }
      catch (e) {
        track(input.value, meta.mode);
      }
    }

    window.addEventListener('resize', function () { if (!panel.hidden) { place(panel, input, mount, useFixed); } });
    window.addEventListener('scroll', function () { if (!panel.hidden) { place(panel, input, mount, useFixed); } }, true);
    document.addEventListener('pointerdown', function (event) {
      if (event.target === input || panel.contains(event.target)) {
        return;
      }
      close();
    });
    seq += 1;
    panel.id = 'xs-panel-' + seq;
    input.setAttribute('aria-controls', panel.id);
  }

})(Drupal, drupalSettings, once);
