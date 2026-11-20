// Kris 2 — tema demo: menu mobile, lingua, filtro delle funzioni, segnaposto evidenziati nel codice.
(function () {
  'use strict';

  // Menu su mobile
  var header = document.querySelector('[data-nav]');
  var toggle = document.querySelector('[data-nav-toggle]');
  if (header && toggle) {
    var setOpen = function (open) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) header.setAttribute('data-open', '');
      else header.removeAttribute('data-open');
    };
    toggle.addEventListener('click', function () {
      setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    header.addEventListener('click', function (e) {
      if (e.target.closest('.site-nav a')) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && header.hasAttribute('data-open')) {
        setOpen(false);
        toggle.focus();
      }
    });
  }

  // Cambio lingua: gli stessi indirizzi degli hreflang che Kris mette nella pagina
  document.querySelectorAll('[data-lang]').forEach(function (link) {
    var alt = document.querySelector('link[rel="alternate"][hreflang="' + link.dataset.lang + '"]');
    if (alt) link.href = alt.href + location.hash;
  });

  // Filtro delle funzioni per categoria
  var filter = document.querySelector('[data-filter]');
  var list = document.querySelector('[data-filter-list]');
  if (filter && list) {
    var buttons = filter.querySelectorAll('button');
    var rows = list.querySelectorAll('[data-category]');
    // Mostra solo le categorie che hanno almeno una funzione
    buttons.forEach(function (btn) {
      var value = btn.dataset.filterValue;
      if (value && !list.querySelector('[data-category="' + value + '"]')) btn.hidden = true;
    });
    filter.hidden = rows.length === 0;
    filter.addEventListener('click', function (e) {
      var btn = e.target.closest('button');
      if (!btn) return;
      var value = btn.dataset.filterValue;
      buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
      rows.forEach(function (row) { row.hidden = !!value && row.dataset.category !== value; });
    });
  }

  // Nei blocchi di codice i segnaposto di Kris e gli attributi k-* restano in evidenza
  var escape = function (s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  };
  document.querySelectorAll('.code code').forEach(function (code) {
    var text = code.textContent;
    var html = '';
    var re = /\{\{[^}]*\}\}|\bk-(?:array|template|component|index|plugin|cookie-table)\b|data-kris-[\w-]+/g;
    var last = 0;
    var m;
    while ((m = re.exec(text)) !== null) {
      html += escape(text.slice(last, m.index)) + '<span class="tok">' + escape(m[0]) + '</span>';
      last = m.index + m[0].length;
    }
    code.innerHTML = html + escape(text.slice(last));
  });
})();
