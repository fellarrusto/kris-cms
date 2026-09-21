# Piano B — Editor

**Base:** `main` @ `6c1b1e2` — [editor/index.php](editor/index.php), 1.402 righe · **Autore:** Claude Opus 5 · 18 settembre 2026
**Piano gemello:** [PIANO_architettura_claude.md](PIANO_architettura_claude.md) · **Evidenze:** [REPORT_progetto_claude.md](REPORT_progetto_claude.md)

Il branch `feature/editor_refactor` è **archiviato**: conteneva l'editor del 30 aprile, mentre i tre commit dell'11 maggio hanno aggiunto 237 righe proprio a `editor/index.php`. Del branch riuso **solo il disegno delle cartelle**, riapplicato al codice attuale.

`S` ≈ meno di un'ora · `M` ≈ mezza giornata · `L` ≈ uno o due giorni.

---

## Fase E0 — Sbloccare il salvataggio *(prima di tutto, cinque minuti)*

**Oggi la homepage non è salvabile dal pannello.** Il form di riordino nested ([editor/index.php:948](editor/index.php#L948)) è generato dentro il form di modifica aperto a [:883](editor/index.php#L883); i form annidati sono HTML non valido, quindi il `</form>` interno chiude in anticipo quello esterno e il pulsante "Salva Modifiche" resta fuori.

Verificato con jsdom sulla pagina servita dall'editor:

| Entità | submit dentro il form | `button.form` | submit associati |
|---|---|---|---|
| `homepage` (array `features`, 3 figli) | false | **null** | **0** |
| `navbar` (nessun array) | true | `form.card` | 1 |

Zero submit associati significa che non funziona nemmeno l'invio con Invio. Per lo stesso motivo `getElementById('reorder_nested_…')` è `null`, quindi **anche il drag & drop dei figli non funziona**: la feature del commit `e1994f5` è inerte e in più rompe il salvataggio.

| ID | Intervento | Dim. |
|---|---|---|
| E0.1 | Spostare i form `reorder_nested_*` **fuori** dal form di modifica, accanto ai form `create_nested_*`/`del_nested_*` che sono già correttamente esterni (lo erano fin da `410fa79`). | S |
| E0.2 | Controllo automatico: uno script che carica ogni vista dell'editor e verifica con jsdom che il form principale abbia **esattamente un submit associato**. Diventa un test permanente contro le regressioni di markup. | S |

> Questo è l'unico punto del piano che conviene fare **subito**, prima ancora delle decisioni del piano architettura.

---

## Fase E1 — Split strutturale

Stessa struttura del branch archiviato, ma applicata al codice di oggi. **Zero modifiche alla logica**: è l'unico modo per poter dimostrare che non ho rotto niente.

```text
editor/
├── index.php              # bootstrap: sessione, config, dati, router, layout
├── auth.php               # login / logout / gatekeeper
├── helpers.php            # funzioni pure: JSON, path, schema, skeleton
├── actions.php            # azioni POST (tutte, incluso il riordino)
├── upload.php             # endpoint upload
├── partials/
│   ├── sidebar.php
│   └── media_overlay.php
├── views/
│   ├── dashboard.php  list.php  structure.php
│   └── edit.php  media.php  settings.php
├── js/
│   ├── scripts.js         # oggi esiste ma è VUOTO (0 byte)
│   └── structure.js       # builder dello schema
└── css/
    └── style.css          # + i blocchi oggi inline (dnd, spinner, sf-*)
```

| ID | Intervento | Dim. | Attenzione |
|---|---|---|---|
| E1.1 | Estrarre `auth.php` e `helpers.php` (codice invariato). | S | |
| E1.2 | Estrarre `actions.php` con **tutte** le azioni di `main`, riordino root e nested inclusi. | M | Il branch non le aveva: vanno prese da `main`, non dal branch |
| E1.3 | **Guardia sugli include.** Questo è il punto in cui il branch archiviato aveva aperto un buco: `actions.php` era raggiungibile via HTTP e non controllava la sessione, quindi caricava e cancellava file senza login (riprodotto). Qui: costante `KRIS_EDITOR` definita solo da `index.php`, `defined('KRIS_EDITOR') || exit;` in testa a ogni file incluso, e `actions.php` che comunque richiede una sessione valida. | S | **Da non dimenticare** |
| E1.4 | Estrarre le sei view e i due partial, preservando descrizioni dei campi (`b3a9bd2`) e tabelle drag & drop (`e1994f5`). | M | |
| E1.5 | Svuotare il JS inline dentro `js/scripts.js` e `js/structure.js`; spostare i blocchi `<style>` in `css/style.css`. | M | Il `file_picker_callback` di TinyMCE e gli `onclick` inline devono continuare a risolvere |
| E1.6 | Router con mappa esplicita `action → file` (come il branch): niente include costruiti dall'input. | S | |

### Come dimostro che l'editor fa ancora le stesse cose

Senza test preesistenti, il criterio è il confronto diretto:

1. Due copie (`main` e refactor) servite su due porte, stessi dati di partenza.
2. Per ogni vista: richiesta autenticata a entrambe, **diff dell'HTML** normalizzato. Atteso: differenze solo dove ho spostato i form (E0.1) e dove JS/CSS sono diventati `<script src>`/`<link>`.
3. Per ogni azione (crea, salva, crea nested, elimina nested, elimina, riordino, impostazioni): stessa POST a entrambe, **diff del JSON risultante**. Atteso: identico.
4. Il controllo E0.2 verde su tutte le viste.
5. Sonda di sicurezza: `POST` diretta a `actions.php`, `helpers.php` e a ogni file in `views/` **senza cookie** → nessuna azione eseguita.

---

## Fase E2 — Correttezza

Qui si cambia il comportamento, quindi **dopo** E1 e con i test della Fase 0 del piano architettura già in piedi.

| ID | Intervento | Dim. | Evidenza |
|---|---|---|---|
| E2.1 | **Nomi di campo riservati.** Un campo chiamato `group` manda il salvataggio in **500** (contenuto perso); uno chiamato `id` fa **creare una nuova entità duplicata a ogni salvataggio** invece di aggiornare. Il builder accetta qualsiasi nome `[a-z0-9_]`, quindi sono raggiungibili dall'UI. Soluzione robusta: prefissare i campi (`f[<nome>][<lang>]`) così la collisione diventa impossibile; soluzione rapida: allowlist negata con messaggio. | M | Riprodotto |
| E2.2 | **Non cancellare le traduzioni** delle lingue disattivate: `applyPostToData()` ricostruisce i valori solo dalle lingue attive. Disattivare EN e salvare cancella `en` in modo irreversibile. Le impostazioni devono governare **cosa si edita**, non cosa esiste. | M | Riprodotto: `{"it":"Ciao","en":"Hello"}` → `{"it":"Ciao"}` |
| E2.3 | **Messaggi onesti**: oggi "Percorso non valido" viene sempre sovrascritto da "Contenuto salvato" due righe dopo. Validare il nodo (esistenza, tipo, path pari/dispari) **prima** di mutare, e non salvare in caso d'errore. | S | Riprodotto |
| E2.4 | **Preservare `description`** nel round-trip del builder di struttura. | S | Oggi si perdono tutte al primo salvataggio dalla UI |
| E2.5 | **Riordino**: validare che l'ordine ricevuto sia una permutazione degli ID esistenti; rifiutare i duplicati (`order=0,0` oggi duplica il figlio). | S | |
| E2.6 | **Eliminazione collezione**: oggi toglie il modello e lascia le entità, che il sito continua a servire. Decidere tra archiviazione e cancellazione reale, e allineare il testo del pulsante che promette di eliminare "l'intera collezione e la sua struttura". | M | |
| E2.7 | **Escaping nelle viste**: `$group`, anteprime, nomi file, valori nelle `textarea` e negli attributi. Un valore che contiene `</textarea>` oggi esce dal campo. | M | |
| E2.8 | **Modifiche non salvate vs riordino**: il drag & drop azzera `hasUnsavedChanges` prima di inviare solo l'ordine, quindi quanto digitato va perso senza avviso. Salvare prima, o bloccare il riordino con modifiche pendenti. | S | |

---

## Fase E3 — Sicurezza dell'editor *(dipende dal piano architettura)*

| ID | Intervento | Dipende da |
|---|---|---|
| E3.1 | Token CSRF in tutti i form e verifica in `actions.php` | A2.3 |
| E3.2 | Login dal file di configurazione non versionato, con hash | A2.1 |
| E3.3 | Upload che passa dal servizio unico con allowlist | A2.4 |
| E3.4 | Eliminare il secondo upload duplicato in `actions.php` (politica di nomi diversa da `upload.php`, con rischio di sovrascrittura) | A2.4 |

---

## Fase E4 — Robustezza d'uso *(facoltativa, dopo il resto)*

| ID | Intervento | Dim. |
|---|---|---|
| E4.1 | Avviso di conflitto quando il contenuto è cambiato da quando hai aperto il form (usa A1.5). | M |
| E4.2 | Errori visibili in interfaccia invece che silenziosi (es. upload fallito, scrittura fallita). | M |
| E4.3 | Handler JS agganciati a `data-*` invece che a selettori di struttura (`document.querySelector('form.card')` oggi è un contratto implicito e fragile). | M |
| E4.4 | Indicatore di quali lingue sono compilate per ogni campo. | S |

---

## Sequenza consigliata

```text
E0  sblocco salvataggio        ← subito, indipendente da tutto
       │
       ▼
[Piano A: Fase 0 test] ──► [Piano A: Fase 1 dati] ──► [Piano A: Fase 2 sicurezza]
       │
       ▼
E1  split strutturale          ← dopo, così si sposta codice già corretto
       │
       ▼
E2  correttezza ──► E3 sicurezza (con A2) ──► E4 robustezza
```

**Il punto su cui insisto:** E1 non va fatto per primo. Il refactor sposta codice; se lo sposti prima di correggerlo, fai due volte lo stesso lavoro e perdi la tracciabilità del confronto. E0 invece è indipendente e va fatto oggi, perché senza quello l'editor non salva.

**Su come non farlo appendere:** questo repo ha due tentativi di refactor fermi al primo commit (`refactor01` a giugno 2025, `feature/editor_refactor` ad aprile 2026). E1 è diviso in sei passi da poche ore ciascuno, ognuno verificabile con il confronto HTML/JSON descritto sopra: ogni passo è un commit sensato a sé, e interrompersi a metà non lascia il repo in uno stato peggiore di oggi.
