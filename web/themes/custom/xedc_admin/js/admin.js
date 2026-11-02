/**
 * @file
 * Account menu, theme switch, and table operation menus.
 */
(function (Drupal, once) {
  'use strict';

  const THEME_KEY = 'xedcAdmin.theme';

  function readTheme() {
    try {
      return window.localStorage.getItem(THEME_KEY) === 'dark' ? 'dark' : 'light';
    }
    catch (error) {
      return 'light';
    }
  }

  function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'dark') {
      root.setAttribute('data-theme', 'dark');
    }
    else {
      root.removeAttribute('data-theme');
    }
    document.querySelectorAll('[data-xedc-admin-theme]').forEach((button) => {
      const dark = theme === 'dark';
      button.setAttribute('aria-pressed', dark ? 'true' : 'false');
      button.setAttribute('aria-label', dark ? '切换到浅色' : '切换到深色');
    });
  }

  Drupal.behaviors.xedcAdminTheme = {
    attach(context) {
      once('xedc-admin-theme', '[data-xedc-admin-theme]', context).forEach((button) => {
        applyTheme(readTheme());
        button.addEventListener('click', () => {
          const next = readTheme() === 'dark' ? 'light' : 'dark';
          applyTheme(next);
          try {
            window.localStorage.setItem(THEME_KEY, next);
          }
          catch (error) {
            // The choice still applies for this page view.
          }
        });
      });
    },
  };

  Drupal.behaviors.xedcAdminDropdown = {
    attach(context) {
      once('xedc-admin-dropdown', '[data-xedc-admin-dropdown]', context).forEach((root) => {
        const button = root.querySelector('[data-xedc-admin-dropdown-button]');
        const menu = root.querySelector('[data-xedc-admin-dropdown-menu]');
        if (!button || !menu) {
          return;
        }

        const close = () => {
          menu.hidden = true;
          button.setAttribute('aria-expanded', 'false');
        };
        const open = () => {
          menu.hidden = false;
          button.setAttribute('aria-expanded', 'true');
        };

        button.addEventListener('click', (event) => {
          event.preventDefault();
          if (menu.hidden) {
            open();
          }
          else {
            close();
          }
        });

        document.addEventListener('click', (event) => {
          if (!root.contains(event.target)) {
            close();
          }
        });

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && !menu.hidden) {
            close();
            button.focus();
          }
        });
      });
    },
  };

  const opsQuery = '(max-width: 1023px)';
  let opsLockY = 0;

  function opsMobile() {
    return window.matchMedia(opsQuery).matches;
  }

  function opsLayer() {
    let layer = document.getElementById('xedc-admin-float');
    if (!layer) {
      layer = document.createElement('div');
      layer.id = 'xedc-admin-float';
      layer.className = 'xedc-admin-float';
      document.body.appendChild(layer);
    }
    return layer;
  }

  function lockOps(lock) {
    const root = document.documentElement;
    if (lock) {
      if (root.classList.contains('xedc-admin-ops-lock')) {
        return;
      }
      opsLockY = window.scrollY || root.scrollTop || 0;
      root.classList.add('xedc-admin-ops-lock');
      document.body.style.top = '-' + opsLockY + 'px';
      return;
    }
    if (!root.classList.contains('xedc-admin-ops-lock')) {
      return;
    }
    root.classList.remove('xedc-admin-ops-lock');
    document.body.style.top = '';
    window.scrollTo(0, opsLockY);
  }

  function rowTitle(wrapper) {
    const row = wrapper.closest('tr');
    if (row) {
      const cell = Array.from(row.children).find((child) => {
        if (!child.matches('td, th') || child.querySelector('.dropbutton-wrapper, input, select')) {
          return false;
        }
        const text = child.innerText.trim();
        return text && text !== '更新此项';
      });
      if (cell) {
        return cell.innerText.trim().split('\n')[0].slice(0, 80);
      }
    }
    const link = wrapper.querySelector('.dropbutton-action a, .dropbutton-action button');
    return link ? link.textContent.trim() : '操作';
  }

  function guardDropbutton(wrapper) {
    const list = Drupal.DropButton && Drupal.DropButton.dropbuttons;
    if (!list) {
      return;
    }
    const inst = list.find((item) => item.$dropbutton && item.$dropbutton[0] === wrapper);
    if (!inst || inst._xedcPatched) {
      return;
    }
    const original = inst.hoverOut.bind(inst);
    inst.hoverOut = function hoverOut() {
      if (wrapper._xedcState) {
        return;
      }
      original();
    };
    inst._xedcPatched = true;
  }

  function markDanger(list) {
    let first = true;
    list.querySelectorAll('.dropbutton-action a, .dropbutton-action button, .dropbutton-action input').forEach((item) => {
      if ((item.textContent || '').indexOf('删除') === -1) {
        return;
      }
      item.classList.add('is-danger');
      if (first) {
        item.classList.add('is-danger-first');
        first = false;
      }
    });
  }

  function placePopover(wrapper) {
    const state = wrapper._xedcState;
    if (!state || !state.host.classList.contains('is-popover')) {
      return;
    }
    const anchor = wrapper.querySelector('.dropbutton-toggle') || wrapper;
    const rect = anchor.getBoundingClientRect();
    const host = state.host;
    const gap = 4;
    host.style.right = Math.max(8, Math.round(window.innerWidth - rect.right)) + 'px';
    host.style.left = 'auto';
    host.style.visibility = 'hidden';
    host.style.maxHeight = 'none';
    host.style.top = '0px';
    host.style.bottom = 'auto';
    const natural = host.scrollHeight;
    const below = window.innerHeight - rect.bottom - gap - 8;
    const above = rect.top - gap - 8;
    const flip = below < Math.min(natural, 80) && above > below;
    const available = Math.max(80, flip ? above : below);
    host.style.maxHeight = Math.min(360, available) + 'px';
    if (flip) {
      host.style.top = 'auto';
      host.style.bottom = Math.round(window.innerHeight - rect.top + gap) + 'px';
      host.dataset.xedcFlip = 'up';
    }
    else {
      host.style.bottom = 'auto';
      host.style.top = Math.round(rect.bottom + gap) + 'px';
      delete host.dataset.xedcFlip;
    }
    host.style.visibility = '';
    const box = host.getBoundingClientRect();
    if (box.left < 8) {
      host.style.right = Math.max(8, window.innerWidth - 8 - box.width) + 'px';
    }
  }

  function restoreOps(wrapper) {
    const state = wrapper._xedcState;
    if (!state) {
      return;
    }
    wrapper._xedcState = null;
    const list = state.list;
    const widget = state.widget;
    if (list && widget) {
      if (state.primary) {
        list.insertBefore(state.primary, list.firstChild);
      }
      if (state.toggle) {
        const after = state.primary && state.primary.parentNode === list ? state.primary.nextSibling : list.firstChild;
        list.insertBefore(state.toggle, after);
      }
      widget.appendChild(list);
      delete list.dataset.xedcPortaled;
    }
    if (state.face) {
      state.face.remove();
    }
    if (state.overlay) {
      state.overlay.remove();
    }
    if (state.host) {
      state.host.remove();
    }
    const button = wrapper.querySelector('.dropbutton-toggle button');
    if (button) {
      button.setAttribute('aria-expanded', 'false');
    }
    if (!document.querySelector('.xedc-admin-ops.is-sheet')) {
      lockOps(false);
    }
  }

  function portalOps(wrapper) {
    if (wrapper._xedcState) {
      return;
    }
    const list = wrapper.querySelector('ul.dropbutton');
    const widget = wrapper.querySelector('.dropbutton-widget');
    if (!list || !widget) {
      wrapper.classList.remove('open');
      return;
    }
    document.querySelectorAll('.dropbutton-wrapper.open').forEach((other) => {
      if (other !== wrapper) {
        other.classList.remove('open');
      }
    });
    guardDropbutton(wrapper);
    const primary = list.querySelector(':scope > li.dropbutton-action:not(.secondary-action)');
    const toggle = list.querySelector(':scope > li.dropbutton-toggle');
    const face = document.createElement('div');
    face.className = 'xedc-admin-ops-face';
    if (primary) {
      face.appendChild(primary);
    }
    if (toggle) {
      face.appendChild(toggle);
    }
    widget.appendChild(face);

    const host = document.createElement('div');
    host.className = 'xedc-admin-ops';
    host.setAttribute('role', 'menu');
    const layer = opsLayer();
    const inDialog = wrapper.closest('.ui-dialog, .ui-dialog-off-canvas, #drupal-off-canvas');
    host.style.zIndex = inDialog ? '450' : '300';
    let overlay = null;

    if (opsMobile()) {
      if (primary) {
        list.insertBefore(primary, list.firstChild);
      }
      host.classList.add('is-sheet');
      const handle = document.createElement('div');
      handle.className = 'xedc-admin-ops-handle';
      const title = document.createElement('div');
      title.className = 'xedc-admin-ops-title';
      title.textContent = rowTitle(wrapper);
      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'xedc-admin-ops-cancel';
      cancel.textContent = '取消';
      cancel.addEventListener('click', () => {
        wrapper.classList.remove('open');
        const back = wrapper.querySelector('.dropbutton-toggle button');
        if (back) {
          back.focus();
        }
      });
      overlay = document.createElement('div');
      overlay.className = 'xedc-admin-ops-overlay';
      overlay.addEventListener('click', () => wrapper.classList.remove('open'));
      host.appendChild(handle);
      host.appendChild(title);
      host.appendChild(list);
      host.appendChild(cancel);
      layer.appendChild(overlay);
      layer.appendChild(host);
      lockOps(true);
    }
    else {
      host.classList.add('is-popover');
      host.appendChild(list);
      layer.appendChild(host);
    }

    list.dataset.xedcPortaled = 'true';
    markDanger(list);
    wrapper._xedcState = {
      list: list,
      widget: widget,
      face: face,
      host: host,
      overlay: overlay,
      primary: primary,
      toggle: toggle,
    };
    if (host.classList.contains('is-popover')) {
      placePopover(wrapper);
    }
    const button = wrapper.querySelector('.dropbutton-toggle button');
    if (button) {
      button.setAttribute('aria-haspopup', 'menu');
      button.setAttribute('aria-expanded', 'true');
    }
    host.addEventListener('keydown', (event) => {
      const items = Array.from(host.querySelectorAll('.dropbutton-action a, .dropbutton-action button, .dropbutton-action input, .xedc-admin-ops-cancel'));
      const index = items.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const next = event.key === 'ArrowDown' ? Math.min(items.length - 1, index + 1) : Math.max(0, index - 1);
        if (items[next]) {
          items[next].focus();
        }
      }
      else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        wrapper.classList.remove('open');
        const back = wrapper.querySelector('.dropbutton-toggle button');
        if (back) {
          back.focus();
        }
      }
    });
  }

  function syncOps(wrapper) {
    if (wrapper.classList.contains('open')) {
      portalOps(wrapper);
    }
    else {
      restoreOps(wrapper);
    }
  }

  function bindOpsToggle(wrapper) {
    const button = wrapper.querySelector('.dropbutton-toggle button');
    if (!button || button.dataset.xedcOpsKeys) {
      return;
    }
    button.dataset.xedcOpsKeys = 'true';
    button.setAttribute('aria-haspopup', 'menu');
    if (!button.hasAttribute('aria-expanded')) {
      button.setAttribute('aria-expanded', 'false');
    }
    button.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
        return;
      }
      event.preventDefault();
      if (!wrapper.classList.contains('open')) {
        wrapper.classList.add('open');
      }
      const host = wrapper._xedcState && wrapper._xedcState.host;
      const first = host ? host.querySelector('a, button, input') : null;
      if (first) {
        first.focus();
      }
    });
  }

  function markTableFade(wrap) {
    const more = wrap.scrollWidth - wrap.clientWidth - wrap.scrollLeft > 2;
    wrap.classList.toggle('has-more-x', more);
  }

  Drupal.behaviors.xedcAdminTableScroll = {
    attach(context) {
      once('xedc-admin-table-scroll', '.xedc-admin-table-wrap, .views-list-section', context).forEach((wrap) => {
        wrap.querySelectorAll('td.views-field-description, td.views-ui-view-description, td.views-ui-description').forEach((cell) => {
          if (cell.querySelector('.xedc-clamp')) {
            return;
          }
          const clamp = document.createElement('span');
          clamp.className = 'xedc-clamp';
          while (cell.firstChild) {
            clamp.appendChild(cell.firstChild);
          }
          cell.appendChild(clamp);
        });
        const update = () => markTableFade(wrap);
        wrap.addEventListener('scroll', update, { passive: true });
        update();
        if (window.ResizeObserver) {
          const observer = new ResizeObserver(update);
          observer.observe(wrap);
          wrap._xedcTableObserver = observer;
        }
      });
    },
    detach(context, settings, trigger) {
      if (trigger && trigger !== 'unload') {
        return;
      }
      const scope = context && context.querySelectorAll ? context : document;
      const wraps = [];
      if (scope.matches && scope.matches('.xedc-admin-table-wrap, .views-list-section')) {
        wraps.push(scope);
      }
      if (scope.querySelectorAll) {
        scope.querySelectorAll('.xedc-admin-table-wrap, .views-list-section').forEach((wrap) => wraps.push(wrap));
      }
      wraps.forEach((wrap) => {
        if (wrap._xedcTableObserver) {
          wrap._xedcTableObserver.disconnect();
        }
      });
    },
  };

  Drupal.behaviors.xedcAdminDropbutton = {
    attach(context) {
      once('xedc-admin-dropbutton-root', 'html', context).forEach(() => {
        window.addEventListener('resize', () => {
          document.querySelectorAll('.dropbutton-wrapper.open').forEach((wrapper) => {
            wrapper.classList.remove('open');
          });
        });
        document.addEventListener('scroll', (event) => {
          const host = document.querySelector('.xedc-admin-ops.is-popover');
          if (!host) {
            return;
          }
          if (event.target instanceof Node && host.contains(event.target)) {
            return;
          }
          document.querySelectorAll('.dropbutton-wrapper.open').forEach((wrapper) => {
            wrapper.classList.remove('open');
          });
        }, true);
        document.addEventListener('keydown', (event) => {
          if (event.key !== 'Escape') {
            return;
          }
          const wrapper = document.querySelector('.dropbutton-wrapper.open');
          if (!wrapper) {
            return;
          }
          event.preventDefault();
          const button = wrapper.querySelector('.dropbutton-toggle button');
          wrapper.classList.remove('open');
          if (button) {
            button.focus();
          }
        });
        document.addEventListener('click', (event) => {
          const wrapper = document.querySelector('.dropbutton-wrapper.open');
          if (!wrapper) {
            return;
          }
          if (wrapper.contains(event.target)) {
            return;
          }
          if (event.target.closest && event.target.closest('.xedc-admin-float')) {
            return;
          }
          wrapper.classList.remove('open');
        });
      });

      once('xedc-admin-dropbutton', '.dropbutton-wrapper', context).forEach((wrapper) => {
        if (!wrapper.querySelector('.dropbutton-toggle') && wrapper.querySelectorAll('.dropbutton li').length < 2) {
          const wait = new MutationObserver(() => {
            if (wrapper.querySelector('.dropbutton-toggle')) {
              wait.disconnect();
              bindOpsToggle(wrapper);
            }
          });
          wait.observe(wrapper, { childList: true, subtree: true });
        }
        else {
          bindOpsToggle(wrapper);
          guardDropbutton(wrapper);
        }
        const observer = new MutationObserver(() => syncOps(wrapper));
        observer.observe(wrapper, { attributes: true, attributeFilter: ['class'] });
        wrapper._xedcOpsObserver = observer;
      });
    },
    detach(context, settings, trigger) {
      if (trigger && trigger !== 'unload') {
        return;
      }
      const scope = context && context.querySelectorAll ? context : document;
      const wrappers = [];
      if (scope.matches && scope.matches('.dropbutton-wrapper')) {
        wrappers.push(scope);
      }
      if (scope.querySelectorAll) {
        scope.querySelectorAll('.dropbutton-wrapper').forEach((wrapper) => wrappers.push(wrapper));
      }
      wrappers.forEach((wrapper) => {
        if (wrapper._xedcOpsObserver) {
          wrapper._xedcOpsObserver.disconnect();
          wrapper._xedcOpsObserver = null;
        }
        wrapper.classList.remove('open');
        restoreOps(wrapper);
      });
      if (!document.querySelector('.dropbutton-wrapper.open')) {
        const layer = document.getElementById('xedc-admin-float');
        if (layer && !layer.childElementCount) {
          layer.remove();
        }
      }
    },
  };

  Drupal.behaviors.xedcNodeRemoveLabel = {
    attach(context) {
      once('xedc-node-remove', '.xedc-node .field-multiple-table input[type="submit"]', context).forEach((button) => {
        if (!button.getAttribute('aria-label')) {
          button.setAttribute('aria-label', button.value || '移除');
        }
      });
    },
  };
})(Drupal, once);
