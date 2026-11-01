/**
 * @file
 * Sidebar collapse and the phone drawer.
 */
(function (Drupal, once) {
  'use strict';

  const COLLAPSE_KEY = 'xedcAdmin.sidebarCollapsed';
  const GROUP_KEY = 'xedcAdmin.navGroups';
  let lockedScrollY = 0;

  function readGroups() {
    try {
      const raw = window.localStorage.getItem(GROUP_KEY);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    }
    catch (error) {
      return null;
    }
  }

  function writeGroups(value) {
    try {
      window.localStorage.setItem(GROUP_KEY, JSON.stringify(value));
    }
    catch (error) {
      // Private browsing can reject storage. The open state still applies.
    }
  }

  function lockPageScroll(lock) {
    const root = document.documentElement;
    if (lock) {
      if (root.classList.contains('xedc-admin-scroll-lock')) {
        return;
      }
      lockedScrollY = window.scrollY || root.scrollTop || 0;
      root.classList.add('xedc-admin-scroll-lock');
      document.body.style.top = '-' + lockedScrollY + 'px';
      return;
    }
    if (!root.classList.contains('xedc-admin-scroll-lock')) {
      return;
    }
    root.classList.remove('xedc-admin-scroll-lock');
    document.body.style.top = '';
    window.scrollTo(0, lockedScrollY);
  }

  function isShown(element) {
    return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
  }

  Drupal.behaviors.xedcAdminNavigation = {
    attach(context) {
      once('xedc-admin-navigation', '[data-xedc-admin]', context).forEach((root) => {
        const sidebar = root.querySelector('[data-xedc-admin-sidebar]');
        const overlay = root.querySelector('[data-xedc-admin-overlay]');
        const shell = root.querySelector('[data-xedc-admin-shell]');
        const drawerButton = root.querySelector('[data-xedc-admin-drawer]');
        const collapseButton = root.querySelector('[data-xedc-admin-collapse]');
        const mobile = window.matchMedia('(max-width: 991px)');
        let returnFocus = null;

        const focusable = () => {
          if (!sidebar) {
            return [];
          }
          return Array.from(sidebar.querySelectorAll('a[href], button:not([disabled])')).filter(isShown);
        };

        function setCollapsed(collapsed) {
          root.classList.toggle('is-collapsed', collapsed);
          document.documentElement.classList.toggle('xedc-admin-collapsed', collapsed);
          if (collapseButton) {
            collapseButton.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            collapseButton.setAttribute('aria-label', collapsed ? '展开侧栏' : '折叠侧栏');
          }
        }

        function setDrawer(open) {
          const active = open && mobile.matches;
          root.classList.toggle('is-drawer-open', active);
          if (drawerButton) {
            drawerButton.setAttribute('aria-expanded', active ? 'true' : 'false');
            drawerButton.setAttribute('aria-label', active ? '关闭导航' : '打开导航');
          }
          if (overlay) {
            overlay.hidden = !active;
          }
          if (sidebar) {
            sidebar.inert = mobile.matches && !active;
          }
          if (shell) {
            shell.inert = active;
          }
          lockPageScroll(active);
          if (active) {
            const items = focusable();
            if (items.length) {
              items[0].focus();
            }
          }
        }

        try {
          setCollapsed(window.localStorage.getItem(COLLAPSE_KEY) === '1');
        }
        catch (error) {
          setCollapsed(document.documentElement.classList.contains('xedc-admin-collapsed'));
        }
        setDrawer(false);

        if (drawerButton) {
          drawerButton.addEventListener('click', () => {
            const willOpen = !root.classList.contains('is-drawer-open');
            if (willOpen) {
              returnFocus = drawerButton;
            }
            setDrawer(willOpen);
            if (!willOpen && returnFocus) {
              returnFocus.focus();
            }
          });
        }

        if (overlay) {
          overlay.addEventListener('click', () => {
            setDrawer(false);
            if (returnFocus) {
              returnFocus.focus();
            }
          });
        }

        if (collapseButton) {
          collapseButton.addEventListener('click', () => {
            const next = !root.classList.contains('is-collapsed');
            setCollapsed(next);
            try {
              window.localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
            }
            catch (error) {
              // Private browsing can reject storage. The class still applies.
            }
          });
        }

        root.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && root.classList.contains('is-drawer-open')) {
            event.stopPropagation();
            setDrawer(false);
            if (returnFocus) {
              returnFocus.focus();
            }
            return;
          }
          if (event.key !== 'Tab' || !root.classList.contains('is-drawer-open')) {
            return;
          }
          const items = focusable();
          if (!items.length) {
            return;
          }
          const first = items[0];
          const last = items[items.length - 1];
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          }
          else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        });

        const onViewport = () => {
          setDrawer(false);
        };
        if (typeof mobile.addEventListener === 'function') {
          mobile.addEventListener('change', onViewport);
        }
        else if (typeof mobile.addListener === 'function') {
          mobile.addListener(onViewport);
        }
      });
    },
  };

  Drupal.behaviors.xedcAdminNavGroups = {
    attach(context) {
      const stored = readGroups() || {};
      once('xedc-admin-nav-group', '[data-xedc-nav-group]', context).forEach((group) => {
        const id = group.getAttribute('data-xedc-nav-group');
        const button = group.querySelector('[data-xedc-nav-toggle]');
        const list = group.querySelector('[data-xedc-nav-list]');
        if (!id || !button || !list) {
          return;
        }
        let open = group.getAttribute('data-xedc-nav-open') === '1';
        if (Object.prototype.hasOwnProperty.call(stored, id)) {
          open = stored[id] === true;
        }

        const apply = (next, save) => {
          list.hidden = !next;
          button.setAttribute('aria-expanded', next ? 'true' : 'false');
          group.setAttribute('data-xedc-nav-open', next ? '1' : '0');
          if (!save) {
            return;
          }
          const current = readGroups() || {};
          current[id] = next;
          writeGroups(current);
        };

        apply(open, false);
        button.addEventListener('click', () => {
          apply(button.getAttribute('aria-expanded') !== 'true', true);
        });
      });
    },
  };
})(Drupal, once);
