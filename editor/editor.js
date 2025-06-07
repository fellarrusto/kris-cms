let currentElement = null;

function createRepeatableItemElement(key, item, kId) {
    const div = document.createElement("div");
    div.className = "repeatable-item";
    div.dataset.index = key;

    let html = '';

    Object.keys(item).forEach(subKey => {
        const field = item[subKey];
        if (field.hasOwnProperty("video") || (subKey === 'video' && field.hasOwnProperty('src'))) {
            html += `
                <label style="display:block; margin-top: 12px; font-weight: 600;">Upload Video</label>
                <input type="file" class="video-upload-${subKey}" accept="video/*" />
                <input type="hidden" class="repeatable-${subKey}-src" value="${field.src || ''}" />
            `;
        } else if (field.hasOwnProperty("src")) {
            // Handle image
            html += `
                <label style="display:block; margin-top: 12px; font-weight: 600; cursor: pointer; margin-bottom: 8px;">
                    Upload Image
                </label>
                <input type="file" class="image-upload-${subKey}" accept="image/*" />
                <input type="hidden" class="image-src-${subKey} repeatable-${subKey}-src" value="${field.src}" />
            `;
        } else if (field.hasOwnProperty("action")) {
            // Handle action URL
            html += `<label style="display:block; margin-top: 12px; font-weight: 600;">Title (IT):</label>
                <input type="text" class="repeatable-${subKey}-it" value="${field.it || ''}" 
                    style="width: 100%; padding: 6px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px;" />

                <label style="display:block; margin-top: 12px; font-weight: 600;">Title (EN):</label>
                <input type="text" class="repeatable-${subKey}-en" value="${field.en || ''}" 
                    style="width: 100%; padding: 6px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px;" />

                <label style="display:block; margin-top: 12px; font-weight: 600;">Action URL:</label>
                <input type="text" class="repeatable-${subKey}-action" value="${field.action || ''}" 
                    style="width: 100%; padding: 6px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px;" />
                <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">
                    Please enter a valid URL starting with http:// or https://
                </small>`
        } else if (field.hasOwnProperty("it") && field.hasOwnProperty("en")) {
            // Handle text (e.g., title or paragraph in different languages)
            html += `
                <label style="display:block; margin-top: 12px; font-weight: 600;">Description (IT):</label>
                <textarea class="repeatable-${subKey}-it" 
                    style="width: 100%; padding: 6px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px; min-height: 60px;">${field.it || ''}</textarea>

                <label style="display:block; margin-top: 12px; font-weight: 600;">Description (EN):</label>
                <textarea class="repeatable-${subKey}-en" 
                    style="width: 100%; padding: 6px; margin-top: 4px; border: 1px solid #ccc; border-radius: 4px; min-height: 60px;">${field.en || ''}</textarea>
            `;
        }
    });

    html += `
        <button class="delete-repeatable-btn" 
            style="margin-top: 12px; padding: 8px 16px; background-color: #e74c3c; border: none; color: white; border-radius: 4px; cursor: pointer;">
            Delete
        </button>
        <hr style="margin-top: 20px; border-color: #ddd;"/>
    `;

    div.innerHTML = html;

    Object.keys(item).forEach(subKey => {
        // Handle image uploads
        const fileInput = div.querySelector('.image-upload-' + subKey);
        if (fileInput) {
            const hiddenSrcInput = div.querySelector('.image-src-' + subKey);

            fileInput.addEventListener("change", () => {
                const file = fileInput.files[0];
                if (!file) return;

                const filename = Date.now() + "_" + file.name;
                const filepath = "/src/" + filename;

                uploadImageToServer(file, filename, () => {
                    hiddenSrcInput.value = filepath;
                });
            });
        }

        // Handle video uploads
        const videoInput = div.querySelector('.video-upload-' + subKey);
        if (videoInput) {
            const hiddenSrcInput = div.querySelector('.repeatable-' + subKey + '-src');

            videoInput.addEventListener("change", () => {
                const file = videoInput.files[0];
                if (!file) return;

                const filename = Date.now() + "_" + file.name;
                const filepath = "/src/" + filename;

                uploadVideoToServer(file, filename, () => {
                    hiddenSrcInput.value = filepath;
                });
            });
        }
    });

    div.querySelector(".delete-repeatable-btn").onclick = () => {
        div.remove();
    };

    return div;
}

function updateRepeatableData(kId) {
    const container = document.getElementById("repeatable-items-container");
    const items = {};
    container.querySelectorAll(".repeatable-item").forEach(div => {
        const idx = div.dataset.index;
        const itemData = {};

        // Dynamically handle each key in the item
        Object.keys(window.kData[kId][idx]).forEach(key => {
            const classPrefix = `repeatable-${key}`;
            const fields = div.querySelectorAll(`[class*='${classPrefix}']`);
            fields.forEach(field => {
                const suffixMatch = field.className.match(new RegExp(`repeatable-${key}-(\\S+)`));

                if (suffixMatch) {
                    const suffix = suffixMatch[1];

                    if (!itemData[key]) {
                        itemData[key] = {};
                    }

                    itemData[key][suffix] = field.value.trim();
                }
            });
        });

        console.log('Item data for index', idx, ':', itemData);

        items[idx] = itemData;
    });

    window.kData[kId] = items;
}

// Call this to add a new empty repeatable item block
function addRepeatableItem(kId) {
    const container = document.getElementById("repeatable-items-container");

    const items = window.kData[kId] || {};

    let newItemTemplate = null;
    for (const itemKey in items) {
        if (items.hasOwnProperty(itemKey)) {
            newItemTemplate = items[itemKey];
            break;
        }
    }

    const newItem = {};
    if (newItemTemplate) {
        Object.keys(newItemTemplate).forEach(subKey => {
            const field = newItemTemplate[subKey];
            if (field.hasOwnProperty("video") || (subKey === 'video' && field.hasOwnProperty('src'))) {
                newItem[subKey] = { video: "" };
            } else if (field.hasOwnProperty("src")) {
                newItem[subKey] = { src: "" };
            } else if (field.hasOwnProperty("action")) {
                newItem[subKey] = { it: "", en: "", action: "" };
            } else if (field.hasOwnProperty("it") && field.hasOwnProperty("en")) {
                newItem[subKey] = { it: "", en: "" };
            }
        });
    }

    const newItemKey = `new-item-${Date.now()}`;  // Generate a unique key for the new item
    items[newItemKey] = newItem;
    window.kData[kId] = items;

    const itemDiv = createRepeatableItemElement(newItemKey, newItem, kId);
    container.appendChild(itemDiv);
}


async function editRepeatable(e, el) {
    currentElement = el;
    const kId = el.getAttribute("k-id");
    const saveBtn = document.querySelector("#editor-repeatable .save-btn");
    const addBtn = document.querySelector("#editor-repeatable .add-btn");
    saveBtn.setAttribute("onclick", `saveRepeatable('${kId}')`);
    addBtn.setAttribute("onclick", `addRepeatableItem('${kId}')`);
    document.getElementById("editorOverlay").style.display = "block";
    document.getElementById("editor-text").style.display = "none";
    document.getElementById("editor-image").style.display = "none";
    document.getElementById("editor-repeatable").style.display = "block";

    try {
        const response = await fetch('../../k_data.json');
        if (!response.ok) throw new Error('Failed to load JSON');
        kData = await response.json();
    } catch (err) {
        console.error('Error loading data:', err);
        return;
    }

    const container = document.getElementById("repeatable-items-container");
    container.innerHTML = "";

    const items = kData[kId];
    Object.keys(items).forEach(key => {
        const item = items[key];
        console.log('key:', item);
        const itemDiv = createRepeatableItemElement(key, item, kId, items);
        container.appendChild(itemDiv);
    });
}

// Save repeatable data to server like saveContent does
function saveRepeatable(kId) {
    updateRepeatableData(kId);
    saveDataToServer();
}


function editText(e, el) {
    currentElement = el;
    document.getElementById("editorOverlay").style.display = "block";
    document.getElementById("editor-text").style.display = "block";
    document.getElementById("editor-image").style.display = "none";
    document.getElementById("editor-video").style.display = "none";
    document.getElementById("editor-repeatable").style.display = "none";

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
    document.getElementById("editor-repeatable").style.display = "none";
    document.getElementById("editor-video").style.display = "none";
    document.getElementById("editor-image").style.display = "block";

}

function editVideo(e, el) {
    if (e && typeof e.preventDefault === 'function') {
        e.preventDefault();
    }
    currentElement = el;
    document.getElementById("editorOverlay").style.display = "block";
    document.getElementById("editor-text").style.display = "none";
    document.getElementById("editor-image").style.display = "none";
    document.getElementById("editor-repeatable").style.display = "none";
    document.getElementById("editor-video").style.display = "block";
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
    const kId = currentElement.getAttribute("k-id");
    window.kData[kId].src = filepath;

    uploadImageToServer(fileUpload, filename, () => {
        currentElement.src = filepath;
        saveDataToServer();
    });
}

function saveVideo() {
    const fileUpload = document.getElementById("video-upload").files[0];
    if (!fileUpload) {
        alert("Seleziona un file prima di salvare.");
        return;
    }

    const filename = Date.now() + "_" + fileUpload.name;
    const filepath = "/src/" + filename;
    const kId = currentElement.getAttribute("k-id");
    window.kData[kId].src = filepath;

    uploadVideoToServer(fileUpload, filename, () => {
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

function uploadVideoToServer(file, filename, callback) {
    const formData = new FormData();
    formData.append("video", file);
    formData.append("filename", filename);

    fetch("upload_video.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            callback();
        } else {
            alert("Errore nel caricamento del video.");
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
                location.reload();
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
    document.getElementById("editor-video").style.display = "none";
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