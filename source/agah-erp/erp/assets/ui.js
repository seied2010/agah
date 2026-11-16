(function () {
    'use strict';
    var root = document.querySelector('.erp');
    if (!root) return;

    var storageKey = 'agah_erp_theme';
    var configured = root.getAttribute('data-theme') || 'system';
    var preferred = '';
    try { preferred = localStorage.getItem(storageKey) || ''; } catch (e) {}

    function systemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function activeTheme() {
        var value = preferred || configured;
        return value === 'system' ? systemTheme() : value;
    }
    function paintTheme() {
        var value = activeTheme();
        root.setAttribute('data-theme-active', value);
        document.body.setAttribute('data-agah-theme', value);
        document.documentElement.style.colorScheme = value;
        var toggle = root.querySelector('.erp-theme-toggle');
        if (toggle) {
            toggle.setAttribute('aria-pressed', value === 'dark' ? 'true' : 'false');
            toggle.title = value === 'dark' ? 'تغییر به حالت روشن' : 'تغییر به حالت تیره';
            toggle.querySelector('span').textContent = value === 'dark' ? '☀' : '◐';
        }
    }
    paintTheme();

    var themeToggle = root.querySelector('.erp-theme-toggle');
    if (themeToggle) themeToggle.addEventListener('click', function () {
        preferred = activeTheme() === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(storageKey, preferred); } catch (e) {}
        paintTheme();
    });
    if (window.matchMedia) {
        var scheme = window.matchMedia('(prefers-color-scheme: dark)');
        if (scheme.addEventListener) scheme.addEventListener('change', function () { if (!preferred && configured === 'system') paintTheme(); });
    }

    var navButton = root.querySelector('.erp-mobile-nav');
    var navigation = root.querySelector('.erp-navigation');
    function closeNav() {
        root.classList.remove('erp-nav-open');
        if (navButton) navButton.setAttribute('aria-expanded', 'false');
    }
    if (navButton && navigation) {
        navButton.addEventListener('click', function () {
            var open = root.classList.toggle('erp-nav-open');
            if (open) navigation.open = true;
            navButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        navigation.addEventListener('click', function (event) {
            if (event.target.closest('a') && window.matchMedia('(max-width: 820px)').matches) closeNav();
        });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeNav(); });
        document.addEventListener('click', function (event) {
            if (!root.classList.contains('erp-nav-open') || navigation.contains(event.target) || navButton.contains(event.target)) return;
            closeNav();
        });
    }

    root.querySelectorAll('.erp-card').forEach(function (card) {
        if (card.querySelector('form')) card.classList.add('erp-card-form');
        if (card.querySelector('.erp-table')) card.classList.add('erp-card-table');
    });

    var current = root.querySelector('.erp-navigation [aria-current="page"]');
    if (current && current.scrollIntoView && window.innerWidth > 820) current.scrollIntoView({block: 'nearest'});
}());
