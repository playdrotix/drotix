/* DROTIX admin: support tickets (quick replies, close confirm, priority toggle, live polling, scroll to newest). */
(function () {
  var text = document.getElementById('tkText');
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-insert]');
    if (!b || !text) return;
    var v = b.getAttribute('data-insert');
    text.value = text.value.trim() ? text.value.replace(/\s*$/, '\n\n') + v : v;
    text.focus();
  });

  // priority: unchecked box sends "normal"
  var st = document.querySelector('form[data-tkstatus]');
  if (st) {
    st.addEventListener('submit', function (e) {
      var pri = st.querySelector('[data-pri]'), norm = st.querySelector('[data-prinormal]');
      norm.disabled = pri.checked;
      if (st.elements.set_status.value === 'closed' && !confirm('Close this ticket? The player is notified.')) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
  }
  var rep = document.getElementById('tkReply');
  if (rep) {
    rep.addEventListener('submit', function (e) {
      var s = document.getElementById('tkSet').value;
      if (s === 'closed' && !confirm('Send this reply and CLOSE the ticket?')) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
  }

  // newest message in view
  var msgs = document.getElementById('tkMsgs');
  if (msgs) msgs.scrollTop = msgs.scrollHeight;

  // live updates: poll our own server (no Supabase key in the browser)
  var poll = document.querySelector('[data-tkpoll]'), live = document.getElementById('tkLive');
  if (!poll) return;
  var url = poll.getAttribute('data-tkpoll'), sig = null, shown = false;
  function busy() { return (text && text.value.trim() !== '') || document.querySelector('details.act[open]'); }
  async function tick() {
    try {
      var r = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      var j = await r.json();
      if (sig === null) { sig = j.sig; return; }
      if (j.sig !== sig) {
        if (!busy()) { location.reload(); return; }
        if (!shown && live) { shown = true; live.innerHTML = '<a href="" style="color:var(--gd)">● New activity: click to refresh</a>'; }
      }
      if (live && !shown) live.innerHTML = '<i class="dot on"></i> live';
    } catch (e) { if (live) live.textContent = '○ offline, retrying…'; }
  }
  tick(); setInterval(tick, 8000);
})();
