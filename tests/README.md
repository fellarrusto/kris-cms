# Test di Kris 2 CMS

Suite senza dipendenze esterne: si esegue con PHP e basta.

```bash
php tests/run.php                    # tutto
php tests/run.php template           # solo le suite/test che contengono "template"
php tests/run.php --update-snapshots # rigenera i golden file
```

Esecuzione completa: circa 2 secondi.

## Come leggere gli esiti

| Esito | Significato | Cosa fare |
|---|---|---|
| `ok` | comportamento corretto, verificato | niente |
| `FAIL` | **regressione**: qualcosa che funzionava si è rotto | correggere prima di proseguire |
| `xfail` | bug noto identificato nel test: il test è rosso di proposito | correggere quando si affronta quel difetto |
| `XPASS` | un bug noto risulta risolto | sostituire `xfail()` con `test()`: da quel momento è protetto da regressioni |

Il comando esce con codice 1 solo per `FAIL` e `XPASS`. Gli `xfail` non fanno fallire la build: sono la fotografia dei difetti già censiti.

## Il ciclo di lavoro

Ogni `xfail` deve identificare il difetto che documenta, con una descrizione o un riferimento a un issue.

1. Si corregge il difetto descritto dal test.
2. Si rilancia la suite: quei test diventano `XPASS`.
3. Si promuovono da `xfail()` a `test()`.
4. Da lì in poi quel bug non può tornare senza far fallire la suite.

## Struttura

```
tests/
├── run.php            runner e asserzioni
├── bootstrap.php      helper, sandbox isolata, confronto snapshot
├── harness/render.php rende una pagina pubblica in un processo separato
├── fixtures/          copia congelata dei dati, usata al posto di data/
├── snapshots/         HTML atteso delle pagine pubbliche
└── suites/            i test veri e propri
```

**I test non leggono né scrivono `data/`.** Le pagine vengono rese in una copia temporanea del progetto in cui `data/` è sostituita da `tests/fixtures/`, così modificare i contenuti del sito non rompe la suite. La copia viene cancellata a fine esecuzione.

Quando un intervento cambia l'output pubblico **di proposito**, si rigenerano gli snapshot con `--update-snapshots` e si controlla il diff prima di committare: è lì che si vede cosa è cambiato davvero nelle pagine.

## Perché non PHPUnit

La suite usa PHP senza dipendenze di sviluppo aggiuntive. Per struttura del framework, conversione dei siti e separazione degli artefatti di deploy, consulta [AGENTS.md](../AGENTS.md).
