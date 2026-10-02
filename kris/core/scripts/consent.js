/*
 * Consenso ai cookie per i siti Kris. Opzionale: lo include il template che
 * mostra il banner. Nessuna dipendenza, nessun cookie finche l'utente non
 * sceglie.
 *
 * Markup (vedi AGENTS.md, "Privacy e cookie"):
 *   [data-kris-consent-banner]          contenitore del banner, con l'attributo hidden
 *   [data-kris-consent="accept"]        accetta i cookie non tecnici
 *   [data-kris-consent="reject"]        li rifiuta (deve essere facile quanto accettare)
 *   [data-kris-consent="open"]          riapre il banner, per esempio dal footer
 *   <script type="text/plain" data-kris-consent-script [src="..."]>
 *                                       eseguito solo dopo il consenso
 *
 * La scelta resta in un cookie kris_consent (accepted | rejected) per 180
 * giorni; dopo, il banner ricompare. Evento "kris:consent" su document con
 * detail.accepted, anche al caricamento se la scelta esiste gia.
 */
(function () {
    'use strict';
    var NAME = 'kris_consent';
    var MAX_AGE = 180 * 24 * 60 * 60;

    function read() {
        var match = document.cookie.match(/(?:^|;\s*)kris_consent=(accepted|rejected)/);
        return match ? match[1] : null;
    }

    function save(value) {
        document.cookie = NAME + '=' + value + '; max-age=' + MAX_AGE + '; path=/; SameSite=Lax'
            + (location.protocol === 'https:' ? '; Secure' : '');
    }

    function banners() {
        return document.querySelectorAll('[data-kris-consent-banner]');
    }

    function show(visible) {
        banners().forEach(function (b) { b.hidden = !visible; });
    }

    // Uno script bloccato viene sostituito da uno vero: solo cosi il browser lo esegue.
    function runBlockedScripts() {
        document.querySelectorAll('script[type="text/plain"][data-kris-consent-script]').forEach(function (old) {
            var script = document.createElement('script');
            for (var i = 0; i < old.attributes.length; i++) {
                var a = old.attributes[i];
                if (a.name !== 'type' && a.name !== 'data-kris-consent-script') script.setAttribute(a.name, a.value);
            }
            if (!old.src) script.textContent = old.textContent;
            old.parentNode.replaceChild(script, old);
        });
    }

    function apply(value) {
        if (value === 'accepted') runBlockedScripts();
        document.dispatchEvent(new CustomEvent('kris:consent', {detail: {accepted: value === 'accepted'}}));
    }

    function choose(value) {
        var previous = read();
        save(value);
        show(false);
        // Revocare un consenso dato non spegne gli script gia avviati: si ricarica.
        if (previous === 'accepted' && value === 'rejected') { location.reload(); return; }
        if (previous !== value) apply(value);
    }

    function init() {
        document.addEventListener('click', function (event) {
            var target = event.target.closest('[data-kris-consent]');
            if (!target) return;
            var action = target.getAttribute('data-kris-consent');
            if (action === 'accept') { event.preventDefault(); choose('accepted'); }
            else if (action === 'reject') { event.preventDefault(); choose('rejected'); }
            else if (action === 'open') { event.preventDefault(); show(true); }
        });
        var current = read();
        if (current) apply(current); else show(true);
    }

    window.KrisConsent = {
        status: read,
        accept: function () { choose('accepted'); },
        reject: function () { choose('rejected'); },
        open: function () { show(true); }
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
