/**
 * @file
 * Share the public profile URL.
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
    }
  };
})(Drupal, once);
