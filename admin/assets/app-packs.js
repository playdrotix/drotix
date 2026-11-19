/* DROTIX admin: coin pack form live preview (as in the app's Shop). */
(function () {
  var form = document.getElementById('pkForm');
  if (!form) return;
  var $ = function (id) { return document.getElementById(id); };
  var pid = $('pkPid');
  pid.addEventListener('input', function () { var p = pid.selectionStart; pid.value = pid.value.toLowerCase().replace(/[^a-z0-9_.]/g, ''); try { pid.setSelectionRange(p, p); } catch (e) {} });
  function inr(v) { v = Number(v) || 0; return '₹' + (Math.floor(v) === v ? v.toLocaleString('en-IN') : v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })); }
  function el(t, c, x) { var n = document.createElement(t); if (c) n.className = c; if (x != null) n.textContent = x; return n; }
  function render() {
    var coins = parseInt($('pkCoins').value, 10) || 0, bonus = parseInt($('pkBonus').value, 10) || 0, price = parseFloat($('pkPrice').value) || 0;
    var box = $('pkPreview'); box.innerHTML = '';
    var pk = el('div', 'pk'); pk.appendChild(el('div', 'pk-coin', '🪙'));
    var main = el('div', 'pk-main'); var b = el('b', '', coins.toLocaleString('en-IN') + ' coins ');
    if (bonus) b.appendChild(el('span', 'pk-bonus', '+ ' + bonus.toLocaleString('en-IN') + ' bonus'));
    main.appendChild(b); main.appendChild(el('span', '', $('pkTitle').value)); pk.appendChild(main);
    if ($('pkBadge').value.trim()) pk.appendChild(el('span', 'pk-badge', $('pkBadge').value.trim()));
    pk.appendChild(el('span', 'pk-price', inr(price)));
    box.appendChild(pk);
    $('pkRate').textContent = price > 0 ? ((coins + bonus) / price).toFixed(1) + ' coins per ₹1 · total ' + (coins + bonus).toLocaleString('en-IN') : '';
  }
  form.addEventListener('input', render);
  render();
})();
