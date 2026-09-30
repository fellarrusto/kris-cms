# Piano C — Aggiornamento del framework dai siti installati

**Base:** `main` @ `aa8f2c9` · **Autore:** Claude Opus 5 · 30 settembre 2026
**Piani correlati:** [PIANO_architettura_claude.md](PIANO_architettura_claude.md) (chiude la decisione D1 e riprende A5.2, A5.5) · [PIANO_editor_claude.md](PIANO_editor_claude.md)

Obiettivo: da un sito già installato, un admin preme **un tasto** nell'editor e il framework si aggiorna da solo — download, backup, sostituzione dei file, migrazione dei dati, verifica, e rollback automatico se qualcosa va storto.

**Contesto dato:** hosting condivisi con solo FTP, repository pubblica, il tasto lo può premere l'admin del sito (spesso il cliente). Le versioni partono da quella attuale, che diventa la **1.0.0**.

**Dimensioni:** `S` ≈ meno di un'ora · `M` ≈ mezza giornata · `L` ≈ uno o due giorni.

---

## 1. Il vincolo che comanda tutto il piano

L'updater contenuto nella 1.0.0 è **l'unico pezzo di codice che non potremo mai correggere da remoto**: un suo bug si ripara solo passando a mano via FTP su ogni sito. Quindi va diviso in due:

| | Cosa fa | Dove vive | Si aggiorna? |
|---|---|---|---|
| **Stadio 1** | Legge il manifest, scarica o riceve lo zip, verifica la firma, lo estrae in staging, passa il controllo allo stadio 2 del pacchetto **nuovo** | Nel sito, congelato dalla 1.0.0 | No |
| **Stadio 2** | Preflight, snapshot, manutenzione, swap, migrazioni, smoke test, rollback | Dentro ogni pacchetto | Sì, a ogni release |

Lo stadio 1 deve restare il più piccolo possibile. Tutto ciò che può migliorare sta nello stadio 2.

---

## 2. Decisioni chiuse

| # | Decisione | Scelta |
|---|---|---|
| C1 | Struttura dei file | Framework tutto in `kris/`, sostituibile in blocco. Nella root restano solo stub. |
| C2 | Canale di distribuzione | File statico `releases.json` nella repo pubblica, zip nelle GitHub Releases. **Mai l'API di GitHub** (60 richieste/ora per IP, condiviso su hosting condiviso). |
| C3 | URL del canale | In `config/`, modificabile. **Non è fidato:** la sicurezza sta nella firma, non nell'URL. |
| C4 | Firma | Ed25519 con `sodium`. Chiave privata **solo sul PC di Filippo**, mai nei secret della CI. Due chiavi pubbliche compilate nella 1.0.0 (principale + riserva offline). |
| C5 | Innesco | Solo manuale: tasto "Verifica aggiornamenti", poi tasto "Aggiorna". Nessun controllo in background, nessuna telemetria, il sito non chiama casa da solo. |
| C6 | Cosa può applicare l'admin | Patch e minor. Le major si fermano con "richiede lo sviluppatore". |
| C7 | Reversibilità | Migrazioni solo in avanti. Il rollback ripristina lo snapshot completo (framework + dati) preso prima dell'update. Nessuna migrazione `down`. |
| C8 | URL dell'editor | Resta `/editor/`, con uno stub. I clienti hanno già il link salvato. |
| C9 | Finestra di rollback | Lo snapshot dell'ultimo update resta disponibile **14 giorni**, con un pulsante "Torna alla versione precedente". |
| C10 | Versione visibile | Sempre in fondo alla sidebar (`Kris 1.0.2`) e in dettaglio in Impostazioni: versione framework, versione dati, ultimo controllo, ultimo aggiornamento, esito. |

---

## 3. Struttura di destinazione

```text
/                          document root
├── index.php              stub → kris/bootstrap.php
├── 404.php                stub, sovrascrivibile dal sito
├── editor/index.php       stub → kris/editor/index.php
├── template/              SITO — mai toccato dall'update
├── assets/                SITO — mai toccato (css, img, uploads)
├── config/                SITO — allowed_pages.json, auth.php, update.php
├── data/                  SITO — contenuti, più lo stato dell'update
│   ├── k_data.json  k_model.json  cms_settings.json
│   ├── kris_state.json    versione framework, versione dati, migrazioni applicate, log
│   ├── backups/           già esistente, scritture di JsonStore
│   ├── updates/           staging degli zip scaricati
│   └── snapshots/         snapshot pre-update per il rollback
└── kris/                  FRAMEWORK — sostituito in blocco
    ├── VERSION
    ├── bootstrap.php
    ├── core/              core/entity, core/template, core/scripts
    ├── editor/
    ├── migrations/        0001_*.php, 0002_*.php, …
    ├── update/
    │   ├── stage1.php     congelato nella 1.0.0
    │   └── installer.php  stadio 2
    └── autoload.php       (vedi C-aperta 1)
```

**Perché una cartella sola:** l'update diventa `kris` → `kris.old` e `kris.new` → `kris`. Due rename. Risolve insieme atomicità (il sito non è mai a metà tra due versioni), rollback (si rinomina all'indietro), file rimossi tra due versioni (spariscono, invece di restare come view orfane) e modifiche locali al core (si vedono, invece di mescolarsi).

---

## 4. Il contratto congelato nella 1.0.0

Queste cose lo stadio 1 le conosce per sempre, quindi vanno decise una volta e non cambiate più:

1. **Percorsi:** `kris/`, `data/kris_state.json`, `data/updates/`, `data/snapshots/`, il flag di manutenzione `data/kris_maintenance`.
2. **Chiavi pubbliche:** due, scritte nel codice dello stadio 1.
3. **Punto di ingresso dello stadio 2:** `kris/update/installer.php`, che definisce `kris_update_install(array $ctx): array`. `$ctx` contiene i percorsi assoluti, la versione di partenza, quella di arrivo e la cartella di staging. Il ritorno è `['ok' => bool, 'steps' => [...], 'error' => ?string]`.
4. **Campi del manifest** (aggiungerne è lecito, rinominarli no):

```json
{
  "latest": "1.0.2",
  "releases": [
    {
      "version": "1.0.2",
      "url": "https://github.com/<owner>/<repo>/releases/download/v1.0.2/kris-1.0.2.zip",
      "sha256": "…",
      "min_from": "1.0.0",
      "data_version": 1,
      "requires": { "php": "8.1", "ext": ["dom", "mbstring", "json", "zip"] },
      "breaking": false,
      "changelog_it": "Testo per il cliente, non per lo sviluppatore."
    }
  ]
}
```

Accanto va `releases.json.sig`, firma distaccata del manifest. Firmando il manifest si firmano indirettamente gli zip, perché contiene i loro sha256: una firma per release.

---

## 5. Cosa succede alla pressione del tasto

**Verifica aggiornamenti**
1. Legge l'URL da `config/update.php`. Accetta solo `https://` e solo host in allowlist (`raw.githubusercontent.com`, `github.com`), senza seguire redirect fuori da quella lista. Serve a evitare che quel campo diventi un modo per far interrogare al server indirizzi interni dell'hosting o file locali.
2. Scarica manifest e firma. Firma non valida → si ferma qui.
3. Confronta le versioni con `version_compare`, mai come stringhe: `"1.0.10" < "1.0.9"` è vero per il confronto tra stringhe e il sito resterebbe fermo credendosi aggiornato.
4. Scrive il risultato in cache con la data, così il tasto premuto dieci volte non fa dieci chiamate. Se la connessione in uscita è bloccata — succede spesso sugli hosting condivisi — il messaggio propone subito l'**upload dello zip a mano**.

**Aggiorna**
1. **Lock** (`data/updates/.lock`): due admin non possono partire insieme.
2. Download dello zip, controllo dello sha256 dichiarato nel manifest firmato.
3. Estrazione in `data/updates/<versione>/`, che non è scaricabile via HTTP. Si scartano le voci con `..`, percorsi assoluti o fuori dalle cartelle previste (zip slip).
4. **Passaggio allo stadio 2 del pacchetto nuovo.** Il codice che esegue l'update non deve essere il codice che viene sovrascritto.
5. **Preflight**, provando davvero: crea una cartella di test, la rinomina, la cancella. Su molti hosting PHP gira con un utente diverso da quello FTP e non può scrivere; su alcuni (Aruba su Windows/IIS) il rename di cartelle fallisce. Più versione PHP, estensioni, spazio su disco. Se non passa, si ferma **prima** di toccare qualsiasi file.
6. **Snapshot** in `data/snapshots/<timestamp>/`: `kris/` e tutti i file di `data/` in un colpo solo.
7. **Manutenzione on** (flag file letto da `bootstrap.php`, risposta 503).
8. **Swap:** `kris` → `kris.old`, `kris.new` → `kris`, poi `opcache_reset()` — senza quello OPcache continua a servire i file vecchi.
9. **Migrazioni** (§6).
10. **Smoke test:** valida i dati contro il modello e renderizza in-process tutte le `allowed_pages` con i contenuti reali, con un guardiano anche sugli errori fatali.
11. Se un passo da 5 a 10 fallisce: **rollback automatico** (ripristino di `kris.old` e dei dati dallo snapshot), manutenzione off, messaggio con il punto in cui si è fermato.
12. Successo: manutenzione off, `kris_state.json` aggiornato, riga nel registro degli update (chi, quando, da quale versione a quale, esito), `kris.old` rimosso, snapshot conservato 14 giorni.

---

## 6. Politica delle migrazioni dei dati

Due cose che vanno tenute separate:

- **Migrazioni del framework:** cambia il *formato* di `k_data.json`, `k_model.json`, `cms_settings.json`, `auth.php`. Le fa l'updater.
- **Modifiche al modello del sito:** il cliente rinomina un campo da Struttura. È contenuto suo, e resta gestito come oggi. L'updater non lo tocca mai.

Regole per le prime:

1. **Numerate e solo in avanti:** `kris/migrations/0003_tipo_dallo_schema.php`.
2. **Funzioni pure sugli array:** ricevono i dati, restituiscono i dati, senza I/O. Tutte girano in memoria e si scrive una volta sola alla fine, tramite `JsonStore`.
3. **Autosufficienti:** non usano `Entity`, `JsonRepository` né altre classi del core. Una migrazione scritta oggi deve funzionare tra due anni, quando quelle classi saranno diverse. È questa regola che permette di saltare più versioni in un colpo solo.
4. **Idempotenti**, e con un test obbligatorio fixture prima → fixture dopo, nella suite esistente.
5. **`data_version` separata dalla versione del framework:** la maggior parte delle release non tocca i dati.
6. **Migrazioni mancanti rilevate:** se il codice conosce la versione dati 5 e ne trova 3, l'editor mostra "migrazione necessaria" e la propone. Così anche chi carica i file a mano via FTP finisce sullo stesso percorso.
7. **Protezione dal downgrade:** se trova dati a una versione **più alta** di quella che conosce — caso tipico: un deploy FTP da una copia locale vecchia sovrascrive `kris/` — l'editor va in sola lettura con un avviso, invece di scrivere in un formato che non capisce.

Le major non si applicano dall'editor perché possono rompere i template scritti a mano, e nessuna migrazione dei dati lo rimedia (esempio: l'escape di default, decisione D3 del piano architettura). Per quelle: scansione dei template, elenco degli avvisi, intervento umano.

---

## 7. Pipeline di release

1. Tag su Git.
2. La CI esegue la suite e costruisce lo zip: solo `kris/` e gli stub, con dentro l'elenco dei file e i loro hash. Esclusi `.git/`, `tests/`, i `PIANO_*.md`, le configurazioni locali degli agenti, `data/`, `template/`, `assets/`, `config/`.
3. Verifichi lo zip in locale e lo **firmi sul tuo PC**. La repo è pubblica e i clienti si fidano del pulsante: con la chiave privata nei secret di GitHub, chi prende l'account GitHub manda codice su tutti i siti dei clienti.
4. Pubblichi zip e firma nella Release, aggiorni `releases.json` e `releases.json.sig` su `main`.

Se `sodium` manca sull'hosting (raro, è incluso da PHP 7.2, ma qualcuno lo disattiva), il controllo remoto si disabilita e resta solo l'upload manuale dello zip, con verifica dello sha256 e un avviso esplicito.

---

## 8. Fasi

| ID | Intervento | Dim. | Verifica |
|---|---|---|---|
| **C0.1** | Spostamento del framework in `kris/`, stub nella root, aggiornamento di autoload e percorsi | M | La suite passa, sito ed editor funzionano, gli snapshot non cambiano |
| C0.2 | `kris/VERSION` a `1.0.0`, `data/kris_state.json`, versione visibile in sidebar e Impostazioni | S | La versione compare nell'editor |
| C0.3 | Runner delle migrazioni + migrazione `0001` di partenza (crea lo stato su un sito che non lo ha) | M | Un sito senza `kris_state.json` viene inizializzato senza perdere dati |
| C0.4 | Rilevazione migrazioni mancanti e protezione dal downgrade | S | Dati a versione più alta → editor in sola lettura |
| **C1.1** | Stadio 2: preflight, snapshot, manutenzione, swap, migrazioni, smoke test, rollback | L | Update finto 1.0.0 → 1.0.1 in locale, e rollback provocato a ogni passo |
| C1.2 | Stadio 1 congelato: manifest, firma, staging, passaggio di controllo | M | Firma manomessa → rifiutato; host fuori allowlist → rifiutato |
| C1.3 | Upload manuale dello zip dall'editor | M | Funziona con la rete in uscita bloccata |
| C1.4 | UI: avviso, changelog, barra di avanzamento, esito, registro, "torna alla versione precedente" | M | Un non tecnico completa l'update senza domande |
| C1.5 | `config/update.php` con URL, allowlist e interruttore `updates: on/off` per i siti personalizzati | S | A `off` la sezione non appare |
| **C2.1** | Generazione delle chiavi, script di firma locale, workflow CI di build | M | Zip riproducibile, firma verificata dallo stadio 1 |
| C2.2 | `releases.json` + `releases.json.sig` pubblicati | S | Il tasto Verifica trova la versione |
| **C3.1** | Tag `1.0.0` | S | |
| C3.2 | Release `1.0.1` di prova e update end-to-end su un hosting FTP reale | M | Sito e editor funzionanti dopo l'update, rollback provato lì |
| C3.3 | Aggiornamento di `AGENTS.md` e `README.md`: nuova struttura, deploy, update | M | |

L'ordine conta: C0 ha valore da solo, perché rende sicuri anche gli aggiornamenti fatti a mano via FTP.

---

## 9. Cosa non farei

- **Aggiornamenti in background o automatici.** Richiederebbero scheduler agganciato alle visite, release ritardate, kill switch, versioni revocate, health check di cui nessuno guarda l'esito e telemetria verso di noi. Si può aggiungere dopo, per le sole patch, quando avremo visto funzionare qualche update vero.
- **Migrazioni `down`.** Il rollback dello snapshot fa lo stesso lavoro con meno codice da mantenere. Costo accettato: le modifiche fatte tra update e rollback si perdono, e va detto chiaro nella UI.
- **Toccare `template/` in automatico.** Al massimo si scansiona e si avvisa.
- **Aggiornare `soulandfish` con questo meccanismo:** troppo diverso. Si porta a mano alla 1.0.0 o si lascia com'è.
- **Aggiornare i siti installati prima della 1.0.0 da remoto:** passaggio manuale una volta sola, poi entrano nel giro.

---

## 10. Decisioni ancora aperte

| # | Decisione | Raccomandazione |
|---|---|---|
| CA1 | **Composer o un autoloader nostro?** Composer non porta nessuna dipendenza a runtime (`composer.json` non ne dichiara, i test sono uno script nostro): `vendor/` serve solo all'autoload. Un `kris/autoload.php` PSR-4 di trenta righe lo sostituisce. | **Sì, togliere Composer.** Un pacchetto senza `vendor/` da rigenerare è più semplice da distribuire su FTP. Va aggiornata la documentazione di installazione. |
| CA2 | **Su quale hosting facciamo la prova end-to-end (C3.2)?** Serve uno hosting reale tra quelli che usate, non XAMPP: il preflight esiste proprio per le differenze tra hosting. | Da indicare. |
| CA3 | **`404.php` è del framework o del sito?** Oggi è nella root e un sito potrebbe volerlo personalizzato. | Default nel framework, sovrascrivibile dal sito; l'update non tocca la copia del sito. |
