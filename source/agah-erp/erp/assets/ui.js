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
    var navigation = root.querySelector('.erp-menu-sidebar>.erp-navigation');
    var mobileMedia = window.matchMedia('(max-width: 820px)');
    var workContent = root.querySelector('.erp-workspace-content'), companyBar = root.querySelector('.erp-top');
    function syncNav() {
        if (!navigation) return;
        var open = root.classList.contains('erp-nav-open') && mobileMedia.matches;
        navigation.inert = mobileMedia.matches && !open;
        navigation.setAttribute('aria-hidden', navigation.inert ? 'true' : 'false');
        if (workContent) workContent.inert = open;
        if (companyBar) companyBar.inert = open;
    }
    function closeNav(returnFocus) {
        root.classList.remove('erp-nav-open');
        if (navButton) navButton.setAttribute('aria-expanded', 'false');
        syncNav();
        if (returnFocus && navButton) navButton.focus({preventScroll:true});
    }
    if (navButton && navigation) {
        navigation.id = 'agah-work-navigation';navButton.setAttribute('aria-controls', navigation.id);syncNav();
        if(mobileMedia.addEventListener)mobileMedia.addEventListener('change',function(){closeNav(false);});
        navButton.addEventListener('click', function () {
            var open = root.classList.toggle('erp-nav-open');
            if (open) navigation.open = true;
            navButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            syncNav();
            if(open)navigation.querySelector('a')?.focus({preventScroll:true});
        });
        navigation.addEventListener('click', function (event) {
            if (event.target.closest('a') && window.matchMedia('(max-width: 820px)').matches) closeNav();
        });
        document.addEventListener('keydown', function (event) {
            if(!root.classList.contains('erp-nav-open') || !mobileMedia.matches)return;
            if(event.key==='Escape'){event.preventDefault();closeNav(true);return;}
            if(event.key!=='Tab')return;
            var stops=[navButton].concat(Array.from(navigation.querySelectorAll('a[href],button,summary,[tabindex="0"]')).filter(function(el){return !el.disabled&&(el.checkVisibility?el.checkVisibility():el.getClientRects().length&&!el.closest('details:not([open])>div'));}));
            var index=stops.indexOf(document.activeElement),next=event.shiftKey?index-1:index+1;
            if(index===-1||next<0||next>=stops.length){event.preventDefault();stops[next<0?stops.length-1:0].focus();}
        });
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
