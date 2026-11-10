(function (Drupal) {
  'use strict';

  var savedY = null;

  function restoreScroll() {
    if (savedY === null) {
      return;
    }
    var y = savedY;
    window.scrollTo(0, y);
    window.requestAnimationFrame(function () {
      window.scrollTo(0, y);
    });
  }

  function resetFormState(form) {
    if (!form) {
      return;
    }
    delete form.dataset.submitting;
    form.classList.remove('is-submitting');
    var button = form.querySelector('[data-drupal-selector="edit-submit"], .form-submit');
    if (button) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  }

  if (window.jQuery) {
    window.jQuery.fn.xedcCommentDone = function () {
      var form = this.get(0);
      var holder = form ? form.closest('.cm-reply-hold') : null;
      var field = document.querySelector('.xedc-comments .card-body')
        || document.querySelector('.xedc-comments')
        || document.querySelector('.img-detail__comments .card-body')
        || document.querySelector('.img-detail__comments');
      if (form && holder && field) {
        var parent = holder.parentElement;
        while (holder.firstChild && holder.firstChild !== form) {
          parent.insertBefore(holder.firstChild, holder);
        }
        field.appendChild(form);
        holder.remove();
      }
      if (form) {
        var pid = form.querySelector('input[name="pid"]');
        if (pid) {
          pid.value = '0';
        }
        var area = form.querySelector('textarea');
        if (area) {
          area.placeholder = Drupal.t('写下你的评论…');
        }
      }
      resetFormState(form);
      restoreScroll();
      return this;
    };
  }

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form.comment-form');
    if (!form) return;
    if (form.classList.contains('comment-form--edit')) {
      return;
    }
    savedY = window.scrollY || document.documentElement.scrollTop || 0;
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    form.classList.add('is-submitting');
    var button = form.querySelector('[data-drupal-selector="edit-submit"], .form-submit');
    if (button) button.setAttribute('aria-busy', 'true');
  }, true);

  if (window.jQuery) {
    window.jQuery(document).on('ajaxComplete', function (event, xhr, settings) {
      var extra = settings && settings.extraData;
      var formId = extra && (extra.form_id || '');
      if (!formId || String(formId).indexOf('comment') === -1) {
        return;
      }
      window.setTimeout(function () {
        document.querySelectorAll('form.comment-form.is-submitting').forEach(resetFormState);
        restoreScroll();
      }, 0);
    });
  }

})(Drupal);

(function () {
  'use strict';

  function placeError() {
    var form = document.querySelector('form.comment-form');
    var alert = document.querySelector('.alert-danger, .messages--error');
    if (!form || !alert || form.querySelector('.comment-form__error')) return;
    var invalid = form.querySelector('.form-textarea.is-invalid, .form-textarea[aria-invalid="true"], .form-control.is-invalid');
    var item = invalid && invalid.closest('.form-item');
    if (!item) return;
    var copy = alert.cloneNode(true);
    copy.querySelectorAll('.visually-hidden, .btn-close').forEach(function (node) { node.remove(); });
    var text = (copy.textContent || '').replace(/\s+/g, ' ').trim();
    if (!text) return;
    var box = document.createElement('div');
    box.className = 'comment-form__error';
    box.setAttribute('role', 'alert');
    box.textContent = text;
    item.appendChild(box);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', placeError);
  }
  else {
    placeError();
  }
})();

(function (Drupal) {
  'use strict';

  var E = '😀 😁 😂 🤣 😊 😍 😘 😎 🤔 😅 😭 😡 👍 👏 🙏 💪 ❤️ 🔥 🎉 🌹 😴 🤗 😏 🥰 😱 🙈 💯 ✨ 👀 🤝'.split(' ');
  var ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/></svg>';

  function each(context, selector, fn) {
    var root = context && context.querySelectorAll ? context : document;
    if (root.matches && root.matches(selector)) {
      fn(root);
    }
    root.querySelectorAll(selector).forEach(fn);
  }

  function paintAvatars(context) {
    each(context, '.cm__avatar:not(:has(img))', function (el) {
      if (el.dataset.xedcAvatar) {
        return;
      }
      el.dataset.xedcAvatar = '1';
      var n = el.dataset.name || '', h = 0, i;
      for (i = 0; i < n.length; i++) {
        h = (h * 31 + n.charCodeAt(i)) % 360;
      }
      el.style.background = 'hsl(' + h + ',55%,88%)';
      el.style.color = 'hsl(' + h + ',45%,32%)';
    });
  }

  function mountEmoji(context) {
    each(context, 'form.comment-form', function (f) {
      var ta = f.querySelector('textarea');
      if (!ta || f.querySelector('.cm-emoji')) {
        return;
      }
      var w = document.createElement('div');
      w.className = 'cm-emoji';
      w.innerHTML = '<button type="button" class="cm-emoji__btn" aria-label="' + Drupal.t('表情') + '">' + ICON + '</button><div class="cm-emoji__panel" hidden></div>';
      var p = w.querySelector('.cm-emoji__panel');
      E.forEach(function (e) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = e;
        p.appendChild(b);
      });
      w.querySelector('.cm-emoji__btn').onclick = function (ev) {
        ev.preventDefault();
        p.hidden = !p.hidden;
      };
      p.onclick = function (ev) {
        if (ev.target.tagName !== 'BUTTON') {
          return;
        }
        ta.setRangeText(ev.target.textContent, ta.selectionStart, ta.selectionEnd, 'end');
        ta.focus();
        p.hidden = true;
      };
      f.appendChild(w);
    });
  }

  if (!document.documentElement.dataset.xedcCommentUi) {
    document.documentElement.dataset.xedcCommentUi = '1';
    document.addEventListener('click', function (e) {
      var reply = e.target.closest('.cm__links a[href*="/comment/reply/"]');
      if (reply) {
        var form = document.querySelector('form.comment-form');
        var art = reply.closest('article.cm');
        var m = reply.getAttribute('href').match(/\/comment\/reply\/node\/\d+\/field_comment\/(\d+)/);
        if (form && art && m) {
          e.preventDefault();
          var holder = document.querySelector('.cm-reply-hold');
          if (!holder) {
            holder = document.createElement('div');
            holder.className = 'cm-reply-hold';
          }
          var ta = form.querySelector('textarea');
          var name = art.querySelector('.cm__head b');
          ta.placeholder = Drupal.t('回复 @name', {'@name': name ? name.textContent : ''});
          form.action = reply.getAttribute('href');
          var pid = form.querySelector('input[name="pid"]');
          if (pid) {
            pid.value = m[1];
          }
          art.querySelector('.cm__main').appendChild(holder);
          holder.appendChild(form);
          ta.focus();
        }
        return;
      }
      var img = e.target.closest('.cm__main .field--name-field-comment-image img');
      if (!img) {
        return;
      }
      e.preventDefault();
      var box = document.querySelector('.cm-lightbox');
      if (!box) {
        box = document.createElement('div');
        box.className = 'cm-lightbox';
        box.onclick = function () { box.hidden = true; };
        document.body.appendChild(box);
      }
      var link = img.closest('a');
      var shot = document.createElement('img');
      shot.alt = '';
      shot.src = link ? link.href : img.src;
      box.replaceChildren(shot);
      box.hidden = false;
    });
  }

  Drupal.behaviors.xedcCommentExtras = {
    attach: function (context) {
      paintAvatars(context);
      mountEmoji(context);
    }
  };
})(Drupal);
