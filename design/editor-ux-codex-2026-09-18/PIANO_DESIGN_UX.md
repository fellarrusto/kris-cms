# Kris — Piano di design e UX dell’editor

18 settembre 2026 · Proposta complementare al [piano editor di Claude](../../PIANO_editor_claude.md) e al [piano architettura](../../PIANO_architettura_claude.md).

**Obiettivo:** rendere l’editor chiaro per chi aggiorna un sito, con un percorso riconoscibile per ogni azione e senza perdere lavoro quando qualcosa va storto.

**Consegna:** questo piano e un [prototipo HTML interattivo](index.html). Il prototipo usa dati dimostrativi, funziona senza installazioni o librerie esterne e non comunica con il CMS. I suoi salvataggi sono simulati in memoria: ricaricare la pagina ripristina i dati iniziali.

## 1. Direzione di prodotto

L’editor dovrebbe rispondere sempre a quattro domande:

1. Dove mi trovo e quale contenuto sto modificando?
2. Qual è la prossima azione utile?
3. Cosa è già stato salvato e cosa no?
4. Se qualcosa non funziona, come recupero senza riscrivere tutto?

La proposta è un **piccolo studio editoriale digitale**: fondo chiaro, testo scuro, verde profondo per l’azione principale, pannelli puliti e gerarchia leggibile. La personalità viene da tipografia, spaziatura e anteprime, non da una dashboard piena di numeri decorativi.

L’interfaccia cambia in risposta al lavoro: mostra l’anteprima mentre scrivi, aggiorna la completezza delle traduzioni, esplicita le modifiche pendenti, accompagna operazioni lente e lascia recuperare dagli errori. Le animazioni servono a mantenere il contesto; non sono il principale elemento di interattività.

### Decisioni di base

| Tema | Scelta proposta | Motivazione |
|---|---|---|
| Pagina iniziale | Contenuti organizzati per raccolta | Il compito più comune è aggiornare qualcosa, non osservare statistiche |
| Struttura e impostazioni | Area di configurazione distinta | Chi scrive non deve attraversare strumenti di modellazione |
| Salvataggio | Esplicito, con barra persistente | Il CMS rende subito visibili le modifiche: il controllo deve restare all’utente |
| Terminologia | Italiano uniforme: contenuto, raccolta, campo, servizio | Evitare alternanza di “instance”, “entity”, “Back”, “Edit” e termini tecnici |
| Azioni distruttive | Contestuali e separate da quelle primarie | Una croce accanto a ogni riga è troppo facile da confondere con una chiusura |
| Feedback | Stato vicino all’azione; banner per problemi persistenti | Un messaggio generico in cima non basta a capire che cosa è successo |
| Elementi annidati | Contesto del padre sempre visibile | Evitare di trasformare un percorso di dati in un labirinto di pagine |
| Funzioni future | Mostrate solo quando supportate | Niente “Pubblica”, cestino, cronologia o autosave fittizi |

**Nel CMS attuale “Salva” modifica il sito.** Il design non introduce etichette “bozza/pubblicato” finché non esiste un vero workflow con versioni separate.

## 2. Architettura dell’interfaccia

### Navigazione

```text
Nome del sito / ambiente
  Contenuti
    Raccolta
      Elenco dei contenuti
        Modifica contenuto
          Elemento annidato
  Libreria media

Configurazione
  Struttura
    Raccolte e modelli
      Campi / sotto-campi / impatto delle modifiche
  Impostazioni
    Lingue di modifica
    Accesso

Account
  Gestione accesso / Esci
```

Il nome del sito è sempre visibile. Su desktop la navigazione resta laterale; su telefono diventa compatta, senza obbligare a scorrere una sidebar prima di raggiungere il contenuto. Nel prodotto con molte raccolte, una ricerca filtra la lista: non vanno aggiunte decine di voci alla navigazione globale.

### Anatomia delle schermate

- **Topbar:** breadcrumb e collegamento al sito salvato. Un’anteprima delle modifiche richiede un’azione distinta.
- **Intestazione:** nome comprensibile, breve descrizione, una sola azione primaria locale quando serve.
- **Area centrale:** gruppi di campi e liste; i nomi tecnici sono secondari e disponibili nella configurazione.
- **Colonna di contesto:** anteprima indicativa, traduzioni e informazioni utili. Scompare su schermi stretti, senza nascondere azioni essenziali.
- **Barra di salvataggio:** stato del lavoro, annullamento delle modifiche e salvataggio. Non copre gli ultimi campi o la tastiera su mobile.

Le schermate di elenco non devono sembrare form. Le schermate di modifica non devono sembrare dashboard. Le impostazioni non devono sembrare una libreria di contenuti.

## 3. Flussi UI completi

### U01 — Accesso e sessione

**Ingresso:** login semplice con etichette persistenti, possibilità di mostrare la password e compatibilità con password manager. Durante l’accesso il pulsante indica “Accesso in corso…” e non accetta un secondo invio.

**Errore:** messaggio vicino al form, mantenendo lo username; distinguere problema di rete da credenziali non valide senza esporre informazioni sull’esistenza di account. Un blocco temporaneo mostra quando si può riprovare, usando il valore reale restituito dal backend.

**Sessione scaduta durante il lavoro:** conservare il form aperto, mostrare “La sessione è scaduta. Il tuo lavoro è ancora qui”, consentire una nuova autenticazione in un contesto controllato e poi ricontrollare la versione dei dati prima di riprendere il salvataggio. Nessun redirect immediato che cancelli il contenuto del modulo.

Il prototipo simula la riconnessione; non implementa credenziali, timeout o autenticazione reale.

### U02 — Trovare e aprire un contenuto

L’elenco mostra **titolo/nome leggibile**, una piccola anteprima quando utile, stato delle traduzioni e azione “Modifica”. L’ID tecnico non è la prima colonna. Data di ultima modifica e autore compaiono soltanto quando i metadati vengono realmente registrati.

Ricerca e filtri devono mantenersi tornando dalla modifica. Il clic sul titolo apre il contenuto; le azioni secondarie restano pulsanti distinti, senza righe cliccabili contenenti azioni ambigue.

Tre stati vuoti diversi:

| Condizione | Testo | Azione |
|---|---|---|
| Raccolta senza modello | “Prima definiamo quali informazioni raccogliere.” | “Configura i campi” nell’area Struttura |
| Modello presente, nessun contenuto | “Il primo contenuto, quando vuoi.” | “Crea contenuto” |
| Ricerca senza risultati | “Nessun risultato per questa ricerca.” | “Rimuovi i filtri” |

Un errore di lettura dell’archivio **non deve diventare uno stato vuoto** con un pulsante che rischia di sovrascrivere i dati.

### U03 — Creare un contenuto

**Scegli raccolta → dai un nome → compila → salva → resta nella modifica.** Il titolo della schermata cambia da “Nuovo contenuto” al suo nome; il messaggio conferma la creazione e offre “Torna ai contenuti”.

Aprire il modulo non dovrebbe creare subito un record pubblico vuoto. Questa è una modifica rispetto a `create_instance` attuale e richiede un endpoint che assegni l’ID alla prima scrittura riuscita. Finché non esiste, mantenere il comportamento reale e comunicarlo; non fingere che il record sia una bozza privata.

Validazione vicino ai campi, riepilogo degli errori all’inizio e focus sul primo errore. I valori corretti restano compilati. Doppio clic sul salvataggio non deve duplicare il contenuto.

### U04 — Modificare, tradurre e salvare

Usare etichette umane derivate da metadati espliciti, testo di aiuto breve e gruppi visivi. Una descrizione lunga non va usata automaticamente come titolo: `label` e `description` hanno ruoli distinti. Se manca un’etichetta, il nome tecnico viene reso leggibile senza cambiarne la chiave.

Il selettore della lingua è a livello di sezione o modulo, non ripetuto in decine di schede. Passare da IT a EN mantiene entrambi i valori in memoria. Se una lingua ha campi mancanti, mostrare “1/3 testi compilati”, non una falsa percentuale di qualità.

Campi richtext: barra essenziale, comandi avanzati raccolti, inserimento media con lo stesso picker del resto dell’editor. I campi `plain` non localizzati non devono duplicarsi cambiando lingua. I campi immagine devono rispettare lo schema reale: se localizzati, cambiano con la lingua; la demo usa una copertina condivisa soltanto per semplificare il percorso.

**Salvataggio:** stato immediato “Salvataggio in corso…”, pulsante occupato e prevenzione del doppio invio. Successo solo dopo conferma del server; restare sulla pagina, nella stessa lingua e posizione. Errore: testi invariati, spiegazione utile e “Riprova”.

Nel primo rilascio il form può essere momentaneamente non modificabile durante la richiesta breve; deve però rimanere leggibile e mostrare lo stato. Se la rete si interrompe dopo l’invio, non affermare “non salvato” con certezza: verificare versione/esito oppure usare “Non possiamo confermare il salvataggio”.

### U05 — Uscire con modifiche pendenti

La protezione vale per sidebar, breadcrumb, cambio contenuto, logout e navigazione indietro interna. Dialog con tre azioni:

- **Resta qui:** chiude il dialog e restituisce il focus.
- **Esci senza salvare:** scarta soltanto le modifiche del contesto e procede alla destinazione richiesta.
- **Salva e continua:** procede solo dopo successo; un errore mantiene l’utente sul form.

Per ricarica/chiusura della scheda si usa la protezione nativa del browser, nei limiti consentiti. Non tentare di sostituirla con un dialog personalizzato che il browser non garantisce. Non usare questo avviso a ogni cambio di lingua o apertura di una finestra media.

### U06 — Elementi annidati

Le righe mostrano un titolo, un riepilogo, la posizione e “Modifica”; niente percorsi `features/0/highlights/2` come titolo della UI. Il breadcrumb mostra invece “Homepage › Servizi › Design e sviluppo”.

**Direzione finale:** un pannello laterale modifica il figlio mantenendo visibile il padre. “Applica alla pagina” trasferisce le modifiche nel form del padre; “Salva modifiche” salva insieme contenuto, figli e ordine. Rimuovere un figlio resta annullabile prima del salvataggio. Questa unità di lavoro deve corrispondere a una scrittura atomica della radice e a una sola verifica di versione.

**Non è il contratto attuale:** oggi creazione/cancellazione nested sono richieste indipendenti e il salvataggio del padre preserva i figli memorizzati. La UI finale richiede un cambiamento esplicito del backend, con validazione ricorsiva del documento e gestione degli ID.

**Passaggio intermedio compatibile:** prima di aprire una modifica nested con il padre modificato, offrire “Salva e apri”, “Apri senza salvare” e “Resta qui”. Il figlio ha il proprio salvataggio e un ritorno esplicito al padre. Non mostrare “Applica alla pagina” finché l’operazione viene salvata separatamente.

Per profondità superiori a uno, evitare pannelli e modali impilati: usare una pagina focalizzata con breadcrumb. Tornando indietro, ripristinare lingua, espansioni e posizione.

### U07 — Riordinare

Il trascinamento può essere una scorciatoia desktop, ma servono sempre **Sposta in alto / Sposta in basso** utilizzabili con tastiera e touch. La nuova posizione viene annunciata e il focus resta sull’elemento spostato.

Nella direzione finale l’ordine è una modifica del documento, salvata con la pagina. Nel passaggio intermedio, se il backend salva l’ordine immediatamente, il form sporco va protetto prima di inviare l’operazione. Mai azzerare il dirty flag per nascondere l’avviso.

Su errore del riordino immediato, ripristinare l’ordine confermato e offrire “Riprova”; su riordino preparato, mantenere la proposta locale. Il server deve verificare ID, duplicati e versione: l’animazione non sostituisce la correttezza.

### U08 — Scegliere e caricare media

**Apri picker → cerca o carica → seleziona → conferma “Usa questa immagine” → ritorna al campo.** Un clic sulla miniatura non modifica subito il contenuto. “Annulla”, Escape e chiusura non cambiano la selezione originale.

Upload con stato per file: in attesa, caricamento, elaborazione, pronto o errore. Dimensioni e formati ammessi vengono dal servizio backend e sono mostrati prima della scelta. Una percentuale compare solo se misurata: durante l’elaborazione si usa uno stato indeterminato. La progressione della demo è esplicitamente simulata.

Se un file di un gruppo fallisce, gli altri riusciti restano disponibili. “Riprova” agisce solo sui falliti. Chiudere durante una richiesta richiede una scelta esplicita; non promettere di annullare un upload già concluso sul server. La libreria conserva il file caricato anche se poi non viene selezionato nel contenuto: va comunicato quando rilevante.

Metadati e testo alternativo devono rispettare il modello dati. Un’immagine decorativa può non avere testo alternativo; un campo obbligatorio non va introdotto soltanto nella UI senza supporto dello schema.

### U09 — Eliminazioni

| Oggetto | Interazione proposta | Condizione |
|---|---|---|
| Figlio preparato nel documento | Rimuovi + “Annulla” locale | Diventa definitivo soltanto salvando il padre |
| Contenuto già salvato | Dialog con nome, impatto e “Elimina contenuto” | Richiesta server verificata; nessun falso ripristino |
| Media | Mostrare riferimenti; se in uso, offrire prima sostituzione/rimozione riferimenti | La ricerca deve essere completa oppure dichiarata incompleta |
| Raccolta | Area avanzata, riepilogo di modello/contenuti coinvolti | Prima decidere se si archivia o si cancella davvero |
| Campo con dati | Anteprima della modifica, migrazione/backup reali, conferma esplicita | Dipendenza dal piano schema |

Nei dialog distruttivi il focus iniziale va sull’azione sicura. Il pulsante rosso nomina l’oggetto dell’azione. La digitazione del nome è riservata a operazioni ampie e irreversibili, non a ogni cancellazione.

Un toast “Annulla” dopo una cancellazione server richiede un vero cestino o una reale operazione inversa. Il backup tecnico non equivale automaticamente a ripristino self-service.

### U10 — Struttura e modello

Area distinta dall’editing quotidiano. Lista dei campi con etichetta, tipo e chiave tecnica secondaria. Proprietà del campo in un pannello dedicato: etichetta, aiuto, tipo, localizzazione e validazioni effettivamente supportate.

Distinguere modifiche innocue e modifiche ai dati. Cambiare un’etichetta non deve rinominare la chiave. Cambiare tipo, rimuovere o rinominare una chiave richiede una schermata di impatto: quali contenuti, quali trasformazioni, eventuale backup e possibilità di tornare indietro.

Prima di applicare, riepilogo concreto: “Etichetta del campo titolo aggiornata”, “Nuovo campo immagine aggiunto”, “Campo sottotitolo rimosso da N contenuti”. Il numero N deve arrivare dall’analisi reale, non essere un placeholder.

### U11 — Lingue e impostazioni

“Lingue di modifica” e “Lingue visibili sul sito” sono concetti distinti. Disattivare una lingua nell’editor mantiene le traduzioni. Una lingua principale deve restare selezionata; il cambio della principale richiede una politica di fallback dichiarata.

Il form mantiene le impostazioni precedenti finché il server non conferma. Non usare dialog per ogni checkbox. Eventuali effetti sul sito vengono riepilogati prima dell’applicazione.

### U12 — Conflitti, assenza rete e contenuto non disponibile

**Conflitto di versione:** confronto tra lavoro locale e versione server. Nessuna sovrascrittura automatica. Consentire copia/esportazione del lavoro, ritorno alla modifica e caricamento della versione aggiornata con conferma sullo scarto locale. La fusione campo per campo è una funzione successiva, non un requisito da improvvisare nella prima implementazione.

**Contenuto eliminato da un’altra sessione:** “Questo contenuto non è più disponibile”. Consentire di recuperare i testi; non ricreare automaticamente l’entità col vecchio ID.

**Offline:** messaggio persistente vicino al salvataggio. Non aggiungere una coda silenziosa che pubblica modifiche al ritorno della rete. Un retry deve verificare versione ed esito precedente.

## 4. Un sistema coerente di stati e messaggi

```text
Caricamento -> Pronto -> Modificato -> Salvataggio -> Salvato
                 ^          |              |
                 |          |              +-> Errore recuperabile -> Riprova
                 |          |              +-> Conflitto -> Confronto
                 |          |              +-> Sessione scaduta -> Accesso -> Verifica versione
                 |          |
                 +----------+-> Annulla modifiche
```

Lo stato “Salvato” si raggiunge soltanto dopo conferma del backend. I fallimenti conservano il lavoro locale. Un cambio di lingua non cambia l’identità del documento.

| Situazione | Componente | Esempio di messaggio | Durata/azione |
|---|---|---|---|
| Campo invalido | Errore sotto al campo + riepilogo | “Inserisci un titolo.” | Fino alla correzione |
| Salvataggio riuscito | Stato nella barra + toast breve | “Modifiche salvate.” | Stato persistente, toast temporaneo |
| Errore confermato prima della scrittura | Banner | “Non siamo riusciti a salvare. I tuoi testi sono ancora qui.” | “Riprova” |
| Esito di scrittura incerto | Banner | “Non possiamo confermare il salvataggio.” | Verifica versione/esito, poi retry |
| Conflitto | Banner + confronto | “Il contenuto è cambiato da quando l’hai aperto.” | “Confronta le versioni” |
| Eliminazione irreversibile | Dialog | “Eliminare «Nome contenuto»?” | Annulla / Elimina contenuto |
| Modifica locale reversibile | Toast con azione | “Servizio rimosso dalla pagina.” | Annulla, valido prima del salvataggio |
| Upload fallito | Errore sulla riga del file | “Il file supera il limite di 5 MB.” | Valore reale del limite; sostituisci file |
| Lettura dati fallita | Stato pagina di errore | “I contenuti non sono disponibili.” | Riprova; nessun archivio vuoto sostitutivo |

**Niente `alert()`, `confirm()` o `prompt()` per i normali flussi del prodotto.** L’eccezione è la protezione nativa sull’uscita dalla scheda. I toast non devono contenere l’unica copia di un errore importante e non devono sparire prima di un’azione necessaria.

## 5. Design visivo, accessibilità e responsive

### Fondamenta

| Elemento | Proposta |
|---|---|
| Fondo | `#F6F7F4`, chiaro con tono leggermente caldo |
| Superfici | Bianco, bordi `#DFE5DF`, ombre contenute |
| Testo | `#202B27`, secondario `#63716A` |
| Azione principale | Verde `#166C51`, hover `#0C4F3A` |
| Errore | `#A42E36` con fondo chiaro e testo esplicito |
| Attenzione | `#815813` su fondo ambrato chiaro |
| Tipografia UI | Font di sistema, nessuna dipendenza da CDN; titoli compatti e gerarchia netta |
| Form di produzione | Testo 16 px sui dispositivi piccoli; evitare zoom automatico e aiuti illeggibili |
| Spaziatura | Multipli di 4/8; separazione più forte tra sezioni che tra label e campo |
| Angoli | 8–12 px per controlli e pannelli, senza trasformare ogni elemento in una pillola |

La demo usa una densità desktop più compatta per mostrare il sistema. In implementazione, il testo di aiuto importante va mantenuto leggibile e non reso minuscolo per far stare tutto nello schermo.

### Comportamento

- Controlli raggiungibili da tastiera, focus visibile e ritorno del focus all’elemento che ha aperto un pannello.
- Dialog con titolo accessibile, focus contenuto, Escape dove sicuro e azione di chiusura nominata. Nessuna finestra dentro un’altra finestra.
- Icone accompagnate da testo o nome accessibile; colore mai unico indicatore di errore, stato o selezione.
- Messaggi di stato annunciati senza spostare inutilmente il focus; errori bloccanti raggiungibili dal riepilogo.
- Su mobile, input e pulsanti comodi da toccare: target di progetto circa 44 px per i controlli frequenti. Niente azioni essenziali visibili solo in hover.
- Liste adattate prima di ricorrere allo scroll orizzontale; pulsanti di riordino disponibili anche senza drag.
- Una sola area di scorrimento principale; pannelli laterali a tutto schermo sul telefono. Collaudo anche con tastiera virtuale e zoom del testo.
- Transizioni brevi per apertura pannelli e feedback, senza spostare campi mentre si scrive. Rispetto di `prefers-reduced-motion`.

Il prototipo non costituisce una certificazione di accessibilità. Contrasto, lettore di schermo, focus, zoom e tastiera vanno verificati sul prodotto integrato, incluso TinyMCE.

## 6. Integrazione con il piano di Claude

Il piano tecnico e quello di design hanno responsabilità diverse. **E1 resta uno split meccanico**, con verifiche di equivalenza: non vi mescolerei il redesign, altrimenti il confronto HTML smette di essere utile.

| Incremento UX | Contenuto | Prerequisiti tecnici | Criterio di chiusura |
|---|---|---|---|
| D0 — Sblocco | Form valido e salvataggio raggiungibile | E0 | Ogni form ha il proprio submit; flusso di salvataggio funzionante |
| D1 — Fondamenta | Layout, gerarchia, navigazione, lessico, controlli responsive | E1 | Tutte le viste esistono e le azioni restano raggiungibili anche da tastiera |
| D2 — Editing affidabile | Barra di stato, validazione, messaggi onesti, protezione uscita | E2.1–E2.4, E2.7, JsonStore | Un errore non perde campi; successo solo dopo conferma |
| D3 — Media e accesso | Picker, upload per file, sessione scaduta, eliminazioni | E3, A2, controllo riferimenti | Esiti parziali e sessioni scadute sono recuperabili |
| D4 — Conflitti | Versione del documento, confronto, conservazione del lavoro | A1.5 + E4.1 | Due sessioni non si sovrascrivono silenziosamente |
| D5 — Nested unificato | Figli e ordine preparati nel padre, scrittura atomica | Nuovo contratto di documento + E2.5/E2.8 | Un salvataggio applica tutto o niente; ID univoci |
| D6 — Struttura avanzata | Metadati, analisi impatto, migrazioni | A4 + E2.6 | Nessuna perdita implicita al cambio schema |

**Cambierei una priorità del piano B:** errori visibili, lavoro preservato e segnalazione delle lingue non sono soltanto rifiniture facoltative di E4. Sono parte dei requisiti per distribuire il nuovo editor. Alcuni dipendono dal backend, ma la loro UX va progettata ora.

Le decisioni su cancellazione/archiviazione, lingue pubblicate e migrazioni restano decisioni di prodotto. Il design propone conseguenze e messaggi; non le risolve nascondendo i dati o promettendo un ripristino inesistente.

## 7. Contratti da consegnare all’implementazione

Un handler non restituisce soltanto una stringa `$msg`: deve distinguere successo, campi invalidi, versione in conflitto, sessione scaduta, formato non ammesso e scrittura fallita. Le risposte espongono un codice stabile, dettagli sicuri per l’utente, eventuali errori per campo e la nuova versione del documento.

Esempio concettuale, non API già esistente:

```json
{
  "status": "validation_error",
  "message": "Controlla i campi indicati.",
  "fields": { "title.it": "Inserisci un titolo." }
}
```

Il client conserva il suo documento finché il server conferma. Il server resta l’autorità per validazione, permessi, versione e risultato. Un flag JavaScript non può garantire che una scrittura sia riuscita.

Metadati visuali come etichette, aiuti, gruppi, campi obbligatori e comportamento localizzato devono essere aggiunti in modo esplicito allo schema, con fallback per i modelli esistenti. Non codificarli soltanto nei template del pannello.

## 8. Verifica dei flussi

Checklist da usare come criteri di accettazione, con dati di test separati:

1. Modificare IT, passare a EN e tornare: IT resta intatto.
2. Salvare con doppio clic o Invio: una sola operazione, nessuna entità duplicata.
3. Simulare errore di scrittura: testo mantenuto, nessun messaggio positivo, retry disponibile.
4. Uscire con modifiche: tutte e tre le scelte funzionano; “Salva e continua” non naviga in caso di errore.
5. Modificare un figlio e chiuderlo: lavoro mantenuto o scartato solo con una scelta coerente.
6. Riordinare da tastiera: ordine persistito corretto, focus recuperabile, nessun ID duplicato.
7. Cambiare media e annullare: immagine originale invariata.
8. Caricare più file con un errore: risultati distinti, nessuna perdita dei file riusciti.
9. Eliminare un oggetto: nome e impatto corretti, azione annullabile solo quando esiste davvero il ripristino.
10. Disattivare e riattivare una lingua: traduzioni conservate.
11. Salvare due versioni concorrenti: nessuna sovrascrittura automatica e lavoro locale recuperabile.
12. Far scadere la sessione: modulo conservato, accesso e verifica versione prima del retry.
13. Aprire un archivio illeggibile: stato di errore, non “Nessun contenuto”.
14. Usare telefono, zoom e sola tastiera: nessuna azione essenziale irraggiungibile o coperta.
15. Rimuovere un campo valorizzato: impatto e backup verificati, nessuna cancellazione implicita.

## 9. Cosa mostra il prototipo e cosa resta da implementare

**Interattivo nella demo:** elenco e ricerca, creazione locale, campi IT/EN, anteprima indicativa, pannello figli, riordino con pulsanti, picker, scelta di file locali, validazione, guardia di uscita, annullamento, salvataggio simulato, errore/retry, conflitto e sessione scaduta, impostazioni lingue e configurazione basilare del modello.

**Solo specificato:** login reale, permessi, API, persistenza, CSRF, ricerca completa degli usi dei media, cestino, migrazioni, anteprima fedele dei template, localizzazione dei figli e dell’immagine, gestione di annidamenti profondi, rete reale e stati ambigui delle scritture. Il builder dimostrativo non genera dinamicamente nuovi controlli nell’editor.

Le immagini della demo sono illustrazioni CSS locali. Il sito “Studio Forma” e i suoi contenuti sono esempi visivi. Nessun nome, conteggio o stato della demo va trasferito nel prodotto come dato reale.

**Per provarlo:** apri `index.html`, cambia un titolo, modifica un servizio e tenta di cambiare sezione. Nei controlli “Prova gli stati dell’interfaccia” scegli errore, conflitto o sessione scaduta, poi salva. Le scelte influenzano solo la simulazione.
