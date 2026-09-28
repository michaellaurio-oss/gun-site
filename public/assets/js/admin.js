// Admin helpers: "tick all" checkbox and the photo uploader, which shrinks photos in the
// browser (max 2400px, rotated upright) before sending, so big phone photos upload quickly
// and stay under the host's upload limits. The server re-checks and re-encodes every file.
(function () {
  'use strict';

  var all = document.getElementById('check-all');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = all.checked; });
    });
  }

  // ---- Firearm picker: type to narrow the list; "Add a new firearm" carries the search text ----
  var pick = document.getElementById('firearm_id');
  var searchWrap = document.querySelector('[data-firearm-search]');
  if (pick && searchWrap) {
    var search = document.getElementById('firearm-search');
    var none = document.querySelector('[data-firearm-none]');
    var addLink = document.querySelector('[data-add-firearm]');
    var firearms = [];
    Array.prototype.forEach.call(pick.querySelectorAll('optgroup'), function (g) {
      Array.prototype.forEach.call(g.children, function (o) { firearms.push({ group: g.label, value: o.value, text: o.textContent }); });
    });
    searchWrap.hidden = false;

    var rebuild = function () {
      var words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
      var keep = pick.value;
      // Ignore spaces and punctuation on both sides, so "sp01" finds "SP-01" and "1022" finds "10/22".
      var compact = function (s) { return s.toLowerCase().replace(/[^a-z0-9]/g, ''); };
      var matches = firearms.filter(function (o) {
        var hay = compact(o.text);
        return words.every(function (w) { return hay.indexOf(compact(w)) >= 0; });
      });
      pick.innerHTML = '';
      var ph = document.createElement('option');
      ph.value = '';
      ph.textContent = words.length ? (matches.length ? matches.length + ' match' + (matches.length === 1 ? '' : 'es') + '…' : 'No matches') : 'Choose a firearm…';
      pick.appendChild(ph);
      var group = null, el = null;
      matches.forEach(function (o) {
        if (o.group !== group) { el = document.createElement('optgroup'); el.label = o.group; pick.appendChild(el); group = o.group; }
        var opt = document.createElement('option');
        opt.value = o.value; opt.textContent = o.text;
        el.appendChild(opt);
      });
      var stillThere = matches.some(function (o) { return o.value === keep; });
      pick.value = stillThere ? keep : (words.length && matches.length ? matches[0].value : '');
      none.hidden = !(words.length && !matches.length);
      if (addLink) {
        var base = addLink.getAttribute('data-base');
        addLink.href = search.value.trim() ? base + (base.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(search.value.trim()) : base;
      }
    };
    search.addEventListener('input', rebuild);
    search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); pick.focus(); } });
  }

  var form = document.querySelector('form[data-resize]');
  if (!form) return;
  var input = form.querySelector('input[type=file]');
  var status = form.querySelector('.upload-status');
  var MAX = 2400;
  var BATCH = 4;

  function shrink(file) {
    if (!window.createImageBitmap || !/^image\/(jpeg|png|webp)$/.test(file.type)) return Promise.resolve(file);
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var scale = Math.min(1, MAX / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas');
      c.width = Math.round(bmp.width * scale);
      c.height = Math.round(bmp.height * scale);
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(bmp, 0, 0, c.width, c.height);
      return new Promise(function (res) {
        c.toBlob(function (b) { res(b ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.9);
      });
    }).catch(function () { return file; });
  }

  function send(files) {
    var fd = new FormData();
    fd.append('csrf', form.querySelector('[name=csrf]').value);
    fd.append('action', 'upload');
    files.forEach(function (f) { fd.append('photos[]', f, f.name); });
    // getAttribute: form.action would return the hidden <input name="action">, not the URL.
    return fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('Server said ' + r.status);
    });
  }

  function upload(list) {
    var files = Array.prototype.slice.call(list).filter(function (f) { return /^image\//.test(f.type); });
    if (!files.length) return;
    input.disabled = true;
    var done = 0;
    status.textContent = 'Preparing ' + files.length + ' photo' + (files.length === 1 ? '' : 's') + '…';
    var chain = Promise.resolve();
    for (var i = 0; i < files.length; i += BATCH) {
      (function (group) {
        chain = chain.then(function () { return Promise.all(group.map(shrink)); })
          .then(function (small) { return send(small); })
          .then(function () { done += group.length; status.textContent = 'Uploaded ' + done + ' of ' + files.length + '…'; });
      })(files.slice(i, i + BATCH));
    }
    chain.then(function () {
      location.href = location.pathname + location.search + '#photos';
      location.reload();
    }).catch(function (e) {
      status.textContent = 'Upload stopped: ' + e.message + '. Reload the page to see what was saved.';
      input.disabled = false;
    });
  }

  input.addEventListener('change', function () { upload(input.files); });
  ['dragenter', 'dragover'].forEach(function (ev) {
    form.addEventListener(ev, function (e) { e.preventDefault(); form.classList.add('dragover'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    form.addEventListener(ev, function (e) { e.preventDefault(); form.classList.remove('dragover'); });
  });
  form.addEventListener('drop', function (e) { upload(e.dataTransfer.files); });
})();
