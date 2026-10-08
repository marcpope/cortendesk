/**
 * CortenDesk early shell state. Loaded synchronously in <head>, before any
 * stylesheet paints, so the page never flashes the wrong theme or sidebar
 * width on load. Plain script, no build step.
 *
 * Theme (issue #94): signed-in pages carry the user's saved choice in
 * data-rd-theme-pref (dark, light or system), rendered by the server. Pages
 * without it, such as the sign-in page, use the last choice made in this
 * browser (localStorage "rd-theme"). "system" follows prefers-color-scheme.
 *
 * Sidebar: the width class depends only on the viewport (see shell.js).
 */
(function () {
    'use strict';

    var html = document.documentElement;

    var pref = html.getAttribute('data-rd-theme-pref');

    try {
        if (pref) {
            // Keep this browser's sign-in page in step with the account.
            window.localStorage.setItem('rd-theme', pref);
        } else {
            pref = window.localStorage.getItem('rd-theme');
        }
    } catch (e) {
        // Storage blocked: the server-rendered theme still applies.
    }

    if (pref === 'system') {
        pref = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    if (pref === 'light' || pref === 'dark') {
        html.setAttribute('data-bs-theme', pref);
    }

    var width = window.innerWidth;
    html.setAttribute('data-rd-sidebar', width < 768 ? 'offcanvas' : (width <= 1140 ? 'condensed' : 'expanded'));
})();
