/**
 * OPNManager Collapsible Sidebar
 * Handles pinning, hover expand/collapse on desktop,
 * and overlay drawer on mobile. Vanilla JS, no jQuery.
 */

(function () {
  'use strict';

  var STORAGE_KEY = 'opnmgr-sidebar-pinned';
  var MOBILE_BREAKPOINT = 768;

  // Element references (resolved after DOM is ready).
  var sidebar, pinBtn, hamburgerBtn, backdrop;

  /**
   * Returns true when the viewport is at or below the mobile breakpoint.
   */
  function isMobile() {
    return window.innerWidth <= MOBILE_BREAKPOINT;
  }

  /**
   * Read the persisted pinned state from localStorage.
   */
  function loadPinnedState() {
    return localStorage.getItem(STORAGE_KEY) === 'true';
  }

  /**
   * Persist the pinned state and update DOM attributes.
   */
  function savePinnedState(pinned) {
    localStorage.setItem(STORAGE_KEY, pinned ? 'true' : 'false');
  }

  // --- Desktop behavior --------------------------------------------------

  function onSidebarMouseEnter() {
    if (isMobile()) return;
    sidebar.classList.add('expanded');
  }

  function onSidebarMouseLeave() {
    if (isMobile()) return;
    if (sidebar.classList.contains('pinned')) return;
    sidebar.classList.remove('expanded');
  }

  /**
   * Toggle the pinned state of the sidebar.
   */
  function togglePin() {
    var pinned = sidebar.classList.toggle('pinned');
    if (pinned) {
      sidebar.classList.add('expanded');
    }
    document.body.setAttribute('data-sidebar-pinned', pinned ? 'true' : 'false');
    savePinnedState(pinned);
    updatePinIcon(pinned);
  }

  /**
   * Update the pin button icon. Solid upright when pinned,
   * rotated when unpinned.
   */
  function updatePinIcon(pinned) {
    if (!pinBtn) return;
    var icon = pinBtn.querySelector('i');
    if (!icon) return;
    if (pinned) {
      icon.className = 'fas fa-thumbtack';
      icon.style.transform = 'none';
    } else {
      icon.className = 'fas fa-thumbtack';
      icon.style.transform = 'rotate(45deg)';
    }
  }

  // --- Mobile behavior ----------------------------------------------------

  /**
   * Open the sidebar as a mobile overlay.
   */
  function openMobileSidebar() {
    sidebar.classList.add('mobile-open');
    if (backdrop) backdrop.classList.add('show');
  }

  /**
   * Close the mobile sidebar overlay.
   */
  function closeMobileSidebar() {
    sidebar.classList.remove('mobile-open');
    if (backdrop) backdrop.classList.remove('show');
  }

  // --- Initialization -----------------------------------------------------

  function init() {
    sidebar = document.getElementById('sidebar');
    pinBtn = document.getElementById('sidebar-pin');
    hamburgerBtn = document.getElementById('hamburger-btn');
    backdrop = document.getElementById('sidebar-backdrop');

    if (!sidebar) return;

    // Restore pinned state on desktop.
    var pinned = loadPinnedState();
    if (pinned && !isMobile()) {
      sidebar.classList.add('pinned', 'expanded');
      document.body.setAttribute('data-sidebar-pinned', 'true');
    } else {
      document.body.setAttribute('data-sidebar-pinned', 'false');
    }
    updatePinIcon(pinned);

    // Desktop hover listeners.
    sidebar.addEventListener('mouseenter', onSidebarMouseEnter);
    sidebar.addEventListener('mouseleave', onSidebarMouseLeave);

    // Pin button.
    if (pinBtn) {
      pinBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        togglePin();
      });
    }

    // Hamburger button (mobile).
    if (hamburgerBtn) {
      hamburgerBtn.addEventListener('click', openMobileSidebar);
    }

    // Backdrop click closes mobile sidebar.
    if (backdrop) {
      backdrop.addEventListener('click', closeMobileSidebar);
    }

    // Clicking a menu link closes the mobile sidebar.
    sidebar.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (isMobile()) closeMobileSidebar();
      });
    });

    // Handle window resize to switch between mobile/desktop modes.
    window.addEventListener('resize', onResize);
  }

  /**
   * Clean up classes when switching between mobile and desktop viewports.
   */
  function onResize() {
    if (isMobile()) {
      // Entering mobile — remove desktop-only classes.
      sidebar.classList.remove('expanded', 'pinned');
      document.body.setAttribute('data-sidebar-pinned', 'false');
    } else {
      // Entering desktop — close any mobile overlay and restore pinned state.
      closeMobileSidebar();
      if (loadPinnedState()) {
        sidebar.classList.add('pinned', 'expanded');
        document.body.setAttribute('data-sidebar-pinned', 'true');
        updatePinIcon(true);
      }
    }
  }

  // Run init once the DOM is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
