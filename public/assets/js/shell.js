/**
 * CortenDesk console shell: sidebar, theme toggle, fullscreen, password
 * reveal and Bootstrap tooltips. Plain script, no jQuery, no build step.
 * Needs bootstrap.bundle.min.js loaded first.
 *
 * Sidebar states live on <html data-rd-sidebar="...">:
 *   expanded   full-width sidebar (wide screens)
 *   condensed  icon rail; labels fly out on hover (768-1140px, or toggled)
 *   offcanvas  hidden off-screen; the menu button slides it in over a
 *              backdrop (phones, below 768px)
 * The menu button switches between expanded and condensed for the current
 * page only. A resize re-derives the state from the width, as before.
 */
(function () {
    'use strict';

    var html = document.documentElement;
    var OPEN = 'rd-sidebar-open';

    function widthState() {
        var w = window.innerWidth;
        if (w < 768) {
            return 'offcanvas';
        }
        return w <= 1140 ? 'condensed' : 'expanded';
    }

    function setSidebar(state) {
        html.setAttribute('data-rd-sidebar', state);
        if (state !== 'offcanvas') {
            closeOffcanvas();
        }
    }

    // --- Off-canvas sidebar (phones) ------------------------------------

    function openOffcanvas() {
        if (html.classList.contains(OPEN)) {
            return;
        }
        html.classList.add(OPEN);
        var shade = document.createElement('div');
        shade.className = 'rd-sidebar-backdrop';
        shade.addEventListener('click', closeOffcanvas);
        document.body.appendChild(shade);
        document.body.style.overflow = 'hidden';
    }

    function closeOffcanvas() {
        html.classList.remove(OPEN);
        var shade = document.querySelector('.rd-sidebar-backdrop');
        if (shade) {
            shade.remove();
        }
        document.body.style.overflow = '';
    }

    function onMenuButton() {
        var state = html.getAttribute('data-rd-sidebar');
        if (state === 'offcanvas') {
            if (html.classList.contains(OPEN)) {
                closeOffcanvas();
            } else {
                openOffcanvas();
            }
            return;
        }
        setSidebar(state === 'condensed' ? 'expanded' : 'condensed');
    }

    // --- Theme ------------------------------------------------------------

    // The choice is saved on the account (POST /account/theme), so it holds in
    // every tab and browser (issue #94), and in localStorage for the sign-in
    // page. "system" follows the browser's colour scheme until the user picks.
    var systemLight = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;

    function resolveTheme(pref) {
        if (pref === 'system') {
            return systemLight && systemLight.matches ? 'light' : 'dark';
        }
        return pref === 'light' ? 'light' : 'dark';
    }

    function applyTheme(pref) {
        html.setAttribute('data-rd-theme-pref', pref);
        html.setAttribute('data-bs-theme', resolveTheme(pref));
        try {
            window.localStorage.setItem('rd-theme', pref);
        } catch (e) {
            // Storage blocked: the account still keeps the choice.
        }
    }

    function saveTheme(pref) {
        var token = document.querySelector('meta[name="csrf-token"]');
        if (!token || !window.fetch) {
            return;
        }
        window.fetch('/account/theme', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token.getAttribute('content'),
            },
            body: JSON.stringify({ theme: pref }),
        }).catch(function () {
            // Offline or signed out: this page still switched.
        });
    }

    function toggleTheme() {
        var next = html.getAttribute('data-bs-theme') === 'light' ? 'dark' : 'light';
        applyTheme(next);
        saveTheme(next);
    }

    if (systemLight && systemLight.addEventListener) {
        systemLight.addEventListener('change', function () {
            if (html.getAttribute('data-rd-theme-pref') === 'system') {
                html.setAttribute('data-bs-theme', resolveTheme('system'));
            }
        });
    }

    // My Account saves the choice through Livewire and announces it here.
    window.addEventListener('rd-theme', function (event) {
        var pref = event.detail && event.detail.theme;
        if (pref === 'dark' || pref === 'light' || pref === 'system') {
            applyTheme(pref);
        }
    });

    // --- Fullscreen -------------------------------------------------------

    function toggleFullscreen(event) {
        event.preventDefault();
        var doc = document;
        var root = doc.documentElement;
        if (!doc.fullscreenElement && !doc.webkitFullscreenElement) {
            var enter = root.requestFullscreen || root.webkitRequestFullscreen;
            if (enter) {
                enter.call(root);
            }
        } else {
            var exit = doc.exitFullscreen || doc.webkitExitFullscreen;
            if (exit) {
                exit.call(doc);
            }
        }
    }

    function syncFullscreenClass() {
        var on = !!(document.fullscreenElement || document.webkitFullscreenElement);
        html.classList.toggle('rd-is-fullscreen', on);
    }

    // --- Password reveal ---------------------------------------------------
    // Markup: an .input-group holding the <input> and a sibling
    // <div data-password="false"> with an eye icon inside.

    function togglePassword(button) {
        var input = button.parentElement && button.parentElement.querySelector('input');
        if (!input) {
            return;
        }
        var reveal = button.getAttribute('data-password') !== 'true';
        input.type = reveal ? 'text' : 'password';
        button.setAttribute('data-password', reveal ? 'true' : 'false');
        button.classList.toggle('show-password', reveal);
    }

    // --- Wire up -----------------------------------------------------------

    function init() {
        document.querySelectorAll('[data-rd-toggle="sidebar"]').forEach(function (el) {
            el.addEventListener('click', onMenuButton);
        });
        document.querySelectorAll('[data-rd-dismiss="sidebar"]').forEach(function (el) {
            el.addEventListener('click', closeOffcanvas);
        });
        document.querySelectorAll('[data-rd-toggle="theme"]').forEach(function (el) {
            el.addEventListener('click', toggleTheme);
        });
        document.querySelectorAll('[data-rd-toggle="fullscreen"]').forEach(function (el) {
            el.addEventListener('click', toggleFullscreen);
        });
        document.addEventListener('fullscreenchange', syncFullscreenClass);
        document.addEventListener('webkitfullscreenchange', syncFullscreenClass);

        document.querySelectorAll('[data-password]').forEach(function (el) {
            el.addEventListener('click', function () { togglePassword(el); });
        });

        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                window.bootstrap.Tooltip.getOrCreateInstance(el);
            });
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
                window.bootstrap.Popover.getOrCreateInstance(el);
            });
        }

        // Bring the current page's menu entry into view when the menu is
        // taller than the window (a phone, or a short laptop screen): an
        // entry more than 400px down is scrolled to sit 300px from the top.
        var scroller = document.querySelector('.rd-sidebar-scroll');
        var entries = scroller ? scroller.querySelectorAll('.rd-current > a') : [];
        var current = entries.length ? entries[entries.length - 1] : null;
        if (current) {
            var offset = current.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
            if (offset - 300 > 100) {
                scroller.scrollTop = offset - 300;
            }
        }

        var lastWidth = window.innerWidth;
        window.addEventListener('resize', function () {
            // Phones fire resize when the URL bar hides; only a width change
            // means the layout should be re-derived.
            if (window.innerWidth !== lastWidth) {
                lastWidth = window.innerWidth;
                setSidebar(widthState());
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
