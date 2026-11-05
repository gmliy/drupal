(function () {
  'use strict';

  var loadingTimers = new WeakMap();

  function endFavorite(form) {
    if (!form) return;
    delete form.dataset.xedcFavoriteLoading;
    form.removeAttribute('aria-busy');
    var timer = loadingTimers.get(form);
    if (timer) {
      window.clearTimeout(timer);
      loadingTimers.delete(form);
    }
  }

  function beginFavorite(form) {
    if (form.dataset.xedcFavoriteLoading === '1') return false;
    form.dataset.xedcFavoriteLoading = '1';
    form.setAttribute('aria-busy', 'true');
    loadingTimers.set(form, window.setTimeout(function () {
      loadingTimers.delete(form);
      endFavorite(form);
    }, 15000));
    return true;
  }

  function blurMemberActionFocus() {
    var active = document.activeElement;
    if (!active || active === document.body || !active.closest) return;
    if (active.matches('.xedc-favorite-btn, .xedc-history-delete-btn') || active.closest('.xedc-favorite-form, .xedc-history-delete-form')) {
      active.blur();
    }
  }

  document.addEventListener('mousedown', function (event) {
    var button = event.target.closest('.xedc-favorite-btn, .xedc-history-delete-btn');
    if (!button) return;
    event.preventDefault();
  }, true);

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.xedc-favorite-btn');
    if (!button) return;
    var form = button.closest('form.xedc-favorite-form');
    if (!form) return;
    if (form.dataset.xedcFavoriteLoading === '1') {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    beginFavorite(form);
  }, true);

  document.addEventListener('submit', function (event) {
    var form = event.target && event.target.closest ? event.target.closest('form.xedc-favorite-form') : null;
    if (!form || form.dataset.xedcFavoriteLoading === '1') return;
    beginFavorite(form);
  }, true);

  if (window.jQuery) {
    window.jQuery(document).on('ajaxComplete', function (event, xhr, settings) {
      var data = typeof settings.data === 'string' ? settings.data : '';
      var isFavorite = data.indexOf('xedc_member_favorite_toggle') !== -1;
      var isHistory = data.indexOf('xedc_member_history_delete') !== -1;
      if (!isFavorite && !isHistory) return;
      if (isFavorite) {
        document.querySelectorAll('form.xedc-favorite-form[data-xedc-favorite-loading]').forEach(endFavorite);
      }
      blurMemberActionFocus();
      window.setTimeout(blurMemberActionFocus, 0);
    });
  }

  function revealActiveMemberTab() {
    var nav = document.querySelector('[data-xm-nav]');
    if (!nav) return;
    var active = nav.querySelector('.xm-nav__item.is-active');
    if (!active) return;
    if (nav.scrollWidth <= nav.clientWidth + 1) return;
    var navRect = nav.getBoundingClientRect();
    var itemRect = active.getBoundingClientRect();
    var delta = itemRect.left - navRect.left - ((nav.clientWidth - itemRect.width) / 2);
    if (Math.abs(delta) < 2) return;
    nav.scrollLeft += delta;
  }

  function paintAliasStatus(status, result) {
    status.textContent = result.message || '';
    status.classList.remove('is-ok', 'is-bad', 'is-wait');
    if (result.status === 'available' || result.status === 'empty') {
      status.classList.add('is-ok');
    }
    else if (result.status === 'checking') {
      status.classList.add('is-wait');
    }
    else if (result.message) {
      status.classList.add('is-bad');
    }
  }

  function bindAliasCheck() {
    var input = document.querySelector('[data-xm-alias]');
    var status = document.querySelector('[data-xm-alias-status]');
    if (!input || !status || input.dataset.xmAliasBound === '1') return;
    input.dataset.xmAliasBound = '1';
    var settings = window.drupalSettings || {};
    var endpoint = settings.xedcMember && settings.xedcMember.aliasCheck;
    if (!endpoint) return;
    var timer = 0;
    var seq = 0;

    function run() {
      var value = input.value;
      var current = ++seq;
      if (value.trim() === '') {
        paintAliasStatus(status, { status: 'empty', message: '' });
        return;
      }
      paintAliasStatus(status, { status: 'checking', message: '检查中' });
      var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(value);
      window.fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (response) {
        if (!response.ok) throw new Error('alias');
        return response.json();
      }).then(function (data) {
        if (current !== seq) return;
        paintAliasStatus(status, data || { status: 'invalid', message: '暂时无法检查' });
      }).catch(function () {
        if (current !== seq) return;
        paintAliasStatus(status, { status: 'invalid', message: '暂时无法检查' });
      });
    }

    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(run, 280);
    });
    if (input.value.trim() !== '') run();
  }

  function bindAvatarUpload() {
    var input = document.getElementById('xm-avatar-input');
    if (!input || input.dataset.xmBound === '1') return;
    input.dataset.xmBound = '1';
    input.addEventListener('change', function () {
      if (!input.files || !input.files.length || !input.form) return;
      var submit = input.form.querySelector('.xm-form-actions [type="submit"], .form-actions [type="submit"]');
      if (submit && input.form.requestSubmit) {
        input.form.requestSubmit(submit);
      }
      else {
        input.form.submit();
      }
    });
  }

  function bindMemberDrawer() {
    var root = document.querySelector('[data-xm-root]');
    if (!root || root.dataset.xmDrawerBound === '1') return;
    var drawer = root.querySelector('[data-xm-drawer]');
    var backdrop = root.querySelector('[data-xm-backdrop]');
    var openBtn = root.querySelector('[data-xm-open]');
    var closeBtn = root.querySelector('[data-xm-close]') || (drawer && drawer.querySelector('[data-xm-close]'));
    if (!drawer || !backdrop || !openBtn) return;
    root.dataset.xmDrawerBound = '1';

    var mobileQuery = window.matchMedia('(max-width: 1023px)');
    var placeholder = document.createElement('span');
    placeholder.hidden = true;
    placeholder.setAttribute('data-xm-drawer-slot', '');
    drawer.before(placeholder);

    function isMobile() {
      return mobileQuery.matches;
    }

    function placeDrawer() {
      if (isMobile()) {
        if (backdrop.parentNode !== document.body) document.body.appendChild(backdrop);
        if (drawer.parentNode !== document.body) document.body.appendChild(drawer);
        backdrop.hidden = false;
      }
      else {
        setOpen(false);
        if (placeholder.parentNode) {
          placeholder.after(drawer);
          drawer.after(backdrop);
        }
        backdrop.hidden = true;
        drawer.removeAttribute('aria-hidden');
      }
    }

    function setOpen(open) {
      if (!isMobile()) open = false;
      document.documentElement.classList.toggle('xm-nav-open', open);
      openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (isMobile()) {
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        backdrop.hidden = false;
      }
      if (!open) return;
      var active = drawer.querySelector('.xm-nav__item.is-active') || drawer.querySelector('a, button');
      if (active && active.focus) active.focus();
    }

    openBtn.addEventListener('click', function () {
      setOpen(!document.documentElement.classList.contains('xm-nav-open'));
    });
    backdrop.addEventListener('click', function () {
      setOpen(false);
      openBtn.focus();
    });
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        setOpen(false);
        openBtn.focus();
      });
    }
    drawer.addEventListener('click', function (event) {
      if (event.target.closest('a')) setOpen(false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && document.documentElement.classList.contains('xm-nav-open')) {
        setOpen(false);
        openBtn.focus();
      }
    });
    if (mobileQuery.addEventListener) {
      mobileQuery.addEventListener('change', placeDrawer);
    }
    else if (mobileQuery.addListener) {
      mobileQuery.addListener(placeDrawer);
    }
    placeDrawer();
  }

  function bootMemberUi() {
    revealActiveMemberTab();
    bindAvatarUpload();
    bindAliasCheck();
    bindMemberDrawer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootMemberUi);
  }
  else {
    bootMemberUi();
  }
})();
