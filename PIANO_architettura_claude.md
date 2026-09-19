# Piano A — Architettura e coerenza

**Base:** `main` @ `6c1b1e2` · **Autore:** Claude Opus 5 · 18 settembre 2026
**Piano gemello:** [PIANO_editor_claude.md](PIANO_editor_claude.md) — i due si incastrano, le dipendenze sono in §9.
**Evidenze:** [REPORT_progetto_claude.md](REPORT_progetto_claude.md) (bug con prova) e [REPORT_editor_refactor_claude.md](REPORT_editor_refactor_claude.md).

Il branch `feature/editor_refactor` è **archiviato e ignorato**: conteneva l'editor di aprile, superato dai tre commit di maggio. Tutto quanto segue parte da `main`.

**Dimensioni:** `S` ≈ meno di un'ora · `M` ≈ mezza giornata · `L` ≈ uno o due giorni. Sono stime grossolane per ordinare il lavoro, non impegni.

---

## 1. Criterio che userei

Il codice è piccolo (≈2.100 righe PHP) e l'impianto regge. Non serve riscrivere: servono **tre contratti che oggi non esistono**.

1. **Un solo modo di leggere e scrivere i dati.** Oggi sono due (`JsonRepository` per il sito, `getJson`/`saveJson` per l'editor), con politiche diverse su errori e scrittura.
2. **Un solo modo di rendere un valore.** Oggi il motore stampa tutto grezzo, quindi `text` e `richtext` sono indistinguibili in output e un parametro URL entra nel markup.
3. **Un solo modo di dire cos'è un contenuto.** Oggi lo schema governa l'editor, ma il frontend legge il tipo scritto dentro il dato: possono divergere.

Tutto il resto del piano discende da qui. L'ordine non è per gravità teorica ma per **rischio di perdere contenuti di un cliente**, che è l'unico danno non recuperabile.

---

## 2. Decisioni che servono prima di partire

Non le do per scontate: cambiano il piano.

| # | Decisione | Opzioni | Cosa consiglio |
|---|---|---|---|
| D1 | **Il CMS è un prodotto che si aggiorna, o un template che si clona?** Il branch `soulandfish` (14 commit, mar–mag 2025) è un sito cliente cresciuto dentro questo repo, su una versione vecchia. | (a) prodotto versionato, siti separati dal motore · (b) si continua a clonare per cliente | **(a)**, ma solo dai prossimi siti. Senza questo, ogni fix di questo piano resta confinato al repo e i siti già consegnati non lo vedranno mai. |
| D2 | **Dove sta il document root?** Oggi è la root del repo: `data/`, `config/` e `vendor/` sono scaricabili (verificato: `GET /data/k_data.json` → 200, 26 KB). | (a) spostare tutto in `public/` · (b) `.htaccess` versionato che blocca le cartelle | **(b) subito, (a) dopo.** L'`.htaccess` è mezz'ora e chiude il buco; `public/` è più pulito ma tocca URL, autoload e deploy. |
| D3 | **Escaping: rompiamo i template esistenti?** Mettere l'escape di default cambia il rendering di ogni campo che oggi contiene HTML. | (a) escape di default, `richtext` esplicitamente HTML · (b) escape solo dove serve | **(a)**, previa scansione dei dati esistenti per vedere quali campi contengono tag (nei dati attuali sono pochi). È l'unico modo per rendere vera la distinzione tra `text` e `richtext`. |
| D4 | **SVG negli upload?** Un SVG può contenere script. | (a) ammessi · (b) esclusi · (c) ammessi ma sanificati | **(b)** salvo che servano davvero: oggi c'è un solo SVG (il logo), gestibile come asset statico. |
| D5 | **Schema: cosa succede ai dati quando rinomini o togli un campo?** Oggi il contenuto sparisce al primo salvataggio successivo. | (a) rinomina = migrazione dei dati · (b) rimozione = conferma esplicita e backup · (c) si lascia com'è, documentandolo | **(a)+(b)** nella forma minima: nessuna migrazione automatica, ma avviso esplicito e backup prima di applicare. |

---

## 3. Fase 0 — Rete di sicurezza *(prerequisito di tutto)*

Senza questa fase ogni fix successivo è una scommessa: non esiste un test nel repo, e il lint PHP non intercetta nessuno dei bug trovati.

| ID | Intervento | Dim. | Verifica |
|---|---|---|---|
| A0.1 | PHPUnit come dipendenza **dev** (`require-dev`), così l'hosting di produzione non la installa. Cartella `tests/`. | S | `composer install --no-dev` non scarica nulla di nuovo |
| A0.2 | **Test di regressione sui bug già riprodotti**: operatori `>=`/`<=`, `if` annidati, confronto su campo multilingua, import di frammenti multi-radice, `"0"` trattato come vuoto, entità inesistente. Scritti **prima** dei fix, quindi inizialmente rossi. | M | 6 test falliscono per i motivi attesi |
| A0.3 | **Snapshot dell'HTML attuale** delle pagine pubbliche (homepage, dettaglio `features/0..2`) come golden file. | S | Rieseguendo, diff vuoto |
| A0.4 | **Fixture separate dai contenuti reali**: `tests/fixtures/k_data.json`. I test non devono mai toccare `data/`. | S | `git status` pulito dopo la suite |

> Nota su A0.3: gli snapshot conterranno anche gli artefatti attuali (es. `<?xml encoding="UTF-8">`). Vanno aggiornati **consapevolmente** quando un fix li cambia — è proprio il loro scopo.

---

## 4. Fase 1 — Integrità dei dati *(la priorità vera)*

Tre modi verificati di perdere contenuti, tutti senza un messaggio d'errore.

| ID | Intervento | Dim. | Perché (evidenza) |
|---|---|---|---|
| A1.1 | **`Kris\Storage\JsonStore`**: unico punto di lettura/scrittura. Lettura che **fallisce rumorosamente** su JSON illeggibile (mai il default vuoto); scrittura atomica `tmp` + `rename`; `LOCK_EX`; controllo del valore di ritorno di `json_encode` e `file_put_contents`. | M | Con `k_data.json` corrotto, un "Nuovo Elemento" ha portato il file **da 26.690 a 253 byte** |
| A1.2 | **Backup rotativo** prima di ogni scrittura (ultimi N in `storage/backups/`). | S | Serve a rendere reversibile qualunque altro errore del piano |
| A1.3 | Far passare **sia `JsonRepository` sia l'editor** da `JsonStore`. Qui sparisce il doppio strato di persistenza. | M | `grep file_put_contents` → una sola occorrenza |
| A1.4 | **Aggiornamento per identità** (`name` + `id`) invece che per indice dell'array. | S | Oggi `Entity::save()` scrive a un indice calcolato altrove |
| A1.5 | **Rilevazione conflitti**: salvare confrontando un `mtime`/hash letto all'apertura del form; se cambiato, avvisare invece di sovrascrivere. | M | Due editor aperti oggi si sovrascrivono senza accorgersene |

**Come verifico la fase:** un test che corrompe una fixture e si aspetta un'eccezione (non un file riscritto); un test che scrive e uccide il processo a metà non è realistico, ma il `rename` atomico è verificabile leggendo che non esiste mai un file parziale.

---

## 5. Fase 2 — Superficie pubblica e accesso

| ID | Intervento | Dim. | Note |
|---|---|---|---|
| A2.1 | **Credenziali fuori dal codice**: `config/auth.php` non versionato (`.gitignore`), password con `password_hash`, confronto con `password_verify`, e primo avvio che chiede di crearle se il file manca. | M | Oggi `admin`/`password` in chiaro e versionate |
| A2.2 | **Sessione**: `session_regenerate_id(true)` al login, cookie `HttpOnly`/`SameSite=Lax`/`Secure` se HTTPS, timeout. | S | |
| A2.3 | **Token CSRF** su tutte le mutazioni (helper `csrf_field()` + verifica centralizzata). | M | Dipendenza: il piano editor lo usa in ogni form |
| A2.4 | **`Kris\Media\UploadService` unico**: allowlist estensioni **e** MIME, limite dimensione, nome generato, verifica esito, decisione D4 su SVG. Oggi l'upload esiste in due punti con due politiche diverse. | M | Un `.php` caricato è stato eseguito dal server locale |
| A2.5 | **Blocco HTTP di `data/`, `config/`, `vendor/`** (decisione D2). | S | Verificato oggi: JSON scaricabile senza login |
| A2.6 | **Upload non eseguibili**: `.htaccess` in `assets/uploads/` che disattiva l'esecuzione PHP, oltre alla allowlist. Difesa in profondità. | S | |
| A2.7 | Protezione tentativi di login ripetuti (ritardo progressivo o blocco temporaneo). | S | |

**Verifica:** ripeto le sonde che ho già usato — upload di un file non ammesso → rifiutato; `GET /data/k_data.json` → 403; POST senza token → rifiutato; ID di sessione diverso prima/dopo il login.

---

## 6. Fase 3 — Contratti del rendering

Qui stanno i bug che il README non documenta e che mordono chi scrive i template.

| ID | Intervento | Dim. | Evidenza |
|---|---|---|---|
| A3.1 | **Escaping contestuale** (decisione D3): `text`/`plain` escapati, `richtext` esplicitamente HTML. Politica separata per i valori dentro attributi. | L | Un `text` con `<em>` diventa markup; `text` e `richtext` oggi si comportano uguale |
| A3.2 | **Validare `ln`** contro le lingue configurate, usarlo per `<html lang>`, generare la nav lingue dalle lingue pubblicate invece che a mano nel template. | M | `?ln=…` costruito ad arte crea un attributo sul tag `<a>`; `?ln=en` lascia comunque `lang="it"` |
| A3.3 | **Parser — operatori**: alternative ordinate dalla più lunga (`>=`, `<=` prima di `>`, `<`). | S | `n=7`, `{{#if n >= 5}}` → `KO` |
| A3.4 | **Parser — `if` annidati**: lo stack deve puntare al contenitore del ramo attivo. | M | `{{#if a}}A{{#if b}}B{{/if}}C{{/if}}` → `AC`, la `B` sparisce |
| A3.5 | **Parser — confronto localizzato**: stessa risoluzione di lingua dell'interpolazione. | S | `{{#if title == "Ciao"}}` su campo multilingua → sempre falso |
| A3.6 | **`DomHelper`**: importare tutti i nodi radice con un `DocumentFragment`; rimuovere la processing instruction `<?xml encoding="UTF-8">` dall'output. | M | Un frammento a due radici perde la seconda; la PI finisce in ogni pagina |
| A3.7 | **Entità mancante → 404**: controllare il risultato del repository prima di assegnarlo alla proprietà tipizzata, così la guardia esistente torna raggiungibile. | S | `?key=inesistente` → **500** con stack trace |
| A3.8 | **Decidere cos'è `Entity`**: modello scrivibile o snapshot di sola lettura. `set()`, `setData()`, `save()`, `toJson()` oggi **non sono chiamati da nessuna parte**. O li usa l'editor (dopo A1.3), o si tolgono. | M | Metà della classe è codice morto; `getData()` tratta `"0"` come vuoto |
| A3.9 | **Base URL centralizzata**: `template/detail.html` e `404.php` usano link assoluti a `/`, quindi l'installazione in sottocartella si rompe. | S | |

---

## 7. Fase 4 — Schema e modello

| ID | Intervento | Dim. | Evidenza |
|---|---|---|---|
| A4.1 | **Validazione server dello schema**, ricorsiva: tipi ammessi, nomi duplicati, forma di `of`, rifiuto del JSON invalido **senza sovrascrivere**. | M | Uno `schema_json` invalido oggi salva uno schema vuoto e dice "Struttura aggiornata con successo" |
| A4.2 | **Nomi di campo riservati** (`id`, `group`, `path`, `save_entity`, …) rifiutati con messaggio chiaro, oppure risolti alla radice prefissando i campi nel form. | S | Campo `group` → **500**; campo `id` → **entità duplicata a ogni salvataggio** |
| A4.3 | **Conservare i metadati** dello schema (`description`) nel round-trip del builder. | S | Oggi si perdono al primo salvataggio dalla UI |
| A4.4 | **Politica di evoluzione** (decisione D5): rinomina con migrazione, rimozione con conferma e backup. | M | Oggi il contenuto di un campo tolto sparisce silenziosamente |
| A4.5 | **Chi comanda sul tipo**: decidere se il frontend deve fidarsi del tipo scritto nel dato o consultare lo schema. Oggi divergono dopo ogni modifica di struttura. | M | Decisione di contratto, non solo codice |

---

## 8. Fase 5 — Distribuzione e manutenzione

| ID | Intervento | Dim. |
|---|---|---|
| A5.1 | `composer.json`: dichiarare `ext-dom` e `ext-mbstring` (usate e non dichiarate), nome pacchetto reale al posto di `tuonome/kris2`, versione PHP effettivamente testata. | S |
| A5.2 | Separare **dati di esempio** (`examples/`) dai **dati dell'installazione**, così un aggiornamento non sovrascrive i contenuti di un cliente (collegato a D1). | M |
| A5.3 | README: documentare setup sicuro delle credenziali, protezione dei JSON, backup, e i limiti reali del motore (es. `{{#if}}` finché A3.5 non è fatto). Correggere la parte che dice che gli schemi nested si modificano a mano: il builder esiste. | M |
| A5.4 | CI minima: lint PHP + suite di test a ogni push. | S |
| A5.5 | Versionamento del CMS (tag) e nota di upgrade per i siti esistenti (collegato a D1). | M |
| A5.6 | Pulizia branch: 10 su 13 sono già in `main`. `refactor01` è archeologia (struttura di cartelle non più esistente), `feature/editor_refactor` è superato, `soulandfish` è un sito cliente da separare, non da mergiare. | S |

---

## 9. Ordine e dipendenze

```text
FASE 0  test + snapshot            ──┐ abilita tutto il resto
                                      │
FASE 1  JsonStore, backup, lock    ◄──┤ PRIORITÀ: perdita contenuti
   │                                  │
   ├──► A2.4 upload unico             │
   └──► A3.8 destino di Entity        │
                                      │
FASE 2  credenziali, CSRF, HTTP    ◄──┘ PRIORITÀ: pubblicabilità
   └──► A2.3 CSRF ──► piano editor (E3.1)
                                      
FASE 3  rendering                     richiede D3 (escaping)
   └──► A3.2 lingue ──► piano editor (E2.2 traduzioni)

FASE 4  schema                        A4.2 ──► piano editor (E2.1)

FASE 5  distribuzione                 richiede D1
```

**Sequenza che consiglio:** D1–D5 → Fase 0 → **E0 del piano editor** (5 minuti, sblocca l'editing) → Fase 1 → Fase 2 → **E1 del piano editor** (lo split) → Fase 3 → Fase 4 → Fase 5.

Motivo: la Fase 1 protegge i contenuti, la Fase 2 rende pubblicabile, e lo split dell'editor conviene farlo **dopo** perché così il codice che si sposta è già quello corretto — si sposta una volta sola.

---

## 10. Cosa non farei

- **Niente framework** (Laravel, Symfony, Slim): il perimetro non lo giustifica e non risolverebbe nessuno dei contratti mancanti.
- **Niente SQLite/MySQL adesso.** Il branch `feature/sqlite` esiste, ma cambiare storage prima di avere uno strato di persistenza unico significa scrivere due volte lo stesso lavoro. Dopo A1.3 diventa una sostituzione localizzata — se mai servirà.
- **Niente riscrittura del frontend.** I template HTML a mano sono il prodotto.
- **Niente rinomina di cartelle per estetica.** `template/` e `editor/views/` possono convivere.
- **Nessuna ottimizzazione di performance** senza prima una misura: oggi non ho benchmark e gli archivi sono piccoli.
