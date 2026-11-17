/* Live arena panel (footer) + notification bell/toast/inbox. Talks to /api/live/ , /api/submit/ (ping) , /api/notify/ */
(function () {
  'use strict';
  if (location.protocol === 'file:' && !window.__DROTIX_DEMO) { return; }
  var d = document, SVG = 'http://www.w3.org/2000/svg';
  var vid = null; try { vid = localStorage.getItem('drotix_vid'); } catch (e) {}
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches;
  function el(tag, cls, txt) { var n = d.createElement(tag); if (cls) { n.className = cls; } if (txt != null) { n.textContent = txt; } return n; }
  function shape(id) { var s = d.createElementNS(SVG, 'svg'); s.setAttribute('aria-hidden', 'true'); var u = d.createElementNS(SVG, 'use'); u.setAttribute('href', '#' + id); s.appendChild(u); return s; }

  /* ---------- odometer ---------- */
  function Odo(host, size, min) {
    var box = el('span', 'odo ' + size); host.appendChild(box);
    var digits = [], minD = min || 1;
    function addDigit() {
      var dg = el('span', 'dg'), rl = el('span', 'rl');
      for (var i = 0; i < 10; i++) { rl.appendChild(el('b', '', i)); }
      dg.appendChild(rl); box.appendChild(dg); digits.push(rl);
    }
    this.set = function (n) {
      var s = String(Math.max(0, Math.floor(n))); while (s.length < minD) { s = '0' + s; }
      while (digits.length < s.length) { addDigit(); }
      var off = digits.length - s.length;
      digits.forEach(function (rl, i) { var v = i < off ? 0 : +s.charAt(i - off); rl.style.transform = 'translateY(-' + (v * 1.25) + 'em)'; });
      box.setAttribute('aria-label', s); box.setAttribute('role', 'img');
    };
  }

  /* ---------- footer panel ---------- */
  var foot = d.querySelector('.site-footer'), panel = null, prev = null, oOnline, oTotal, oToday, roster, feed, MAXR = 24;
  function build() {
    var f = d.querySelector('.f-bottom'); if (!foot || !f) { return; }
    panel = el('section', 'live'); panel.hidden = true; panel.setAttribute('aria-label', 'Live arena status');
    panel.appendChild(el('div', 'live-scan'));
    var id = el('div', 'live-id'), eye = el('div', 'live-eye'); eye.appendChild(el('i'));
    var t = el('div', 'live-t'); t.appendChild(el('small', '', 'Live · The Arena')); t.appendChild(el('b', '', 'Players alive now'));
    id.appendChild(eye); id.appendChild(t);
    var big = el('div', 'live-stat'); id.appendChild(big);
    oOnline = new Odo(big, 'big', 3);
    var mid = el('div', 'live-mid'); roster = el('div', 'roster'); roster.setAttribute('aria-hidden', 'true'); feed = el('div', 'live-feed'); feed.setAttribute('aria-live', 'polite');
    mid.appendChild(roster); mid.appendChild(feed);
    var nums = el('div', 'live-nums'), a = el('div', 'live-stat t'), b = el('div', 'live-stat');
    a.appendChild(el('small', '', 'Visits today')); b.appendChild(el('small', '', 'Total visits'));
    oToday = new Odo(a, 'sm', 3); oTotal = new Odo(b, 'sm', 4);
    nums.appendChild(a); nums.appendChild(b);
    var sh = el('div', 'live-shapes'); sh.appendChild(shape('s-circle')); sh.appendChild(shape('s-tri')); sh.appendChild(shape('s-sq'));
    panel.appendChild(id); panel.appendChild(mid); panel.appendChild(nums); panel.appendChild(sh);
    var wrap = f.parentNode; wrap.insertBefore(panel, f);
  }
  function setRoster(n) {
    var want = Math.min(n, MAXR), have = roster.querySelectorAll('i:not(.out)'), i;
    for (i = have.length; i < want; i++) { roster.insertBefore(el('i'), roster.querySelector('em')); }
    for (i = have.length - 1; i >= want; i--) { (function (node) { node.className = 'out'; setTimeout(function () { if (node.parentNode) { node.parentNode.removeChild(node); } }, 950); })(have[i]); }
    var more = roster.querySelector('em');
    if (n > MAXR) { if (!more) { more = el('em'); roster.appendChild(more); } more.textContent = '+' + (n - MAXR); } else if (more) { roster.removeChild(more); }
  }
  function say(txt, cls) { feed.innerHTML = ''; feed.appendChild(el('span', cls, txt)); }
  function applyLive(j) {
    if (!panel || !j || !j.ok) { return; }
    panel.hidden = false;
    oOnline.set(j.online); oTotal.set(j.total); oToday.set(j.today); setRoster(j.online);
    if (prev !== null && j.online !== prev) {
      var df = j.online - prev;
      say(df > 0 ? '+' + df + (df > 1 ? ' players entered the arena' : ' player entered the arena') : Math.abs(df) + (df < -1 ? ' players left the game' : ' player left the game'), df > 0 ? 'up' : 'dn');
    }
    prev = j.online;
  }
  function pollLive() { if (d.hidden) { return; } fetch('/api/live/', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(applyLive).catch(function () {}); }
  function ping() {
    if (d.hidden || !vid) { return; }
    fetch('/api/submit/', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'ping', vid: vid, path: location.pathname }) }).catch(function () {});
  }

  /* ---------- notifications ---------- */
  var bell, cnt, pan, list, toastBox, items = [], unread = 0, titleBase = d.title, polling = false, shown = {};
  try { shown = JSON.parse(localStorage.getItem('drotix_toasted') || '{}') || {}; } catch (e) { shown = {}; }
  function ago(iso) {
    var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) { return 'just now'; } if (s < 3600) { return Math.floor(s / 60) + ' min ago'; }
    if (s < 86400) { return Math.floor(s / 3600) + ' h ago'; } return Math.floor(s / 86400) + ' d ago';
  }
  function bellSvg() {
    var s = d.createElementNS(SVG, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    var p1 = d.createElementNS(SVG, 'path'); p1.setAttribute('d', 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9'); var p2 = d.createElementNS(SVG, 'path'); p2.setAttribute('d', 'M10.3 21a1.94 1.94 0 0 0 3.4 0');
    s.appendChild(p1); s.appendChild(p2); return s;
  }
  function buildUI() {
    if (bell) { return; }
    bell = el('button', 'nb'); bell.type = 'button'; bell.setAttribute('aria-label', 'Messages from Drotix'); bell.setAttribute('aria-expanded', 'false');
    bell.appendChild(bellSvg()); cnt = el('span', 'cnt'); cnt.hidden = true; bell.appendChild(cnt);
    pan = el('div', 'np'); pan.setAttribute('role', 'dialog'); pan.setAttribute('aria-label', 'Your messages');
    var h = el('div', 'np-h'), sh = el('span', 'sh'); sh.appendChild(shape('s-circle')); sh.appendChild(shape('s-tri')); sh.appendChild(shape('s-sq'));
    h.appendChild(sh); h.appendChild(el('span', '', 'Your messages'));
    var mk = el('button', '', 'Mark read'); mk.type = 'button'; mk.onclick = markRead; h.appendChild(mk);
    list = el('div', 'np-l'); pan.appendChild(h); pan.appendChild(list);
    toastBox = el('div', 'nt'); toastBox.setAttribute('aria-live', 'polite');
    d.body.appendChild(bell); d.body.appendChild(pan); d.body.appendChild(toastBox);
    bell.onclick = function () { setPanel(!pan.classList.contains('on')); };
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setPanel(false); } });
    d.addEventListener('click', function (e) { if (pan.classList.contains('on') && !pan.contains(e.target) && !bell.contains(e.target)) { setPanel(false); } });
  }
  function setPanel(open) { pan.classList.toggle('on', open); bell.setAttribute('aria-expanded', String(open)); if (open) { renderList(); markRead(); } }
  function renderList() {
    list.innerHTML = '';
    if (!items.length) { list.appendChild(el('div', 'np-e', 'No messages yet. When the Drotix team replies, it shows up here.')); return; }
    items.forEach(function (n, i) {
      var c = el('div', 'np-i' + (n.read ? '' : ' un')); c.style.animationDelay = (i * 60) + 'ms';
      c.appendChild(el('b', '', n.title)); if (n.body) { c.appendChild(el('p', '', n.body)); } c.appendChild(el('time', '', ago(n.created_at))); list.appendChild(c);
    });
  }
  function paint() {
    if (!bell) { return; }
    cnt.hidden = unread < 1; cnt.textContent = unread > 9 ? '9+' : unread; bell.classList.toggle('ring', unread > 0);
    d.title = unread > 0 ? '(' + unread + ') New message · ' + titleBase : titleBase;
  }
  function markRead() {
    if (!unread) { return; }
    items.forEach(function (n) { n.read = true; }); unread = 0; paint(); if (pan.classList.contains('on')) { renderList(); }
    fetch('/api/notify/', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ vid: vid, action: 'read' }) }).catch(function () {});
  }
  function toast(n) {
    var c = el('div', 'nt-c'), top = el('div', 'nt-top'); top.appendChild(shape('s-circle')); top.appendChild(shape('s-tri')); top.appendChild(shape('s-sq'));
    top.appendChild(el('span', '', 'New message')); top.appendChild(el('span', 'sp'));
    var x = el('button', 'nt-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Close'); top.appendChild(x);
    var b = el('div', 'nt-b'); b.appendChild(el('b', '', n.title)); if (n.body) { b.appendChild(el('p', '', n.body)); }
    c.appendChild(top); c.appendChild(b); c.appendChild(el('div', 'nt-bar')); toastBox.appendChild(c);
    var gone = false; function bye() { if (gone) { return; } gone = true; c.classList.add('bye'); setTimeout(function () { if (c.parentNode) { c.parentNode.removeChild(c); } }, 450); }
    x.onclick = function (e) { e.stopPropagation(); bye(); };
    c.onclick = function () { bye(); setPanel(true); };
    setTimeout(bye, 9000);
  }
  function applyNotify(j) {
    if (!j || !j.ok) { return; }
    items = j.items || []; unread = j.unread || 0;
    if (items.length) { buildUI(); }
    var fresh = items.filter(function (n) { return !n.read && !shown[n.id]; });
    if (fresh.length) {
      fresh.slice(0, 2).forEach(function (n, i) { setTimeout(function () { toast(n); }, i * 700); });
      fresh.forEach(function (n) { shown[n.id] = 1; }); try { localStorage.setItem('drotix_toasted', JSON.stringify(shown)); } catch (e) {}
    }
    paint(); if (pan && pan.classList.contains('on')) { renderList(); }
    if (items.length && !polling) { polling = true; setInterval(pollNotify, 25000); }
  }
  function pollNotify() { if (d.hidden || !vid) { return; } fetch('/api/notify/?vid=' + encodeURIComponent(vid), { cache: 'no-store' }).then(function (r) { return r.json(); }).then(applyNotify).catch(function () {}); }
  /* after the visitor sends a form we keep checking for the reply even if the inbox is still empty */
  var wait = false; try { wait = localStorage.getItem('drotix_inbox') === '1'; } catch (e) {}
  if (wait && !polling) { polling = true; setInterval(pollNotify, 25000); }
  window.addEventListener('drotix:sent', function () { if (!polling) { polling = true; setInterval(pollNotify, 25000); } });
  d.addEventListener('visibilitychange', function () { if (!d.hidden) { pollNotify(); pollLive(); } });

  build();
  if (panel) { pollLive(); setInterval(pollLive, 20000); }
  ping(); setInterval(ping, 45000);
  setTimeout(pollNotify, 1200);
})();
