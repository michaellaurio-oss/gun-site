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

  function show(i) {
    var n = photos.length;
    i = (i + n) % n;
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
    if (Math.abs(dx) > 50) show(current + (dx < 0 ? 1 : -1));
    x0 = null;
  });
})();
