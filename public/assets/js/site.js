// Site-wide behaviour: mobile menu and tooltip dismissal.
(function () {
  'use strict';

  var toggle = document.querySelector('.menu-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', String(open));
      nav.classList.toggle('open', open);
    });
  }

  // Esc hides an open tooltip without moving focus (WCAG 1.4.13).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var el = document.activeElement;
    var tip = el && el.closest ? el.closest('.tip') : null;
    if (tip) tip.classList.add('tip-dismissed');
    document.querySelectorAll('.tip:hover').forEach(function (t) { t.classList.add('tip-dismissed'); });
  });
  function reset(e) {
    var tip = e.target && e.target.closest ? e.target.closest('.tip') : null;
    if (tip) tip.classList.remove('tip-dismissed');
  }
  document.addEventListener('focusout', reset);
  document.addEventListener('mouseout', reset);

  // Tooltip triggers inside a card must not follow the card link.
  document.addEventListener('click', function (e) {
    var trig = e.target.closest ? e.target.closest('.tip-trigger') : null;
    if (trig) { e.preventDefault(); trig.focus(); }
  });
})();
