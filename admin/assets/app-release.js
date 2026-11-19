/* DROTIX admin: app update form (Google Play only, no APK): version code warning + publish confirm. */
(function () {
  var form = document.getElementById('relForm');
  if (!form) return;
  var curCode = parseInt(form.getAttribute('data-cur-code'), 10) || 0;
  var curMin = parseInt(form.getAttribute('data-cur-min'), 10) || 0;
  var code = document.getElementById('relCode'), minEl = document.getElementById('relMin'), warn = document.getElementById('codeWarn');

  function warnCode() {
    var c = parseInt(code.value, 10) || 0, low = curCode && c <= curCode;
    warn.textContent = low ? '⚠ Must be higher than the current code ' + curCode + '.' : (curCode ? 'Current: ' + curCode : '');
    warn.style.color = low ? 'var(--gd)' : '';
  }
  code.addEventListener('input', warnCode); warnCode();

  form.addEventListener('submit', function (e) {
    var stop = function (m) { e.preventDefault(); e.stopImmediatePropagation(); if (m) alert(m); };
    var c = parseInt(code.value, 10) || 0, m = parseInt(minEl.value, 10) || 0;
    if (m > c) return stop('The minimum supported code cannot be higher than the version code.');
    var msg = 'Publish DROTIX ' + form.elements.version_name.value + ' (code ' + c + ')?\n\nIs this version already LIVE in Google Play? Players on older versions are sent to Google Play right away.';
    if (curCode && c <= curCode) msg += '\n\n⚠ The version code is NOT higher than the current one (' + curCode + ').';
    if (m > curMin) msg += '\n\nFORCED UPDATE: the minimum goes from ' + curMin + ' to ' + m + '. Every player below code ' + m + ' must update before they can play (no "Later").';
    if (!confirm(msg)) stop();
  }, true);
})();
