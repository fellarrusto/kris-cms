---
name: Kris 2 — tema demo
description: Il sito di Kris 2 fatto con Kris: bianco e nero, Geist, il prodotto vero al lavoro.
colors:
  bg: "#ffffff"
  fg: "#0a0a0a"
  fg-2: "#3b3b3b"
  muted: "#666666"
  line: "#ebebeb"
  line-2: "#d9d9d9"
  soft: "#f5f5f5"
  code-bg: "#0a0a0a"
  code-line: "#262626"
  code-fg: "#a3a3a3"
  code-hi: "#ffffff"
typography:
  display:
    fontFamily: "Geist, ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif"
    fontSize: "clamp(2.5rem, 1.35rem + 4vw, 4.375rem)"
    fontWeight: 600
    lineHeight: 1.02
    letterSpacing: "-0.034em"
  headline:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(1.875rem, 1.3rem + 2vw, 2.875rem)"
    fontWeight: 600
    lineHeight: 1.05
    letterSpacing: "-0.035em"
  headline-page:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2.125rem, 1.4rem + 2.8vw, 3.5rem)"
    fontWeight: 600
    lineHeight: 1.04
    letterSpacing: "-0.04em"
  title:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "-0.02em"
  title-row:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.0625rem"
    fontWeight: 500
    lineHeight: 1.6
    letterSpacing: "-0.01em"
  lede:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(1.0625rem, 1rem + 0.3vw, 1.25rem)"
    fontWeight: 400
    lineHeight: 1.55
  body:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
    fontFeature: "\"ss01\", \"cv11\""
  label:
    fontFamily: "Geist, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.6
  mono:
    fontFamily: "Geist Mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace"
    fontSize: "0.8125rem"
    fontWeight: 400
    lineHeight: 1.75
rounded:
  md: "10px"
  lg: "14px"
  pill: "999px"
spacing:
  gutter: "clamp(16px, 4vw, 40px)"
  container: "1200px"
  header: "64px"
  grid-gap: "clamp(40px, 5vw, 72px)"
  section: "clamp(64px, 9vw, 128px)"
  row: "20px"
components:
  button-primary:
    backgroundColor: "{colors.fg}"
    textColor: "{colors.bg}"
    rounded: "{rounded.pill}"
    padding: "0 22px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "#2b2b2b"
  button-secondary:
    backgroundColor: "{colors.bg}"
    textColor: "{colors.fg}"
    rounded: "{rounded.pill}"
    padding: "0 22px"
    height: "44px"
  button-inverse:
    backgroundColor: "{colors.bg}"
    textColor: "{colors.fg}"
    rounded: "{rounded.pill}"
    padding: "0 22px"
    height: "44px"
  button-small:
    rounded: "{rounded.pill}"
    padding: "0 16px"
    height: "36px"
  filter-chip:
    backgroundColor: "{colors.bg}"
    textColor: "{colors.fg-2}"
    rounded: "{rounded.pill}"
    padding: "0 14px"
    height: "36px"
  filter-chip-pressed:
    backgroundColor: "{colors.fg}"
    textColor: "{colors.bg}"
  tag:
    textColor: "{colors.fg-2}"
    rounded: "{rounded.pill}"
    padding: "0 8px"
    height: "20px"
  code-block:
    backgroundColor: "{colors.code-bg}"
    textColor: "{colors.code-fg}"
    typography: "{typography.mono}"
    rounded: "{rounded.md}"
    padding: "18px"
  frame:
    backgroundColor: "{colors.bg}"
    rounded: "{rounded.lg}"
  close-band:
    backgroundColor: "{colors.fg}"
    textColor: "{colors.bg}"
  cookie-banner:
    backgroundColor: "{colors.bg}"
    rounded: "{rounded.lg}"
    padding: "20px"
    width: "min(420px, calc(100vw - 24px))"
---

# Design System: Kris 2 — tema demo

Questo documento descrive il tema pubblico della demo di Kris 2 (`template/`, `assets/css/style.css`, `assets/js/site.js`, `assets/fonts/`), ricavato dal codice consegnato. L'editor (`admin-src/`, servito da `/kris-admin/`) è un altro design, con le sue variabili e il suo tema chiaro e scuro: qui non è coperto. I segnaposto `{{campo}}`, `k-array` e `k-component` sono risolti da Kris sul server, prima che la pagina arrivi al browser: il sistema visivo riguarda l'HTML già reso.

## Overview

**Creative North Star: "Il banco di prova"**

Il sito mostra il prodotto vero al lavoro, nello standard della categoria eseguito con finitura alta: un titolo forte, la schermata reale dell'editor, le funzioni elencate con precisione e il codice vero del tema. Il mondo è bianco puro e nero quasi assoluto; i grigi esistono solo per il testo secondario e per i filetti da 1 px. Niente colore d'accento: la gerarchia la fanno peso, dimensione e il contrasto pieno del nero.

La densità è quella di un indice, non di una vetrina: le funzioni sono righe in una tabella filtrabile, i passi sono righe numerate con il codice accanto, le domande sono `details` divisi da filetti. Le superfici si separano con linee, non con riquadri; l'unico oggetto "sollevato" è la cornice che contiene le schermate dell'editor. Il movimento è uno solo: la schermata dell'apertura entra una volta, partendo già visibile.

**Key Characteristics:**
- Bianco `#ffffff` e nero `#0a0a0a`, grigi solo per testo secondario e filetti.
- Geist per tutto, tracking negativo sui titoli; Geist Mono solo per codice, nomi di file, pillola della versione, date e selettore della lingua.
- Pulsanti a pillola: primario nero pieno, secondario a filetto.
- Schermate vere dell'editor in una cornice sottile con barra e ombra morbida.
- Codice su nero con i segnaposto di Kris evidenziati in bianco.
- Griglia 5fr/7fr ripetuta in apertura, intestazioni di sezione, passi e domande.

## Colors

Monocromo: un nero, un bianco e una scala di grigi con compiti stretti.

### Primary
- **Nero inchiostro** (fg): testo principale, pulsante primario, filetto superiore degli indici (funzioni, novità, domande), banda di chiusura, stato premuto del filtro e della lingua, anello di focus, selezione del testo.

### Neutral
- **Bianco carta** (bg): fondo di ogni pagina, testo sul nero, pulsante sulla banda di chiusura.
- **Grafite** (fg-2): testo secondario lungo (sottotitoli, sintesi, risposte, `prose`).
- **Grigio nota** (muted): metadati brevi: categorie, date, numeri dei passi, requisiti, intestazioni del piè di pagina.
- **Filetto** (line): divisori tra righe, bordo dell'intestazione, bordo superiore delle sezioni.
- **Filetto marcato** (line-2): bordo dei pulsanti secondari, del filtro, dei tag, della cornice e del banner; anche il grande «404» decorativo.
- **Velo** (soft): fondo al passaggio del mouse sulle righe e sui link del menu, fondo del codice in linea.
- **Codice** (code-bg, code-line, code-fg, code-hi): fondo nero dei blocchi di codice, filetto della loro barra, testo grigio e segnaposto in bianco.

### Named Rules
**La Regola del Bianco e Nero.** Nessun colore di tinta. Un grigio nuovo si giustifica solo come testo secondario o filetto; ogni altro ruolo usa il nero o il bianco.

**La Regola del Filetto.** Le aree si separano con linee da 1 px (`line` tra le righe, `fg` in cima a un indice), non con fondi colorati o riquadri.

## Typography

**Display Font:** Geist (con ui-sans-serif, system-ui)
**Body Font:** Geist
**Label/Mono Font:** Geist Mono (con ui-monospace, SFMono-Regular, Menlo)

**Character:** un solo sans geometrico e asciutto, tenuto stretto nei titoli (tracking da -0.02em a -0.04em) e neutro nel testo; il mono entra solo dove il contenuto è davvero codice o un dato tecnico. Entrambi i font sono variabili (100–900), ospitati in `assets/fonts/` con licenza OFL e `font-display: swap`; Geist è precaricato da `kris_head.html`.

### Hierarchy
- **Display** (600, clamp fino a 70px, 1.02): solo il titolo dell'apertura, su tre righe.
- **Headline** (600, clamp fino a 46px, 1.05): titoli di sezione; la banda di chiusura sale fino a 64px con -0.04em e `max-width: 14ch`.
- **Headline di pagina** (600, clamp fino a 56px, 1.04): `h1` delle pagine di funzione, articolo e informative.
- **Title** (600, 22px, 1.25): titolo dei passi. Le righe degli indici usano un titolo più piccolo a peso 500 (17px per funzioni e domande, 19px per le novità).
- **Lede** (400, clamp 17–20px, 1.55, colore grafite): sottotitolo dell'apertura e delle pagine interne, `max-width: 34rem`.
- **Body** (400, 16px, 1.6, `ss01` e `cv11` attivi); la `prose` dei campi richtext sale a 17px con interlinea 1.7, in grafite, con `max-width` 40–44rem.
- **Label** (400–500, 14px): navigazione, filtro, categorie, metadati.
- **Mono** (13px, 1.75 nel codice): blocchi di codice, barra del nome file, URL della cornice, pillola della versione, date, lingua, intestazioni e nomi della tabella dei cookie.

### Named Rules
**La Regola del Mono Onesto.** Geist Mono solo per codice, nomi di file, versione, date e sigle della lingua. Mai per etichette decorative o titoletti sopra i titoli.

**La Regola del Tracking.** Più grande è il titolo, più è stretto: -0.034em / -0.035em / -0.04em sui titoli grandi, -0.01em / -0.02em sui piccoli, 0 sul testo.

## Layout

Contenitore di 1200px più due margini laterali `clamp(16px, 4vw, 40px)`. La griglia di riferimento è **5fr / 7fr** con spazio `clamp(40px, 5vw, 72px)`: apertura (testo a sinistra, schermata a destra che esce del 18% oltre il margine), intestazioni di sezione (titolo a sinistra, testo allineato in basso a destra), passi (testo numerato a sinistra, codice o schermata a destra), domande (titolo a sinistra, elenco a destra). Le pagine di funzione con codice usano 6fr / 5fr con il codice fisso in alto (`sticky` sotto l'intestazione).

Le righe degli indici hanno colonne fisse: categoria 12rem, nome 4fr, sintesi 5fr, freccia 16px (novità: data 12rem, titolo, estratto). Le righe sporgono di 12px a sinistra e a destra, così il fondo al passaggio del mouse non sposta il testo.

Ritmo verticale: sezioni `clamp(64px, 9vw, 128px)` separate da un filetto; passi 40px; righe 20px (novità 28px); banda di chiusura `clamp(72px, 10vw, 144px)`. Intestazione fissa alta 64px; `scroll-padding-top` ne tiene conto.

Punti di rottura: 960px (apertura e codice delle funzioni su una colonna), 860px (menu a scomparsa, righe degli indici impilate: nome, sintesi, categoria), 760px (intestazioni di sezione, passi, domande e piè di pagina su una colonna), 600px (banner dei cookie a tutta larghezza).

## Elevation & Depth

Il sistema è piatto: profondità data da filetti e dal contrasto nero/bianco. Le ombre sono tre, legate a oggetti che stanno davvero sopra la pagina.

### Shadow Vocabulary
- **Cornice** (`box-shadow: 0 1px 2px rgb(0 0 0 / 0.04), 0 12px 32px -8px rgb(0 0 0 / 0.12), 0 40px 80px -24px rgb(0 0 0 / 0.14)`): solo la cornice delle schermate dell'editor.
- **Banner** (`box-shadow: 0 24px 64px -16px rgb(0 0 0 / 0.22)`): il banner dei cookie, che galleggia sul contenuto.
- **Menu mobile** (`box-shadow: 0 24px 40px -24px rgb(0 0 0 / 0.18)`): il pannello del menu aperto sotto i 860px.

L'intestazione è bianca all'86% con `backdrop-filter: saturate(1.6) blur(12px)` e un filetto in basso.

### Named Rules
**La Regola della Cornice Unica.** Ombra morbida solo per ciò che sta sopra la pagina (schermate, banner, menu aperto). Contenuti, righe e codice restano piatti.

## Shapes

Tre forme: la pillola (999px) per tutto ciò che si preme (pulsanti, filtro, lingua, tag, versione, link del menu); l'angolo da 10px per i blocchi di codice e l'immagine della 404; l'angolo da 14px per la cornice e il banner. Le righe degli indici hanno angoli quasi vivi (2px). Il marchio è un quadrato da 24px con angoli da 6px e una K tracciata in bianco. Le icone sono SVG in linea, 16px (14px nei pulsanti), tratto 1.5, estremi arrotondati, colore del testo.

## Components

### Buttons
- **Shape:** pillola (999px), altezza minima 44px, 15px peso 500.
- **Primary:** nero pieno su bianco; al passaggio `#2b2b2b`.
- **Secondary:** bianco con filetto marcato; al passaggio il filetto diventa nero.
- **Sulla banda nera:** `inverse` bianco pieno (passaggio `#e6e6e6`) e `ghost` trasparente con filetto `#3a3a3a` che diventa bianco.
- **Small:** 36px, 14px, per l'intestazione e il banner. **Link:** testo grafite sottolineato con filetto, per le azioni di terzo livello del banner.
- **Stati:** premuto `scale(0.98)`; focus con anello nero da 2px a 3px di distanza (bianco sulla banda di chiusura). Una freccia diagonale SVG segnala i link esterni.

### Chips
- **Filtro delle funzioni:** pillole da 36px a filetto, grafite; premuto nero pieno (`aria-pressed`). Il filtro resta nascosto senza funzioni e mostra solo le categorie che ne hanno almeno una.
- **Tag** «Nuovo nella 2.0»: pillola da 20px a filetto, 12px, accanto al nome.

### Navigation
Intestazione fissa da 64px: marchio, «Kris», pillola mono della versione; link a pillola grafite con fondo velo al passaggio; selettore IT/EN in mono dentro una pillola a filetto, lingua attiva in nero pieno; pulsante Scarica piccolo. Sotto i 860px un pulsante a due righe (che ruota di 90° aperto) apre un pannello a righe divise da filetti; Esc lo chiude e riporta il focus.

### Cornice delle schermate (componente firma)
Contenitore con filetto marcato, angoli da 14px e ombra della cornice; barra alta 36px su `#fbfbfb` con tre punti grigi da 9px e l'URL `/kris-admin/` in mono. Contiene solo catture vere dell'editor. Usata in apertura, nei passi, nelle pagine di funzione e come copertina degli articoli.

### Codice su nero (componente firma)
Blocco nero con angoli da 10px, barra con il nome del file in mono grigio e filetto scuro, codice in Geist Mono 13px/1.75 grigio. `site.js` mette in bianco i segnaposto `{{…}}`, gli attributi `k-*` e `data-kris-*`: si leggono come la parte che conta del tema. Il testo resta quello del campo, scappato.

### Indice delle funzioni (componente firma)
Elenco aperto da un filetto nero, una riga per funzione: categoria in grigio nota, nome a 17px peso 500 con eventuale tag, sintesi in grafite, freccia che al passaggio scorre di 3px e diventa nera. Fondo velo al passaggio. La variante compatta (pagina di funzione, «Altre funzioni») toglie la sintesi. Le novità usano la stessa riga con data in mono.

### Passi
Righe numerate con contatore CSS `01`, `02`, `03` in mono grigio, titolo 22px e testo; a destra il codice su nero o una cornice.

### Domande
`details` divisi da filetti sotto un filetto nero; domanda a 17px peso 500, chevron SVG che ruota di 180° aperto; risposta in `prose` grafite.

### Banda di chiusura
Fondo nero a tutta larghezza, titolo bianco fino a 64px su `14ch`, testo `#a6a6a6`, pulsanti `inverse` e `ghost`; selezione e focus invertiti.

### Banner dei cookie
Pannello bianco fisso in basso a destra, 420px, angoli da 14px, filetto marcato e ombra del banner; entra con 12px di salita in 360ms. Secondo livello con caselle per categoria (`accent-color` nero, mai spuntate), Rifiuta e Accetta della stessa dimensione, «Personalizza» come link. Sotto i 600px occupa la larghezza con 8px di margine e i due pulsanti si dividono la riga.

### Pagine di stato
404 e lavori in corso: numero grande in filetto marcato come segno decorativo, titolo, testo, azioni.

### Movimento
Un solo ingresso: la schermata dell'apertura sale di 28px da `scale(0.985)` in 1100ms con `cubic-bezier(0.22, 0.8, 0.24, 1)`, senza opacità di partenza (è visibile dal primo frame). Il resto sono transizioni di stato da 150–200ms. Con `prefers-reduced-motion` animazioni e scorrimento morbido si spengono.

### Immagini e provenienza
Le immagini del tema sono contenuti caricati dal CMS in `assets/uploads/`, non grafica inventata:
- `kris-editor-home.png`, `kris-editor-modifica.png`, `kris-editor-da-pubblicare.png`, `kris-editor-plugin.png`: catture vere dell'editor di Kris, Chrome headless, 1280×800 a 2×, su una copia locale di questo sito il 2026-11-20.
- `kris-editor-campo.png`: ritaglio della cattura del modulo di modifica della homepage, stessa sessione.
- `kris-favicon.png`: resa del marchio SVG del tema.
- Le varianti `-480w`, `-960w`, `-1600w.webp` le genera Kris al caricamento.

## Do's and Don'ts

### Do:
- **Do** usare solo i token di `:root` per colore, raggio e larghezze; nero `#0a0a0a` per testo e azioni, grigi solo per testo secondario e filetti da 1 px.
- **Do** presentare funzioni, novità e domande come righe di un indice sotto un filetto nero, con fondo velo al passaggio.
- **Do** mostrare il prodotto con catture vere dell'editor dentro la cornice, e dichiararne la provenienza.
- **Do** scrivere il codice mostrato su nero, con i segnaposto di Kris evidenziati, copiato dal tema vero.
- **Do** usare la griglia 5fr/7fr e il contenitore da 1200px con il margine `clamp(16px, 4vw, 40px)`.
- **Do** disegnare le icone come SVG in linea, 16px, tratto 1.5, estremi arrotondati, `currentColor`.
- **Do** far partire ogni animazione da uno stato già visibile e spegnerla con `prefers-reduced-motion`.

### Don't:
- **Don't** usare card tutte uguali icona-titolo-testo per le funzioni o per qualunque elenco.
- **Don't** mettere titoletti, sopratitoli o etichette in maiuscoletto sopra i titoli: il titolo parla da solo.
- **Don't** usare icone a glifo (emoji, caratteri, font di icone) al posto degli SVG.
- **Don't** introdurre un colore d'accento o un grigio come fondo di sezione; l'unica superficie scura è la banda di chiusura (e il codice).
- **Don't** usare Geist Mono per testo che non sia codice, nomi di file, versione, date o sigle della lingua.
- **Don't** dare ombra a righe, codice o testi: l'ombra spetta solo a cornice, banner e menu aperto.
- **Don't** usare schermate finte, numeri, clienti o testimonianze inventati.
