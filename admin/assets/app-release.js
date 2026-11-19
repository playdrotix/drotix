/* DROTIX admin: app release form. SHA-256 in the browser, upload to Supabase Storage with a one-time signed link
   (made by the server, so no key is in the browser), progress bar, auto-filled read-only fields, publish confirm. */
(function () {
  var form = document.getElementById('relForm');
  if (!form) return;
  var curCode = parseInt(form.getAttribute('data-cur-code'), 10) || 0;
  var curMin = parseInt(form.getAttribute('data-cur-min'), 10) || 0;
  var code = document.getElementById('relCode'), minEl = document.getElementById('relMin');
  var file = document.getElementById('apkFile'), status = document.getElementById('apkStatus');
  var bar = document.getElementById('apkBar'), fill = bar.querySelector('i');
  var url = document.getElementById('apkUrl'), sha = document.getElementById('apkSha'), size = document.getElementById('apkSize');
  var platform = document.getElementById('relPlatform'), apkBox = document.getElementById('apkBox'), iosBox = document.getElementById('iosBox');
  var uploading = false;

  function warnCode() {
    var c = parseInt(code.value, 10) || 0;
    document.getElementById('codeWarn').textContent = (curCode && c <= curCode) ? '⚠ Must be higher than the current code ' + curCode + '.' : (curCode ? 'Current: ' + curCode : '');
    document.getElementById('codeWarn').style.color = (curCode && c <= curCode) ? 'var(--gd)' : '';
  }
  function togglePlatform() { var a = platform.value === 'android'; apkBox.hidden = !a; iosBox.hidden = a; }
  code.addEventListener('input', function () { warnCode(); if (url.value && file.files.length) { status.textContent = 'Version code changed: choose the APK again so it is stored under the new code.'; url.value = ''; } });
  platform.addEventListener('change', togglePlatform);
  warnCode(); togglePlatform();

  function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }

  file.addEventListener('change', async function () {
    var f = file.files[0]; if (!f) return;
    var c = parseInt(code.value, 10) || 0;
    url.value = ''; sha.value = ''; size.value = '';
    if (c <= 0) { status.textContent = 'Enter the version code first, then choose the file again.'; file.value = ''; return; }
    if (!/\.apk$/i.test(f.name)) { status.textContent = 'That is not an .apk file.'; file.value = ''; return; }
    uploading = true; bar.hidden = false; fill.style.width = '0';
    try {
      status.textContent = 'Computing SHA-256 of ' + f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)…';
      var digest = hex(await crypto.subtle.digest('SHA-256', await f.arrayBuffer()));
      status.textContent = 'Getting an upload link…';
      var body = new FormData();
      body.append('_csrf', form.elements._csrf.value); body.append('action', 'sign'); body.append('code', String(c));
      var res = await fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin' });
      var j = await res.json();
      if (!j.upload) throw new Error(j.error || 'Could not get an upload link.');
      await new Promise(function (resolve, reject) {
        var x = new XMLHttpRequest();
        x.open('PUT', j.upload);
        x.setRequestHeader('Content-Type', 'application/vnd.android.package-archive');
        x.setRequestHeader('x-upsert', 'true');
        x.upload.onprogress = function (e) { if (e.lengthComputable) { var p = Math.round(e.loaded * 100 / e.total); fill.style.width = p + '%'; status.textContent = 'Uploading… ' + p + '%'; } };
        x.onload = function () { (x.status >= 200 && x.status < 300) ? resolve() : reject(new Error('Upload failed (HTTP ' + x.status + '): ' + x.responseText.slice(0, 200))); };
        x.onerror = function () { reject(new Error('Upload failed (network).')); };
        x.send(f);
      });
      url.value = j.public; sha.value = digest; size.value = String(f.size);
      fill.style.width = '100%';
      status.textContent = '✓ Uploaded ' + f.name + '. URL, SHA-256 and size are filled in.';
    } catch (err) {
      status.textContent = '✗ ' + err.message; file.value = '';
    } finally { uploading = false; }
  });

  form.addEventListener('submit', function (e) {
    var stop = function (m) { e.preventDefault(); e.stopImmediatePropagation(); if (m) alert(m); };
    if (uploading) return stop('Wait until the upload has finished.');
    var c = parseInt(code.value, 10) || 0, m = parseInt(minEl.value, 10) || 0;
    if (platform.value === 'android' && (!url.value || !sha.value)) return stop('Upload the APK first.');
    if (m > c) return stop('The minimum supported code cannot be higher than the version code.');
    var msg = 'Publish DROTIX ' + form.elements.version_name.value + ' (code ' + c + ')?\n\nPlayers on an older version see the "Please update DROTIX" screen right away.';
    if (curCode && c <= curCode) msg += '\n\n⚠ The version code is NOT higher than the current one (' + curCode + ').';
    if (m > curMin) msg += '\n\nFORCED UPDATE: the minimum goes from ' + curMin + ' to ' + m + '. Every player below code ' + m + ' must update before they can play (no "Later").';
    if (!confirm(msg)) stop();
  }, true);
})();
