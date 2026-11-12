<?php

/**
 * @file
 * Front-end theme preference (light / dark / system) boot script.
 */

/**
 * Inline JS: resolve stored preference before paint to avoid flash.
 */
function _xedc_theme_boot_script(): string {
  return "(function(){try{var p=localStorage.getItem('theme');var t;if(p==='dark'||p==='light'){t=p;}else{t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}var h=document.documentElement;h.setAttribute('data-bs-theme',t);h.setAttribute('data-theme',t);if(p==='light'||p==='dark'||p==='system'){h.setAttribute('data-theme-pref',p);}else{h.setAttribute('data-theme-pref','system');}}catch(e){}})();";
}
