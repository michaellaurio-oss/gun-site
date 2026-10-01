// Compare picks: up to 4 guns saved in localStorage ("compare"), the Compare checkboxes
// that set them ([data-compare] on inventory cards and the gun page), and the tray fixed to
// the bottom of the window. compare-page.js uses window.Compare to keep the picks in step
// with the compare page's URL.
(function () {
  'use strict';

  var KEY = 'compare';
  var MAX = 4;

  // Stored as [{id, make, model, photo}] so the tray can show picks from other pages.
  function clean(list) {
    var out = [];
    var seen = {};
    (Array.isArray(list) ? list : []).forEach(function (g) {
      if (!g || typeof g !== 'object') return;
      var id = parseInt(g.id, 10);
      if (!(id > 0) || seen[id] || out.length >= MAX) return;
      seen[id] = true;
      out.push({ id: id, make: String(g.make || ''), model: String(g.model || ''), photo: g.photo ? String(g.photo) : '' });
    });
    return out;
  }
  function load() {
    try { return clean(JSON.parse(localStorage.getItem(KEY) || '[]')); } catch (e) { return []; }
  }
  function save(list) {
    list = clean(list);
    try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* private mode: picks last for this page only */ }
    return list;
  }
  function has(id) {
    return load().some(function (g) { return g.id === id; });
  }
  window.Compare = { MAX: MAX, load: load, save: save, has: has };

  var host = document.getElementById('cmp-tray');
  if (!host) return;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function icon(paths, size) {
    return '<svg class="icon" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths + '</svg>';
  }
  var CLOSE = icon('<path d="M6 6l12 12M18 6L6 18"/>', 16);
  var ARROW = icon('<path d="M5 12h14M13 6l6 6-6 6"/>', 18);
  var LIMIT = icon('<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>', 16);

  var compareUrl = host.getAttribute('data-compare-url');
  var addUrl = host.getAttribute('data-add-url') || '';
  host.innerHTML = '<div class="cmp-tray" role="region" aria-label="Compare tray">' +
    '<div class="cmp-limit" role="status"></div><div class="container cmp-tray-inner"></div></div>';
  var tray = host.firstChild;
  var limitEl = tray.querySelector('.cmp-limit');
  var inner = tray.querySelector('.cmp-tray-inner');

  // Keep the tray from covering the pager and footer.
  function pad() {
    document.body.style.paddingBottom = host.hidden ? '' : tray.offsetHeight + 'px';
  }

  function limit(show) {
    limitEl.innerHTML = show ? LIMIT + 'You can compare up to 4 guns. Remove one to add another.' : '';
    pad();
  }

  function syncBoxes(list) {
    document.querySelectorAll('input[data-compare]').forEach(function (box) {
      var id = parseInt(box.getAttribute('data-compare'), 10);
      var on = list.some(function (g) { return g.id === id; });
      box.checked = on;
      var card = box.closest('.card');
      if (card) card.classList.toggle('is-picked', on);
    });
  }

  function render() {
    var list = load();
    var n = list.length;
    host.hidden = n === 0;
    var h = '<div class="cmp-tray-title"><div class="t">Compare</div><div class="n">' + n + ' of ' + MAX + ' selected</div></div><ul class="cmp-slots">';
    for (var i = 0; i < MAX; i++) {
      var g = list[i];
      if (g) {
        h += '<li class="cmp-slot"><span class="th">' + (g.photo ? '<img src="' + esc(g.photo) + '" alt="">' : '') + '</span>' +
          '<span class="txt"><span class="mk">' + esc(g.make) + '</span><span class="md">' + esc(g.model) + '</span></span>' +
          '<button type="button" class="cmp-x" data-remove="' + g.id + '" aria-label="Remove ' + esc((g.make + ' ' + g.model).trim()) + ' from compare">' + CLOSE + '</button></li>';
      } else if (addUrl) {
        h += '<li class="cmp-slot empty"><a href="' + esc(addUrl) + '"><span class="add-txt">Add a gun</span></a></li>';
      } else {
        h += '<li class="cmp-slot empty" aria-hidden="true"><span class="add-txt">Add a gun</span></li>';
      }
    }
    h += '</ul><div class="cmp-tray-actions"><button type="button" class="link-btn" data-clear>Clear</button>';
    if (n >= 2) {
      var ids = list.map(function (x) { return x.id; }).join(',');
      h += '<a class="btn btn-accent cmp-go" href="' + esc(compareUrl + '?ids=' + ids) + '">Compare ' + n + ' guns' + ARROW + '</a>';
    } else {
      h += '<span class="btn cmp-go cmp-go-off" aria-disabled="true">Pick 1 more to compare</span>';
    }
    h += '</div>';
    inner.innerHTML = h;
    if (!n) limit(false);
    syncBoxes(list);
    pad();
  }

  document.addEventListener('change', function (e) {
    var box = e.target;
    if (!box.matches || !box.matches('input[data-compare]')) return;
    var id = parseInt(box.getAttribute('data-compare'), 10);
    var list = load().filter(function (g) { return g.id !== id; });
    if (box.checked) {
      if (list.length >= MAX) {
        // No silent replacement: refuse the 5th pick and say why.
        box.checked = false;
        limit(true);
        return;
      }
      list.push({ id: id, make: box.getAttribute('data-make'), model: box.getAttribute('data-model'), photo: box.getAttribute('data-photo') });
    }
    save(list);
    limit(false);
    render();
  });

  inner.addEventListener('click', function (e) {
    var x = e.target.closest('[data-remove]');
    if (x) {
      var id = parseInt(x.getAttribute('data-remove'), 10);
      var index = Array.prototype.indexOf.call(inner.querySelectorAll('.cmp-x'), x);
      save(load().filter(function (g) { return g.id !== id; }));
      limit(false);
      render();
      var left = inner.querySelectorAll('.cmp-x');
      if (!host.hidden) (left[Math.min(index, left.length - 1)] || inner.querySelector('[data-clear]')).focus();
      return;
    }
    if (e.target.closest('[data-clear]')) {
      save([]);
      render();
    }
  });

  // Picks changed in another tab, or this page came back from the browser's back/forward cache.
  window.addEventListener('storage', function (e) { if (e.key === KEY) render(); });
  window.addEventListener('pageshow', function (e) { if (e.persisted) render(); });
  window.addEventListener('resize', pad);

  render();
})();
