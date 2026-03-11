function setLanguage(lang) {
    const params = new URLSearchParams(location.search);
    params.set('ln', lang);
    location.href = location.pathname + '?' + params;
}

function updateActiveLanguage() {
    const params = new URLSearchParams(location.search);
    const currentLang = params.get('ln') || 'it';
  
    document.querySelectorAll('.lang-btn').forEach(btn => {
        btn.classList.remove('active');
    });
  
    const activeBtn = document.querySelector(`[onclick*="'${currentLang}'"]`);
    if (activeBtn) activeBtn.classList.add('active');
}

// Nuova utility core per filtrare i k-array
function filterItems(btn, tag, itemSelector) {
    // Gestisce lo stile dei bottoni "fratelli"
    Array.from(btn.parentElement.children).forEach(b => {
        b.classList.remove('btn-primary');
        b.classList.add('btn-secondary');
    });
    btn.classList.remove('btn-secondary');
    btn.classList.add('btn-primary');

    // Filtra gli elementi in base al data-tags
    document.querySelectorAll(itemSelector).forEach(el => {
        const tags = el.dataset.tags ? el.dataset.tags.split(',').map(t => t.trim()) : [];
        el.style.display = (!tag || tags.includes(tag)) ? 'block' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateActiveLanguage();
});