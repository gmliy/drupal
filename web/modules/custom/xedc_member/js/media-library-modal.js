/**
 * @file
 * Media Library modal height and iOS touch scrolling.
 *
 * Drupal locks body scroll on .ui-dialog-content. On iPhone that lock
 * preventDefault()s a touch when the locked element itself cannot scroll,
 * which cancels the list inside it. The list scroller is .view-content.
 * A capture-phase listener lets that gesture through and still blocks
 * background scrolling at the list edges.
 *
 * The admin dialog shell also centers with transform. iOS Safari will not
 * finger-scroll an overflow child of a transformed fixed box, so height and
 * top are custom properties and transform is cleared in CSS.
 */
(function (Drupal, once) {
  'use strict';

  function mediaDialogs() {
    return [...document.querySelectorAll('.ui-dialog.media-library-widget-modal')];
  }

  function fit(dialog) {
    const vv = window.visualViewport;
    const vh = vv ? vv.height : window.innerHeight;
    const vw = vv ? vv.width : window.innerWidth;
    const offsetTop = vv ? vv.offsetTop : 0;
    const wide = vw >= 768;
    const gutter = wide ? 16 : 8;
    const height = Math.max(280, Math.round(Math.min(920, vh - gutter)));
    const top = wide
      ? Math.round(offsetTop + Math.max(8, (vh - height) / 2))
      : Math.round(offsetTop + 4);
    dialog.style.setProperty('--xedc-ml-h', height + 'px');
    dialog.style.setProperty('--xedc-ml-top', top + 'px');
    dialog.classList.toggle('xedc-ml-short', vh < 520);
  }

  function listScroller(dialog) {
    return dialog.querySelector('.js-media-library-view .view-content');
  }

  function allowListTouch(dialog) {
    if (dialog.xedcMlTouch) {
      return;
    }
    dialog.xedcMlTouch = true;
    let startY = 0;
    let moved = false;
    dialog.addEventListener('touchstart', (event) => {
      moved = false;
      startY = event.touches && event.touches[0] ? event.touches[0].clientY : 0;
    }, { capture: true, passive: true });
    dialog.addEventListener('touchmove', (event) => {
      const scroller = listScroller(dialog);
      if (!scroller || !event.target || !scroller.contains(event.target)) {
        return;
      }
      const y = event.touches && event.touches[0] ? event.touches[0].clientY : startY;
      if (Math.abs(y - startY) > 8) {
        moved = true;
      }
      const dy = y - startY;
      const atTop = scroller.scrollTop <= 0;
      const atBottom = scroller.scrollTop + scroller.clientHeight + 1 >= scroller.scrollHeight;
      const towardTop = dy > 0;
      const canScroll = (towardTop && !atTop) || (!towardTop && !atBottom);
      if (canScroll) {
        event.stopPropagation();
      }
    }, { capture: true });
    dialog.addEventListener('touchend', () => {
      if (!moved) {
        return;
      }
      dialog.xedcMlSuppressClick = true;
      window.setTimeout(() => {
        dialog.xedcMlSuppressClick = false;
        moved = false;
      }, 400);
    }, { capture: true });
    const content = dialog.querySelector('.ui-dialog-content');
    if (content) {
      const current = content.ontouchmove;
      if (typeof current === 'function' && !current.xedcMlList) {
        function wrapped(event) {
          const scroller = listScroller(dialog);
          if (scroller && event.target && scroller.contains(event.target)) {
            if (typeof event.stopPropagation === 'function') {
              event.stopPropagation();
            }
            return;
          }
          return current.call(content, event);
        }
        wrapped.xedcMlList = true;
        content.ontouchmove = wrapped;
      }
    }
    dialog.xedcMlMoved = () => moved;
    dialog.xedcMlResetMoved = () => {
      moved = false;
    };
  }

  function guardClicks(dialog) {
    const scroller = listScroller(dialog);
    if (!scroller || scroller.xedcMlClickGuard) {
      return;
    }
    scroller.xedcMlClickGuard = true;
    scroller.addEventListener('click', (event) => {
      if (!dialog.xedcMlSuppressClick) {
        return;
      }
      dialog.xedcMlSuppressClick = false;
      event.preventDefault();
      event.stopPropagation();
    }, true);
  }

  function lockList(scroller) {
    const api = window.bodyScrollLock;
    if (!scroller || !api || typeof api.lock !== 'function') {
      return;
    }
    const state = typeof api.getLockState === 'function' ? api.getLockState() : null;
    if (state && Array.isArray(state.lockedElements)) {
      for (let i = state.lockedElements.length - 1; i >= 0; i--) {
        const el = state.lockedElements[i];
        if (!el || !el.isConnected) {
          state.lockedElements.splice(i, 1);
        }
      }
      if (state.lockedElements.indexOf(scroller) !== -1) {
        return;
      }
    }
    api.lock(scroller);
  }

  function sync() {
    mediaDialogs().forEach((dialog) => {
      const content = dialog.querySelector('.ui-dialog-content');
      if (content) {
        content.style.removeProperty('height');
        content.style.removeProperty('max-height');
      }
      fit(dialog);
      allowListTouch(dialog);
      guardClicks(dialog);
    });
    once(
      'xedc-ml-modal-scroll',
      '.ui-dialog.media-library-widget-modal .js-media-library-view .view-content',
      document,
    ).forEach(lockList);
  }

  let scheduled = false;
  function schedule() {
    if (scheduled) {
      return;
    }
    scheduled = true;
    window.requestAnimationFrame(() => {
      scheduled = false;
      if (mediaDialogs().length) {
        sync();
      }
    });
  }

  Drupal.behaviors.xedcMediaLibraryModal = {
    attach(context) {
      once('xedc-ml-modal-root', 'html', context).forEach(() => {
        window.addEventListener('dialog:aftercreate', schedule);
        window.addEventListener('resize', schedule);
        if (window.visualViewport) {
          window.visualViewport.addEventListener('resize', schedule);
          window.visualViewport.addEventListener('scroll', schedule);
        }
        const observer = new MutationObserver(() => {
          if (mediaDialogs().length) {
            schedule();
          }
        });
        observer.observe(document.body, { childList: true, subtree: true });
      });
      if (mediaDialogs().length) {
        schedule();
      }
    },
  };
})(Drupal, once);
