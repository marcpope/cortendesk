/**
 * CortenDesk early shell state. Loaded synchronously in <head>, before any
 * stylesheet paints, so the page never flashes the wrong theme or sidebar
 * width on load. Plain script, no build step.
 *
 * Theme: the layouts ship data-bs-theme="dark". A choice made with the topbar
 * toggle is kept in sessionStorage, so it lasts across reloads and page
 * changes in this tab and resets in a new one.
 *
 * Sidebar: the width class depends only on the viewport (see shell.js).
 */
(function () {
    'use strict';

    var html = document.documentElement;

    try {
        var theme = window.sessionStorage.getItem('rd-theme');
        if (theme === 'light' || theme === 'dark') {
            html.setAttribute('data-bs-theme', theme);
        }
    } catch (e) {
        // Storage blocked: keep the default the layout declares.
    }

    var width = window.innerWidth;
    html.setAttribute('data-rd-sidebar', width < 768 ? 'offcanvas' : (width <= 1140 ? 'condensed' : 'expanded'));
})();
