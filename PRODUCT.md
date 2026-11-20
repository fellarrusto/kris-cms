# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Agenzie e sviluppatori web che valutano Kris per fare siti ai loro clienti: vogliono capire in pochi minuti cosa fa il framework, come si lavora sul tema (HTML, CSS e JS normali) e come il cliente aggiorna i contenuti dall'editor. Il sito demo è anche il tema di partenza dei siti nuovi.

## Product Purpose

Kris è un CMS PHP senza dipendenze per siti gestiti da agenzie: il tema è HTML/CSS/JS scritto a mano, il cliente modifica testi, immagini, elenchi e pagine da un editor web, e il sito si pubblica su qualunque hosting PHP via FTP. La demo deve mostrare tutte le funzionalità della 2.0 al lavoro, su contenuti veri del progetto, e restare un tema pulito da cui partire.

## Positioning

Un tema scritto a mano diventa gestibile marcando i contenuti (`{{campo}}`, `k-array`, `k-component`), senza build, database obbligatorio o framework frontend; i dati sono documenti JSON (o un database a scelta), le modifiche si possono preparare in attesa e pubblicare dopo un'anteprima, e un agente AI (Kris Agent) le prepara nello staging senza poter pubblicare.

## Operating Context

L'agenzia converte un design (Figma o HTML statico) in un tema Kris, lo carica via FTP con `tools/package.php`, e il cliente lavora in `/kris-admin/`. Aggiornamenti del framework firmati dall'editor; plugin ufficiali (Misurazione, IndexNow) installabili dall'editor.

## Capabilities and Constraints

Funzionalità della 2.0 da mostrare: indirizzi leggibili con slug, multilingua (it/en), raccolte e Posts, elenchi annidati, componenti condivisi, campi text/richtext/media/select/boolean/list, condizioni nei template, `{{tipo.campo}}`, contenuti sospesi, pagina 404 e lavori in corso del tema, privacy e cookie con banner a categorie e tabella dei cookie (`k-cookie-table`), proprietà del sito e SEO automatica (canonical, hreflang, Open Graph, sitemap, robots), immagini ottimizzate (srcset, WebP), `kris_head.html`, blocchi dei plugin (`k-plugin`). Il tema è HTML/CSS/JS reso da Kris in PHP: nessun framework né build. PHP 8.3 sulla 2.0.

## Brand Commitments

Nome: Kris (la 2.0 si presenta come «Kris 2»). Colori di marca: nero e bianco. Logo, verde e grafica della demo 1.x non sono vincolanti.

## Evidence on Hand

Repository pubblica https://github.com/fellarrusto/kris-cms, README.md, AGENTS.md, docs/2.0/. Nessun cliente, prezzo, numero di installazioni, testimonianza o benchmark: non vanno inventati. I contenuti dimostrativi (articoli, voci di esempio) si scrivono come esempi e si segnalano come tali dove potrebbero sembrare reali.

## Product Principles

- Mostrare, non dichiarare: ogni funzionalità si vede al lavoro nella pagina stessa.
- Il tema resta un buon punto di partenza: HTML leggibile, CSS ordinato, nessuna dipendenza.
- Il cliente non tocca il codice: tutto quello che cambia si cambia dall'editor.
- Onestà: niente numeri, clienti o promesse che il progetto non ha.
