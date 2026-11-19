/* DROTIX admin: coupons page (uppercase code, Generate, Unlimited toggle, copy buttons). */
(function () {
  var code = document.getElementById('cpCode');
  if (code && !code.readOnly) {
    code.addEventListener('input', function () {
      var p = code.selectionStart;
      code.value = code.value.toUpperCase().replace(/[^A-Z0-9_]/g, '');
      try { code.setSelectionRange(p, p); } catch (e) {}
    });
  }
  var gen = document.getElementById('cpGen');
  if (gen) {
    gen.addEventListener('click', function () {
      var a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', out = '', r = new Uint32Array(8);
      crypto.getRandomValues(r);
      for (var i = 0; i < 8; i++) out += a[r[i] % a.length];
      code.value = out; code.focus();
    });
  }
  var unl = document.getElementById('cpUnl'), max = document.getElementById('cpMax');
  function syncMax() { if (!unl || !max) return; max.disabled = unl.checked; max.required = !unl.checked; if (unl.checked) max.value = ''; }
  if (unl) { unl.addEventListener('change', syncMax); syncMax(); }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy]');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    var t = b.getAttribute('data-copy'), old = b.innerHTML;
    var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.innerHTML = old; }, 1200); };
    if (navigator.clipboard) navigator.clipboard.writeText(t).then(done, function () { prompt('Copy the code:', t); });
    else prompt('Copy the code:', t);
  });
})();
