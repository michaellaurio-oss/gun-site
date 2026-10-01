// Compare page: keeps the saved picks in step with the URL, "Show differences only",
// "Copy link", phone paging (2 guns at a time, buttons or swipe) and the compact gun bar
// that sticks to the top of the window once the gun photos have scrolled away.
(function () {
  'use strict';

  var DATA = JSON.parse(document.getElementById('cmp-data').textContent);
  var $ = function (id) { return document.getElementById(id); };

  // The URL is the source of truth on this page (it may be a link someone shared).
  if (DATA.sync && window.Compare) window.Compare.save(DATA.guns);

  var table = $('cmp-table');
  if (!table) {
    if (DATA.cleanUrl) history.replaceState(null, '', DATA.cleanUrl);
    return;
  }
  var n = DATA.guns.length;

  function pageUrl(ids, diff) {
    return DATA.base + '?ids=' + ids.join(',') + (diff ? '&diff=1' : '');
  }

  // ---------- Show differences only ----------
  var diffBox = $('cmp-diff');
  function setDiff(on) {
    table.classList.toggle('diff-only', on);
    $('cmp-shown').textContent = on ? 'Showing differences only' : 'All details';
    history.replaceState(null, '', pageUrl(DATA.ids, on));
    // Removing a gun keeps the choice.
    table.querySelectorAll('[data-remove-ids]').forEach(function (a) {
      var rest = a.getAttribute('data-remove-ids');
      a.href = pageUrl(rest ? rest.split(',') : [], on);
    });
  }
  diffBox.addEventListener('change', function () { setDiff(diffBox.checked); });
  setDiff(diffBox.checked);  // also tidies the URL (drops guns that are no longer listed)

  // ---------- Copy link / Share ----------
  var copyBtn = $('cmp-copy');
  var copyText = $('cmp-copy-text');
  var phone = window.matchMedia('(max-width: 799px)');
  function canShare() { return phone.matches && typeof navigator.share === 'function'; }
  function copyLabel() { copyText.textContent = canShare() ? 'Share' : 'Copy link'; }
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }
  var copyTimer;
  copyBtn.addEventListener('click', function () {
    var link = location.href;
    if (canShare()) {
      navigator.share({ title: document.title, url: link }).catch(function () {});
      return;
    }
    var done = function (ok) {
      copyText.textContent = ok ? 'Link copied' : 'Copy failed';
      clearTimeout(copyTimer);
      copyTimer = setTimeout(copyLabel, 3000);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(link).then(function () { done(true); }, function () { done(fallbackCopy(link)); });
    } else {
      done(fallbackCopy(link));
    }
  });

  // ---------- Phone: 2 guns at a time ----------
  // "Show differences only" is decided across all compared guns (server side), not just the 2 on screen.
  var start = 0;
  var pager = $('cmp-pager');
  var prevBtn = $('cmp-prev');
  var nextBtn = $('cmp-next');
  var cells = table.querySelectorAll('[data-col]');
  function layout() {
    var on = phone.matches;
    var last = Math.max(0, n - 2);
    start = on ? Math.max(0, Math.min(start, last)) : 0;
    pager.hidden = !on || n <= 2;
    cells.forEach(function (el) {
      var c = parseInt(el.getAttribute('data-col'), 10);
      el.classList.toggle('off', on && (c < start || c > start + 1));
      el.classList.toggle('second', on && c === start + 1);
    });
    $('cmp-range').textContent = (start + 1) + '–' + Math.min(n, start + 2);
    prevBtn.disabled = start <= 0;
    nextBtn.disabled = start >= last;
    copyLabel();
  }
  function step(d) {
    start += d * 2;
    layout();
  }
  prevBtn.addEventListener('click', function () { step(-1); });
  nextBtn.addEventListener('click', function () { step(1); });
  if (phone.addEventListener) phone.addEventListener('change', layout); else phone.addListener(layout);

  var x0 = null;
  var y0 = null;
  table.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
  table.addEventListener('touchend', function (e) {
    if (x0 === null || !phone.matches || n <= 2) return;
    var dx = e.changedTouches[0].clientX - x0;
    var dy = e.changedTouches[0].clientY - y0;
    x0 = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) step(dx < 0 ? 1 : -1);
  });
  layout();

  // ---------- Photo arrows (step through each gun's photos) ----------
  table.addEventListener('click', function (e) {
    var btn = e.target.closest('.cmp-pnav');
    if (!btn) return;
    var wrap = btn.closest('.cmp-photo-wrap');
    var photos = JSON.parse(wrap.getAttribute('data-photos'));
    var i = parseInt(wrap.getAttribute('data-index') || '0', 10);
    i = (i + parseInt(btn.getAttribute('data-step'), 10) + photos.length) % photos.length;
    wrap.setAttribute('data-index', i);
    wrap.querySelector('img').src = photos[i];
    wrap.querySelector('.cmp-pcount').textContent = (i + 1) + ' / ' + photos.length;
  });

  // ---------- Compact gun bar while scrolling ----------
  var mini = $('cmp-mini');
  var heads = $('cmp-heads');
  function onScroll() {
    mini.classList.toggle('show', heads.getBoundingClientRect().bottom < 0);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();
