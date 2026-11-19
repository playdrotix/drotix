/* DROTIX admin: home banner form (image upload with a one-time signed link, live 16:7 preview, action fields). */
(function () {
  var form = document.getElementById('bnForm');
  if (!form) return;
  var file = document.getElementById('bnFile'), url = document.getElementById('bnUrl'), status = document.getElementById('bnStatus');
  var bar = document.getElementById('bnBar'), fill = bar.querySelector('i'), prev = document.getElementById('bnPreview');
  var title = document.getElementById('bnTitle'), sub = document.getElementById('bnSub'), act = document.getElementById('bnAct');
  var routeL = document.getElementById('bnRouteL'), urlL = document.getElementById('bnUrlL');
  var uploading = false;

  function render() {
    prev.innerHTML = '';
    var bn = document.createElement('div'); bn.className = 'bn';
    if (/^https:\/\//i.test(url.value.trim())) bn.style.backgroundImage = 'url("' + url.value.trim().replace(/"/g, '%22') + '")';
    else { var em = document.createElement('div'); em.className = 'bn-empty'; em.textContent = 'No image'; bn.appendChild(em); }
    if (title.value.trim() || sub.value.trim()) {
      var t = document.createElement('div'); t.className = 'bn-txt';
      if (title.value.trim()) { var b = document.createElement('b'); b.textContent = title.value.trim(); t.appendChild(b); }
      if (sub.value.trim()) { var s = document.createElement('span'); s.textContent = sub.value.trim(); t.appendChild(s); }
      bn.appendChild(t);
    }
    prev.appendChild(bn);
    var warn = [];
    if (title.value.length > 40) warn.push('title is longer than 40 characters');
    if (sub.value.length > 80) warn.push('subtitle is longer than 80 characters');
    if (!uploading) status.textContent = warn.length ? '⚠ ' + warn.join(', ') + ' (it may be cut off in the app).' : status.textContent.replace(/^⚠.*$/, '');
  }
  function actFields() { routeL.hidden = act.value !== 'route'; urlL.hidden = act.value !== 'url'; }
  [url, title, sub].forEach(function (el) { el.addEventListener('input', render); });
  act.addEventListener('change', actFields);
  actFields(); render();

  file.addEventListener('change', async function () {
    var f = file.files[0]; if (!f) return;
    if (['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) < 0) { status.textContent = '✗ Use a JPG, PNG or WebP image.'; file.value = ''; return; }
    if (f.size > 3 * 1048576) { status.textContent = '✗ The image is ' + (f.size / 1048576).toFixed(1) + ' MB: max 3 MB.'; file.value = ''; return; }
    uploading = true; bar.hidden = false; fill.style.width = '0';
    try {
      var img = new Image(); img.src = URL.createObjectURL(f);
      await new Promise(function (r) { img.onload = r; img.onerror = r; });
      var ratioNote = img.naturalWidth ? ' (' + img.naturalWidth + '×' + img.naturalHeight + (Math.abs(img.naturalWidth / img.naturalHeight - 16 / 7) > 0.15 ? ', not 16:7: it will be cropped' : '') + ')' : '';
      status.textContent = 'Getting an upload link…';
      var body = new FormData();
      body.append('_csrf', form.elements._csrf.value); body.append('action', 'sign'); body.append('type', f.type); body.append('size', String(f.size));
      var j = await (await fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin' })).json();
      if (!j.upload) throw new Error(j.error || 'Could not get an upload link.');
      await new Promise(function (resolve, reject) {
        var x = new XMLHttpRequest();
        x.open('PUT', j.upload);
        x.setRequestHeader('Content-Type', f.type);
        x.upload.onprogress = function (e) { if (e.lengthComputable) { var p = Math.round(e.loaded * 100 / e.total); fill.style.width = p + '%'; status.textContent = 'Uploading… ' + p + '%'; } };
        x.onload = function () { (x.status >= 200 && x.status < 300) ? resolve() : reject(new Error('Upload failed (HTTP ' + x.status + '): ' + x.responseText.slice(0, 200))); };
        x.onerror = function () { reject(new Error('Upload failed (network).')); };
        x.send(f);
      });
      url.value = j.public; fill.style.width = '100%';
      status.textContent = '✓ Uploaded ' + f.name + ratioNote + '.';
    } catch (err) { status.textContent = '✗ ' + err.message; file.value = ''; }
    finally { uploading = false; render(); }
  });

  form.addEventListener('submit', function (e) {
    if (uploading) { e.preventDefault(); e.stopImmediatePropagation(); alert('Wait until the upload has finished.'); }
  }, true);
})();
