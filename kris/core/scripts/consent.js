/*
 * Consenso ai cookie per i siti Kris. Opzionale: lo include il template che
 * mostra il banner. Nessuna dipendenza, nessun cookie finche l'utente non
 * sceglie.
 *
 * Markup (vedi AGENTS.md, "Privacy e cookie"):
 *   [data-kris-consent-banner]          contenitore del banner, con l'attributo hidden
 *   [data-kris-consent="accept"]        accetta tutte le categorie
 *   [data-kris-consent="reject"]        le rifiuta tutte (deve essere facile quanto accettare)
 *   [data-kris-consent="details"]       mostra o nasconde le scelte per categoria
 *   [data-kris-consent-details]         contenitore delle scelte, con l'attributo hidden
 *   <input type="checkbox" data-kris-consent-category="statistics">
 *                                       una categoria: thirdparty, statistics, marketing
 *   [data-kris-consent="save"]          salva le categorie spuntate
 *   [data-kris-consent="open"]          riapre il banner, per esempio dal footer
 *   <script type="text/plain" data-kris-consent-script="statistics" [src="..."]>
 *                                       eseguito solo con il consenso a quella categoria;
 *                                       senza categoria, solo con "accetta tutto"
 *
 * La scelta resta in un cookie kris_consent per 180 giorni: "accepted" (tutto),
 * "rejected" (niente) o "c:statistics,marketing" (solo le categorie elencate).
 * Dopo, il banner ricompare. Evento "kris:consent" su document con
 * detail.accepted (tutto accettato) e detail.categories ({statistics: true, ...}),
 * anche al caricamento se la scelta esiste gia.
 */
(function () {
    'use strict';
    var NAME = 'kris_consent';
    var MAX_AGE = 180 * 24 * 60 * 60;
    var CATEGORIES = ['thirdparty', 'statistics', 'marketing'];

    // La scelta salvata come elenco di categorie accettate, o null se non c'e
    function read() {
        var match = document.cookie.match(/(?:^|;\s*)kris_consent=([^;]*)/);
        if (!match) return null;
        var value = decodeURIComponent(match[1]);
        if (value === 'accepted') return CATEGORIES.slice();
        if (value === 'rejected') return [];
        if (value.indexOf('c:') !== 0) return null;
        return value.slice(2).split(',').filter(function (c) { return CATEGORIES.indexOf(c) !== -1; });
    }

    function encode(accepted) {
        if (accepted.length === CATEGORIES.length) return 'accepted';
        if (accepted.length === 0) return 'rejected';
        return 'c:' + accepted.join(',');
    }

    function save(accepted) {
        document.cookie = NAME + '=' + encodeURIComponent(encode(accepted)) + '; max-age=' + MAX_AGE + '; path=/; SameSite=Lax'
            + (location.protocol === 'https:' ? '; Secure' : '');
    }

    function banners() {
        return document.querySelectorAll('[data-kris-consent-banner]');
    }

    function show(visible) {
        banners().forEach(function (b) { b.hidden = !visible; });
        if (visible) syncCheckboxes(read() || []);
    }

    // Le caselle mostrano la scelta attuale; mai spuntate in partenza
    function syncCheckboxes(accepted) {
        document.querySelectorAll('[data-kris-consent-category]').forEach(function (box) {
            box.checked = accepted.indexOf(box.getAttribute('data-kris-consent-category')) !== -1;
        });
    }

    function allowed(script, accepted) {
        var category = script.getAttribute('data-kris-consent-script');
        if (!category) return accepted.length === CATEGORIES.length;
        return accepted.indexOf(category) !== -1;
    }

    // Uno script bloccato viene sostituito da uno vero: solo cosi il browser lo esegue.
    function runBlockedScripts(accepted) {
        document.querySelectorAll('script[type="text/plain"][data-kris-consent-script]').forEach(function (old) {
            if (!allowed(old, accepted)) return;
            var script = document.createElement('script');
            for (var i = 0; i < old.attributes.length; i++) {
                var a = old.attributes[i];
                if (a.name !== 'type' && a.name !== 'data-kris-consent-script') script.setAttribute(a.name, a.value);
            }
            if (!old.src) script.textContent = old.textContent;
            old.parentNode.replaceChild(script, old);
        });
    }

    function apply(accepted) {
        runBlockedScripts(accepted);
        var categories = {};
        CATEGORIES.forEach(function (c) { categories[c] = accepted.indexOf(c) !== -1; });
        document.dispatchEvent(new CustomEvent('kris:consent', {
            detail: {accepted: accepted.length === CATEGORIES.length, categories: categories}
        }));
    }

    function choose(accepted) {
        var previous = read();
        save(accepted);
        show(false);
        // Revocare un consenso dato non spegne gli script gia avviati: si ricarica.
        if (previous && previous.some(function (c) { return accepted.indexOf(c) === -1; })) { location.reload(); return; }
        if (!previous || encode(previous) !== encode(accepted)) apply(accepted);
    }

    function checked() {
        var accepted = [];
        document.querySelectorAll('[data-kris-consent-category]').forEach(function (box) {
            var c = box.getAttribute('data-kris-consent-category');
            if (box.checked && CATEGORIES.indexOf(c) !== -1 && accepted.indexOf(c) === -1) accepted.push(c);
        });
        return accepted;
    }

    function init() {
        document.addEventListener('click', function (event) {
            var target = event.target.closest('[data-kris-consent]');
            if (!target) return;
            var action = target.getAttribute('data-kris-consent');
            if (action === 'accept') { event.preventDefault(); choose(CATEGORIES.slice()); }
            else if (action === 'reject') { event.preventDefault(); choose([]); }
            else if (action === 'save') { event.preventDefault(); choose(checked()); }
            else if (action === 'details') {
                event.preventDefault();
                var banner = target.closest('[data-kris-consent-banner]') || document;
                var details = banner.querySelector('[data-kris-consent-details]');
                if (details) {
                    details.hidden = !details.hidden;
                    target.setAttribute('aria-expanded', details.hidden ? 'false' : 'true');
                }
            }
            else if (action === 'open') { event.preventDefault(); show(true); }
        });
        var current = read();
        if (current) apply(current); else show(true);
    }

    window.KrisConsent = {
        // "accepted", "rejected", "custom" o null se non ha ancora scelto
        status: function () {
            var current = read();
            if (!current) return null;
            var value = encode(current);
            return value.indexOf('c:') === 0 ? 'custom' : value;
        },
        allows: function (category) { var current = read(); return !!current && current.indexOf(category) !== -1; },
        accept: function () { choose(CATEGORIES.slice()); },
        reject: function () { choose([]); },
        open: function () { show(true); }
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
