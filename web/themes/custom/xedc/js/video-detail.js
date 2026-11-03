/**
 * @file
 * Video player behavior on top of the existing Plyr build.
 */
(function (Drupal, drupalSettings, once) {
  var rates = [0.5, 0.75, 1, 1.25, 1.5, 2];

  function storageGet(key) {
    try {
      return window.localStorage.getItem(key);
    }
    catch (e) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
    }
    catch (e) {}
  }

  function formatTime(seconds) {
    seconds = Math.max(0, Math.floor(seconds || 0));
    var hours = Math.floor(seconds / 3600);
    var minutes = Math.floor((seconds % 3600) / 60);
    var remain = seconds % 60;
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    if (hours > 0) {
      return pad(hours) + ':' + pad(minutes) + ':' + pad(remain);
    }
    return pad(minutes) + ':' + pad(remain);
  }

  function typingTarget(el) {
    if (!el || !el.tagName) {
      return false;
    }
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
  }

  function savedRate() {
    var rate = parseFloat(storageGet('xedcVideoRate') || '1');
    return rates.indexOf(rate) === -1 ? 1 : rate;
  }

  function errorText(code) {
    if (code === 2) {
      return Drupal.t('网络异常，暂时无法加载视频。');
    }
    if (code === 4) {
      return Drupal.t('视频文件不存在，或当前无法播放。');
    }
    return Drupal.t('视频无法播放，请稍后重试。');
  }

  function readLocal(nid) {
    var raw = storageGet('xedcVideoProgress:' + nid);
    if (!raw) {
      return null;
    }
    try {
      var data = JSON.parse(raw);
      if (!data || typeof data.position !== 'number') {
        return null;
      }
      return data;
    }
    catch (e) {
      return null;
    }
  }

  function writeLocal(nid, position) {
    storageSet('xedcVideoProgress:' + nid, JSON.stringify({
      position: Math.floor(position),
      saved: Date.now()
    }));
  }

  function clearLocal(nid) {
    try {
      window.localStorage.removeItem('xedcVideoProgress:' + nid);
    }
    catch (e) {}
  }

  function token() {
    if (!token.promise) {
      var base = (drupalSettings.path && drupalSettings.path.baseUrl) || '/';
      token.promise = window.fetch(base + 'session/token', { credentials: 'same-origin' })
        .then(function (response) { return response.text(); })
        .catch(function () { return ''; });
    }
    return token.promise;
  }

  function bindPlayer(root) {
    var video = root.querySelector('video.vd-video');
    if (!video || !window.Plyr) {
      return;
    }
    var nid = root.getAttribute('data-nid') || '';
    var knownDuration = parseInt(root.getAttribute('data-duration') || '0', 10);
    var scope = root.parentElement || root;
    var state = scope.querySelector('.vd-state');
    var stateText = scope.querySelector('.vd-state__text');
    var retry = scope.querySelector('.vd-state__retry');
    var resume = scope.querySelector('.vd-resume');
    var resumeText = resume ? resume.querySelector('p') : null;
    var hlsUrl = video.getAttribute('data-hls');
    if (hlsUrl) {
      if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = hlsUrl;
      }
      else if (window.Hls && window.Hls.isSupported()) {
        var hls = new window.Hls({ maxBufferLength: 30 });
        hls.loadSource(hlsUrl);
        hls.attachMedia(video);
        hls.on(window.Hls.Events.ERROR, function (event, data) {
          if (data && data.fatal) {
            showError(Drupal.t('网络异常，暂时无法加载视频。'));
          }
        });
      }
    }

    var settings = ['speed'];
    if (video.querySelector('track')) {
      settings.push('captions');
    }
    var controls = ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings'];
    if (document.pictureInPictureEnabled) {
      controls.push('pip');
    }
    controls.push('fullscreen');
    var player = new window.Plyr(video, {
      iconUrl: '/themes/custom/xedc/js/plyr.svg',
      controls: controls,
      settings: settings,
      speed: { selected: savedRate(), options: rates },
      keyboard: { focused: false, global: false },
      tooltips: { controls: true, seek: true },
      hideControls: true,
      clickToPlay: true,
      resetOnEnd: false,
      invertTime: false,
      preload: 'metadata',
      ratio: '16:9',
      i18n: {
        play: Drupal.t('播放'),
        pause: Drupal.t('暂停'),
        mute: Drupal.t('静音'),
        unmute: Drupal.t('取消静音'),
        settings: Drupal.t('设置'),
        speed: Drupal.t('倍速'),
        normal: Drupal.t('正常'),
        pip: Drupal.t('画中画'),
        enterFullscreen: Drupal.t('全屏'),
        exitFullscreen: Drupal.t('退出全屏'),
        volume: Drupal.t('音量'),
        menuBack: Drupal.t('返回'),
        currentTime: Drupal.t('当前时间'),
        duration: Drupal.t('时长'),
        seek: Drupal.t('拖动进度')
      }
    });

    function applyPreferences() {
      var rate = savedRate();
      video.playbackRate = rate;
      player.speed = rate;
      var volume = parseFloat(storageGet('xedcVideoVolume') || '');
      if (!isNaN(volume)) {
        var next = Math.min(1, Math.max(0, volume));
        video.volume = next;
        player.volume = next;
      }
      if (storageGet('xedcVideoMuted') === '1') {
        video.muted = true;
        player.muted = true;
      }
    }
    video.addEventListener('loadedmetadata', function () {
      applyPreferences();
      if (video.videoWidth > 0 && video.videoHeight > video.videoWidth) {
        root.classList.add('is-portrait');
        player.ratio = video.videoWidth + ':' + video.videoHeight;
      }
    });

    function whenReady() {
      if (video.readyState >= 1) {
        return Promise.resolve();
      }
      return new Promise(function (resolve) {
        var done = function () {
          video.removeEventListener('loadedmetadata', done);
          resolve();
        };
        video.addEventListener('loadedmetadata', done);
        setTimeout(done, 8000);
      });
    }

    function seekTo(position) {
      return whenReady().then(function () {
        var target = Math.max(0, position);
        video.currentTime = target;
        if (Math.abs((video.currentTime || 0) - target) < 0.75) {
          return video.currentTime;
        }
        return new Promise(function (resolve) {
          var finish = function () {
            video.removeEventListener('seeked', finish);
            resolve(video.currentTime);
          };
          video.addEventListener('seeked', finish);
          setTimeout(function () {
            video.removeEventListener('seeked', finish);
            if (Math.abs((video.currentTime || 0) - target) > 0.75) {
              video.currentTime = target;
            }
            setTimeout(function () { resolve(video.currentTime); }, 350);
          }, 1000);
        });
      });
    }

    function showError(message, allowRetry) {
      if (!state || !stateText) {
        return;
      }
      stateText.textContent = message;
      state.hidden = false;
      if (retry) {
        retry.hidden = allowRetry === false;
      }
      root.classList.remove('is-buffering');
    }

    var playbackState = root.getAttribute('data-playback-state') || '';
    if (playbackState === 'failed') {
      showError(Drupal.t('兼容播放文件生成失败，请稍后再试。'), false);
    }
    else if (playbackState === 'transcoding') {
      showError(Drupal.t('正在准备兼容播放文件。如果画面无法显示，请稍后再试。'), false);
    }

    function hideError() {
      if (state) {
        state.hidden = true;
      }
    }

    video.addEventListener('error', function () {
      var code = video.error ? video.error.code : 0;
      if (code === 2 || navigator.onLine === false) {
        showError(Drupal.t('网络异常，暂时无法加载视频。'));
        return;
      }
      showError(errorText(code));
    });
    video.addEventListener('timeupdate', function () {
      if (playbackState === 'copy' || playbackState === 'failed' || video.videoWidth > 0) {
        return;
      }
      if (video.currentTime > 0.4 && video.readyState >= 2) {
        showError(Drupal.t('当前浏览器不支持这个视频的编码。'), false);
      }
    });
    window.addEventListener('online', function () {
      if (video.error) {
        hideError();
        video.load();
      }
    });
    video.addEventListener('waiting', function () {
      root.classList.add('is-buffering');
    });
    video.addEventListener('playing', function () {
      root.classList.remove('is-buffering');
      hideError();
    });
    video.addEventListener('canplay', function () {
      root.classList.remove('is-buffering');
    });
    player.on('ratechange', function () {
      storageSet('xedcVideoRate', String(player.speed));
    });
    player.on('volumechange', function () {
      storageSet('xedcVideoVolume', String(player.volume));
      storageSet('xedcVideoMuted', player.muted ? '1' : '0');
    });
    if (retry) {
      retry.addEventListener('click', function () {
        hideError();
        player.restart();
        player.play();
      });
    }

    var lastPost = 0;
    function remember(position) {
      var duration = knownDuration || Math.floor(player.duration || 0);
      if (duration > 0 && position >= duration - 3) {
        clearLocal(nid);
        post(position, true);
        return;
      }
      if (position < 5) {
        return;
      }
      writeLocal(nid, position);
      post(position, false);
    }

    function post(position, finished) {
      var uid = drupalSettings.user && parseInt(drupalSettings.user.uid, 10);
      if (!uid || !nid) {
        return;
      }
      var now = Date.now();
      if (!finished && now - lastPost < 15000) {
        return;
      }
      lastPost = now;
      var base = (drupalSettings.path && drupalSettings.path.baseUrl) || '/';
      token().then(function (csrf) {
        if (!csrf) {
          return;
        }
        window.fetch(base + 'video/progress/' + nid, {
          method: 'POST',
          credentials: 'same-origin',
          keepalive: true,
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-CSRF-Token': csrf
          },
          body: 'position=' + Math.floor(position)
        });
      });
    }

    player.on('timeupdate', function () {
      if (player.playing) {
        remember(player.currentTime || 0);
      }
    });
    player.on('pause', function () {
      remember(player.currentTime || 0);
    });
    player.on('ended', function () {
      clearLocal(nid);
      post(knownDuration || player.duration || 0, true);
      if (resume) {
        resume.hidden = true;
      }
    });

    function offer(position) {
      var duration = knownDuration || 0;
      if (!resume || !resumeText || position < 5) {
        return;
      }
      if (duration > 0 && position >= duration - 3) {
        clearLocal(nid);
        return;
      }
      resumeText.textContent = Drupal.t('上次看到 @time', { '@time': formatTime(position) });
      resume.hidden = false;
      resume.querySelector('.vd-resume__go').onclick = function () {
        resume.hidden = true;
        seekTo(position).then(function () { player.play().catch(function () {}); });
      };
      resume.querySelector('.vd-resume__start').onclick = function () {
        clearLocal(nid);
        post(0, true);
        resume.hidden = true;
        seekTo(0).then(function () { player.play().catch(function () {}); });
      };
    }

    window.addEventListener('pagehide', function () {
      var position = player.currentTime || 0;
      if (position >= 5) {
        writeLocal(nid, position);
        post(position, false);
      }
    });

    var local = readLocal(nid);
    var choice = local;
    var uid = drupalSettings.user && parseInt(drupalSettings.user.uid, 10);
    if (uid && nid) {
      var base = (drupalSettings.path && drupalSettings.path.baseUrl) || '/';
      window.fetch(base + 'video/progress/' + nid, { credentials: 'same-origin' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data || !data.position) {
            if (choice) {
              offer(choice.position);
            }
            return;
          }
          var server = { position: data.position, saved: (data.saved || 0) * 1000 };
          if (!choice || server.saved >= choice.saved) {
            choice = server;
          }
          offer(choice.position);
        })
        .catch(function () {
          if (choice) {
            offer(choice.position);
          }
        });
    }
    else if (choice) {
      offer(choice.position);
    }

    root.xedcPlayer = player;
  }

  function bindKeys() {
    document.addEventListener('keydown', function (event) {
      if (event.altKey || event.ctrlKey || event.metaKey || typingTarget(event.target) || typingTarget(document.activeElement)) {
        return;
      }
      if (event.target && event.target.closest && event.target.closest('a, button, input, textarea, select')) {
        return;
      }
      var root = document.querySelector('[data-xedc-player]');
      var player = root && root.xedcPlayer;
      if (!player) {
        return;
      }
      var duration = player.duration || parseInt(root.getAttribute('data-duration') || '0', 10);
      if (event.key === ' ' || event.key === 'Spacebar') {
        event.preventDefault();
        player.togglePlay();
      }
      else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        player.currentTime = Math.max(0, (player.currentTime || 0) - 5);
      }
      else if (event.key === 'ArrowRight') {
        event.preventDefault();
        player.currentTime = Math.min(duration || player.currentTime + 5, (player.currentTime || 0) + 5);
      }
      else if (event.key === 'ArrowUp') {
        event.preventDefault();
        player.increaseVolume(0.05);
      }
      else if (event.key === 'ArrowDown') {
        event.preventDefault();
        player.decreaseVolume(0.05);
      }
      else if (event.key === 'f' || event.key === 'F') {
        event.preventDefault();
        player.fullscreen.toggle();
      }
      else if (event.key === 'm' || event.key === 'M') {
        event.preventDefault();
        player.muted = !player.muted;
      }
    });
  }

  function bindEmbeds(context) {
    once('xedc-video-embed', '.vd-click', context).forEach(function (box) {
      box.addEventListener('click', function () {
        if (!box.getAttribute('data-embed')) {
          return;
        }
        var frame = document.createElement('iframe');
        frame.src = box.getAttribute('data-embed');
        frame.allow = 'autoplay; fullscreen';
        frame.allowFullscreen = true;
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        frame.title = Drupal.t('视频');
        box.replaceWith(frame);
      });
    });
  }

  function bindBodies(context) {
    once('xedc-video-body', '[data-vd-body]', context).forEach(function (body) {
      var content = body.querySelector('.vd-body__content');
      var button = body.querySelector('.vd-body__toggle');
      if (!content || !button) {
        return;
      }
      window.requestAnimationFrame(function () {
        if (content.scrollHeight <= 160) {
          return;
        }
        body.classList.add('is-collapsed');
        button.hidden = false;
        button.addEventListener('click', function () {
          var open = body.classList.toggle('is-open');
          body.classList.toggle('is-collapsed', !open);
          button.textContent = open ? Drupal.t('收起') : Drupal.t('展开');
        });
      });
    });
  }

  Drupal.behaviors.xedcVideoPlayer = {
    attach: function (context) {
      once('xedc-video-player', '[data-xedc-player]', context).forEach(bindPlayer);
      bindEmbeds(context);
      bindBodies(context);
      if (!document.documentElement.dataset.xedcVideoKeys) {
        document.documentElement.dataset.xedcVideoKeys = '1';
        bindKeys();
      }
    }
  };
})(Drupal, drupalSettings, once);
