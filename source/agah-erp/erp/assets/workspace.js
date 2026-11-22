/* Work continuity only. Form values and credentials never enter browser storage. */
(function () {
    'use strict';
    const root = document.querySelector('.erp[data-work-user]');
    if (!root || root.dataset.workUser === '0') return;
    const openRequestedPanel = () => { if (location.hash === '#invoice-delete') { const panel = root.querySelector('#invoice-delete'); if (panel) panel.open = true; } };
    openRequestedPanel();window.addEventListener('hashchange',openRequestedPanel);
    const storageKey = 'agah.workspace-return.v1';
    const labelTableControls = () => root.querySelectorAll('table input:not([type="hidden"]),table select,table textarea').forEach(el => {
        if(el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.labels?.length)return;
        const cell=el.closest('td,th'),table=el.closest('table'),row=el.closest('tr');
        const heading=table?.tHead?.rows[table.tHead.rows.length-1]?.cells[cell?.cellIndex]?.textContent.trim();
        el.setAttribute('aria-label',(heading||'ورودی جدول')+'، ردیف '+new Intl.NumberFormat('fa-IR').format((row?.sectionRowIndex||0)+1));
    });
    labelTableControls();
    let labelPending=false;
    new MutationObserver(() => {if(!labelPending){labelPending=true;queueMicrotask(()=>{labelPending=false;labelTableControls();});}}).observe(root,{childList:true,subtree:true});
    const scope = ['workUser', 'workCompany', 'workYear'].map(key => root.dataset[key]).join(':');
    const tracked = new Map(), lastField = new WeakMap();
    let leaving = false;
    const identity = form => ['act', 'id', 'entity', 'user_id', 'item_id'].map(key => {
        const el=form.elements.namedItem(key);return el?.type==='hidden'?el.value:'';
    }).join(':');
    const controls = form => [...form.elements].filter(el => el.name && !['hidden', 'submit', 'button', 'reset'].includes(el.type));
    // These signatures live only in memory and disappear when this page closes.
    const signature = form => JSON.stringify(controls(form).map(el => [el.name, el.type === 'file' ? [...el.files].map(file => [file.name, file.size, file.lastModified]) : el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.tagName === 'SELECT' ? [...el.selectedOptions].map(o => o.value) : el.value]));
    const dirty = form => tracked.has(form) && signature(form) !== tracked.get(form);
    const initialize = () => root.querySelectorAll('form[method="post"]').forEach(form => {
        const action = form.elements.namedItem('act')?.value;
        if (action && action !== 'module_backup_download' && !tracked.has(form)) tracked.set(form, signature(form));
    });
    if (document.readyState === 'complete') initialize();
    else window.addEventListener('load', initialize, {once: true});
    root.addEventListener('focusin', event => {
        const el = event.target;
        if (el.form && el.name && !['hidden', 'submit', 'button', 'password'].includes(el.type)) lastField.set(el.form, el);
    });
    window.addEventListener('beforeunload', event => {
        if (!leaving && [...tracked.keys()].some(form => form.isConnected && dirty(form))) {
            event.preventDefault(); event.returnValue = '';
        }
    });
    window.addEventListener('pageshow', () => { leaving = false; });

    const route = value => {
        const url = new URL(value, location.href);
        if (url.origin !== location.origin || url.pathname !== location.pathname) return null;
        return JSON.stringify([url.pathname, ...['c', 'p', 'id', 'edit', 'kind'].map(key => url.searchParams.get(key) || (key === 'p' ? 'dashboard' : ''))]);
    };
    const announce = text => {
        let status = root.querySelector('[data-work-status]');
        if (!status) {
            status = document.createElement('span'); status.dataset.workStatus = '1';
            status.className = 'erp-work-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
            const brand=root.querySelector('.erp-brand-home');if(brand)brand.insertAdjacentElement('afterend',status);else root.prepend(status);
        }
        status.title=text;status.setAttribute('aria-label',text);
        status.textContent=text.includes('با موفقیت')?'ثبت شد':text.includes('دریافت شد')?'دریافت شد':text.includes('در حال')?'در حال انجام…':text.includes('نیاز به بررسی')||text.includes('پاسخ سرور')?'بررسی ثبت':'ثبت نشد';
    };
    window.agahWorkspace = Object.freeze({
        allowSubmit(form) {
            return ![...tracked.keys()].some(other => other !== form && other.isConnected && dirty(other)) || window.confirm('در فرم دیگری تغییر ذخیره‌نشده دارید. با ادامه، آن تغییرات کنار گذاشته می‌شود. ادامه می‌دهید؟');
        },
        capture(form) {
            const el = lastField.get(form), field = el && el.isConnected ? {name: el.name, index: [...form.elements].filter(x => x.name === el.name).indexOf(el)} : null;
            return {route: route(location.href), x: window.scrollX, y: window.scrollY, form: identity(form), field,
                areas: [...root.querySelectorAll('.erp-scroll')].map(el => [el.scrollLeft, el.scrollTop])};
        },
        navigate(destination, state) {
            const url = new URL(destination, location.href);
            if (url.origin !== location.origin || !route(url.href)) throw new Error('Invalid work destination');
            const pending = {scope, created: Date.now(), target: route(url.href), state: state?.route === route(url.href) ? state : null};
            try { sessionStorage.setItem(storageKey, JSON.stringify(pending)); } catch (ignore) { /* Saving still works without storage. */ }
            leaving = true;
            location.assign(url.href);
        },
        announce,
    });

    let pending;
    try { const raw = sessionStorage.getItem(storageKey); sessionStorage.removeItem(storageKey); pending = raw && JSON.parse(raw); } catch (ignore) { return; }
    if (!pending || pending.scope !== scope || pending.target !== route(location.href) || !Number.isFinite(pending.created) || Date.now() - pending.created > 120000 || pending.created > Date.now()) return;
    announce('عملیات با موفقیت انجام شد.');
    const state = pending.state;
    if (!state) return; // New documents and different work pages start normally.
    let interrupted = false;
    ['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(type => window.addEventListener(type, () => { interrupted = true; }, {once: true, passive: true}));
    const restore = () => {
        if (interrupted) return;
        root.querySelectorAll('.erp-scroll').forEach((el, i) => {
            const point = state.areas?.[i];
            if (Array.isArray(point) && point.every(Number.isFinite)) { el.scrollLeft = point[0]; el.scrollTop = point[1]; }
        });
        const form = [...root.querySelectorAll('form')].find(el => identity(el) === state.form);
        const el = form && state.field && [...form.elements].filter(x => x.name === state.field.name)[state.field.index];
        if (el && !el.disabled && el.type !== 'password' && el.getClientRects().length) el.focus({preventScroll: true});
        if (Number.isFinite(state.x) && Number.isFinite(state.y)) window.scrollTo({left: state.x, top: state.y, behavior: 'instant'});
    };
    const ready = () => {
        requestAnimationFrame(() => requestAnimationFrame(restore));
        // Font metrics can change the table height; never override subsequent user movement.
        document.fonts?.ready.then(restore);
    };
    if (document.readyState === 'complete') ready(); else window.addEventListener('load', ready, {once: true});
})();
