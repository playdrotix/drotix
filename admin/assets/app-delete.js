/* DROTIX admin: delete-account danger zone (button enabled only when the username is typed exactly + a note). */
(function () {
  var f = document.getElementById('delForm');
  if (!f) return;
  var user = document.getElementById('delUser'), note = document.getElementById('delNote'), btn = document.getElementById('delBtn');
  var want = f.getAttribute('data-username');
  function check() { btn.disabled = !(user.value === want && note.value.trim().length >= 3); }
  user.addEventListener('input', check); note.addEventListener('input', check); check();
  f.addEventListener('submit', function (e) {
    if (btn.disabled) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    var msg = 'PERMANENTLY DELETE the DROTIX account "' + want + '"?\n\n' +
      'This deletes the profile, username, photo, mobile, coins and coin/purchase history, matches, friends, chat, challenges, ' +
      'notifications, support tickets, reports and their screenshots.\n\nIt cannot be undone. The username can never be registered again.';
    if (!confirm(msg)) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);
})();
