/**
 * @file
 * Pixel scenes for the six XEDC status pages.
 *
 * One traveler sprite. Stories advance on timers so they can pause while
 * the tab is hidden. Retry-After still comes only from the server.
 */
(function (Drupal) {
  'use strict';

  var motionBound = false;
  var WALK = [2, 3, 4, 5];

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

  function setTheme(mode, persist) {
    var root = document.documentElement;
    root.setAttribute('data-bs-theme', mode);
    root.setAttribute('data-theme', mode);
    if (persist) {
      try {
        localStorage.setItem('theme', mode);
      }
      catch (e) {}
    }
  }

  function currentTheme() {
    var mode = document.documentElement.getAttribute('data-bs-theme');
    return mode === 'dark' ? 'dark' : 'light';
  }

  function prefersReduce() {
    try {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
    catch (e) {
      return false;
    }
  }

  function paused() {
    return document.hidden || document.documentElement.classList.contains('xs-paused');
  }

  function pace() {
    var slow = false;
    try {
      slow = window.matchMedia('(pointer: coarse)').matches;
    }
    catch (e) {}
    if (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 4) {
      slow = true;
    }
    return slow ? 170 : 120;
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
    var queue = page.classList.contains('xs-429') ? page : page.querySelector('.xs-429');
    var template = hold.getAttribute('data-xs-wait-tpl') || '请等待 @count';
    var done = hold.getAttribute('data-xs-wait-done') || '可以重新尝试了';
    var ends = Date.now() + (total * 1000);
    if (queue) {
      queue.classList.add('is-holding');
    }
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
        if (queue) {
          queue.classList.remove('is-holding');
          queue.classList.add('is-ready');
        }
        return;
      }
      window.setTimeout(tick, 250);
    }
    tick();
  }

  function Seq() {
    this.timers = [];
    this.dead = false;
  }

  Seq.prototype.clear = function () {
    var i;
    for (i = 0; i < this.timers.length; i++) {
      window.clearTimeout(this.timers[i]);
    }
    this.timers = [];
    this.dead = true;
  };

  Seq.prototype.after = function (ms, fn) {
    var seq = this;
    var start = Date.now();
    function tick() {
      if (seq.dead) {
        return;
      }
      if (paused()) {
        start = Date.now();
        seq.timers.push(window.setTimeout(tick, 200));
        return;
      }
      var wait = ms - (Date.now() - start);
      if (wait > 16) {
        seq.timers.push(window.setTimeout(tick, wait));
        return;
      }
      fn();
    }
    this.timers.push(window.setTimeout(tick, ms));
  };

  function frame(el, n) {
    el.style.setProperty('--f', String(n));
  }

  function face(el, dir) {
    el.style.setProperty('--face', dir < 0 ? '-1' : '1');
  }

  function place(el, x) {
    el.style.left = x + 'px';
  }

  function walkTo(seq, el, from, to, done) {
    var x = from;
    var step = to >= from ? 5 : -5;
    var i = 0;
    face(el, step > 0 ? 1 : -1);
    place(el, x);
    function hop() {
      if (seq.dead) {
        return;
      }
      frame(el, WALK[i % 4]);
      i += 1;
      x += step;
      if ((step > 0 && x >= to) || (step < 0 && x <= to)) {
        place(el, to);
        frame(el, 0);
        done();
        return;
      }
      place(el, x);
      seq.after(pace(), hop);
    }
    hop();
  }

  function bindScenes(page) {
    var stage = page.querySelector('.xp');
    var el = page.querySelector('[data-xp-actor]');
    if (!stage || !el) {
      return;
    }
    var kind = stage.getAttribute('data-xp');
    var seq = new Seq();
    if (kind === '404') {
      bindLost(page, stage, el, seq);
    }
    else if (kind === '403') {
      bindWall(stage, el, seq);
    }
    else if (kind === '500') {
      bindFault(stage, el, seq);
    }
    else if (kind === '502') {
      bindSignal(page, stage, el, seq);
    }
    else if (kind === '503') {
      bindRepair(stage, el, seq);
    }
    else if (kind === '429') {
      bindQueue(page, stage, el, seq);
    }
  }

  function bindLost(page, stage, el, seq) {
    var ask = stage.querySelector('.xp-ask');
    var lookTimer = 0;
    place(el, -36);
    face(el, 1);
    walkTo(seq, el, -36, 72, function () {
      seq.after(240, function () {
        frame(el, 0);
        if (ask) {
          ask.classList.add('is-on');
        }
        seq.after(320, function () {
          face(el, -1);
          frame(el, 6);
          seq.after(420, function () {
            face(el, 1);
            frame(el, 6);
            seq.after(420, function () {
              frame(el, 9);
              face(el, 1);
              stage.dataset.xpPhase = 'sit';
            });
          });
        });
      });
    });
    stage.addEventListener('pointermove', function (event) {
      if (stage.dataset.xpPhase !== 'sit') {
        return;
      }
      var box = stage.getBoundingClientRect();
      face(el, event.clientX < box.left + box.width / 2 ? -1 : 1);
      frame(el, 6);
      window.clearTimeout(lookTimer);
      lookTimer = window.setTimeout(function () {
        if (stage.dataset.xpPhase === 'sit') {
          frame(el, 9);
        }
      }, 520);
    });
    var home = page.querySelector('[data-xp-leave]');
    if (home) {
      home.addEventListener('click', function (event) {
        if (home.dataset.xpGone === '1') {
          return;
        }
        event.preventDefault();
        home.dataset.xpGone = '1';
        stage.dataset.xpPhase = 'leave';
        if (ask) {
          ask.classList.remove('is-on');
        }
        seq.clear();
        seq = new Seq();
        var href = home.getAttribute('href') || '/';
        walkTo(seq, el, parseInt(el.style.left, 10) || 72, 176, function () {
          window.location.href = href;
        });
      });
    }
  }

  function bindWall(stage, el, seq) {
    var stop = stage.querySelector('.xp-stop');
    var wall = stage.querySelector('[data-xp-wall]');
    place(el, -20);
    walkTo(seq, el, -20, 108, function () {
      place(el, 100);
      frame(el, 8);
      seq.after(180, function () {
        walkTo(seq, el, 100, 108, function () {
          place(el, 96);
          frame(el, 0);
          face(el, 1);
          if (stop) {
            stop.classList.add('is-on');
          }
        });
      });
    });
    stage.addEventListener('pointermove', function (event) {
      if (!wall) {
        return;
      }
      var box = wall.getBoundingClientRect();
      var near = Math.abs(event.clientX - box.left) < 42 && Math.abs(event.clientY - (box.top + box.height / 2)) < 48;
      wall.classList.toggle('is-near', near);
    });
    stage.addEventListener('pointerleave', function () {
      if (wall) {
        wall.classList.remove('is-near');
      }
    });
  }

  function bindFault(stage, el, seq) {
    var xp = stage;
    place(el, 68);
    frame(el, 0);
    function fault() {
      if (xp.dataset.xpBusy === '1') {
        return;
      }
      xp.dataset.xpBusy = '1';
      xp.classList.remove('is-fault');
      window.requestAnimationFrame(function () {
        xp.classList.add('is-fault');
      });
      frame(el, 8);
      seq.after(560, function () {
        xp.classList.remove('is-fault');
        frame(el, 0);
        xp.dataset.xpBusy = '';
      });
    }
    seq.after(360, fault);
    var replay = stage.querySelector('[data-xp-replay]');
    if (replay) {
      replay.addEventListener('click', fault);
    }
  }

  function bindSignal(page, stage, el, seq) {
    place(el, 28);
    face(el, 1);
    frame(el, 0);
    function run(done) {
      var sigs = stage.querySelectorAll('.xp-sig');
      var i = 0;
      Array.prototype.forEach.call(sigs, function (sig) {
        sig.classList.remove('is-off');
      });
      function step() {
        if (i >= sigs.length) {
          frame(el, 0);
          if (done) {
            done();
          }
          return;
        }
        sigs[i].classList.add('is-off');
        i += 1;
        frame(el, i === 3 ? 7 : 0);
        seq.after(220, step);
      }
      seq.after(260, step);
    }
    run();
    var retry = page.querySelector('[data-xp-retry]');
    if (retry) {
      retry.addEventListener('click', function (event) {
        event.preventDefault();
        seq.clear();
        seq = new Seq();
        run(function () {
          window.location.reload();
        });
      });
    }
  }

  function bindRepair(stage, el, seq) {
    var blocks = stage.querySelectorAll('.xp-screen');
    var spark = stage.querySelector('.xp-spark');
    var n = 0;
    place(el, 36);
    face(el, 1);
    function loop() {
      if (seq.dead) {
        return;
      }
      frame(el, 10);
      if (spark) {
        spark.classList.add('is-on');
      }
      seq.after(200, function () {
        if (spark) {
          spark.classList.remove('is-on');
        }
        if (n < blocks.length) {
          blocks[n].classList.add('is-on');
          n += 1;
        }
        frame(el, 0);
        seq.after(680, loop);
      });
    }
    loop();
  }

  function bindQueue(page, stage, el, seq) {
    var crates = stage.querySelectorAll('.xp-crate');
    var i = 0;
    place(el, 28);
    frame(el, 0);
    function add() {
      if (i < crates.length) {
        crates[i].classList.add('is-on');
        frame(el, i % 2 ? 7 : 0);
        i += 1;
        seq.after(280, add);
        return;
      }
      frame(el, 9);
      if (!page.querySelector('[data-xs-wait]')) {
        return;
      }
      function poll() {
        if (page.classList.contains('is-ready')) {
          var k = crates.length - 1;
          function less() {
            if (k >= 0) {
              crates[k].classList.remove('is-on');
              k -= 1;
              seq.after(180, less);
              return;
            }
            frame(el, 0);
          }
          less();
          return;
        }
        seq.after(300, poll);
      }
      poll();
    }
    seq.after(200, add);
  }

  function init(root) {
    var pages = readyPages(root);
    if (!pages.length) {
      return;
    }
    bindMotion();
    pages.forEach(function (page) {
      page.setAttribute('data-xs-ready', '1');
      page.querySelectorAll('[data-xs-theme]').forEach(function (button) {
        button.addEventListener('click', function () {
          setTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
        });
      });
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
      if (!prefersReduce()) {
        bindScenes(page);
      }
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
