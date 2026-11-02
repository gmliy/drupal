/**
 * @file
 * Drawer navigation: desktop flyout, phone drill-down, focus, scroll lock.
 */
(function (Drupal, once) {
  'use strict';

  const desktopQuery = '(min-width: 1024px)';
  let lockedScrollY = 0;
  let touchLock = null;

  function isDesktop() {
    return window.matchMedia(desktopQuery).matches;
  }

  function lockPageScroll(lock) {
    const root = document.documentElement;
    if (lock) {
      if (root.classList.contains('xedc-admin-scroll-lock')) {
        return;
      }
      lockedScrollY = window.scrollY || root.scrollTop || 0;
      root.classList.add('xedc-admin-scroll-lock');
      document.body.style.position = 'fixed';
      document.body.style.top = '-' + lockedScrollY + 'px';
      document.body.style.left = '0';
      document.body.style.right = '0';
      document.body.style.width = '100%';
      touchLock = function (event) {
        const scroller = event.target.closest('[data-xedc-nav-scroll]');
        if (scroller && scroller.scrollHeight > scroller.clientHeight + 1) {
          const atTop = scroller.scrollTop <= 0;
          const atBottom = scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 1;
          const touch = event.touches && event.touches[0];
          if (touch && scroller._xedcLastY != null) {
            const goingDown = touch.clientY > scroller._xedcLastY;
            if ((atTop && goingDown) || (atBottom && !goingDown)) {
              event.preventDefault();
            }
          }
          return;
        }
        event.preventDefault();
      };
      document.addEventListener('touchmove', touchLock, { passive: false });
      return;
    }
    if (!root.classList.contains('xedc-admin-scroll-lock')) {
      return;
    }
    root.classList.remove('xedc-admin-scroll-lock');
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    if (touchLock) {
      document.removeEventListener('touchmove', touchLock);
      touchLock = null;
    }
    window.scrollTo(0, lockedScrollY);
  }

  Drupal.behaviors.xedcAdminInputMode = {
    attach(context) {
      once('xedc-admin-input', 'html', context).forEach((root) => {
        const target = root.classList.contains('xedc-admin-active')
          ? root
          : document.querySelector('[data-xedc-front-nav]');
        if (!target) {
          return;
        }
        const setMode = (mode) => target.setAttribute('data-input', mode);
        root.addEventListener('pointerdown', () => setMode('pointer'), true);
        root.addEventListener('keydown', (event) => {
          const key = event.key;
          if (key === 'Tab' || key === 'Enter' || key === ' ' || key.indexOf('Arrow') === 0 || key === 'Escape') {
            setMode('keyboard');
          }
        }, true);
      });
    },
  };

  Drupal.behaviors.xedcAdminNavigation = {
    attach(context) {
      once('xedc-admin-navigation', '[data-xedc-admin]', context).forEach((root) => {
        const drawer = root.querySelector('[data-xedc-admin-sidebar]');
        const overlay = root.querySelector('[data-xedc-admin-overlay]');
        const shell = root.querySelector('[data-xedc-admin-shell]');
        const opener = root.querySelector('[data-xedc-admin-drawer]');
        const closer = root.querySelector('[data-xedc-admin-close]');
        if (!drawer || !opener) {
          return;
        }

        let open = false;
        const stack = ['root'];

        const pane = (id) => drawer.querySelector('[data-xedc-nav-pane="' + id + '"]');
        const parents = () => Array.from(drawer.querySelectorAll('[data-xedc-nav-parent]'));

        function focusables() {
          const sections = [];
          if (isDesktop()) {
            sections.push(pane('root'));
            if (stack.length > 1) {
              sections.push(pane(stack[stack.length - 1]));
            }
          }
          else {
            sections.push(pane(stack[stack.length - 1]));
          }
          const nodes = [];
          sections.forEach((section) => {
            if (!section || section.hidden) {
              return;
            }
            nodes.push(...Array.from(section.querySelectorAll('a[href], button:not([disabled])')));
          });
          return nodes.filter((element) => !element.closest('[hidden], [inert]'));
        }

        function focusWithoutRing(element) {
          if (!element) {
            return;
          }
          document.documentElement.setAttribute('data-input', 'pointer');
          element.focus({ preventScroll: true });
        }

        function showChild(id, record) {
          parents().forEach((button) => {
            const selected = id !== 'root' && button.getAttribute('data-xedc-nav-parent') === id;
            button.setAttribute('aria-expanded', selected ? 'true' : 'false');
          });
          drawer.querySelectorAll('[data-xedc-nav-pane]').forEach((section) => {
            const name = section.getAttribute('data-xedc-nav-pane');
            section.hidden = name !== 'root' && name !== id;
          });
          drawer.classList.toggle('is-drilled', id !== 'root' && !isDesktop());
          if (id === 'root') {
            stack.splice(0, stack.length, 'root');
            return;
          }
          if (record === false) {
            return;
          }
          if (isDesktop()) {
            stack.splice(0, stack.length, 'root', id);
            return;
          }
          if (stack[stack.length - 1] !== id) {
            stack.push(id);
          }
        }

        function setOpen(next) {
          open = next;
          root.classList.toggle('is-nav-open', open);
          drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
          drawer.inert = !open;
          if (isDesktop()) {
            drawer.removeAttribute('aria-modal');
          }
          else if (open) {
            drawer.setAttribute('aria-modal', 'true');
          }
          else {
            drawer.removeAttribute('aria-modal');
          }
          opener.setAttribute('aria-expanded', open ? 'true' : 'false');
          opener.setAttribute('aria-label', open ? '关闭导航' : '打开导航');
          if (overlay) {
            overlay.hidden = !open;
          }
          const pageLock = shell || document.querySelector('.dialog-off-canvas-main-canvas');
          if (pageLock && pageLock !== root && !drawer.contains(pageLock)) {
            pageLock.inert = open;
          }
          lockPageScroll(open);
          if (!open) {
            showChild('root');
          }
        }

        function openNav() {
          setOpen(true);
          const items = focusables();
          const first = items.find((element) => element.hasAttribute('data-xedc-nav-parent') || element.matches('a.xedc-admin-nav__link'));
          if (document.documentElement.getAttribute('data-input') === 'keyboard' && first) {
            first.focus({ preventScroll: true });
          }
        }

        function closeNav() {
          setOpen(false);
          focusWithoutRing(opener);
        }

        function openParent(id, fromKeyboard) {
          if (!open) {
            setOpen(true);
          }
          showChild(id);
          if (!fromKeyboard) {
            return;
          }
          const child = pane(id);
          const target = child && (isDesktop()
            ? child.querySelector('a[href]')
            : child.querySelector('[data-xedc-nav-back], a[href]'));
          if (target) {
            target.focus({ preventScroll: true });
          }
        }

        function back() {
          if (stack.length <= 1) {
            showChild('root', false);
            return;
          }
          const closed = stack.pop();
          showChild(stack[stack.length - 1], false);
          const button = drawer.querySelector('[data-xedc-nav-parent="' + closed + '"]');
          if (document.documentElement.getAttribute('data-input') === 'keyboard' && button) {
            button.focus({ preventScroll: true });
          }
        }

        drawer.inert = true;

        opener.addEventListener('click', () => {
          if (open) {
            closeNav();
          }
          else {
            openNav();
          }
        });
        if (closer) {
          closer.addEventListener('click', closeNav);
        }
        if (overlay) {
          overlay.addEventListener('click', closeNav);
        }

        parents().forEach((button) => {
          button.addEventListener('click', () => {
            const id = button.getAttribute('data-xedc-nav-parent');
            const already = button.getAttribute('aria-expanded') === 'true';
            if (already && isDesktop()) {
              showChild('root');
              return;
            }
            openParent(id, document.documentElement.getAttribute('data-input') === 'keyboard');
          });
        });

        function setGroup(button, open) {
          const row = button.closest('.xedc-admin-nav__acc');
          const sub = row ? row.querySelector('.xedc-admin-nav__sub') : null;
          button.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (row) {
            row.classList.toggle('is-open', open);
          }
          if (sub) {
            sub.inert = !open;
          }
        }

        drawer.addEventListener('click', (event) => {
          if (event.target.closest('[data-xedc-admin-close]')) {
            event.preventDefault();
            closeNav();
            return;
          }
          const group = event.target.closest('[data-xedc-nav-group]');
          if (group) {
            event.preventDefault();
            const paneNode = group.closest('[data-xedc-nav-pane]');
            const willOpen = group.getAttribute('aria-expanded') !== 'true';
            paneNode.querySelectorAll('[data-xedc-nav-group]').forEach((button) => {
              setGroup(button, willOpen && button === group);
            });
            return;
          }
          const backButton = event.target.closest('[data-xedc-nav-back]');
          if (backButton) {
            event.preventDefault();
            back();
          }
        });

        drawer.addEventListener('touchstart', (event) => {
          const scroller = event.target.closest('[data-xedc-nav-scroll]');
          if (scroller && event.touches[0]) {
            scroller._xedcLastY = event.touches[0].clientY;
          }
        }, { passive: true });
        drawer.addEventListener('touchmove', (event) => {
          const scroller = event.target.closest('[data-xedc-nav-scroll]');
          if (scroller && event.touches[0]) {
            scroller._xedcLastY = event.touches[0].clientY;
          }
        }, { passive: true });

        document.addEventListener('keydown', (event) => {
          if (!open || event.key !== 'Escape') {
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          closeNav();
        }, true);

        root.addEventListener('keydown', (event) => {
          if (!open || event.key === 'Escape') {
            return;
          }
          const activePane = document.activeElement && document.activeElement.closest('[data-xedc-nav-pane]');
          const items = activePane
            ? Array.from(activePane.querySelectorAll('a[href], button:not([disabled])')).filter((element) => !element.closest('[hidden], [inert]'))
            : focusables();
          const index = items.indexOf(document.activeElement);
          if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!items.length) {
              return;
            }
            event.preventDefault();
            const next = event.key === 'ArrowDown'
              ? items[(index + 1 + items.length) % items.length]
              : items[(index - 1 + items.length) % items.length];
            next.focus({ preventScroll: true });
            return;
          }
          if (event.key === 'ArrowRight') {
            const parent = document.activeElement && document.activeElement.closest('[data-xedc-nav-parent]');
            if (parent) {
              event.preventDefault();
              openParent(parent.getAttribute('data-xedc-nav-parent'), true);
            }
            return;
          }
          if (event.key === 'ArrowLeft') {
            if (stack.length > 1 || drawer.querySelector('[data-xedc-nav-parent][aria-expanded="true"]')) {
              event.preventDefault();
              back();
            }
            return;
          }
          if (event.key !== 'Tab' || !items.length) {
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

        const desktop = window.matchMedia(desktopQuery);
        const onViewport = () => {
          if (open) {
            closeNav();
          }
        };
        if (typeof desktop.addEventListener === 'function') {
          desktop.addEventListener('change', onViewport);
        }
      });
    },
  };

  Drupal.behaviors.xedcFrontLauncher = {
    attach(context) {
      once('xedc-front-launcher', '.xedc-admin--front .xedc-front-nav__button', context).forEach((button) => {
        const root = button.closest('.xedc-admin--front');
        if (!root) {
          return;
        }
        let last = window.scrollY || 0;
        let ticking = false;
        window.addEventListener('scroll', () => {
          if (ticking) {
            return;
          }
          ticking = true;
          window.requestAnimationFrame(() => {
            const y = window.scrollY || 0;
            const phone = window.matchMedia('(max-width: 767px)').matches;
            if (phone && y > last + 8 && y > 40) {
              root.classList.add('is-launcher-hidden');
            }
            else if (y < last - 8) {
              root.classList.remove('is-launcher-hidden');
            }
            last = y;
            ticking = false;
          });
        }, { passive: true });
      });
    },
  };
})(Drupal, once);
