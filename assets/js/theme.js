/**
 * OPNManager Theme Toggle System
 * Supports dark/light themes with localStorage persistence
 * and system preference detection.
 */

(function () {
  'use strict';

  var STORAGE_KEY = 'opnmgr-theme';

  /**
   * Determine the initial theme from localStorage, system preference, or default.
   */
  function getInitialTheme() {
    var stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'dark' || stored === 'light') {
      return stored;
    }
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
      return 'light';
    }
    return 'dark';
  }

  /**
   * Apply theme to the document and update the toggle button icon.
   */
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-bs-theme', theme);
    updateToggleIcon(theme);
  }

  /**
   * Update the toggle button icon: sun when dark (click to go light),
   * moon when light (click to go dark).
   */
  function updateToggleIcon(theme) {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    var icon = btn.querySelector('i');
    if (!icon) return;
    icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
  }

  /**
   * Toggle between dark and light themes.
   */
  window.toggleTheme = function () {
    var current = document.documentElement.getAttribute('data-theme') || 'dark';
    var next = current === 'dark' ? 'light' : 'dark';
    localStorage.setItem(STORAGE_KEY, next);
    applyTheme(next);
    document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: next } }));
  };

  // Apply theme immediately to prevent flash of unstyled content.
  applyTheme(getInitialTheme());

  // Listen for system preference changes. Only auto-switch if the user
  // has not manually saved a preference.
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', function (e) {
      if (localStorage.getItem(STORAGE_KEY)) return;
      applyTheme(e.matches ? 'light' : 'dark');
    });
  }
})();
