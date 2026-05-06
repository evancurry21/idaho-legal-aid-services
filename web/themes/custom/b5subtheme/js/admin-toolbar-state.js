/**
 * @file
 * Restore admin navigation toolbar expanded state from localStorage before
 * first paint. Externalised from inline <script> in navigation templates so
 * CSP can drop 'unsafe-inline' from script-src.
 */
(function () {
  'use strict';
  if (localStorage.getItem('Drupal.navigation.sidebarExpanded') !== 'false') {
    document.documentElement.classList.add('admin-toolbar-expanded');
  }
})();
