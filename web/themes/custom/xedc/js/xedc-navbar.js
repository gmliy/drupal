(function () {
  var d = document, h = d.documentElement;
  function qa(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }

  // 主题：light → dark → system（跟随系统）
  function resolveTheme(pref) {
    if (pref === 'dark' || pref === 'light') {
      return pref;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function applyTheme(pref, persist) {
    var resolved = resolveTheme(pref);
    h.setAttribute('data-bs-theme', resolved);
    h.setAttribute('data-theme', resolved);
    h.setAttribute('data-theme-pref', pref === 'dark' || pref === 'light' ? pref : 'system');
    if (persist) {
      try { localStorage.setItem('theme', pref); } catch (e) {}
    }
  }
  var themeOrder = ['light', 'dark', 'system'];
  function nextPref(cur) {
    var i = themeOrder.indexOf(cur);
    if (i < 0) {
      return 'system';
    }
    return themeOrder[(i + 1) % themeOrder.length];
  }
  try {
    var pref = localStorage.getItem('theme');
    if (pref !== 'dark' && pref !== 'light' && pref !== 'system') {
      pref = 'system';
    }
    applyTheme(pref, false);
  } catch (e) {}
  try {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      var p = localStorage.getItem('theme');
      if (p !== 'dark' && p !== 'light') {
        applyTheme('system', false);
      }
    });
  } catch (e) {}
  qa('[data-xn-theme]').forEach(function (b) {
    b.addEventListener('click', function () {
      var cur = h.getAttribute('data-theme-pref') || localStorage.getItem('theme') || 'system';
      if (cur !== 'dark' && cur !== 'light' && cur !== 'system') {
        cur = 'system';
      }
      applyTheme(nextPref(cur), true);
    });
  });

  // 抽屉与遮罩挂到 body，避免被父级 transform 影响 fixed 定位
  var sheet = d.getElementById('xn-sheet');
  var overlay = d.querySelector('.xn-overlay');
  if (overlay) d.body.appendChild(overlay);
  if (sheet) d.body.appendChild(sheet);

  function openSheet() { h.classList.add('xn-open'); if (sheet) sheet.setAttribute('aria-hidden', 'false'); }
  function closeSheet() { h.classList.remove('xn-open'); if (sheet) sheet.setAttribute('aria-hidden', 'true'); }
  qa('[data-xn-open]').forEach(function (b) { b.addEventListener('click', openSheet); });
  qa('[data-xn-close]').forEach(function (b) { b.addEventListener('click', closeSheet); });

  // 抽屉手风琴（默认收起）
  qa('.xn-chev').forEach(function (b) {
    b.addEventListener('click', function () {
      var acc = b.closest('.xn-acc');
      var on = acc.classList.toggle('open');
      b.setAttribute('aria-expanded', on ? 'true' : 'false');
    });
  });

  // PC 下拉：悬停（CSS）或点击箭头
  var dds = qa('.xn-dd');
  function closeDD() {
    dds.forEach(function (x) {
      x.classList.remove('open');
      x.querySelector('.xn-dd-btn').setAttribute('aria-expanded', 'false');
    });
  }
  dds.forEach(function (dd) {
    var b = dd.querySelector('.xn-dd-btn');
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var on = !dd.classList.contains('open');
      closeDD();
      closeLang();
      if (on) { dd.classList.add('open'); b.setAttribute('aria-expanded', 'true'); }
    });
  });

  var langs = qa('.xn-lang');
  function closeLang() {
    langs.forEach(function (el) { setLangOpen(el, false); });
  }
  function setLangOpen(el, on) {
    var langBtn = el.querySelector('.xn-lang-btn');
    var langMenu = el.querySelector('.xn-lang-menu');
    var sheetLang = el.classList.contains('xn-lang--sheet');
    el.classList.toggle('open', on);
    if (langBtn) langBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (!langMenu) return;
    langMenu.setAttribute('aria-hidden', on ? 'false' : 'true');
    if (!sheetLang) langMenu.hidden = !on;
  }
  langs.forEach(function (el) {
    var langBtn = el.querySelector('.xn-lang-btn');
    if (!langBtn) return;
    langBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var on = !el.classList.contains('open');
      closeLang();
      closeDD();
      if (on) setLangOpen(el, true);
    });
  });
  d.addEventListener('click', function (e) {
    if (!e.target.closest('.xn-dd')) closeDD();
    if (!e.target.closest('.xn-lang')) closeLang();
  });
  d.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSheet(); closeDD(); closeLang(); } });
  window.addEventListener('resize', function () { if (window.innerWidth >= 992) closeSheet(); });

  // 手机搜索
  var hd = d.querySelector('[data-xn]');
  qa('[data-xn-search]').forEach(function (b) {
    b.addEventListener('click', function () {
      var on = hd.classList.toggle('xn-search-open');
      if (on) { var i = hd.querySelector('.xn-msearch input'); if (i) i.focus(); }
    });
  });

  // 会员头像：按下时保持一小段缩放，避免点按过快看不出反馈
  qa('.xn-account').forEach(function (a) {
    var timer;
    a.addEventListener('pointerdown', function () {
      a.classList.add('is-pressed');
      clearTimeout(timer);
      timer = setTimeout(function () { a.classList.remove('is-pressed'); }, 180);
    });
  });

  // 当前页高亮
  var cur = location.pathname.replace(/\/$/, '') || '/';
  qa('.xn-link,.xn-m-link,.xn-m-sub').forEach(function (a) {
    var p = a.pathname.replace(/\/$/, '') || '/';
    if (p === cur || (p !== '/' && cur.indexOf(p + '/') === 0)) a.setAttribute('aria-current', 'page');
  });
})();
