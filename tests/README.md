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
| `xfail` | bug noto e documentato nel piano: il test è rosso di proposito | niente, finché non si affronta quell'intervento |
| `XPASS` | un bug noto risulta risolto | sostituire `xfail()` con `test()`: da quel momento è protetto da regressioni |

Il comando esce con codice 1 solo per `FAIL` e `XPASS`. Gli `xfail` non fanno fallire la build: sono la fotografia dei difetti già censiti.

## Il ciclo di lavoro

Ogni `xfail` porta il riferimento dell'intervento nel piano (es. `[A3.3]`).

1. Si affronta l'intervento, ad esempio A3.3 (operatori `>=` e `<=`).
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

`vendor/` è dentro il document root e il README indica di caricarla via FTP: una dipendenza di sviluppo finirebbe pubblicata sul server insieme al resto. Finché quel punto non è risolto (decisione D2 del piano architettura), la suite resta a dipendenze zero.
