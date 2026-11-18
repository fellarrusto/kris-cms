# Editor di Kris — specifiche funzionali per il design

Questo documento descrive cosa deve fare l'editor di Kris: schermate, contenuti, azioni, stati e componenti. Non dà indicazioni sull'aspetto: identità visiva, tipografia, colori, densità e composizione sono da progettare.

## 1. Il prodotto

Kris è un CMS per siti vetrina. Uno sviluppatore costruisce il sito in HTML e CSS; il proprietario del sito ne modifica testi, immagini ed elenchi da un pannello raggiungibile all'indirizzo `/editor/` del sito stesso. Quel pannello è l'editor, oggetto di questo documento.

Un sito ha un solo account per l'editor. Non ci sono ruoli né elenchi di utenti.

### Chi lo usa

| Persona | Cosa fa | In che situazione |
| --- | --- | --- |
| **Cliente**: titolare di un bar, uno studio, un negozio | Cambia un testo o una foto, aggiunge un articolo, sospende una voce del menu | Di rado e di fretta, fra un'attività e l'altra. Non è un tecnico e teme di rompere il sito |
| **Sviluppatore o agenzia** | Definisce la struttura dei contenuti e le pagine, installa plugin, aggiorna Kris | Con regolarità, su più siti. Vuole precisione e controllo |
| **Assistente AI** | Prepara modifiche al sito | Non usa l'interfaccia. Le sue modifiche restano in attesa e una persona le rivede nell'editor prima di pubblicarle |

Cliente e sviluppatore entrano con le stesse credenziali e vedono le stesse voci. Il cliente è l'utente principale: le funzioni da sviluppatore devono esserci senza appesantire il lavoro sui contenuti.

### Cosa deve riuscire

1. Il cliente trova il contenuto da cambiare, lo modifica, salva e capisce che il sito è cambiato.
2. In ogni momento è chiaro se una modifica va subito sul sito pubblico o resta in attesa.
3. Chi rivede le modifiche in attesa capisce cosa cambierà sul sito e quali rischi ci sono, prima di pubblicare.
4. Nessuna azione distruttiva avviene senza che la persona sappia cosa perde e se può tornare indietro.

### Dispositivi

Computer e telefono hanno lo stesso peso. Modificare un testo, caricare una foto dalla fotocamera, aggiungere un articolo e pubblicare le modifiche in attesa sono operazioni normali da telefono. Ogni schermata va progettata per entrambi.

### Lingua e tono

L'interfaccia è in italiano. Frasi brevi, seconda persona singolare, parole comuni al posto dei termini tecnici. L'editor dice sempre cosa succede e quando, per esempio:

- «I contenuti salvati sono subito visibili sul sito.»
- «Il sito pubblico non cambia finché non le pubblichi.»
- «Prima viene salvata una copia.»

Non promette nulla che non sia descritto in questo documento.

## 2. Vincoli

- Pagine generate sul server; nessun framework frontend e nessun passo di compilazione. Le interazioni sono JavaScript semplice.
- Tre temi: chiaro, scuro e automatico (segue il sistema). La scelta resta sul dispositivo.
- I valori di stile sono token; i componenti sono un insieme chiuso e documentato. I plugin costruiscono le loro schermate con gli stessi componenti e non possono aggiungere stili propri.
- Finestre di dialogo con l'elemento nativo `<dialog>`.
- Il testo formattato si modifica con un editor a barra degli strumenti (TinyMCE) da integrare nel sistema.
- Accessibilità: uso completo da tastiera, focus sempre visibile, collegamento per saltare al contenuto, voce di navigazione corrente indicata, messaggi di stato annunciati ai lettori di schermo, contrasto adeguato nei due temi, aree di tocco adeguate su telefono, rispetto della preferenza di movimento ridotto.
- I contenuti del sito possono essere in più lingue; le etichette dell'editor no.

## 3. Concetti

| Concetto | Significato |
| --- | --- |
| **Tipo** | Un genere di contenuto con i suoi campi: «Homepage», «Progetti», «Menu di navigazione». Ha un nome visibile, una descrizione e un nome tecnico |
| **Contenuto unico** | Un tipo di cui esiste un solo esemplare: una pagina, il piè di pagina, le impostazioni. Si apre direttamente in modifica |
| **Pagina** | Un contenuto unico che ha un indirizzo pubblico: la homepage, «Chi siamo» |
| **Blocco** | Un contenuto unico senza indirizzo, mostrato dentro altre pagine: il menu di navigazione, il piè di pagina |
| **Raccolta** | Un tipo con molti contenuti: progetti, articoli, eventi. Ha un elenco ordinabile. Ogni suo contenuto può avere un indirizzo proprio |
| **Contenuto** | Un esemplare di un tipo |
| **Campo** | Un valore modificabile di un contenuto. È **condiviso** fra le lingue oppure **per lingua** |
| **Lista** | Un campo che contiene un gruppo ripetuto di elementi, ognuno con i suoi campi (le domande frequenti di una pagina). Le liste possono contenere altre liste |
| **Sezione** | Un raggruppamento di campi con un nome, per ordinare un contenuto con molti campi |
| **Indirizzo** | Il percorso pubblico (`/menu`, `/progetto/3`) con cui si raggiunge una pagina o un contenuto di una raccolta |
| **Sospeso** | Un contenuto o un elemento di lista che resta nell'editor ma non compare sul sito |
| **In attesa** | Modifiche preparate e non ancora pubblicate |
| **Plugin** | Un'estensione che aggiunge funzioni al sito e schermate all'editor |

### Tipi di campo

| Tipo | Valore | Controllo |
| --- | --- | --- |
| Testo | Testo senza formattazione, breve o di più righe | Campo o area di testo |
| Testo formattato | Paragrafi, titoli, elenchi, link, grassetto e corsivo | Editor con barra degli strumenti |
| Media | Un file della libreria: immagine, video o PDF | Anteprima, scelta dalla libreria, rimozione |
| Scelta | Una o più opzioni fra quelle definite nella struttura | Scelta singola o multipla |
| Interruttore | Acceso o spento | Interruttore |
| Lista | Elementi ripetuti | Elenco ordinabile dentro il contenuto |

Ogni tipo di campo può essere condiviso o per lingua.

### Quantità realistiche

| Elemento | Minimo | Tipico | Massimo |
| --- | --- | --- | --- |
| Tipi in un sito | 2 | 6–10 | 30 |
| Contenuti in una raccolta | 0 | 5–40 | 500 |
| Campi di un tipo | 1 | 10–25 | 80 |
| Sezioni di un tipo | 0 | 3–6 | 12 |
| Elementi di una lista | 0 | 3–12 | 100 |
| Livelli di liste annidate | 0 | 1 | 3 |
| Lingue attive | 1 | 2 | 5 |
| File nella libreria | 0 | 30–200 | 3.000 |
| Modifiche in attesa | 0 | 3–20 | 150 |
| Plugin installati | 0 | 1–3 | 15 |
| Pagine del sito | 1 | 5–10 | 40 |

I titoli dei contenuti vanno da una parola a una frase di 120 caratteri. Un contenuto può non avere alcun titolo: in quel caso si mostra un nome di ripiego («Contenuto #4»). I nomi dei file possono essere lunghi e senza spazi.

## 4. Organizzazione

Sezioni dell'editor:

| Gruppo | Voci |
| --- | --- |
| **Contenuti** | Le pagine, i blocchi e le raccolte del sito |
| **Libreria media** | I file caricati |
| **Sito** | Proprietà del sito, Privacy e cookie, Pagina 404, Lavori in corso |
| **Da pubblicare** | Le modifiche in attesa. Compare solo quando ci sono modifiche in attesa o una pubblicazione ancora annullabile; mostra quante sono |
| **Plugin installati** | Le voci che ogni plugin aggiunge; da zero a molte |
| **Sviluppo** | Struttura, Indirizzi, Plugin |
| **Impostazioni** | Lingue, Caricamenti, Account, Assistente AI, Archivio dei dati, Versione di Kris |

Sempre raggiungibili: il tema, la versione di Kris installata, l'uscita.

## 5. Elementi presenti in ogni schermata

### Navigazione principale

Raggiunge tutte le sezioni del capitolo 4. Indica la sezione corrente e il numero di modifiche in attesa. Dà accesso rapido alle sole raccolte con l'opzione «i nuovi in cima» (articoli, notizie, eventi), con il numero di contenuti di ciascuna; le altre raccolte si raggiungono dalla schermata «Contenuti».

### Posizione

Il percorso dalla radice al punto corrente: sezione, tipo, contenuto, eventuale elemento di lista.

### Modalità di salvataggio

Un selettore fra due modalità:

- **Sito pubblico**: ogni salvataggio è subito visibile ai visitatori.
- **In attesa**: ogni salvataggio diventa una modifica in attesa; i visitatori non vedono nulla finché non viene pubblicata.

È il controllo più importante dell'editor. La modalità attiva deve essere inequivocabile in ogni schermata, e la modalità «In attesa» deve essere percepibile nell'intera interfaccia, non solo nel selettore. In «In attesa» è sempre presente il rimando a «Rivedi e pubblica».

Alcune funzioni esistono solo in «Sito pubblico»: struttura, lingue, eliminazione di file, plugin. In «In attesa» vanno mostrate come non disponibili, con il motivo.

### Apertura del sito

In «Sito pubblico»: apre il sito in una nuova scheda. In «In attesa»: apre l'anteprima con le modifiche in attesa applicate.

### Avvisi di sistema

Compaiono in ogni schermata, sopra il contenuto.

| Avviso | Quando | Conseguenza |
| --- | --- | --- |
| Modifiche sospese | Aggiornamento di Kris in corso | Editor in sola lettura; nessuna azione |
| Aggiornamento interrotto | Un aggiornamento non si è concluso | Sola lettura; rimando a «Versione di Kris» per il ripristino |
| Formato dei contenuti da aggiornare | Dopo un aggiornamento che cambia il formato dei dati | Sola lettura; pulsante «Aggiorna il formato dei contenuti», con la nota che prima viene salvata una copia |
| Versione non corrispondente | I contenuti sono stati salvati da una versione più recente di Kris | Sola lettura; spiegazione |
| Lavori in corso attivi | L'interruttore è acceso | Promemoria: i visitatori vedono la pagina di lavorazione |
| Manca l'email di recupero | Account senza email | Campo email nell'avviso, «Salva email», «Più tardi» |
| Plugin disattivato | Un plugin è stato spento dopo un errore | Nome del plugin, rimando alla sua scheda |

In sola lettura i contenuti si consultano ma ogni controllo di modifica è disabilitato.

### Esito delle azioni

- **Messaggio in pagina**: conferma o errore di un'azione che ricarica la schermata.
- **Notifica breve**: per le azioni immediate che non ricaricano (ordine salvato, indirizzo copiato).
- **Errore di una richiesta**: se una richiesta al server non riesce, l'editor lo dice e lascia intatto ciò che la persona ha scritto.

### Barra di salvataggio

Presente nelle schermate con un modulo. Stati:

| Stato | Contenuto |
| --- | --- |
| Nessuna modifica | Stato di quiete; pulsante di salvataggio |
| Modifiche non salvate | Indicazione che ci sono modifiche; «Annulla modifiche»; pulsante di salvataggio in evidenza |
| Salvataggio in corso | Indicazione di attesa; pulsante non azionabile |
| Salvato | Conferma, mostrata solo dopo la risposta del server |
| Errore | Motivo; i campi restano compilati; si può riprovare |

Una riga spiega l'effetto del salvataggio nella modalità corrente. Uscendo dalla schermata con modifiche non salvate, l'editor chiede conferma.

### Conferma

Ogni azione distruttiva o irreversibile passa da una finestra di conferma con: titolo in forma di domanda, testo che dice cosa si perde e se si può annullare, pulsante con il verbo dell'azione («Elimina contenuto», «Pubblica», «Scarta»), pulsante per rinunciare.

## 6. Schermate

### 6.1 Accesso

Schermate senza navigazione.

| Schermata | Contenuto | Stati |
| --- | --- | --- |
| **Primo avvio** | Creazione dell'account: nome utente, email, password di almeno 10 caratteri | Dati non validi |
| **Accesso** | Nome utente, password, «Password dimenticata?» | Credenziali errate; troppi tentativi, con il tempo di attesa |
| **Password dimenticata** | Invio di un link all'email registrata | Link inviato; email non configurata o invio non riuscito: «Per ripristinare l'accesso contatta l'amministratore dell'hosting» |
| **Nuova password** | Raggiunta dal link, valido 30 minuti e una sola volta | Link scaduto o già usato |
| **Sessione scaduta** | Compare durante il lavoro; chiede di accedere di nuovo senza perdere ciò che si stava scrivendo | — |

### 6.2 Contenuti

**Scopo**: scegliere cosa modificare. È la schermata di arrivo.

**Contenuto**

- Due gruppi: «Pagine e blocchi» (i contenuti unici) e «Raccolte».
- Ogni elemento indica cosa è: pagina, blocco o raccolta. L'editor lo ricava da solo: un contenuto unico è una pagina se ha un indirizzo, un blocco se non ne ha.
- Per ogni tipo: nome visibile, descrizione, data dell'ultima modifica; per le raccolte, numero di contenuti e di sospesi.
- Ricerca per nome.
- Riquadri che i plugin possono aggiungere (per esempio un riepilogo delle visite).

**Azioni**

- Aprire un tipo: l'intero elemento è azionabile, non solo il nome. Un contenuto unico apre la modifica; una raccolta apre l'elenco.
- Aprire la struttura di un tipo (azione secondaria).

**Stati**: nessun tipo definito, con rimando a «Struttura»; nessun risultato della ricerca.

### 6.3 Elenco di una raccolta

**Scopo**: trovare, ordinare, aggiungere, sospendere ed eliminare i contenuti di una raccolta.

**Contenuto**

- Nome e descrizione della raccolta, numero di contenuti, ricerca.
- Un elemento per contenuto con: titolo, stato visibile o sospeso, eventuale anteprima dell'immagine, eventuale indirizzo della pagina.
- Le raccolte con l'opzione «i nuovi in cima» sono elenchi di articoli: i nuovi contenuti entrano in testa e ogni elemento mostra anche data e riassunto, se il tipo li ha.

**Azioni**

- **Aprire**: l'intero elemento apre il contenuto. Resta anche un pulsante «Modifica» esplicito.
- **Riordinare**: per trascinamento da una maniglia dedicata, posta all'estremità dell'elemento; solo la maniglia avvia il trascinamento. Da tastiera, comandi per spostare in alto e in basso. L'ordine si salva subito, senza ricaricare e senza spostare la posizione di scorrimento.
- **Sospendere o riattivare**: interruttore sull'elemento, con effetto immediato.
- **Eliminare**: con conferma. Dopo l'eliminazione la schermata resta dov'era.
- **Nuovo contenuto**: crea il contenuto e apre la modifica.
- **Struttura**: apre la struttura della raccolta.

**Stati**: raccolta senza campi, con rimando a «Struttura»; raccolta vuota; nessun risultato; elemento sospeso, riconoscibile ma presente; in attesa, dove ogni azione diventa una modifica in attesa; sola lettura.

### 6.4 Modifica di un contenuto

La schermata più usata. Vale per i contenuti di una raccolta, i contenuti unici, gli elementi di una lista e le voci del gruppo «Sito».

**Intestazione**

- Nome del tipo e titolo del contenuto.
- Per un elemento di lista: il percorso dal contenuto principale all'elemento, con ogni livello azionabile.
- Ritorno al livello precedente: all'elenco della raccolta, al contenuto che contiene la lista, alla schermata «Contenuti».
- Interruttore visibile / sospeso. Assente nei contenuti unici e nelle voci di «Sito».
- Azioni che i plugin possono aggiungere.

**Lingua di modifica**

Un selettore fra le lingue attive del sito. Cambiando lingua cambiano i valori dei soli campi per lingua. Requisiti:

- di ogni campo deve essere evidente se è condiviso o per lingua;
- per i campi per lingua, deve essere visibile quali lingue non hanno ancora un valore;
- con una sola lingua attiva il selettore e le indicazioni non compaiono.

**Sezioni e campi**

- I campi sono raggruppati in sezioni, nell'ordine definito nella struttura. I campi senza sezione vengono prima.
- Una sezione si può aprire e chiudere e mostra quanti campi contiene.
- Ogni campo ha un'etichetta e, se definita, una descrizione di aiuto. Il nome tecnico è un'informazione secondaria, utile allo sviluppatore.
- Con 80 campi e 12 sezioni la schermata deve restare percorribile: serve un modo per orientarsi e saltare a una sezione, anche su telefono.

**Campo media**

- Mostra l'anteprima del file scelto: immagine, video riproducibile, o icona e nome per un PDF.
- Azioni: scegliere dalla libreria, caricare un file nuovo, togliere il file.
- L'indirizzo del file è un'informazione secondaria.
- Per un'immagine: se è ottimizzata (più misure in formato leggero) e quanto pesa rispetto all'originale. Se non lo è, l'azione «Ottimizza» la ottimizza sul posto, senza cambiarne l'indirizzo.

**Campo lista**

- Elenco degli elementi con: titolo, stato visibile o sospeso, numero di elementi.
- Stesse regole dell'elenco di una raccolta: l'intero elemento apre la modifica, maniglia per riordinare, pulsante «Modifica», interruttore, eliminazione con conferma, «Aggiungi elemento».
- Aggiunta, eliminazione, ordine e sospensione si salvano subito, anche se nel modulo ci sono testi non ancora salvati. In quel caso, prima di aprire un elemento l'editor chiede se salvarli.
- Aprire un elemento porta alla stessa schermata di modifica, un livello più in basso.

**Indirizzo del contenuto**

Compare solo quando un indirizzo del sito è collegato al tipo.

- Un nome per l'indirizzo, per lingua e facoltativo. Se è vuoto, la pagina usa il numero del contenuto.
- L'editor lo propone a partire dal titolo e mostra l'indirizzo completo che ne risulta.
- Se il nome è già usato da un altro contenuto, lo segnala e propone una variante.
- Avverte che, cambiando il nome, i vecchi indirizzi continuano a funzionare.

**Motori di ricerca e condivisione**

Compare solo per i tipi che lo attivano. Tre campi per lingua: titolo, descrizione, immagine. Mostra un'anteprima del risultato su un motore di ricerca e dell'aspetto di un link condiviso, con l'indicazione di quando titolo e descrizione superano la lunghezza consigliata.

**Stati**

| Stato | Comportamento |
| --- | --- |
| Contenuto non più disponibile | Spiegazione e ritorno all'elenco |
| Tipo senza campi | Rimando a «Struttura» |
| Modifiche non salvate | Vedi barra di salvataggio |
| Errore di salvataggio | I campi restano compilati |
| Conflitto | Il contenuto è stato cambiato altrove dopo l'apertura (un'altra scheda, l'assistente AI). L'editor non sovrascrive: mostra che c'è una versione più recente e fa scegliere se ricaricarla o conservare la propria |
| In attesa | Il salvataggio crea modifiche in attesa; i campi mostrano i valori in attesa |
| Sola lettura | Controlli disabilitati |

### 6.5 Struttura

**Scopo**: definire i tipi e i loro campi. È una funzione da sviluppatore, disponibile solo in «Sito pubblico».

**Elenco dei tipi**

- Tutti i tipi del sito, con nome visibile, nome tecnico, contenuto unico o raccolta, numero di campi e di contenuti.
- I tipi di sistema sono visibili e distinguibili; i loro campi non si modificano.
- Azioni: nuovo tipo (nome tecnico, nome visibile), riordino dei tipi, apertura di un tipo.

**Un tipo**

- **Identità**: nome visibile, descrizione, nome tecnico (in sola lettura dopo la creazione).
- **Forma**: contenuto unico o raccolta. Il passaggio a contenuto unico è possibile solo se esiste al massimo un contenuto.
- **Opzioni**: «i nuovi in cima»; campi per motori di ricerca e condivisione.
- **Collegamenti**: le pagine del sito e i modelli grafici che usano il tipo, in sola lettura.
- **Campi**: elenco modificabile, descritto sotto.
- **Eliminazione del tipo**: separata dal resto, con conferma che indica quanti contenuti si perdono.

**Campi**

Per ogni campo: nome visibile, nome tecnico, tipo di campo, condiviso o per lingua, descrizione di aiuto, sezione.

- Un campo «Scelta» ha l'elenco delle opzioni (valore tecnico, nome per lingua), riordinabile, e l'indicazione di scelta singola o multipla.
- Un campo «Lista» contiene a sua volta un elenco di campi, fino a tre livelli.
- I campi si aggiungono, si eliminano, si riordinano e si spostano fra le sezioni per trascinamento e da tastiera.
- Le sezioni si creano, si rinominano e si riordinano; una sezione vuota non viene salvata.
- I nomi tecnici ammettono lettere minuscole, numeri e trattino basso; alcuni nomi sono riservati. L'errore va mostrato sul campo.

**Riepilogo dell'impatto**

Se il salvataggio elimina, rinomina o cambia tipo a campi che contengono già valori, prima di applicare compare un riepilogo: per ogni campo coinvolto cosa succede, quanti contenuti sono interessati e alcuni esempi dei valori che andranno persi. Azioni: applicare o tornare alla struttura con le modifiche ancora presenti.

**Stati**: tipo senza campi; errore di validazione; modifiche non salvate; riepilogo dell'impatto; non disponibile in «In attesa».

### 6.6 Indirizzi

**Scopo**: vedere e gestire gli indirizzi pubblici del sito. Funzione da sviluppatore.

**Elenco degli indirizzi**

- Elenco con: nome, indirizzo (`/menu`, `/progetto/…`), tipo collegato, modello grafico, presenza nella mappa del sito, indicizzabile o no, apertura della pagina.
- Un indirizzo è collegato a un contenuto unico (che così diventa una pagina) oppure a ogni contenuto di una raccolta.
- Modifica di un indirizzo: percorso, tipo, modello grafico, mappa del sito sì o no, priorità nella mappa del sito, indicizzabile sì o no.
- Collegamenti in sola lettura alla mappa del sito e a `robots.txt` generati.
- L'indirizzo viene verificato: nomi riservati e indirizzi già usati sono rifiutati con il motivo.
- Quando l'indirizzo cambia, l'editor avvisa che il vecchio verrà reindirizzato al nuovo.

**Reindirizzamenti**

- Elenco «da → a», con aggiunta, modifica ed eliminazione.
- Quelli creati in automatico da un cambio di indirizzo sono distinguibili da quelli scritti a mano.

**Lingua automatica**

Un interruttore con una spiegazione breve: il sito propone la lingua del visitatore quando l'indirizzo non ne indica una.

**Stati**: pagina senza modello grafico; modello grafico senza pagina; tipo senza pagina; indirizzo in conflitto.

### 6.7 Libreria media

**Scopo**: caricare, trovare ed eliminare i file.

**Contenuto**

- Area di caricamento: per trascinamento, per scelta di uno o più file, da fotocamera su telefono. Indica formati ammessi e peso massimo (immagini e PDF fino a 8 MB, video fino a 50 MB).
- Ricerca per nome; numero di file.
- Per ogni file: anteprima, nome, peso, formato, stato «in attesa» se non ancora pubblicato; per le immagini, ottimizzata o no.
- Filtro dei file non usati da nessun contenuto.

**Azioni**

- Aprire il file a grandezza piena.
- Copiare l'indirizzo.
- Eliminare, con conferma. Prima di eliminare l'editor indica dove il file è usato.

**Stati**: libreria vuota; nessun risultato; caricamento in corso, con avanzamento per file; file rifiutato, con il motivo (formato, peso); più file in coda con esiti diversi; in attesa, dove l'eliminazione non è disponibile.

**Scelta di un file**

Una finestra aperta da un campo media. Contiene la stessa libreria con ricerca e caricamento. Si seleziona un file e si conferma con «Usa questo file»; la selezione corrente è sempre indicata. Un file caricato dalla finestra resta in libreria anche se non viene scelto. Su telefono occupa l'intero schermo.

### 6.8 Da pubblicare

**Scopo**: rivedere e pubblicare le modifiche in attesa, preparate dall'editor in modalità «In attesa» o dall'assistente AI. È una schermata di revisione: deve far capire cosa cambierà sul sito e mettere in evidenza i rischi.

**Contenuto**, in ordine di importanza:

1. **Riepilogo**: quante modifiche e di che genere, chi le ha preparate e quando, apertura dell'anteprima.
   - **Pagine toccate**: l'elenco delle pagine e dei contenuti con un indirizzo che cambiano, ognuno con il proprio link di anteprima. Serve soprattutto per le pagine nuove o non collegate dal menu, che altrimenti non si raggiungono.
2. **Problemi da controllare**, se presenti:
   - un file è stato cambiato anche sul sito dopo la preparazione;
   - una modifica non è più applicabile perché il contenuto è cambiato o non esiste più;
   - un modello grafico usa ancora un campo che viene eliminato.
3. **Codice da approvare**, se le modifiche aggiungono codice eseguito dal browser. Spiega che quel codice gira con l'accesso all'editor aperto e potrebbe agire a nome della persona. Elenca i file coinvolti. L'approvazione richiede di spuntare un'assunzione di responsabilità e vale per l'intero gruppo di file. Finché manca, l'anteprima non esegue quel codice. Se il codice cambia, l'approvazione va ripetuta.
4. **Contenuti, struttura e pagine**: l'elenco delle modifiche. Per ognuna: descrizione in linguaggio comune («Cambia il titolo di Homepage»), valore precedente e valore nuovo, autore, data, eventuale indicazione «non applicabile».
5. **File caricati**: anteprime dei file che entreranno in libreria.
6. **Font**: i caratteri tipografici aggiunti al sito.
7. **File del sito**: per ogni file modificato il percorso, lo stato (nuovo, modificato, da eliminare, cambiato anche sul sito), autore e data, un avviso su ciò che aggiunge (codice, domini esterni) e le differenze riga per riga, con righe aggiunte, tolte e invariate. Le differenze lunghe devono potersi ridurre.
8. **Azioni**:
   - «Pubblica sul sito», con conferma. Con problemi aperti diventa «Pubblica comunque» e la conferma dice che le modifiche non applicabili verranno saltate.
   - «Scarta le modifiche», con conferma: elimina tutte le modifiche in attesa.
   - Scartare una singola modifica.
9. **Ultima pubblicazione**: data, autore, cosa conteneva. «Annulla la pubblicazione» è disponibile per 14 giorni, con conferma che avverte della perdita delle modifiche successive a quegli stessi contenuti.

**Stati**: nessuna modifica in attesa; solo l'ultima pubblicazione annullabile; problemi aperti; codice non approvato; codice approvato; molte modifiche (150) da raggruppare e riassumere; pubblicazione in corso; pubblicazione riuscita; pubblicazione non riuscita.

### 6.9 Sito

Quattro contenuti unici di sistema. Usano la schermata di modifica (6.4), ciascuno con un'introduzione che spiega a cosa serve.

| Voce | Campi | Particolarità |
| --- | --- | --- |
| **Proprietà del sito** | Nome del sito, indirizzo pubblico, icona del sito, modello del titolo delle pagine («Pagina \| Nome del sito»), immagine di condivisione e descrizione predefinite, codici di verifica dei motori di ricerca; dati dell'attività per i motori di ricerca (tipo di attività, nome, logo, indirizzo, telefono, orari, profili social); righe aggiuntive per `robots.txt` | L'icona mostra un'anteprima di come appare nella scheda del browser; il modello del titolo mostra un esempio con una pagina vera |
| **Privacy e cookie** | Dati del titolare; informativa sulla privacy e informativa sui cookie (testo formattato); testi dell'avviso sui cookie; nome e descrizione delle categorie di cookie | Mostra in sola lettura l'elenco dei cookie dichiarati dai plugin: nome, durata, fornitore, categoria |
| **Pagina 404** | Titolo, testo, etichetta e indirizzo del pulsante, immagine | Anteprima della pagina |
| **Lavori in corso** | Interruttore acceso / spento; i campi definiti dallo sviluppatore per la pagina di lavorazione | Accendere richiede una conferma che dice cosa vedranno i visitatori. Da spento la pagina si può vedere in anteprima. Da acceso, il promemoria compare in tutto l'editor |

### 6.10 Plugin

**Scopo**: installare e gestire le estensioni. Funzione da sviluppatore, disponibile solo in «Sito pubblico».

**Installati**

Per ogni plugin: nome, versione, descrizione breve, stato, interruttore attivo / disattivato, apertura delle impostazioni, aggiornamento quando disponibile, rimozione.

| Stato | Significato |
| --- | --- |
| Attivo | In funzione |
| Disattivato | Spento dalla persona; dati conservati |
| Da aggiornare | È disponibile una versione nuova |
| In errore | Spento in automatico dopo un errore; mostra quando e cosa è successo |
| Non compatibile | Richiede una versione di Kris o un componente del server non presenti |

**Catalogo**

I plugin della raccolta ufficiale: nome, descrizione, autore, versione, requisiti. Ricerca. I plugin già installati sono indicati.

**Scheda di un plugin**

- Descrizione estesa.
- Cosa aggiunge all'editor (voci, schede, campi) e al sito.
- Cookie e servizi esterni che usa.
- Requisiti: versione di Kris, componenti del server, database consigliato o no.
- Registro delle versioni.
- Azione principale secondo lo stato: installa, aggiorna, apri le impostazioni.

**Installazione**

- La conferma dice chiaramente che il plugin esegue codice sul server con gli stessi permessi di Kris, e chiede la password dell'account.
- Avanzamento: scaricamento, verifica della firma, installazione.
- Esiti: riuscita; non riuscita, con il motivo e la garanzia che il sito non è cambiato.

**Rimozione**

La conferma chiede se conservare o eliminare i dati del plugin.

**Stati**: nessun plugin installato; catalogo non raggiungibile; requisiti non soddisfatti; modalità sicura, in cui tutti i plugin sono spenti e l'editor lo segnala; non disponibile in «In attesa».

**Schermate dei plugin**

Un plugin può aggiungere all'editor:

| Aggancio | Dove |
| --- | --- |
| Voce di navigazione | Nel gruppo «Plugin installati» |
| Scheda | In «Impostazioni» |
| Riquadro | Nella schermata «Contenuti» |
| Tipo di campo | Nella modifica di un contenuto e in «Struttura» |
| Azione | Nell'intestazione di un contenuto o sull'elemento di un elenco |
| Sezione | In «Da pubblicare» |

Le schermate di un plugin sono di due generi:

- **Pannello di impostazioni**: generato dall'editor a partire dai campi che il plugin dichiara. Ha gli stessi controlli della modifica di un contenuto, raggruppabili in sezioni, e la barra di salvataggio.
- **Schermata propria**: una pagina dentro la struttura comune dell'editor, composta con i componenti del capitolo 7. Esempi da tenere presenti: un calendario di prenotazioni con elenco e dettaglio, un elenco di messaggi ricevuti da un modulo di contatto, un riepilogo di statistiche.

Servono quindi regole di composizione che permettano a chi scrive un plugin di ottenere una schermata coerente senza progettarla.

### 6.11 Impostazioni

Più sezioni, una visibile per volta. I plugin possono aggiungerne.

| Sezione | Contenuto | Stati particolari |
| --- | --- | --- |
| **Lingue** | Lingue attive del sito, lingua principale, ordine. Nota: disattivare una lingua conserva le traduzioni già salvate | Almeno una lingua deve restare attiva |
| **Caricamenti** | Prefisso dei nomi dei file; larghezza massima delle foto; conversione in formato leggero; ottimizzazione automatica delle immagini al caricamento (più misure per schermi diversi), con «Ottimizza tutta la libreria» per i file già caricati e il suo avanzamento | Il server non può elaborare le immagini: le opzioni non sono disponibili, con il motivo |
| **Account** | Nome utente; email di recupero; invio di un'email di prova; cambio password (attuale, nuova, ripetizione) | Email di prova inviata o non inviata; password attuale errata; password nuova troppo corta o diversa dalla ripetizione |
| **Assistente AI** | Vedi sotto | — |
| **Archivio dei dati** | Dove sono salvati i contenuti (file o database), stato del collegamento, data dell'ultima copia. In sola lettura | Collegamento al database non riuscito |
| **Versione di Kris** | Vedi sotto | — |

**Assistente AI**

- **Non collegato**: campo per incollare un codice di abbinamento, con la spiegazione di dove trovarlo, della sua validità (30 minuti, una volta) e dell'indirizzo del sito per cui vale.
- **Collegato**: stato acceso o spento, data dell'abbinamento, «Spegni» / «Riaccendi», «Scollega» con conferma.
- **Chi può usare l'assistente**: elenco di persone con nome, email, stato (può usarlo, aspetta l'approvazione, escluso, senza abbonamento, abbonamento scaduto o sospeso, amministratore) e azione «Approva» o «Escludi». «Reimposta l'accesso», con conferma: tutti tornano in attesa di approvazione.
- Stati: elenco vuoto; elenco non raggiungibile; codice non valido o scaduto.

**Versione di Kris**

- Versione installata; formato dei contenuti; data ed esito dell'ultimo controllo; data ed esito dell'ultimo aggiornamento.
- «Verifica aggiornamenti».
- Caricamento manuale di un pacchetto di aggiornamento.
- «Torna alla versione precedente», disponibile fino a una data indicata, con l'avviso che le modifiche fatte dopo l'aggiornamento andranno perse.
- Registro degli ultimi aggiornamenti: data, versioni, esito, autore.

Sequenza dell'aggiornamento:

| Passo | Contenuto |
| --- | --- |
| Verifica | In corso; nessun aggiornamento; aggiornamento disponibile con le novità scritte per i clienti; versione disponibile ma riservata allo sviluppatore; controllo non riuscito |
| Controllo di compatibilità | Solo per gli aggiornamenti che cambiano il formato dei dati: elenco di ciò che cambierà sul sito e di ciò che richiede un intervento, diviso fra automatico e da fare a mano |
| Scaricamento e verifica | Avanzamento; firma non valida |
| Installazione | Avanzamento a passi: copia di sicurezza, sostituzione, conversione dei contenuti, verifica delle pagine. Durante l'installazione il sito mostra ai visitatori una pagina di attesa |
| Esito | Riuscito; non riuscito con il sito ripristinato in automatico e il motivo; interrotto, con «Ripristina il sito» |

Altri stati: aggiornamenti gestiti dallo sviluppatore (nessuna azione disponibile); aggiornamento bloccato perché ci sono modifiche in attesa da pubblicare o scartare.

### 6.12 Pagine di servizio

Non fanno parte dell'editor ma dello stesso pacchetto. Sono viste dai visitatori del sito, quindi devono essere neutre rispetto all'aspetto di qualunque sito.

| Pagina | Quando | Contenuto |
| --- | --- | --- |
| Attesa per aggiornamento | Durante un aggiornamento di Kris | Messaggio breve: il sito torna fra pochi minuti |
| Lavori in corso, di serie | Interruttore acceso e sito senza una pagina propria | Titolo e testo modificabili |
| 404, di serie | Indirizzo inesistente e sito senza una pagina propria | Titolo, testo, pulsante modificabili |
| Errore dei dati | L'editor non riesce a leggere i contenuti | Il problema e a chi rivolgersi; nessuna navigazione |
| Barra dell'anteprima | Sopra ogni pagina vista in anteprima | Indica che è un'anteprima, quante modifiche contiene, rimando a «Da pubblicare», uscita dall'anteprima. L'anteprima resta attiva finché non se ne esce, anche scrivendo un indirizzo a mano |
| Approvazione del codice in anteprima | All'apertura di un'anteprima con codice non approvato | Elenco dei file; «Approva ed esegui», dopo l'assunzione di responsabilità; «Guarda senza eseguire» |

## 7. Componenti

Un solo insieme, usato dall'editor e dai plugin. Per ogni componente servono gli stati: normale, al passaggio del puntatore, attivo, con il focus da tastiera, disabilitato, in errore dove ha senso, in caricamento dove ha senso.

**Azioni**

- Pulsante: principale, secondario, discreto, distruttivo; con icona, solo icona, in caricamento.
- Collegamento testuale.
- Interruttore a due stati.
- Selettore a segmenti (lingua, modalità, tema).
- Menu di azioni.
- Maniglia di trascinamento.

**Navigazione**

- Voce di navigazione, con icona e contatore.
- Gruppo di voci con titolo.
- Percorso.
- Navigazione fra sezioni di una schermata.
- Indice di una schermata lunga.
- Ritorno al livello precedente.

**Contenitori**

- Riquadro con intestazione e corpo.
- Riquadro azionabile per intero.
- Sezione apribile.
- Riga di impostazione: nome, spiegazione, valore o controllo.
- Area per azioni distruttive.
- Barra con ricerca, filtri e contatore.

**Elenchi e dati**

- Elenco di elementi azionabili, riordinabili, con stato, anteprima e azioni.
- Griglia di file.
- Tabella.
- Elenco di modifiche con autore e data.
- Confronto prima → dopo di un valore.
- Differenze riga per riga di un file.
- Registro cronologico.
- Elenco di persone con stato e azione.
- Coppia chiave → valore in sola lettura.

**Modulo**

- Campo di testo, area di testo, numero, password, email, ricerca.
- Testo formattato.
- Media: vuoto, con immagine, con video, con PDF.
- Scelta singola, scelta multipla.
- Interruttore, casella.
- Caricamento di file: area di trascinamento, coda, avanzamento, esito per file.
- Etichetta, descrizione di aiuto, messaggio di errore, contatore di caratteri.
- Indicazione condiviso / per lingua; indicazione di traduzione mancante.
- Gruppo di campi con titolo.

**Riscontro**

- Avviso: informazione, attenzione, errore, successo; con o senza azione.
- Nota esplicativa in pagina.
- Etichetta di stato: neutra, positiva, attenzione, pericolo, in attesa.
- Notifica breve.
- Barra di salvataggio.
- Stato vuoto: titolo, spiegazione, azione.
- Avanzamento: indeterminato, percentuale, a passi.
- Segnaposto durante il caricamento.

**Sovrapposizioni**

- Finestra di conferma.
- Finestra con modulo.
- Finestra grande (scelta di un file); a schermo intero su telefono.
- Indicazione di modalità «In attesa».

**Icone**

Un insieme unico. Servono almeno: contenuti, raccolta, contenuto unico, media, immagine, video, documento, sito, privacy, pagina non trovata, lavori in corso, da pubblicare, plugin, struttura, pagine, impostazioni, lingue, account, assistente AI, archivio, versione, aggiungi, modifica, elimina, trascina, su, giù, apri, chiudi, cerca, carica, copia, torna indietro, apri in nuova scheda, visibile, sospeso, condiviso, per lingua, avviso, errore, conferma, tema.

## 8. Da consegnare

1. **Token**: per i temi chiaro e scuro.
2. **Componenti**: tutti quelli del capitolo 7, con stati e varianti.
3. **Schermate**: tutte quelle del capitolo 6, per computer e per telefono, nei due temi.
4. **Stati**: per ogni schermata, almeno lo stato tipico, quello vuoto, quello con i valori massimi della tabella «Quantità realistiche», quello di errore, la modalità «In attesa» e la sola lettura dove previste.
5. **Regole di composizione** per le schermate dei plugin, con almeno un esempio di pannello di impostazioni e uno di schermata propria.
