let currentElement = null;

function edit(e, el) {
    currentElement = el;
    const overlay = document.getElementById("editorOverlay");
    overlay.style.display = "block";

    const editorIt = document.getElementById("editor-it");
    const editorEn = document.getElementById("editor-en");
    const kId = el.getAttribute("k-id");

    textIt = window.kData[kId].it;
    textEn = window.kData[kId].en;

    editorIt.value = textIt;
    editorEn.value = textEn;
}

function saveContent() {
    const editorIt = document.getElementById("editor-it");
    const editorEn = document.getElementById("editor-en");
    const kId = currentElement.getAttribute("k-id");

    window.kData[kId].it = editorIt.value;
    window.kData[kId].en = editorEn.value;

    // Get URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const lang = urlParams.get("ln") || "it"; // Default to "it"

    // Set content based on language
    if (lang === "en") {
        currentElement.innerHTML = editorEn.value;
    } else {
        currentElement.innerHTML = editorIt.value;
    }

    saveDataToServer()

}

function saveDataToServer() {
    fetch("save_data.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify(window.kData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === "success") {
            closeEditor();
            alert("Modifica salvata con successo.");
        } else {
            alert(`Errore durante il salvataggio dei dati: ${data.message}.`);
        }
    })
    .catch(error => console.error("Request failed:", error));
}

function closeEditor() {
    document.getElementById("editorOverlay").style.display = "none";
    currentElement = null;
}

// Chiudi overlay premendo ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeEditor();
});