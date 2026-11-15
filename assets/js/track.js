/* Drotix visitor counter: sends one small, anonymous "visit" per page view to /api/submit/ (saved in Supabase).
   Stores only a random id in the browser (localStorage). No name, no email, no tracking cookies. */
(function () {
  'use strict';
  try {
    if (navigator.doNotTrack === '1' || location.protocol === 'file:') { return; }
    var rnd = function () {
      var a = new Uint8Array(12), s = '';
      (window.crypto || window.msCrypto).getRandomValues(a);
      for (var i = 0; i < a.length; i++) { s += ('0' + a[i].toString(16)).slice(-2); }
      return s;
    };
    var vid = null, isNew = false;
    try { vid = localStorage.getItem('drotix_vid'); if (!vid) { vid = rnd(); localStorage.setItem('drotix_vid', vid); isNew = true; } } catch (e) { vid = rnd(); isNew = true; }
    var sid = null;
    try { sid = sessionStorage.getItem('drotix_sid'); if (!sid) { sid = rnd(); sessionStorage.setItem('drotix_sid', sid); } } catch (e) { sid = rnd(); }
    var w = window.innerWidth || 0;
    var payload = {
      type: 'visit', vid: vid, sid: sid, is_new: isNew,
      path: location.pathname, ref: document.referrer ? document.referrer.slice(0, 250) : '',
      device: w < 700 ? 'mobile' : (w < 1100 ? 'tablet' : 'desktop'),
      lang: navigator.language || '', screen: (screen.width || 0) + 'x' + (screen.height || 0)
    };
    var send = function () {
      fetch('/api/submit/', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).catch(function () {});
    };
    if (document.visibilityState === 'prerender') { document.addEventListener('visibilitychange', send, { once: true }); } else { send(); }
  } catch (e) {}
})();
