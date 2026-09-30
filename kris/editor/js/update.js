// Aggiornamenti di Kris dalla scheda "Versione di Kris" in Impostazioni.
// Usa q, csrf, choose e toast di scripts.js. I testi che arrivano dal
// canale (note di rilascio) entrano nella pagina solo come testo.
'use strict';
(() => {
    const panel = q('[data-update-panel]');
    const recoverButton = q('[data-update-recover]');
    const settingsUrl = 'index.php?action=settings#versione';
    let busy = false;

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    async function call(op, form) {
        const body = form || new FormData();
        body.append('op', op);
        body.append('csrf', csrf());
        let response;
        try {
            response = await fetch('update.php', {method: 'POST', body, credentials: 'same-origin', cache: 'no-store'});
        } catch {
            return {ok: false, error: 'La connessione con il sito si è interrotta. Ricarica la pagina per vedere lo stato.', unknown: true};
        }
        try {
            return await response.json();
        } catch {
            return {ok: false, error: 'Il sito ha risposto in modo inatteso. Ricarica la pagina per vedere lo stato.', unknown: true};
        }
    }

    function setBusy(value) {
        busy = value;
        document.querySelectorAll('[data-update-panel] button, [data-update-recover]').forEach(b => { b.disabled = value; });
    }

    function reloadSoon(delay = 2500) {
        // Parametro sempre nuovo: forza il caricamento anche se l'URL e lo stesso.
        setTimeout(() => { location.href = settingsUrl.replace('#', '&t=' + Date.now() + '#'); }, delay);
    }

    // Durante un'installazione la pagina non va chiusa.
    window.addEventListener('beforeunload', event => {
        if (busy) { event.preventDefault(); event.returnValue = ''; }
    });

    if (recoverButton) {
        recoverButton.addEventListener('click', async () => {
            setBusy(true);
            const r = await call('recover');
            setBusy(false);
            toast(r.ok ? 'Sito ripristinato.' : (r.error || 'Ripristino non riuscito.'));
            reloadSoon(1200);
        });
    }

    if (!panel) return;
    const result = q('[data-update-result]', panel);

    function show(...nodes) {
        result.replaceChildren(...nodes);
    }

    function message(kind, title, text) {
        const box = el('div', kind === 'error' ? 'alert alert-error' : 'notice');
        if (kind === 'error') box.setAttribute('role', 'alert');
        box.append(el('strong', '', title));
        if (text) box.append(el('p', '', text));
        return box;
    }

    function notesList(notes) {
        const list = el('ul', 'update-notes');
        notes.forEach(n => {
            const item = el('li');
            item.append(el('strong', '', 'Kris ' + n.version + (n.date ? ' · ' + n.date : '')));
            if (n.changelog) item.append(el('p', '', n.changelog));
            list.append(item);
        });
        return list;
    }

    // Elenco dei passi con il loro stato, aggiornato mentre si procede.
    function stepsView() {
        const list = el('ol', 'update-steps');
        return {
            node: list,
            add(label) {
                const item = el('li', 'is-running', label);
                list.append(item);
                return {
                    done(ok) { item.className = ok ? 'is-done' : 'is-failed'; },
                };
            },
        };
    }

    async function check() {
        setBusy(true);
        show(message('info', 'Controllo in corso…'));
        const r = await call('check');
        setBusy(false);
        if (!r.ok) {
            show(message('error', 'Verifica non riuscita', r.error));
            q('[data-update-manual]', panel).open = true;
            return;
        }
        const nodes = [];
        if (r.target) {
            const box = el('div', 'update-available');
            box.append(el('strong', '', 'È disponibile Kris ' + r.target));
            box.append(el('p', 'hint', 'Installata ora: Kris ' + r.current + '.'));
            if (r.notes && r.notes.length) box.append(notesList(r.notes));
            const button = el('button', 'btn btn-primary', 'Aggiorna a Kris ' + r.target);
            button.type = 'button';
            button.addEventListener('click', () => install('prepare', null));
            box.append(button);
            nodes.push(box);
        } else if (r.unmet) {
            nodes.push(message('error', 'Aggiornamento non installabile su questo hosting', r.unmet));
        } else {
            nodes.push(message('info', 'Kris è aggiornato', 'La versione installata (' + r.current + ') è la più recente installabile da qui.'));
        }
        if (r.blocked) {
            nodes.push(message('info', 'È uscita Kris ' + r.blocked,
                'Cambia versione principale e può richiedere modifiche al sito: la installa lo sviluppatore, non l’editor.'));
        }
        show(...nodes);
    }

    // prepare: 'prepare' (dal canale) o 'upload' (zip caricato a mano).
    async function install(prepareOp, form) {
        const steps = stepsView();
        show(steps.node);
        setBusy(true);

        const prep = steps.add(prepareOp === 'upload' ? 'Carico e verifico il pacchetto' : 'Scarico e verifico il pacchetto');
        const prepared = await call(prepareOp, form);
        prep.done(prepared.ok);
        if (!prepared.ok) {
            setBusy(false);
            result.append(message('error', 'Il sito non è stato modificato', prepared.error));
            return;
        }

        const info = prepared.prepared;
        setBusy(false);
        const confirmed = await choose(
            'Installare Kris ' + info.version + '?',
            'Il sito resterà in manutenzione per qualche istante. Prima viene fatta una copia di sicurezza: se qualcosa non va, tutto torna com’era da solo. Non chiudere questa pagina finché non hai l’esito.',
            [{label: 'Installa ora', value: true, kind: 'btn-primary'}, {label: 'Annulla', value: false}]
        );
        if (!confirmed) {
            result.append(message('info', 'Aggiornamento annullato', 'Il sito non è stato modificato.'));
            return;
        }

        setBusy(true);
        const run = steps.add('Installo e controllo il sito');
        const r = await call('install');
        run.done(r.ok);
        (r.steps || []).forEach(s => steps.add(s.label).done(s.ok));
        busy = false;

        if (r.ok) {
            result.append(message('info', 'Kris ' + info.version + ' è installata', 'Ricarico la pagina…'));
        } else if (r.unknown) {
            result.append(message('error', 'Esito non disponibile', r.error));
        } else {
            result.append(message('error',
                r.rolled_back ? 'Aggiornamento annullato: il sito è tornato com’era' : 'Aggiornamento non riuscito: il sito non è stato modificato',
                r.error));
        }
        // La pagina ricaricata mostra lo stato reale letto dal server.
        reloadSoon(r.ok ? 2000 : 6000);
    }

    q('[data-update-check]', panel).addEventListener('click', () => { if (!busy) check(); });

    q('[data-update-upload]', panel).addEventListener('click', () => {
        if (busy) return;
        const input = q('[data-update-file]', panel);
        if (!input.files || !input.files.length) { toast('Scegli prima il file zip del pacchetto.'); return; }
        const form = new FormData();
        form.append('package', input.files[0]);
        install('upload', form);
    });

    const rollbackButton = q('[data-update-rollback]', panel);
    if (rollbackButton) {
        rollbackButton.addEventListener('click', async () => {
            if (busy) return;
            const confirmed = await choose(
                'Tornare a Kris ' + rollbackButton.dataset.version + '?',
                'Il sito torna com’era prima dell’ultimo aggiornamento, contenuti compresi: le modifiche fatte da allora andranno perse.',
                [{label: 'Torna alla versione precedente', value: true, kind: 'btn-danger'}, {label: 'Annulla', value: false}]
            );
            if (!confirmed) return;
            setBusy(true);
            const r = await call('rollback');
            busy = false;
            show(r.ok ? message('info', 'Versione precedente ripristinata', 'Ricarico la pagina…')
                      : message('error', 'Non è stato possibile tornare indietro', r.error));
            reloadSoon(r.ok ? 1500 : 6000);
        });
    }
})();
