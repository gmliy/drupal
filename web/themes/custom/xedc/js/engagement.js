(function (Drupal, once) {
  'use strict';

  var csrf = '';

  function token() {
    if (csrf) {
      return Promise.resolve(csrf);
    }
    return fetch(drupalSettings.path.baseUrl + 'xedc/like-token', { credentials: 'same-origin' }).then(function (response) {
      return response.text();
    }).then(function (value) {
      csrf = value;
      return value;
    });
  }

  function post(url, body) {
    return token().then(function (csrf) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-CSRF-Token': csrf,
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(body || {})
      });
    });
  }

  function sheet(root, name) {
    return root.parentElement ? root.parentElement.querySelector('[data-xedc-sheet="' + name + '"]') : null;
  }

  function openSheet(dialog) {
    if (!dialog) {
      return;
    }
    if (typeof dialog.showModal === 'function' && !dialog.open) {
      dialog.showModal();
    }
    else {
      dialog.setAttribute('open', 'open');
    }
    document.documentElement.classList.add('xedc-sheet-open');
  }

  function closeSheet(dialog) {
    if (!dialog) {
      return;
    }
    if (typeof dialog.close === 'function' && dialog.open) {
      dialog.close();
    }
    dialog.removeAttribute('open');
    if (!document.querySelector('dialog[open]')) {
      document.documentElement.classList.remove('xedc-sheet-open');
    }
  }

  function prepareShare(root, dialog) {
    var title = root.getAttribute('data-share-title') || '';
    var url = root.getAttribute('data-share-url') || '';
    var titleNode = dialog.querySelector('.xedc-share__title');
    var urlNode = dialog.querySelector('.xedc-share__url');
    if (titleNode) {
      titleNode.textContent = title;
    }
    if (urlNode) {
      urlNode.textContent = url;
    }
    var qr = dialog.querySelector('.xedc-share__qr');
    var src = root.getAttribute('data-share-qr');
    if (qr && src && qr.getAttribute('src') !== src) {
      qr.src = src;
      qr.hidden = false;
    }
    var nativeButton = dialog.querySelector('[data-xedc-native]');
    if (nativeButton) {
      nativeButton.hidden = !(navigator.share && window.isSecureContext);
    }
    post(root.getAttribute('data-share-record'), { method: 'panel', outcome: 'intent' }).catch(function () {});
  }

  function showNote(root, text) {
    var note = root.parentElement ? root.parentElement.querySelector('[data-xedc-note]') : null;
    if (!note) {
      return;
    }
    note.hidden = text === '';
    note.textContent = text;
  }

  function openLogin(root) {
    var login = root.querySelector('a[href*="/user/login"]') || document.querySelector('a[href*="/user/login"]');
    if (login) {
      login.click();
      return;
    }
    window.location.href = root.getAttribute('data-login') || '/user/login';
  }

  function paintLike(like, label, countNode, on, count) {
    like.classList.toggle('is-on', !!on);
    like.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (label) {
      label.textContent = on ? '已赞' : '点赞';
    }
    if (countNode) {
      countNode.textContent = String(count);
    }
  }

  Drupal.behaviors.xedcEngagement = {
    attach: function (context) {
      once('xedc-engagement', '[data-xedc-act]', context).forEach(function (root) {
        root.addEventListener('click', function (event) {
          var like = event.target.closest('[data-xedc-like]');
            if (like && root.contains(like)) {
            event.preventDefault();
            if (like.classList.contains('is-loading')) {
              return;
            }
            var label = like.querySelector('[data-xedc-like-label]');
            var countNode = like.querySelector('[data-xedc-like-count]');
            var wasOn = like.classList.contains('is-on');
            var wasCount = countNode ? parseInt(countNode.textContent, 10) || 0 : 0;
            var next = !wasOn;
            like.classList.add('is-loading');
            like.setAttribute('aria-busy', 'true');
            paintLike(like, label, countNode, next, Math.max(0, wasCount + (next ? 1 : -1)));
            post(root.getAttribute('data-like-url'), {}).then(function (response) {
              return response.json().then(function (data) {
                return { status: response.status, data: data };
              });
            }).then(function (result) {
              if (result.status === 401 || result.status === 429 || !result.data || !result.data.ok) {
                paintLike(like, label, countNode, wasOn, wasCount);
                showNote(root, (result.data && result.data.message) || '点赞没有成功，请再试一次。');
                return;
              }
              paintLike(like, label, countNode, !!result.data.liked, result.data.count);
              showNote(root, '');
            }).catch(function () {
              paintLike(like, label, countNode, wasOn, wasCount);
              showNote(root, '网络异常，点赞状态已恢复。');
            }).finally(function () {
              like.classList.remove('is-loading');
              like.removeAttribute('aria-busy');
            });
            return;
          }

          var opener = event.target.closest('[data-xedc-open]');
          if (opener && root.contains(opener)) {
            var name = opener.getAttribute('data-xedc-open');
            var dialog = sheet(root, name);
            if (name === 'share') {
              prepareShare(root, dialog);
            }
            openSheet(dialog);
          }
        });

        root.parentElement.addEventListener('click', function (event) {
          var zipLink = event.target.closest('[data-xedc-zip]');
          if (zipLink && root.parentElement.contains(zipLink)) {
            event.preventDefault();
            var zipNote = root.parentElement.querySelector('[data-xedc-download-note]');
            if (zipLink.getAttribute('data-xedc-zip-busy') === '1') {
              return;
            }
            zipLink.setAttribute('data-xedc-zip-busy', '1');
            if (zipNote) {
              zipNote.hidden = false;
              zipNote.textContent = '正在准备压缩包…';
            }
            var started = Date.now();
            var statusUrl = zipLink.href + (zipLink.href.indexOf('?') === -1 ? '?' : '&') + 'status=1';
            var finish = function () {
              zipLink.removeAttribute('data-xedc-zip-busy');
            };
            var poll = function () {
              fetch(statusUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (response) {
                return response.json().then(function (data) {
                  return { status: response.status, data: data };
                });
              }).then(function (result) {
                var data = result.data || {};
                if (zipNote) {
                  zipNote.textContent = data.message || '正在准备压缩包…';
                }
                if (data.state === 'ready' || data.state === 'sync' || data.state === 'build') {
                  finish();
                  window.location.assign(zipLink.href);
                  return;
                }
                if (!data.ok || data.state === 'denied' || data.state === 'failed') {
                  finish();
                  return;
                }
                if (Date.now() - started > 120000) {
                  if (zipNote) {
                    zipNote.textContent = '仍在后台生成。请稍后再次点击下载，不会重新压缩已完成的文件。';
                  }
                  finish();
                  return;
                }
                window.setTimeout(poll, 3000);
              }).catch(function () {
                if (zipNote) {
                  zipNote.textContent = '无法确认压缩包状态，请稍后再试。';
                }
                finish();
              });
            };
            poll();
            return;
          }
          var closer = event.target.closest('[data-xedc-close]');
          if (closer) {
            closeSheet(closer.closest('dialog'));
            return;
          }
          var locked = event.target.closest('[data-xedc-locked]');
          if (!locked) {
            return;
          }
          var note = root.parentElement.querySelector('[data-xedc-download-note]');
          var dialog = locked.closest('dialog');
          var access = locked.getAttribute('data-access');
          var message = locked.getAttribute('data-message') || '';
          var tier = root.getAttribute('data-tier-label') || '';
          var extra = '';
          if (access === 'authenticated') {
            extra = '登录后即可下载。';
          }
          if (access === 'vip') {
            extra = '当前等级：' + tier + '。VIP 可下载专属资源，到期后恢复为普通会员。本站不提供在线支付，需由管理员开通。';
          }
          if (note) {
            note.hidden = false;
            note.textContent = message + (extra ? ' ' + extra : '');
          }
          if (dialog) {
            openSheet(dialog);
          }
        });

        root.parentElement.addEventListener('click', function (event) {
          var copy = event.target.closest('[data-xedc-copy]');
          if (!copy) {
            return;
          }
          var url = root.getAttribute('data-share-url') || '';
          var feedback = root.parentElement.querySelector('[data-xedc-share-feedback]');
          var done = function () {
            if (feedback) {
              feedback.textContent = '已复制链接';
            }
            post(root.getAttribute('data-share-record'), { method: 'copy', outcome: 'success' }).catch(function () {});
          };
          var fallbackCopy = function () {
            var input = document.createElement('textarea');
            input.value = url;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.top = '0';
            input.style.left = '0';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.focus();
            input.select();
            var ok = false;
            try {
              ok = document.execCommand('copy');
            }
            catch (error) {
              ok = false;
            }
            input.remove();
            if (ok) {
              done();
            }
            else if (feedback) {
              feedback.textContent = '复制失败，请手动选择链接';
            }
          };
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done).catch(fallbackCopy);
          }
          else {
            fallbackCopy();
          }
        });

        root.parentElement.addEventListener('click', function (event) {
          var nativeButton = event.target.closest('[data-xedc-native]');
          if (!nativeButton || !navigator.share) {
            return;
          }
          navigator.share({
            title: root.getAttribute('data-share-title') || document.title,
            url: root.getAttribute('data-share-url') || window.location.href
          }).then(function () {
            var feedback = root.parentElement.querySelector('[data-xedc-share-feedback]');
            if (feedback) {
              feedback.textContent = '已调用系统分享';
            }
            post(root.getAttribute('data-share-record'), { method: 'native', outcome: 'success' }).catch(function () {});
          }).catch(function () {});
        });
      });

      once('xedc-sheet-dismiss', 'dialog.xedc-sheet', context).forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
          if (event.target === dialog) {
            closeSheet(dialog);
          }
        });
        dialog.addEventListener('close', function () {
          if (!document.querySelector('dialog[open]')) {
            document.documentElement.classList.remove('xedc-sheet-open');
          }
        });
      });
    }
  };
})(Drupal, once);
