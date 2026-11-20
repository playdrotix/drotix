/* DROTIX admin: chat moderation live updates (polling our own server), toast + short sound, mute toggle, highlight. */
(function () {
  var poll = document.querySelector('[data-chatpoll]');
  if (!poll) return;
  var url = poll.getAttribute('data-chatpoll'), latest = null;
  var muteBtn = document.getElementById('muteBtn'), dot = document.getElementById('liveDot'), toasts = document.getElementById('toasts');
  function muted() { try { return localStorage.getItem('drotix_chat_mute') === '1'; } catch (e) { return false; } }
  function setMuteLabel() { muteBtn.textContent = muted() ? 'Sound: off' : 'Sound: on'; }
  muteBtn.addEventListener('click', function () { try { localStorage.setItem('drotix_chat_mute', muted() ? '0' : '1'); } catch (e) {} setMuteLabel(); });
  setMuteLabel();

  var ctx = null;
  document.addEventListener('click', function () { if (!ctx && window.AudioContext) { try { ctx = new AudioContext(); } catch (e) {} } }, { once: true });
  function beep() {
    if (muted()) return;
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.16].forEach(function (t) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.value = t ? 660 : 880; g.gain.value = 0.08;
        o.connect(g); g.connect(ctx.destination); o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.12);
      });
    } catch (e) {}
  }
  function toast(msg) {
    var d = document.createElement('div'); d.className = 'toast'; d.textContent = msg;
    toasts.appendChild(d); setTimeout(function () { d.remove(); }, 6000);
  }
  function busy() { return document.querySelector('details.act[open]') || (document.activeElement && /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)); }
  function setCounts(c) {
    if (!c) return;
    var map = { cNew: 'new', cRev: 'review', cToday: 'today', cTotal: 'total' };
    Object.keys(map).forEach(function (id) { var el = document.getElementById(id); if (el && c[map[id]] != null) el.textContent = Number(c[map[id]]).toLocaleString(); });
  }

  // a new report shown after a reload: toast it
  try {
    var pend = sessionStorage.getItem('drotix_chat_toast');
    if (pend) { sessionStorage.removeItem('drotix_chat_toast'); toast(pend); }
  } catch (e) {}

  async function tick() {
    try {
      var j = await (await fetch(url, { credentials: 'same-origin', cache: 'no-store' })).json();
      if (!j.ok) { dot.textContent = '○ live paused'; return; }
      dot.innerHTML = '<i class="dot on"></i> live';
      setCounts(j.counts);
      if (latest === null) { latest = j.latest; return; }
      if (j.latest && j.latest !== latest) {
        latest = j.latest;
        var msg = 'Abusive chat blocked — ' + (j.username || 'a player');
        beep();
        var onReports = !/tab=(offenders|words)/.test(location.search);
        if (onReports && !busy()) {
          try { sessionStorage.setItem('drotix_chat_toast', msg); } catch (e) {}
          setTimeout(function () { location.reload(); }, 900);
        } else { toast(msg + ' (refresh to see it)'); }
      }
    } catch (e) { dot.textContent = '○ offline, retrying…'; }
  }
  tick(); setInterval(tick, 8000);

  var h = document.querySelector('tr.hl');
  if (h) h.scrollIntoView({ block: 'center' });
})();
