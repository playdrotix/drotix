/* DROTIX App admin section: confirmations, busy buttons, row links, auto refresh. (admin.js handles plain data-confirm) */
(function () {
  function fmt(n) { return Number(n).toLocaleString(); }

  // Wallet adjust: confirmation with the projected balance (capture phase, runs before admin.js data-confirm)
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.hasAttribute || !f.hasAttribute('data-wallet')) return;
    var amt = parseInt(f.elements.amount.value, 10);
    var dir = f.elements.direction.value;
    var bal = parseInt(f.getAttribute('data-balance') || '0', 10) || 0;
    if (!(amt > 0)) { e.preventDefault(); e.stopImmediatePropagation(); alert('Enter an amount greater than 0.'); return; }
    if (!f.elements.note.value.trim()) { e.preventDefault(); e.stopImmediatePropagation(); alert('A note is required.'); return; }
    var after = dir === 'credit' ? bal + amt : bal - amt;
    var msg = (dir === 'credit' ? 'CREDIT ' : 'DEBIT ') + fmt(amt) + ' coins ' + (dir === 'credit' ? 'to ' : 'from ') + f.getAttribute('data-user') +
      '?\n\nBalance: ' + fmt(bal) + '  →  ' + fmt(after) + (after < 0 ? '  (will be refused: not enough coins)' : '');
    if (!window.confirm(msg)) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);

  // Double confirmation (broadcast to everyone)
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var m2 = f && f.getAttribute && f.getAttribute('data-confirm2');
    if (!m2) return;
    if (!window.confirm(f.getAttribute('data-confirm1') || 'Are you sure?') || !window.confirm(m2)) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);

  // Disable buttons while the request runs (after any confirm has passed)
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target;
    if (!f || f.method.toLowerCase() !== 'post') return;
    setTimeout(function () {
      Array.prototype.forEach.call(f.querySelectorAll('button,[type=submit]'), function (b) { b.disabled = true; if (b.tagName === 'BUTTON') b.textContent = 'Working…'; });
    }, 0);
  });

  // Clickable table rows
  document.addEventListener('click', function (e) {
    var tr = e.target.closest && e.target.closest('tr[data-href]');
    if (!tr || e.target.closest('a,button,input,select,textarea,summary,form')) return;
    if (e.ctrlKey || e.metaKey) { window.open(tr.getAttribute('data-href'), '_blank'); } else { location.href = tr.getAttribute('data-href'); }
  });

  // Close other action pop-ups when one opens
  document.addEventListener('toggle', function (e) {
    if (e.target.matches && e.target.matches('details.act') && e.target.open) {
      Array.prototype.forEach.call(document.querySelectorAll('details.act[open]'), function (d) { if (d !== e.target) d.open = false; });
    }
  }, true);

  // Auto refresh: <div data-autorefresh="60">
  var ar = document.querySelector('[data-autorefresh]');
  if (ar) {
    var s = parseInt(ar.getAttribute('data-autorefresh'), 10) || 60;
    setInterval(function () { if (!document.querySelector('details.act[open]')) location.reload(); }, s * 1000);
  }
})();
