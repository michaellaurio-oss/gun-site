// Admin helpers: "tick all" checkbox, firearm picker, condition grades, and the listing's
// "Add photos": photos are shrunk in the browser (max 2400px, rotated upright) before sending, so
// big phone photos upload quickly and stay under the host's upload limits. The server re-checks and re-encodes every file.
(function () {
  'use strict';

  var all = document.getElementById('check-all');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = all.checked; });
    });
  }

  // ---- Firearm: caliber / action dropdowns with "Add a new one…" ----
  // The text box shows only for "Add a new one". A value already on the list (any capitals) is
  // simply selected; anything else needs a yes to "Are you sure you want to use it?".
  document.querySelectorAll('select[data-choice]').forEach(function (sel) {
    var wrap = sel.parentNode.querySelector('.choice-new');
    var box = wrap.querySelector('input');
    var what = sel.getAttribute('data-choice');
    var known = Array.prototype.map.call(sel.options, function (o) { return o.value; })
      .filter(function (v) { return v && v !== '__new'; });
    function show() { wrap.hidden = sel.value !== '__new'; }
    function check() {
      var v = box.value.trim();
      if (!v) return true;
      var match = known.filter(function (k) { return k.toLowerCase() === v.toLowerCase(); })[0];
      if (match) {
        sel.value = match;
        box.value = '';
        show();
        return true;
      }
      if (box.getAttribute('data-ok') === v) return true;
      if (window.confirm('"' + v + '" isn\'t on the list of ' + what + 's. Are you sure you want to use it?')) {
        box.setAttribute('data-ok', v);
        return true;
      }
      box.focus();
      box.select();
      return false;
    }
    sel.addEventListener('change', function () { show(); if (sel.value === '__new') box.focus(); });
    box.addEventListener('change', check);
    sel.form.addEventListener('submit', function (e) {
      if (sel.value === '__new' && !check()) e.preventDefault();
    });
    show();
  });

  // ---- Forms that ask first (data-confirm), e.g. Tidy values > Merge ----
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!e.defaultPrevented && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // ---- Listing: "New" is only a condition grade for new guns ----
  // Used: drop the New grade (and reset it to "Not graded yet"). New: offer it again and pick it.
  var newUsed = document.getElementById('new_used');
  var grade = document.getElementById('condition');
  if (newUsed && grade) {
    newUsed.addEventListener('change', function () {
      var opt = grade.querySelector('option[value="New"]');
      if (newUsed.value === 'used') {
        if (opt) {
          if (grade.value === 'New') grade.value = '';
          opt.remove();
        }
      } else {
        if (!opt) {
          opt = document.createElement('option');
          opt.value = 'New';
          opt.textContent = 'New';
          grade.insertBefore(opt, grade.options[1] || null);  // right after "Not graded yet"
        }
        if (grade.value === '') grade.value = 'New';
      }
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

  // ---- Listing: "Add photos" above the Create / Save buttons ----
  // Shows the picked photos, then on submit shrinks them in the browser and sends them with
  // the rest of the form. Without JavaScript the form still uploads them as they are.
  var form = document.querySelector('form[data-photo-form]');
  if (!form) return;
  var input = document.getElementById('photos-input');
  var picks = document.getElementById('photo-picks');
  var status = form.querySelector('.upload-status');
  var MAX = 2400;
  var clicked = null;

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

  function chosen() {
    return Array.prototype.slice.call(input.files || []).filter(function (f) { return /^image\//.test(f.type); });
  }

  input.addEventListener('change', function () {
    picks.innerHTML = '';
    var files = chosen();
    files.forEach(function (f) {
      var fig = document.createElement('figure');
      var img = document.createElement('img');
      img.alt = '';
      img.src = URL.createObjectURL(f);
      var cap = document.createElement('figcaption');
      cap.textContent = f.name;
      fig.appendChild(img);
      fig.appendChild(cap);
      picks.appendChild(fig);
    });
    status.textContent = files.length ? files.length + ' photo' + (files.length === 1 ? '' : 's') + ' will be added when you save.' : '';
  });

  // Remember which button was pressed ("Create listing" or "... and add another").
  form.addEventListener('click', function (e) {
    var b = e.target.closest('button[type=submit]');
    if (b) clicked = b;
  });

  form.addEventListener('submit', function (e) {
    var files = chosen();
    if (!files.length || !window.fetch || !window.FormData) return;   // normal submit
    e.preventDefault();
    var buttons = form.querySelectorAll('button[type=submit]');
    buttons.forEach(function (b) { b.disabled = true; });
    status.textContent = 'Preparing ' + files.length + ' photo' + (files.length === 1 ? '' : 's') + '…';
    var fd = new FormData(form);
    fd.delete('photos[]');
    if (clicked && clicked.name) fd.append(clicked.name, clicked.value);
    fd.append('_js', '1');
    Promise.all(files.map(shrink)).then(function (small) {
      small.forEach(function (f) { fd.append('photos[]', f, f.name); });
      status.textContent = 'Saving and uploading ' + files.length + ' photo' + (files.length === 1 ? '' : 's') + '…';
      // getAttribute: form.action would return an <input name="action">, not the URL.
      return fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' });
    }).then(function (r) {
      if (!r.ok) throw new Error('the server said ' + r.status);
      var type = r.headers.get('Content-Type') || '';
      return type.indexOf('json') >= 0 ? r.json().then(function (j) { location.href = j.redirect; }) : (location.href = r.url);
    }).catch(function (err) {
      status.textContent = 'Saving stopped: ' + err.message + '. Nothing may have been saved; try again.';
      buttons.forEach(function (b) { b.disabled = false; });
    });
  });
})();
