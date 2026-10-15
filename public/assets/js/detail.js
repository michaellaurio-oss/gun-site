// Gun detail page: Photos / Specifications tabs and the full-screen photo viewer.
(function () {
  'use strict';

  // ---------- Tabs (arrow keys move between tabs) ----------
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[role="tab"]'));
  function select(tab, focus) {
    tabs.forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) tab.focus();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { select(t, false); });
    t.addEventListener('keydown', function (e) {
      var n = null;
      if (e.key === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
      if (e.key === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
      if (e.key === 'Home') n = tabs[0];
      if (e.key === 'End') n = tabs[tabs.length - 1];
      if (n) { e.preventDefault(); select(n, true); }
    });
  });
  if (tabs.length) select(location.hash === '#specs' ? tabs[1] : tabs[0], false);

  // ---------- Photo viewer ----------
  var dataEl = document.getElementById('photo-data');
  var viewer = document.getElementById('viewer');
  if (!dataEl || !viewer) return;
  var photos = JSON.parse(dataEl.textContent);
  var main = document.getElementById('photo-main');
  var mainImg = main.querySelector('img');
  var thumbs = Array.prototype.slice.call(document.querySelectorAll('.thumb'));
  var img = document.getElementById('viewer-img');
  var pos = document.getElementById('viewer-pos');
  var live = document.getElementById('viewer-live');
  var current = 0;
  var opener = null;

  function label(i) { return photos[i].caption || 'Photo ' + (i + 1); }

  function setMain(i) {
    current = i;
    mainImg.src = photos[i].src;
    mainImg.alt = label(i);
    main.setAttribute('data-index', i);
    main.setAttribute('aria-label', 'Enlarge photo: ' + label(i));
    thumbs.forEach(function (t, k) {
      if (k === i) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current');
    });
  }

  // Click the enlarged photo again to zoom in on details; move the mouse (or drag a finger) to look around; click again to zoom out.
  var zoomed = false;
  function zoomTo(clientX, clientY) {
    // Measure the photo's normal (unzoomed) box: it is centred in its frame, and the transform doesn't change offsetWidth.
    var c = img.parentNode.getBoundingClientRect();
    var w = img.offsetWidth || 1, h = img.offsetHeight || 1;
    var left = c.left + (c.width - w) / 2, top = c.top + (c.height - h) / 2;
    var px = Math.min(100, Math.max(0, (clientX - left) / w * 100));
    var py = Math.min(100, Math.max(0, (clientY - top) / h * 100));
    img.style.transformOrigin = px + '% ' + py + '%';
  }
  function zoomOut() {
    zoomed = false;
    img.classList.remove('zoomed');
    img.style.transform = '';
    img.style.transformOrigin = '';
    img.parentNode.classList.remove('is-zoomed');
  }
  function zoomIn(clientX, clientY) {
    var shown = img.clientWidth || 1;
    var scale = Math.min(4, Math.max(2, (img.naturalWidth || shown * 2) / shown));   // about the photo's real pixels
    zoomed = true;
    img.classList.add('zoomed');
    img.parentNode.classList.add('is-zoomed');
    zoomTo(clientX, clientY);
    img.style.transform = 'scale(' + scale.toFixed(2) + ')';
  }
  img.addEventListener('click', function (e) {
    e.stopPropagation();
    if (zoomed) zoomOut(); else zoomIn(e.clientX, e.clientY);
  });
  img.addEventListener('mousemove', function (e) { if (zoomed) zoomTo(e.clientX, e.clientY); });
  img.addEventListener('touchmove', function (e) {
    if (!zoomed) return;
    e.preventDefault();
    zoomTo(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });

  function show(i) {
    var n = photos.length;
    i = (i + n) % n;
    zoomOut();
    setMain(i);
    img.src = photos[i].src;
    img.alt = label(i);
    pos.textContent = (i + 1) + ' / ' + n;
    live.textContent = label(i) + ', photo ' + (i + 1) + ' of ' + n;
  }

  function open(i, from) {
    opener = from || null;
    show(i);
    if (typeof viewer.showModal === 'function') viewer.showModal(); else viewer.setAttribute('open', '');
    document.getElementById('viewer-close').focus();
  }

  function close() {
    zoomOut();
    if (typeof viewer.close === 'function') viewer.close(); else viewer.removeAttribute('open');
  }

  // Left/right sides of the main photo: step through photos without opening the viewer.
  var inlineLive = document.getElementById('photo-live');
  function step(d) {
    var n = photos.length;
    setMain((current + d + n) % n);
    if (inlineLive) inlineLive.textContent = label(current) + ', photo ' + (current + 1) + ' of ' + n;
  }
  var prevBtn = document.getElementById('photo-prev');
  var nextBtn = document.getElementById('photo-next');
  if (prevBtn) prevBtn.addEventListener('click', function () { step(-1); });
  if (nextBtn) nextBtn.addEventListener('click', function () { step(1); });

  viewer.addEventListener('close', function () { if (opener) opener.focus(); });
  main.addEventListener('click', function () { open(current, main); });
  // Thumbnails switch the large photo; only the centre of the large photo opens the viewer.
  thumbs.forEach(function (t, k) {
    t.addEventListener('click', function () { step(k - current); });
  });
  document.getElementById('viewer-close').addEventListener('click', close);
  document.getElementById('viewer-prev').addEventListener('click', function () { show(current - 1); });
  document.getElementById('viewer-next').addEventListener('click', function () { show(current + 1); });
  viewer.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') { e.preventDefault(); show(current - 1); }
    if (e.key === 'ArrowRight') { e.preventDefault(); show(current + 1); }
    if (e.key === 'z' || e.key === 'Z' || e.key === '+' || e.key === '-') {   // keyboard zoom, centred
      e.preventDefault();
      if (zoomed) { zoomOut(); } else { var r = img.getBoundingClientRect(); zoomIn(r.left + r.width / 2, r.top + r.height / 2); }
    }
  });
  // Click on the dark backdrop area (not the photo or buttons) closes the viewer.
  viewer.addEventListener('click', function (e) {
    if (e.target === viewer || e.target.classList.contains('viewer-img')) close();
  });

  // Swipe on touch screens
  var x0 = null;
  viewer.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
  viewer.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0;
    if (!zoomed && Math.abs(dx) > 50) show(current + (dx < 0 ? 1 : -1));   // while zoomed a drag looks around instead
    x0 = null;
  });
})();
