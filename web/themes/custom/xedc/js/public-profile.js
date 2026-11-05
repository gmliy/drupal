/**
 * @file
 * Public creator profile: share + media fallbacks.
 */
(function (Drupal, once) {
  'use strict';

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var input = document.createElement('textarea');
      input.value = text;
      input.setAttribute('readonly', '');
      input.style.position = 'fixed';
      input.style.left = '-9999px';
      document.body.appendChild(input);
      input.select();
      try {
        if (document.execCommand('copy')) {
          resolve();
        }
        else {
          reject(new Error('copy'));
        }
      }
      catch (error) {
        reject(error);
      }
      finally {
        input.remove();
      }
    });
  }

  function showToast(root, message) {
    var toast = root.querySelector('[data-xp-toast]');
    if (!toast) {
      return;
    }
    toast.textContent = message;
    toast.hidden = false;
    window.setTimeout(function () {
      toast.hidden = true;
    }, 1800);
  }

  function replaceBrokenCover(img) {
    var media = img.closest('.xp-card__media, .xp-card__thumb');
    if (!media) {
      img.remove();
      return;
    }
    var placeholder = document.createElement('span');
    placeholder.className = 'xp-card__placeholder';
    placeholder.setAttribute('aria-hidden', 'true');
    img.replaceWith(placeholder);
  }

  function replaceBrokenAvatar(img) {
    var wrap = img.closest('.xp-avatar');
    if (!wrap) {
      img.remove();
      return;
    }
    wrap.innerHTML = ''
      + '<svg class="xp-avatar__fallback" viewBox="0 0 96 96" aria-hidden="true" focusable="false">'
      + '<circle cx="48" cy="48" r="48"></circle>'
      + '<circle cx="48" cy="38" r="16"></circle>'
      + '<path d="M18 82c5-16 17-24 30-24s25 8 30 24"></path>'
      + '</svg>';
  }

  Drupal.behaviors.xedcPublicProfileShare = {
    attach: function (context) {
      once('xp-share', '[data-xp-share]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var url = button.getAttribute('data-xp-share') || '';
          var title = button.getAttribute('data-xp-title') || document.title;
          var root = button.closest('.xp') || document;
          var finish = function () {
            copyText(url).then(function () {
              showToast(root, Drupal.t('链接已复制'));
            }).catch(function () {
              showToast(root, Drupal.t('复制失败'));
            });
          };
          if (navigator.share) {
            navigator.share({title: title, url: url}).catch(function (error) {
              if (!error || error.name !== 'AbortError') {
                finish();
              }
            });
            return;
          }
          finish();
        });
      });

      once('xp-cover-fallback', '[data-xp-cover]', context).forEach(function (img) {
        img.addEventListener('error', function () {
          replaceBrokenCover(img);
        });
        if (img.complete && img.naturalWidth === 0) {
          replaceBrokenCover(img);
        }
      });

      once('xp-avatar-fallback', '[data-xp-avatar]', context).forEach(function (img) {
        img.addEventListener('error', function () {
          replaceBrokenAvatar(img);
        });
        if (img.complete && img.naturalWidth === 0) {
          replaceBrokenAvatar(img);
        }
      });
    }
  };
})(Drupal, once);
