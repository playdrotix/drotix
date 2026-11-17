/* ====== SITE SETTINGS ====== */
var SITE = {
    email: 'support@playdrotix.in',
    api: '/api/submit/',
    playStore: '#',
    appStore: '#',
    social: {
        instagram: 'https://www.instagram.com/playdrotix/',
        facebook: 'https://www.facebook.com/playdrotix',
        snapchat: 'https://www.snapchat.com/add/playdrotix',
        linkedin: 'https://www.linkedin.com/company/playdrotix/'
    }
};

/* ====== GLOBAL STATE ====== */
var DROTIX = {
    user: null,
    supabaseClient: null,
    realtimeChannels: {},
    unreadNotifications: 0
};

/* ====== UTILITIES ====== */
function $(sel, ctx) { return (ctx || document).querySelector(sel); }
function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
function createEl(tag, cls, txt) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (txt != null) el.textContent = txt;
    return el;
}
function svg(icon) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    u.setAttribute('href', '#' + icon);
    s.appendChild(u);
    return s;
}
function fmtTime(iso) {
    var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' h ago';
    return Math.floor(s / 86400) + ' d ago';
}

/* ====== AUTH STATE MANAGEMENT ====== */
function checkAuthState() {
    // Check for user session via meta tag or cookie
    var userMeta = $('meta[name="user-data"]');
    if (userMeta) {
        try {
            DROTIX.user = JSON.parse(userMeta.content);
            updateUIForAuth();
        } catch (e) {}
    }
}

function updateUIForAuth() {
    var isLoggedIn = !!DROTIX.user;
    $$('.auth-only').forEach(function(el) { el.style.display = isLoggedIn ? '' : 'none'; });
    $$('.guest-only').forEach(function(el) { el.style.display = isLoggedIn ? 'none' : ''; });
    $$('.user-coins').forEach(function(el) { el.textContent = (DROTIX.user && DROTIX.user.coins) || 0; });
    $$('.user-rating').forEach(function(el) { el.textContent = (DROTIX.user && DROTIX.user.rating) || 0; });
    $$('.user-username').forEach(function(el) { el.textContent = (DROTIX.user && DROTIX.user.username) || ''; });
}

/* ====== REAL-TIME NOTIFICATIONS ====== */
function initNotifications() {
    if (!DROTIX.user) return;

    // Poll for notifications (fallback if Supabase Realtime not available)
    function pollNotifications() {
        fetch('/api/notify/?vid=' + getVisitorId(), { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    updateNotificationBadge(data.unread || 0);
                    if (data.items && data.items.length) {
                        renderNotificationList(data.items);
                        showNewNotifications(data.items.filter(function(n) { return !n.read; }));
                    }
                }
            })
            .catch(function() {});
    }

    pollNotifications();
    setInterval(pollNotifications, 30000);

    // Mark as read when notification panel opens
    var bell = $('.notif-bell, [aria-label="Notifications"]');
    if (bell) {
        bell.addEventListener('click', function() {
            fetch('/api/notify/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ vid: getVisitorId(), action: 'read' })
            }).catch(function() {});
            updateNotificationBadge(0);
        });
    }
}

function getVisitorId() {
    try {
        var vid = localStorage.getItem('drotix_vid');
        if (!vid) {
            var ra = new Uint8Array(12);
            (window.crypto || window.msCrypto).getRandomValues(ra);
            vid = Array.prototype.map.call(ra, function(b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            localStorage.setItem('drotix_vid', vid);
        }
        return vid;
    } catch (e) { return ''; }
}

function updateNotificationBadge(count) {
    DROTIX.unreadNotifications = count;
    $$('.notif-badge, .cnt').forEach(function(el) {
        el.hidden = count < 1;
        el.textContent = count > 9 ? '9+' : count;
    });
    document.title = count > 0 ? '(' + count + ') ' + document.title.replace(/^\(\d+\)\s*/, '') : document.title.replace(/^\(\d+\)\s*/, '');
}

function renderNotificationList(items) {
    var list = $('.np-l, .notif-list');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<div class="np-e">No messages yet.</div>';
        return;
    }
    list.innerHTML = '';
    items.forEach(function(n, i) {
        var item = createEl('div', 'np-i' + (n.read ? '' : ' un'));
        item.style.animationDelay = (i * 60) + 'ms';
        item.innerHTML = '<b>' + escapeHtml(n.title) + '</b>' +
            (n.body ? '<p>' + escapeHtml(n.body) + '</p>' : '') +
            '<time>' + fmtTime(n.created_at) + '</time>';
        list.appendChild(item);
    });
}

function showNewNotifications(items) {
    items.slice(0, 2).forEach(function(n, i) {
        setTimeout(function() { showToast(n); }, i * 700);
    });
}

function showToast(n) {
    var box = $('.nt') || createToastContainer();
    var el = createEl('div', 'nt-c');
    el.innerHTML = '<div class="nt-top"><span>New message</span><button class="nt-x">×</button></div><div class="nt-b"><b>' + escapeHtml(n.title) + '</b>' + (n.body ? '<p>' + escapeHtml(n.body) + '</p>' : '') + '</div><div class="nt-bar"></div>';
    box.appendChild(el);
    var gone = false;
    function bye() { if (gone) return; gone = true; el.classList.add('bye'); setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 450); }
    el.querySelector('.nt-x').onclick = function(e) { e.stopPropagation(); bye(); };
    el.onclick = function() { bye(); openNotifPanel(); };
    setTimeout(bye, 9000);
}

function createToastContainer() {
    var box = createEl('div', 'nt');
    box.setAttribute('aria-live', 'polite');
    document.body.appendChild(box);
    return box;
}

function openNotifPanel() {
    var pan = $('.np');
    if (pan) { pan.classList.add('on'); pan.setAttribute('aria-expanded', 'true'); }
}

/* ====== SUPABASE REALTIME (if configured) ====== */
function initSupabaseRealtime() {
    // This would connect to Supabase Realtime if anon key is configured
    // For now, we use polling as fallback
    console.log('[Drotix] Realtime: polling mode active');
}

/* ====== GAME LOGIC HELPERS ====== */
function createBoard(boardEl, size, winCondition, mySymbol, boardState, onMove) {
    boardEl.style.gridTemplateColumns = 'repeat(' + size + ',1fr)';
    boardEl.innerHTML = '';
    var svgX = '<svg aria-hidden="true"><use href="#x"/></svg>';
    var svgO = '<svg aria-hidden="true"><use href="#o"/></svg>';

    boardState.forEach(function(v, i) {
        var cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'match-cell' + (v ? ' filled' : '');
        cell.dataset.index = i;
        cell.innerHTML = v === 'X' ? svgX : (v === 'O' ? svgO : '');
        cell.onclick = function() { if (!cell.classList.contains('filled')) onMove(i); };
        boardEl.appendChild(cell);
    });
}

function checkWinner(board, size, win) {
    var lines = [];
    for (var r = 0; r < size; r++) for (var c = 0; c < size; c++) {
        [[0,1],[1,0],[1,1],[1,-1]].forEach(function(d) {
            var er = r + d[0]*(win-1), ec = c + d[1]*(win-1);
            if (er < 0 || er >= size || ec < 0 || ec >= size) return;
            var line = [];
            for (var i = 0; i < win; i++) line.push((r + d[0]*i)*size + c + d[1]*i);
            lines.push(line);
        });
    }
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i], v = board[line[0]];
        if (v && line.every(function(idx) { return board[idx] === v; })) return { player: v, line: line };
    }
    return null;
}

/* ====== SHARE / DEEP LINK HELPERS ====== */
function shareRoom(code) {
    var url = window.location.origin + '/rooms/?code=' + code;
    if (navigator.share) {
        navigator.share({ title: 'Drotix Room: ' + code, url: url });
    } else {
        navigator.clipboard.writeText(url).then(function() { alert('Room link copied!'); });
    }
}

function handleDeepLink() {
    // Handle app deep links like drotix://room/ABC123
    var path = window.location.pathname;
    if (path.startsWith('/room/') || path.startsWith('/r/')) {
        var code = path.split('/').pop();
        window.location.href = '/rooms/?code=' + code;
    }
}

/* ====== FORM HANDLING ENHANCEMENTS ====== */
function enhanceForms() {
    var t0 = Date.now();
    $$('form[data-form]').forEach(function(f) {
        var msg = f.querySelector('.form-msg'), btn = f.querySelector('button[type=submit]');
        var show = function(ok, text) {
            if (msg) { msg.className = 'form-msg ' + (ok ? 'ok' : 'err'); msg.textContent = text; msg.style.display = 'block'; }
        };
        f.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!f.checkValidity()) { f.reportValidity(); return; }
            var data = {}; new FormData(f).forEach(function(v, k) { data[k] = v; });
            data.type = f.dataset.type || 'contact';
            try {
                var vid = getVisitorId();
                data.vid = vid;
            } catch (e0) {}
            if (f.dataset.source) { data.source = f.dataset.source; }
            data.elapsed = Date.now() - t0;
            var fallback = function() {
                var body = Object.keys(data).filter(function(k) { return ['website', 'type', 'elapsed'].indexOf(k) < 0; })
                    .map(function(k) { return k + ': ' + data[k]; }).join('\n');
                window.location.href = 'mailto:' + SITE.email + '?subject=' + encodeURIComponent(f.dataset.subject || 'Website message') + '&body=' + encodeURIComponent(body);
                show(true, 'Your email app should open with the message ready. Just press send.');
            };
            if (btn) btn.disabled = true;
            fetch(SITE.api, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(data) })
                .then(function(r) { return r.json().then(function(j) { return j; }, function() { return null; }); })
                .then(function(j) {
                    if (j && typeof j.ok === 'boolean') {
                        if (j.ok) { f.reset(); if (data.type === 'contact' || data.type === 'tournament') { try { localStorage.setItem('drotix_inbox', '1'); } catch (e1) {} window.dispatchEvent(new Event('drotix:sent')); } }
                        show(j.ok, j.message || (j.ok ? 'Thank you!' : 'Something went wrong.'));
                    } else { fallback(); }
                })
                .catch(fallback)
                .then(function() { if (btn) btn.disabled = false; });
        });
    });
}

/* ====== SHARE BUTTON ====== */
function initShareButton() {
    var sBtn = $('[data-share-btn]'), sPop = $('[data-share-pop]');
    if (!sBtn || !sPop) return;
    var canon = $('link[rel=canonical]');
    var pUrl = canon ? canon.href : location.href, pTitle = document.title;
    var pText = 'Drotix Tournament Gaming: Compete, survive, win!';
    var links = {
        wa: 'https://wa.me/?text=' + encodeURIComponent(pText + ' ' + pUrl),
        fb: 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(pUrl),
        li: 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(pUrl),
        sc: 'https://www.snapchat.com/scan?attachmentUrl=' + encodeURIComponent(pUrl)
    };
    Object.keys(links).forEach(function(k) { var a = sPop.querySelector('[data-share=' + k + ']'); if (a) a.href = links[k]; });
    var nat = sPop.querySelector('[data-share=native]');
    if (nat && navigator.share) { nat.hidden = false; nat.addEventListener('click', function() { navigator.share({ title: pTitle, text: pText, url: pUrl }).catch(function() {}); setShare(false); }); }
    var setShare = function(open) { sPop.hidden = !open; sBtn.setAttribute('aria-expanded', String(open)); };
    sBtn.addEventListener('click', function(e) { e.stopPropagation(); setShare(sPop.hidden); });
    document.addEventListener('click', function(e) { if (!sPop.hidden && !sPop.contains(e.target) && !sBtn.contains(e.target)) setShare(false); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && !sPop.hidden) { setShare(false); sBtn.focus(); } });
    var cp = sPop.querySelector('[data-share=copy]'), lab = sPop.querySelector('[data-copy-label]');
    if (cp) cp.addEventListener('click', function() {
        var done = function() { lab.textContent = 'Link copied!'; setTimeout(function() { lab.textContent = 'Copy link'; setShare(false); }, 1200); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(pUrl).then(done, function() { window.prompt('Copy this link', pUrl); }); }
        else { window.prompt('Copy this link', pUrl); }
    });
}

/* ====== INIT ====== */
(function() {
    'use strict';

    // File protocol fix
    if (location.protocol === 'file:') {
        $$('a[href]').forEach(function(a) {
            var h = a.getAttribute('href');
            if (!h || /^(https?:|mailto:|tel:|#)/i.test(h)) return;
            var m = h.match(/^([^#?]*)([?#].*)?$/), p = m[1], rest = m[2] || '';
            if (p === '' ) return;
            if (p === '.' || p === './' || /\/$/.test(p)) { a.setAttribute('href', (p === '.' ? './' : p) + 'index.html' + rest); }
        });
    }

    // Social links
    $$('[data-soc]').forEach(function(a) { var u = SITE.social && SITE.social[a.getAttribute('data-soc')]; if (u) a.href = u; });
    $$('[data-year]').forEach(function(n) { n.textContent = new Date().getFullYear(); });
    $$('[data-email]').forEach(function(a) { a.textContent = SITE.email; a.href = 'mailto:' + SITE.email; });
    $$('a[data-store]').forEach(function(a) {
        var u = a.dataset.store === 'play' ? SITE.playStore : SITE.appStore;
        if (u && u !== '#') { a.href = u; var s = a.querySelector('.soon-badge'); if (s) s.remove(); }
    });

    // Header scroll state
    var header = $('.site-header'), bar = $('.progress i');
    var onScroll = function() {
        var y = window.scrollY || document.documentElement.scrollTop, h = document.documentElement.scrollHeight - window.innerHeight;
        if (header) header.classList.toggle('scrolled', y > 24);
        if (bar) bar.style.width = (h > 0 ? Math.min(100, y / h * 100) : 0) + '%';
    };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

    // Mobile menu
    var burger = $('.burger'), menu = $('#menu'), root = document.documentElement;
    var setMenu = function(open) {
        if (!menu || !burger) return;
        if (open) {
            var hb = header.getBoundingClientRect().bottom, br = burger.getBoundingClientRect();
            menu.style.setProperty('--hb', Math.round(hb) + 'px');
            menu.style.setProperty('--bx', Math.round(br.left + br.width / 2) + 'px');
            menu.style.setProperty('--by', Math.round(br.top + br.height / 2) + 'px');
        }
        menu.classList.toggle('open', open); menu.setAttribute('aria-hidden', String(!open));
        try { menu.inert = !open; } catch (e) {}
        burger.setAttribute('aria-expanded', String(open)); burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        root.classList.toggle('menu-open', open);
        if (open) { setTimeout(function() { var a = menu.querySelector('a'); if (a) a.focus({ preventScroll: true }); }, 450); }
    };
    if (menu && burger) {
        try { menu.inert = true; } catch (e) {}
        burger.addEventListener('click', function() { setMenu(!menu.classList.contains('open')); });
        menu.querySelectorAll('a').forEach(function(a) { a.addEventListener('click', function() { setMenu(false); }); });
        document.addEventListener('keydown', function(e) {
            if (!menu.classList.contains('open')) return;
            if (e.key === 'Escape') { setMenu(false); burger.focus(); }
            if (e.key === 'Tab') {
                var f = [burger].concat($$('a', menu)), i = f.indexOf(document.activeElement);
                if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
            }
        });
        window.addEventListener('resize', function() { if (window.innerWidth > 820) setMenu(false); });
    }

    // Reveal animations
    var els = $$('.reveal');
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(es) {
            es.forEach(function(e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
        }, { threshold: .12 });
        els.forEach(function(e) { io.observe(e); });
    } else els.forEach(function(e) { e.classList.add('in'); });

    // Count animations
    $$('[data-count]').forEach(function(el) {
        var to = +el.dataset.count, done = false;
        var run = function() { if (done) return; done = true; var t0 = performance.now(); (function tick(t) { var p = Math.min(1, (t - t0) / 1200); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(tick); })(t0); };
        if ('IntersectionObserver' in window) { new IntersectionObserver(function(es, o) { if (es[0].isIntersecting) { run(); o.disconnect(); } }).observe(el); } else run();
    });

    // Initialize all modules
    checkAuthState();
    enhanceForms();
    initShareButton();
    initNotifications();
    initSupabaseRealtime();
    handleDeepLink();

    // Store links
    $$('a[data-store]').forEach(function(a) {
        var u = a.dataset.store === 'play' ? SITE.playStore : SITE.appStore;
        if (u && u !== '#') { a.href = u; var s = a.querySelector('.soon-badge'); if (s) s.remove(); }
    });
})();

/* ====== HELPER: escape HTML ====== */
function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}