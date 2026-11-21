/* DROTIX admin: Nearby & Rank overview, live rank-up toasts (polls our own server every 10 s). */
(function () {
  var poll = document.querySelector('[data-rankpoll]');
  if (!poll) return;
  var url = poll.getAttribute('data-rankpoll'), last = null, toasts = document.getElementById('toasts');
  function toast(msg) { var d = document.createElement('div'); d.className = 'toast'; d.textContent = msg; toasts.appendChild(d); setTimeout(function () { d.remove(); }, 6000); }
  try { var p = sessionStorage.getItem('drotix_rank_toast'); if (p) { sessionStorage.removeItem('drotix_rank_toast'); toast(p); } } catch (e) {}
  async function tick() {
    try {
      var j = await (await fetch(url, { credentials: 'same-origin', cache: 'no-store' })).json();
      if (last === null) { last = j.id; return; }
      if (j.id && j.id !== last) {
        last = j.id;
        try { sessionStorage.setItem('drotix_rank_toast', 'Rank up: ' + j.text); } catch (e) {}
        location.reload();   // refresh the cards; the toast shows after the reload
      }
    } catch (e) {}
  }
  tick(); setInterval(tick, 10000);
})();
