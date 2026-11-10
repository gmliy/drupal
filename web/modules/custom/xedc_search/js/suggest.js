/**
 * @file
 * Attaches suggestions to existing search fields without moving them.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var cfg = drupalSettings.xedcSearch || {};
  var seq = 0;
  var tokenPromise = null;
  var HISTORY = 'xedc_search_history_v1';
  var VISITOR = 'xedc_search_vid';

  function endpoint(name) {
    return (cfg.endpoints && cfg.endpoints[name]) || '';
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

  function readHistory() {
    try {
      var data = JSON.parse(localStorage.getItem(HISTORY) || '[]');
      return Array.isArray(data) ? data : [];
    }
    catch (e) {
      return [];
    }
  }

  function writeHistory(items) {
    try {
      localStorage.setItem(HISTORY, JSON.stringify(items.slice(0, 10)));
    }
    catch (e) {}
  }

  function remember(q, type) {
    q = (q || '').trim();
    if (!q) {
      return;
    }
    var items = readHistory().filter(function (item) {
      return item.q !== q;
    });
    items.unshift({ q: q, type: type || 'all', t: Date.now() });
    writeHistory(items);
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

  function place(panel, input) {
    var anchor = input.closest('.photo-drop') || input;
    var rect = anchor.getBoundingClientRect();
    if (!rect.width && !rect.height) {
      panel.hidden = true;
      return;
    }
    var maxWidth = window.innerWidth - 16;
    var width = Math.min(Math.max(rect.width, 260), maxWidth);
    var left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);
    panel.style.left = left + 'px';
    panel.style.width = width + 'px';
    var panelHeight = panel.offsetHeight || 240;
    var below = window.innerHeight - rect.bottom - 12;
    var above = rect.top - 12;
    if (below < 180 && above > below) {
      var room = Math.max(120, above - 8);
      panel.style.maxHeight = Math.min(room, window.innerHeight * 0.5) + 'px';
      panel.style.top = Math.max(8, rect.top - Math.min(panel.offsetHeight || panelHeight, room) - 6) + 'px';
    }
    else {
      panel.style.maxHeight = Math.max(120, Math.min(window.innerHeight * 0.7, below)) + 'px';
      panel.style.top = (rect.bottom + 6) + 'px';
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
    var panel = document.createElement('div');
    panel.className = 'xs-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'listbox');
    document.body.appendChild(panel);
    var items = [];
    var active = -1;
    var timer = 0;
    var requestId = 0;
    var composing = false;
    var controller = null;
    var syncClear = function () {};

    function close() {
      panel.hidden = true;
      active = -1;
      input.setAttribute('aria-expanded', 'false');
    }

    function markActive() {
      Array.prototype.forEach.call(panel.querySelectorAll('.xs-item, .xs-complete'), function (el, index) {
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

    function render(payload, historyItems, hotItems) {
      clearNode(panel);
      items = [];
      active = -1;
      var query = payload && payload.q ? payload.q : '';
      if (payload && payload.completion && payload.completion.text && cfg.complete) {
        var completion = document.createElement('button');
        completion.type = 'button';
        completion.className = 'xs-complete';
        var completionText = document.createElement('span');
        fillMatch(completionText, payload.completion.text, query, true);
        completion.appendChild(completionText);
        var hint = document.createElement('span');
        hint.className = 'xs-kind';
        hint.textContent = 'Tab';
        completion.appendChild(hint);
        var entry = { label: payload.completion.text, kind: 'complete', url: endpoint('search') + '?q=' + encodeURIComponent(payload.completion.text) };
        completion.addEventListener('click', function () { activate(entry); });
        panel.appendChild(completion);
        items.push(entry);
      }
      (payload && payload.groups || []).forEach(function (group) {
        var title = document.createElement('div');
        title.className = 'xs-label';
        title.textContent = group.label;
        panel.appendChild(title);
        (group.items || []).forEach(function (item) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'xs-item';
          var image = item.image ? safeImage(item.image) : '';
          if (image) {
            var img = document.createElement('img');
            img.className = 'xs-thumb';
            img.alt = '';
            img.src = image;
            button.appendChild(img);
          }
          var text = document.createElement('span');
          fillMatch(text, item.label, query, false);
          button.appendChild(text);
          button.addEventListener('click', function () { activate(item); });
          panel.appendChild(button);
          items.push(item);
        });
      });
      if (historyItems && historyItems.length) {
        var historyTitle = document.createElement('div');
        historyTitle.className = 'xs-label';
        historyTitle.textContent = Drupal.t('最近搜索');
        panel.appendChild(historyTitle);
        historyItems.forEach(function (item) {
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
          panel.appendChild(row);
          items.push(item);
        });
      }
      if (hotItems && hotItems.length) {
        var hotTitle = document.createElement('div');
        hotTitle.className = 'xs-label';
        hotTitle.textContent = Drupal.t('热门搜索');
        panel.appendChild(hotTitle);
        hotItems.forEach(function (item) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'xs-item';
          button.textContent = item.label;
          button.addEventListener('click', function () { activate(item); });
          panel.appendChild(button);
          items.push(item);
        });
      }
      if (!items.length) {
        if (query) {
          status(panel, Drupal.t('没有匹配的建议'), false);
        }
        else {
          close();
        }
        return;
      }
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      place(panel, input);
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
      writeHistory(readHistory().filter(function (row) { return row.q !== (item.q || item.label); }));
    }

    function openIdle() {
      var q = typedValue(input).trim();
      if (q.length >= (cfg.minLength || 1)) {
        schedule();
        return;
      }
      var local = cfg.history ? readHistory().map(function (item) {
        return { q: item.q, label: item.q, type: item.type, kind: 'history', url: endpoint('search') + '?q=' + encodeURIComponent(item.q) };
      }) : [];
      if (cfg.loggedIn && cfg.history) {
        fetch(endpoint('history'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) { return response.json(); })
          .then(function (json) {
            var remote = json && json.ok && json.data && json.data.storage === 'account' ? json.data.items : [];
            remote.forEach(function (item) { item.kind = 'history'; item.label = item.q; });
            loadHot(remote.length ? remote : local);
          })
          .catch(function () { loadHot(local); });
      }
      else {
        loadHot(local);
      }
    }

    function loadHot(historyItems) {
      if (!cfg.hot) {
        render(null, historyItems, []);
        return;
      }
      fetch(endpoint('hot'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json(); })
        .then(function (json) {
          render(null, historyItems, json && json.ok ? json.data.items : []);
        })
        .catch(function () { render(null, historyItems, []); });
    }

    function schedule() {
      window.clearTimeout(timer);
      var q = typedValue(input).trim();
      if (!cfg.suggest || q.length < (cfg.minLength || 1)) {
        if (document.activeElement === input) {
          openIdle();
        }
        else {
          close();
        }
        return;
      }
      timer = window.setTimeout(function () { fetchSuggest(q); }, cfg.debounce || 250);
    }

    function fetchSuggest(q) {
      if (controller) {
        controller.abort();
      }
      controller = typeof AbortController === 'function' ? new AbortController() : null;
      var id = ++requestId;
      status(panel, Drupal.t('正在查找…'), false);
      place(panel, input);
      var url = endpoint('suggest') + '?q=' + encodeURIComponent(q) + '&type=' + encodeURIComponent(meta.mode);
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('http');
          }
          return response.json();
        })
        .then(function (json) {
          if (id !== requestId || typedValue(input).trim() !== q) {
            return;
          }
          if (!json || !json.ok) {
            status(panel, Drupal.t('无法获取建议，请直接搜索'), true);
            return;
          }
          render(json.data, [], []);
          applyCompletion(q, json.data && json.data.completion);
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') {
            return;
          }
          if (id === requestId) {
            status(panel, Drupal.t('网络异常，请直接搜索'), true);
          }
        });
    }

    function applyCompletion(q, completion) {
      if (!cfg.complete || !completion || !completion.text || meta.target === 'local') {
        return;
      }
      if (!completion.suffix || completion.text.indexOf(q) !== 0) {
        return;
      }
      if (typedValue(input).trim() !== q || input.value !== q) {
        return;
      }
      input.value = completion.text;
      if (input.setSelectionRange) {
        input.setSelectionRange(q.length, completion.text.length);
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
        else {
          dropCompletion();
        }
      }
      else if ((event.key === 'Tab' || event.key === 'ArrowRight') && cfg.complete) {
        if (input.selectionEnd > input.selectionStart) {
          event.preventDefault();
          input.setSelectionRange(input.value.length, input.value.length);
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
        dropCompletion();
        var q = input.value.trim();
        remember(q, meta.mode);
        track(q, meta.mode);
      });
    }

    var clearHost = input.closest('form.xn-search, form.xn-msearch, form.xa-search, .xm-search');
      if (clearHost && !input.classList.contains('sr-input') && !clearHost.querySelector('.xs-clear')) {
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
      clearHost.appendChild(clear);
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

    window.addEventListener('resize', function () { if (!panel.hidden) { place(panel, input); } });
    window.addEventListener('scroll', function () { if (!panel.hidden) { place(panel, input); } }, true);
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
