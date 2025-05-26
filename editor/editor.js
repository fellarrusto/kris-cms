let currentElement = null;

function editText(e, el) {
    currentElement = el;
    document.getElementById("editorOverlay").style.display = "block";
    document.getElementById("editor-text").style.display = "block";
    document.getElementById("editor-image").style.display = "none";

    const editorIt = document.getElementById("editor-it");
    const editorEn = document.getElementById("editor-en");
    const editorAction = document.getElementById("editor-action").parentElement;

    const tag = el.tagName.toLowerCase();
    if (tag === "a" || tag === "button") {
        editorAction.style.display = "block";
        document.getElementById("editor-action").value = window.kData[kId].action || "";
    } else {
        editorAction.style.display = "none";
    }
    const kId = el.getAttribute("k-id");

    textIt = window.kData[kId].it;
    textEn = window.kData[kId].en;

    editorIt.value = textIt;
    editorEn.value = textEn;
}

function editImage(e, el) {
    currentElement = el;
    document.getElementById("editorOverlay").style.display = "block";
    document.getElementById("editor-text").style.display = "none";
    document.getElementById("editor-image").style.display = "block";

}

function isValidURL(url) {
    try {
        const parsedUrl = new URL(url);

        return parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'https:';
    } catch (_) {
        return false;
    }
}

function saveContent() {
    const editorIt = document.getElementById("editor-it");
    const editorEn = document.getElementById("editor-en");
    const editorAction = document.getElementById("editor-action");
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

    const tag = currentElement.tagName.toLowerCase();
    if ((tag === "a" || tag === "button") && editorAction) {
        const action = document.getElementById('editor-action').value.trim();

         if (action !== '' && !isValidURL(action)) {
            alert("Inserisci un URL valido che inizi con http:// o https://");
            editorAction.focus();
            return;
         }

        window.kData[kId].action = editorAction.value;
    }

    saveDataToServer()
}

function saveImage() {
    const fileUpload = document.getElementById("image-upload").files[0];
    if (!fileUpload) {
        alert("Seleziona un file prima di salvare.");
        return;
    }

    const filename = Date.now() + "_" + fileUpload.name; // Nome unico per evitare sovrascritture
    const filepath = "/src/" + filename;
    console.log(filepath)
    const kId = currentElement.getAttribute("k-id");
    window.kData[kId].src = filepath;

    uploadImageToServer(fileUpload, filename, () => {
        currentElement.src = filepath;
        saveDataToServer();
    });
}

function editButton(e, el) {
    currentElement = el;

    document.getElementById("editorOverlay").style.display = "block";
    document.getElementById("editor-text").style.display = "block";
    document.getElementById("editor-image").style.display = "none";
    const actionWrapper = document.getElementById("editor-action").parentElement;
    actionWrapper.style.display = "block";

    const kId = el.getAttribute("k-id");
    document.getElementById("editor-it").value = window.kData[kId].it;
    document.getElementById("editor-en").value = window.kData[kId].en;

    document.getElementById("editor-action").value = window.kData[kId].action || "";
}


function uploadImageToServer(file, filename, callback) {
    const formData = new FormData();
    formData.append("image", file);
    formData.append("filename", filename);

    fetch("upload_image.php", {
        method: "POST",
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                callback();
            } else {
                alert("Errore nel caricamento dell'immagine.");
            }
        })
        .catch(error => console.error("Errore:", error));
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
    document.getElementById("editor-text").style.display = "none";
    document.getElementById("editor-image").style.display = "none";
    currentElement = null;
}

function changeLanguage(language) {
    data = window.kData;
    Object.keys(data).forEach(key => {
        document.querySelector(`[k-id="${key}"]`).innerHTML = data[key][language];
        console.log(key, data[key][language]);
    });
}

// Chiudi overlay premendo ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeEditor();
});

document.addEventListener('DOMContentLoaded', function () {
    const languageSwitcher = document.querySelector('.language-switcher');
    const radioButtons = languageSwitcher.querySelectorAll('input[type="radio"]');

    radioButtons.forEach(radio => {
        radio.addEventListener('change', function (event) {
            const selectedLanguage = event.target.value;
            console.log(`Selected language: ${selectedLanguage}`);
            changeLanguage(selectedLanguage)
        });
    });
});