/* DROTIX admin: legal editor (sections add / delete / move), live preview, validation + publish confirm. */
(function () {
  var form = document.getElementById('legalForm');
  if (!form) return;
  var secs = document.getElementById('secs');
  var prev = document.getElementById('legalPreview');
  var errBox = document.getElementById('legalErr');

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function renumber() {
    Array.prototype.forEach.call(secs.querySelectorAll('.sec'), function (s, i) { s.querySelector('.sec-n').textContent = 'Section ' + (i + 1); });
  }
  function newSection() {
    var tpl = secs.querySelector('.sec');
    var s = tpl.cloneNode(true);
    s.querySelector('input').value = '';
    s.querySelector('textarea').value = '';
    secs.appendChild(s);
    renumber(); render();
    s.querySelector('input').focus();
  }
  document.getElementById('addSec').addEventListener('click', newSection);
  secs.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var s = b.closest('.sec');
    if (b.hasAttribute('data-del')) {
      if (secs.querySelectorAll('.sec').length === 1) { s.querySelector('input').value = ''; s.querySelector('textarea').value = ''; }
      else if (confirm('Delete this section?')) { s.remove(); }
    } else if (b.getAttribute('data-mv') === '-1' && s.previousElementSibling) { secs.insertBefore(s, s.previousElementSibling); }
    else if (b.getAttribute('data-mv') === '1' && s.nextElementSibling) { secs.insertBefore(s.nextElementSibling, s); }
    renumber(); render();
  });

  function val(name) { var f = form.elements[name]; return f ? f.value.trim() : ''; }
  function sections() {
    return Array.prototype.map.call(secs.querySelectorAll('.sec'), function (s) {
      return { heading: s.querySelector('input').value.trim(), body: s.querySelector('textarea').value.trim() };
    });
  }
  function render() {
    prev.innerHTML = '';
    var lp = el('div', 'lp');
    lp.appendChild(el('div', 'lp-title', val('title').toUpperCase()));
    var d = new Date();
    lp.appendChild(el('div', 'lp-date', 'Last updated ' + d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })));
    if (val('summary')) { var sm = el('div', 'lp-sum'); sm.appendChild(el('b', '', 'What changed')); sm.appendChild(document.createElement('br')); sm.appendChild(document.createTextNode(val('summary'))); lp.appendChild(sm); }
    sections().forEach(function (s) { if (!s.heading && !s.body) return; lp.appendChild(el('div', 'lp-h', s.heading)); lp.appendChild(el('div', 'lp-b', s.body)); });
    if (val('contact_website') || val('contact_email')) {
      var c = el('div', 'lp-contact'); c.appendChild(el('div', 'lp-ct', 'CONTACT US'));
      if (val('contact_website')) { var a = el('a', '', val('contact_website')); a.href = val('contact_website'); a.target = '_blank'; a.rel = 'noopener'; var w = el('div'); w.appendChild(a); c.appendChild(w); }
      if (val('contact_email')) { var m = el('a', '', val('contact_email')); m.href = 'mailto:' + val('contact_email'); var w2 = el('div'); w2.appendChild(m); c.appendChild(w2); }
      lp.appendChild(c);
    }
    prev.appendChild(lp);
  }
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  renumber(); render();

  // validate + confirm (capture: runs before the generic "disable while running" handler)
  form.addEventListener('submit', function (e) {
    var list = sections().filter(function (s) { return s.heading || s.body; });
    var bad = list.filter(function (s) { return !s.heading || !s.body; }).length;
    var problems = [];
    if (!val('title')) problems.push('a title');
    if (!list.length) problems.push('at least one section');
    if (bad) problems.push(bad + ' section(s) with an empty heading or body');
    if (problems.length) { e.preventDefault(); e.stopImmediatePropagation(); errBox.textContent = 'Please fix: ' + problems.join(', ') + '.'; return; }
    errBox.textContent = '';
    var must = form.elements.requires_acceptance && form.elements.requires_acceptance.checked;
    var msg = 'Publish a new version of the ' + form.getAttribute('data-doc') + '?\n\n' +
      (must ? 'ALL PLAYERS will be asked to agree again: every signed-in player gets the "I agree" screen right away.'
            : 'Players will NOT be asked to agree again.') + '\n\nPublished versions cannot be edited.';
    if (!confirm(msg)) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);
})();
