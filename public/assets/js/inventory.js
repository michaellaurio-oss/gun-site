// Inventory page: faceted filters, sort, per-page and pagination, all client-side.
// Checkboxes OR within a group, AND across groups. Option counts reflect the other
// groups' selections; zero-count options hide. State is mirrored in the URL.
(function () {
  'use strict';

  var DATA = JSON.parse(document.getElementById('inv-data').textContent);
  var ITEMS = DATA.items;
  var STATUS = DATA.status;
  var NOTES = DATA.notes;
  var LIMIT = 5;
  var PER_PAGE = [12, 24, 48, 96];
  var DEFAULT_PER = 24;

  var TYPE_LABEL = { Handgun: 'Handguns', Rifle: 'Rifles', Shotgun: 'Shotguns', Other: 'Other' };
  var GROUPS = [
    { id: 'newused', label: 'New / Used', order: ['New', 'Used'] },
    { id: 'type', label: 'Type', order: ['Handgun', 'Rifle', 'Shotgun', 'Other'], display: function (v) { return TYPE_LABEL[v] || v; } },
    { id: 'roster', label: 'CA Roster', order: ['On roster', 'Off roster', 'Not yet checked', 'Not required (long guns)'] },
    { id: 'make', label: 'Manufacturer', searchLabel: 'Search manufacturers' },
    { id: 'caliber', label: 'Caliber', searchLabel: 'Search calibers' },
    { id: 'condition', label: 'Condition', order: ['New', 'Like New', 'Excellent', 'Very Good', 'Good', 'Fair', 'Poor'] },
    { id: 'price', label: 'Price', isPrice: true },
    { id: 'mods', label: 'Modifications', order: ['Factory original', 'Modified'] }
  ];
  var FACETS = GROUPS.filter(function (g) { return !g.isPrice; });

  // ---------- state ----------
  var state = readUrl();
  var ui = { closed: {}, expanded: {}, fq: {} };

  function emptySel() {
    var s = {};
    FACETS.forEach(function (g) { s[g.id] = []; });
    return s;
  }

  function readUrl() {
    var p = new URLSearchParams(location.search);
    var sel = emptySel();
    FACETS.forEach(function (g) { sel[g.id] = p.getAll(g.id).filter(Boolean); });
    var per = parseInt(p.get('per'), 10);
    return {
      sel: sel,
      q: (p.get('q') || '').trim(),
      pmin: parsePrice(p.get('pmin')),
      pmax: parsePrice(p.get('pmax')),
      include: p.get('inc') !== '0',
      sort: ['newest', 'price-asc', 'price-desc', 'make'].indexOf(p.get('sort')) >= 0 ? p.get('sort') : 'newest',
      per: PER_PAGE.indexOf(per) >= 0 ? per : DEFAULT_PER,
      page: Math.max(1, parseInt(p.get('page'), 10) || 1)
    };
  }

  function writeUrl() {
    var p = new URLSearchParams();
    if (state.q) p.set('q', state.q);
    FACETS.forEach(function (g) { state.sel[g.id].forEach(function (v) { p.append(g.id, v); }); });
    if (state.pmin !== null) p.set('pmin', state.pmin);
    if (state.pmax !== null) p.set('pmax', state.pmax);
    if (!state.include) p.set('inc', '0');
    if (state.sort !== 'newest') p.set('sort', state.sort);
    if (state.per !== DEFAULT_PER) p.set('per', state.per);
    if (state.page > 1) p.set('page', state.page);
    var qs = p.toString();
    history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
  }

  function parsePrice(v) {
    if (v === null || v === undefined) return null;
    var n = parseFloat(String(v).replace(/[^0-9.]/g, ''));
    return isFinite(n) ? n : null;
  }

  // ---------- filtering ----------
  function baseItems() {
    return state.include ? ITEMS : ITEMS.filter(function (i) { return i.status === 'available'; });
  }

  function matchesText(it) {
    if (!state.q) return true;
    var hay = [it.make, it.model, it.caliber, it.stock, it.type].join(' ').toLowerCase();
    return state.q.toLowerCase().split(/\s+/).every(function (w) { return hay.indexOf(w) >= 0; });
  }

  function matchesPrice(it) {
    if (state.pmin === null && state.pmax === null) return true;
    if (it.price === null) return false;
    if (state.pmin !== null && it.price < state.pmin) return false;
    if (state.pmax !== null && it.price > state.pmax) return false;
    return true;
  }

  // skip = group id whose own selection is ignored (for that group's counts)
  function matches(it, skip) {
    if (!matchesText(it) || !matchesPrice(it)) return false;
    return FACETS.every(function (g) {
      var s = state.sel[g.id];
      return g.id === skip || s.length === 0 || s.indexOf(it[g.id]) >= 0;
    });
  }

  function sortItems(list) {
    var out = list.slice();
    var byNewest = function (a, b) { return (b.listed || '').localeCompare(a.listed || '') || b.id - a.id; };
    if (state.sort === 'newest') out.sort(byNewest);
    if (state.sort === 'price-asc' || state.sort === 'price-desc') {
      var dir = state.sort === 'price-asc' ? 1 : -1;
      out.sort(function (a, b) {
        if (a.price === null && b.price === null) return byNewest(a, b);
        if (a.price === null) return 1;
        if (b.price === null) return -1;
        return (a.price - b.price) * dir || byNewest(a, b);
      });
    }
    if (state.sort === 'make') {
      out.sort(function (a, b) { return a.make.localeCompare(b.make) || a.model.localeCompare(b.model) || byNewest(a, b); });
    }
    return out;
  }

  // ---------- rendering helpers ----------
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  var ICONS = {
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    chevron: '<path d="M6 9l6 6 6-6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    prev: '<path d="M15 6l-6 6 6 6"/>',
    next: '<path d="M9 6l6 6-6 6"/>'
  };
  function icon(name, size) {
    return '<svg class="icon" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICONS[name] + '</svg>';
  }
  var tipN = 0;
  function tooltip(triggerHtml, tip, cls, style) {
    var id = 'jtip-' + (++tipN);
    return '<span class="tip"><button type="button" class="tip-trigger ' + cls + '"' + (style ? ' style="' + esc(style) + '"' : '') +
      ' aria-describedby="' + id + '">' + triggerHtml + '</button><span role="tooltip" id="' + id + '" class="tip-bubble">' + esc(tip) + '</span></span>';
  }

  // "4.02 in / 102 mm" that only wraps at the slash (nowrap_pair() in PHP).
  function pair(s) {
    return String(s).split(' / ').map(function (p) { return '<span class="nw">' + esc(p) + '</span>'; }).join(' / ');
  }

  // Keep in sync with render_card() in app/layout.php.
  function renderCard(c) {
    var st = c.status !== 'available' ? STATUS[c.status] : null;
    var h = '<article class="card"><div class="card-photo">';
    h += c.photo ? '<img src="' + esc(c.photo) + '" alt="" loading="lazy">' : '<span class="ph-text">Photo coming soon</span>';
    h += '<span class="chip">' + esc(c.type) + '</span>';
    if (st) h += '<span class="card-status">' + tooltip(esc(st.label) + icon('info', 12), st.tip, 'badge', 'color:' + st.fg + ';background:' + st.bg) + '</span>';
    h += '</div><div class="card-body"><div class="card-head"><div class="min0">';
    h += '<div class="card-make">' + esc(c.make) + '</div>';
    h += '<h3 class="card-model"><a class="card-link" href="' + esc(c.url) + '">' + esc(c.model) + '</a></h3></div>';
    if (c.stock) h += '<div class="card-stock">#' + esc(c.stock) + '</div>';
    h += '</div><dl class="card-specs">';
    h += '<div><dt>Caliber</dt><dd>' + (c.caliber ? pair(c.caliber) : '—') + '</dd></div>';
    h += '<div><dt>Capacity</dt><dd>' + esc(c.capacity || '—') + '</dd>' + (c.overTen ? '<dd class="ca-note">' + esc(NOTES.capacity) + '</dd>' : '') + '</div>';
    if (c.barrel) h += '<div><dt>Barrel</dt><dd>' + pair(c.barrel) + '</dd></div>';
    if (c.weight) h += '<div><dt>Weight</dt><dd>' + pair(c.weight) + '</dd></div>';
    h += '</dl><div class="card-foot"><div class="tags">';
    h += c.newused === 'New' ? '<span class="tag tag-new">New</span>' : '<span class="tag">Used' + (c.condition ? ' · ' + esc(c.condition) : '') + '</span>';
    if (c.roster === 'On roster') h += '<span class="tag tag-roster">CA Roster</span>';
    if (c.roster === 'Off roster') h += tooltip('Off roster' + icon('info', 12), NOTES.offRoster, 'tag tag-help');
    if (c.mods === 'Modified') h += '<span class="tag">Modified</span>';
    h += '</div><span class="card-price">' + esc(c.priceText) + '</span></div></div></article>';
    return h;
  }

  // ---------- DOM ----------
  var $ = function (id) { return document.getElementById(id); };
  var groupsEl = $('filter-groups');
  var filtersEl = $('filters');
  var gridEl = $('grid');
  var chipsEl = $('chips');
  var pagerEl = $('pager');
  var pagesEl = $('pages');
  var perSelects = document.querySelectorAll('.perpage');
  var sortEl = $('sort');
  var includeEl = $('include-other');
  var openBtn = $('filters-open');
  var headerSearch = document.getElementById('site-search');

  if (!ITEMS.length) {
    // Nothing listed: leave the server-rendered empty message alone.
    return;
  }

  filtersEl.hidden = false;
  openBtn.hidden = false;

  // Build the static group shells once so focus in search boxes survives re-renders.
  GROUPS.forEach(function (g) {
    var wrap = document.createElement('div');
    wrap.className = 'fgroup';
    var panelId = 'filter-' + g.id;
    var h = '<button type="button" class="fgroup-toggle" aria-expanded="true" aria-controls="' + panelId + '" data-toggle="' + g.id + '">' +
      '<span class="label">' + esc(g.label) + '</span><span class="fcount" data-count="' + g.id + '" hidden></span>' + icon('chevron', 18) + '</button>' +
      '<div class="fpanel" id="' + panelId + '" role="group" aria-label="' + esc(g.label) + '">';
    if (g.isPrice) {
      h += '<div class="fprice"><label for="pmin" class="sr-only">Minimum price</label>' +
        '<input id="pmin" class="input" type="text" inputmode="decimal" placeholder="$ Min" autocomplete="off">' +
        '<span aria-hidden="true" class="muted">–</span><label for="pmax" class="sr-only">Maximum price</label>' +
        '<input id="pmax" class="input" type="text" inputmode="decimal" placeholder="$ Max" autocomplete="off"></div>';
    } else {
      if (g.searchLabel) {
        h += '<div class="fsearch" data-fsearch-wrap="' + g.id + '" hidden>' + icon('search', 16) +
          '<label for="fsearch-' + g.id + '" class="sr-only">' + esc(g.searchLabel) + '</label>' +
          '<input id="fsearch-' + g.id + '" type="text" data-fsearch="' + g.id + '" placeholder="' + esc(g.searchLabel) + '" autocomplete="off"></div>';
      }
      h += '<div data-options="' + g.id + '"></div>';
    }
    h += '</div>';
    wrap.innerHTML = h;
    groupsEl.appendChild(wrap);
  });

  var pminEl = $('pmin');
  var pmaxEl = $('pmax');

  function render() {
    var active = document.activeElement;
    var focusKey = active && active.getAttribute ? active.getAttribute('data-focus-key') : null;

    var base = baseItems();
    $('listed-text').textContent = base.length + (base.length === 1 ? ' gun listed' : ' guns listed');

    // Filter groups
    var anySelected = 0;
    FACETS.forEach(function (g) {
      var counts = {};
      base.forEach(function (it) {
        if (it[g.id] && matches(it, g.id)) counts[it[g.id]] = (counts[it[g.id]] || 0) + 1;
      });
      var chosen = state.sel[g.id];
      var vals = g.order ? g.order.slice() : Object.keys(counts).sort(function (a, b) { return (counts[b] - counts[a]) || a.localeCompare(b); });
      chosen.forEach(function (v) { if (vals.indexOf(v) < 0) vals.push(v); });
      vals = vals.filter(function (v) { return (counts[v] || 0) > 0 || chosen.indexOf(v) >= 0; });

      var searchWrap = groupsEl.querySelector('[data-fsearch-wrap="' + g.id + '"]');
      var query = (ui.fq[g.id] || '').trim().toLowerCase();
      if (searchWrap) searchWrap.hidden = vals.length <= LIMIT && !query;
      if (query) vals = vals.filter(function (v) { return v.toLowerCase().indexOf(query) >= 0; });
      var total = vals.length;
      var showAll = !g.searchLabel || ui.expanded[g.id] || !!query;
      var visible = showAll ? vals : vals.slice(0, LIMIT);
      // Always show selected options even when collapsed to top 5.
      if (!showAll) chosen.forEach(function (v) { if (visible.indexOf(v) < 0 && vals.indexOf(v) >= 0) visible.push(v); });

      var h = '';
      visible.forEach(function (v) {
        var key = 'opt:' + g.id + ':' + v;
        h += '<label class="fopt"><input type="checkbox" data-group="' + g.id + '" value="' + esc(v) + '" data-focus-key="' + esc(key) + '"' +
          (chosen.indexOf(v) >= 0 ? ' checked' : '') + '><span class="olabel">' + esc(g.display ? g.display(v) : v) + '</span>' +
          '<span class="ocount"><span class="sr-only">(</span>' + (counts[v] || 0) + '<span class="sr-only"> results)</span></span></label>';
      });
      if (query && total === 0) h += '<div class="muted" style="font-size:14px">No matches</div>';
      if (g.searchLabel && !query && total > LIMIT) {
        h += '<button type="button" class="fmore" data-more="' + g.id + '" data-focus-key="more:' + g.id + '" aria-expanded="' + (!!ui.expanded[g.id]) + '">' +
          (ui.expanded[g.id] ? 'Show fewer' : 'Show all ' + total) + '</button>';
      }
      groupsEl.querySelector('[data-options="' + g.id + '"]').innerHTML = h;

      var countEl = groupsEl.querySelector('[data-count="' + g.id + '"]');
      countEl.hidden = chosen.length === 0;
      countEl.textContent = chosen.length;
      countEl.setAttribute('aria-label', chosen.length + ' selected');
      anySelected += chosen.length;
    });
    var priceCount = (state.pmin !== null ? 1 : 0) + (state.pmax !== null ? 1 : 0);
    var pc = groupsEl.querySelector('[data-count="price"]');
    pc.hidden = priceCount === 0;
    pc.textContent = priceCount ? 1 : '';
    if (document.activeElement !== pminEl) pminEl.value = state.pmin !== null ? state.pmin : '';
    if (document.activeElement !== pmaxEl) pmaxEl.value = state.pmax !== null ? state.pmax : '';

    GROUPS.forEach(function (g) {
      var btn = groupsEl.querySelector('[data-toggle="' + g.id + '"]');
      var open = !ui.closed[g.id];
      btn.setAttribute('aria-expanded', String(open));
      $('filter-' + g.id).hidden = !open;
    });

    // Chips
    var chips = [];
    if (state.q) chips.push({ label: 'Search: “' + state.q + '”', kind: 'q' });
    FACETS.forEach(function (g) {
      state.sel[g.id].forEach(function (v) { chips.push({ label: g.display ? g.display(v) : v, kind: 'sel', group: g.id, value: v }); });
    });
    if (state.pmin !== null || state.pmax !== null) {
      chips.push({ label: 'Price: ' + (state.pmin !== null ? '$' + state.pmin.toLocaleString() : 'any') + ' – ' + (state.pmax !== null ? '$' + state.pmax.toLocaleString() : 'any'), kind: 'price' });
    }
    if (chips.length) {
      var ch = '<span class="lead">Filtered by:</span>';
      chips.forEach(function (c, i) {
        ch += '<button type="button" class="fchip" data-chip="' + i + '" data-focus-key="chip:' + esc(c.label) + '" aria-label="Remove filter ' + esc(c.label) + '">' + esc(c.label) + icon('close', 14) + '</button>';
      });
      ch += '<button type="button" class="link-btn" data-clear-all>Clear all</button>';
      chipsEl.innerHTML = ch;
    }
    chipsEl.hidden = chips.length === 0;
    chipsEl._chips = chips;
    openBtn.querySelector('#filters-open-count').textContent = chips.length ? '(' + chips.length + ')' : '';

    // Results
    var filtered = sortItems(base.filter(function (it) { return matches(it, null); }));
    var total = filtered.length;
    var pages = Math.max(1, Math.ceil(total / state.per));
    if (state.page > pages) state.page = pages;
    var start = (state.page - 1) * state.per;
    var slice = filtered.slice(start, start + state.per);
    gridEl.innerHTML = slice.map(renderCard).join('');
    $('no-results').hidden = total !== 0;
    $('filters-done').textContent = total === 1 ? 'Show 1 result' : 'Show ' + total + ' results';
    $('showing').textContent = total === 0 ? 'No results' : 'Showing ' + (start + 1) + '–' + (start + slice.length) + ' of ' + total;
    $('results-live').textContent = total === 1 ? '1 gun found' : total + ' guns found';
    pagerEl.hidden = total === 0;

    // Pagination with gaps: 1 … 4 5 6 … 12
    var ph = '<button type="button" class="pbtn" data-page="' + (state.page - 1) + '" aria-label="Previous page"' + (state.page <= 1 ? ' disabled' : '') + ' data-focus-key="page:prev">' + icon('prev', 18) + '</button>';
    var last = 0;
    for (var n = 1; n <= pages; n++) {
      if (n === 1 || n === pages || Math.abs(n - state.page) <= 1) {
        if (n - last > 1) ph += '<span class="pgap" aria-hidden="true">…</span>';
        ph += n === state.page
          ? '<button type="button" class="pbtn" aria-current="page" aria-label="Page ' + n + ', current page" data-focus-key="page:cur">' + n + '</button>'
          : '<button type="button" class="pbtn" data-page="' + n + '" aria-label="Page ' + n + '" data-focus-key="page:' + n + '">' + n + '</button>';
        last = n;
      }
    }
    ph += '<button type="button" class="pbtn" data-page="' + (state.page + 1) + '" aria-label="Next page"' + (state.page >= pages ? ' disabled' : '') + ' data-focus-key="page:next">' + icon('next', 18) + '</button>';
    pagesEl.innerHTML = ph;

    perSelects.forEach(function (s) { s.value = String(state.per); });
    sortEl.value = state.sort;
    includeEl.checked = state.include;
    if (headerSearch && document.activeElement !== headerSearch) headerSearch.value = state.q;

    writeUrl();

    if (focusKey) {
      var again = document.querySelector('[data-focus-key="' + (window.CSS && CSS.escape ? CSS.escape(focusKey) : focusKey) + '"]');
      if (again && again !== document.activeElement) again.focus();
    }
  }

  function update(changes, keepPage) {
    Object.keys(changes).forEach(function (k) { state[k] = changes[k]; });
    if (!keepPage) state.page = 1;
    render();
  }

  function toggleSel(group, value) {
    var sel = {};
    Object.keys(state.sel).forEach(function (k) { sel[k] = state.sel[k].slice(); });
    var i = sel[group].indexOf(value);
    if (i >= 0) sel[group].splice(i, 1); else sel[group].push(value);
    update({ sel: sel });
  }

  function clearAll() {
    ui.fq = {};
    groupsEl.querySelectorAll('[data-fsearch]').forEach(function (i) { i.value = ''; });
    update({ sel: emptySel(), q: '', pmin: null, pmax: null });
  }

  // ---------- events ----------
  groupsEl.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('input[type=checkbox][data-group]')) toggleSel(t.getAttribute('data-group'), t.value);
  });
  groupsEl.addEventListener('click', function (e) {
    var tog = e.target.closest('[data-toggle]');
    if (tog) {
      var id = tog.getAttribute('data-toggle');
      ui.closed[id] = !ui.closed[id];
      render();
      return;
    }
    var more = e.target.closest('[data-more]');
    if (more) {
      var g = more.getAttribute('data-more');
      ui.expanded[g] = !ui.expanded[g];
      render();
    }
  });
  groupsEl.addEventListener('input', function (e) {
    var t = e.target;
    if (t.hasAttribute('data-fsearch')) {
      ui.fq[t.getAttribute('data-fsearch')] = t.value;
      render();
    }
  });

  var priceTimer;
  function applyPrice() {
    var mn = parsePrice(pminEl.value);
    var mx = parsePrice(pmaxEl.value);
    if (mn !== state.pmin || mx !== state.pmax) update({ pmin: mn, pmax: mx });
  }
  [pminEl, pmaxEl].forEach(function (el) {
    el.addEventListener('input', function () { clearTimeout(priceTimer); priceTimer = setTimeout(applyPrice, 500); });
    el.addEventListener('change', applyPrice);
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyPrice(); } });
  });

  chipsEl.addEventListener('click', function (e) {
    var b = e.target.closest('[data-chip]');
    if (!b) return;
    var c = chipsEl._chips[+b.getAttribute('data-chip')];
    if (c.kind === 'q') update({ q: '' });
    if (c.kind === 'price') update({ pmin: null, pmax: null });
    if (c.kind === 'sel') toggleSel(c.group, c.value);
    var next = chipsEl.querySelector('.fchip') || document.getElementById('grid');
    if (next && next.focus) next.focus();
  });
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-clear-all]')) clearAll();
  });

  perSelects.forEach(function (s) {
    s.addEventListener('change', function () { update({ per: parseInt(s.value, 10) || DEFAULT_PER }); });
  });
  sortEl.addEventListener('change', function () { update({ sort: sortEl.value }); });
  includeEl.addEventListener('change', function () { update({ include: includeEl.checked }); });

  pagesEl.addEventListener('click', function (e) {
    var b = e.target.closest('[data-page]');
    if (!b || b.disabled) return;
    update({ page: parseInt(b.getAttribute('data-page'), 10) }, true);
    var top = document.querySelector('.inv-title');
    if (top) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  // Header search filters in place on this page.
  var headerForm = headerSearch ? headerSearch.form : null;
  if (headerForm) {
    headerForm.addEventListener('submit', function (e) {
      e.preventDefault();
      update({ q: headerSearch.value.trim() });
    });
  }

  // Mobile filter panel
  function setPanel(open) {
    filtersEl.classList.toggle('open', open);
    document.body.classList.toggle('filters-locked', open);
    openBtn.setAttribute('aria-expanded', String(open));
    if (open) filtersEl.querySelector('button, input').focus(); else openBtn.focus();
  }
  openBtn.addEventListener('click', function () { setPanel(true); });
  $('filters-done').addEventListener('click', function () { setPanel(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && filtersEl.classList.contains('open')) setPanel(false);
  });

  render();
})();
