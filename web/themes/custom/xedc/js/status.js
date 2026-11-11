/**
 * @file
 * Interaction for XEDC status pages: back, reload, Retry-After countdown.
 */
(function (Drupal) {
  'use strict';

  var motionBound = false;

  function readyPages(root) {
    var found = [];
    if (!root || root === document || root === document.documentElement || root === document.body) {
      return Array.prototype.slice.call(document.querySelectorAll('.xs:not([data-xs-ready])'));
    }
    if (root.nodeType === 1 && root.classList && root.classList.contains('xs') && !root.hasAttribute('data-xs-ready')) {
      found.push(root);
    }
    if (root.querySelectorAll) {
      found = found.concat(Array.prototype.slice.call(root.querySelectorAll('.xs:not([data-xs-ready])')));
    }
    return found;
  }

  function prefersReduce() {
    try {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
    catch (e) {
      return false;
    }
  }

  function bindMotion() {
    if (motionBound) {
      return;
    }
    motionBound = true;
    if (prefersReduce()) {
      return;
    }
    function sync() {
      document.documentElement.classList.toggle('xs-paused', document.hidden);
    }
    document.addEventListener('visibilitychange', sync);
    sync();
  }

  function formatWait(seconds) {
    if (seconds >= 60) {
      var minutes = Math.floor(seconds / 60);
      var rest = seconds % 60;
      return minutes + ' 分 ' + rest + ' 秒';
    }
    return seconds + ' 秒';
  }

  function bindWait(page) {
    var hold = page.querySelector('[data-xs-wait]');
    if (!hold) {
      return;
    }
    var total = parseInt(hold.getAttribute('data-xs-wait'), 10);
    if (!total || total < 1) {
      return;
    }
    var link = page.querySelector('[data-xs-retry]');
    var template = hold.getAttribute('data-xs-wait-tpl') || '请等待 @count';
    var done = hold.getAttribute('data-xs-wait-done') || '可以重新尝试了';
    var ends = Date.now() + (total * 1000);
    if (link) {
      link.classList.add('is-disabled');
      link.setAttribute('aria-disabled', 'true');
    }
    function paint(left) {
      hold.textContent = left > 0 ? template.replace('@count', formatWait(left)) : done;
    }
    function tick() {
      var left = Math.max(0, Math.ceil((ends - Date.now()) / 1000));
      paint(left);
      if (left <= 0) {
        if (link) {
          link.classList.remove('is-disabled');
          link.removeAttribute('aria-disabled');
        }
        return;
      }
      window.setTimeout(tick, 250);
    }
    tick();
  }

  function bindReload(page) {
    page.querySelectorAll('[data-xs-reload]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        if (link.dataset.xsSent === '1') {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        link.dataset.xsSent = '1';
        window.setTimeout(function () {
          window.location.reload();
        }, 180);
      });
    });
  }

  function bindIcons(page) {
    if (prefersReduce()) {
      return;
    }
    page.classList.add('is-run');
  }

  function init(root) {
    var pages = readyPages(root);
    if (!pages.length) {
      return;
    }
    bindMotion();
    pages.forEach(function (page) {
      page.setAttribute('data-xs-ready', '1');
      page.querySelectorAll('[data-xs-back]').forEach(function (button) {
        button.addEventListener('click', function () {
          var home = button.getAttribute('data-xs-home') || '/';
          if (window.history.length > 1) {
            window.history.back();
            return;
          }
          window.location.href = home;
        });
      });
      page.addEventListener('click', function (event) {
        var link = event.target.closest('[aria-disabled="true"]');
        if (link) {
          event.preventDefault();
        }
      });
      bindWait(page);
      bindReload(page);
      bindIcons(page);
    });
  }

  if (Drupal && Drupal.behaviors) {
    Drupal.behaviors.xedcStatus = {
      attach: function (context) {
        init(context);
      }
    };
  }
  else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      init(document);
    });
  }
  else {
    init(document);
  }
})(typeof Drupal === 'undefined' ? null : Drupal);
