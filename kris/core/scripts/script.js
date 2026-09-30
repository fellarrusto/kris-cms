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

function filterItems(btn, tag, itemSelector, activeClass = 'filter-active', inactiveClass = 'filter-inactive') {
    Array.from(btn.parentElement.children).forEach(b => {
        b.classList.remove(activeClass, inactiveClass);
        b.classList.add(inactiveClass);
    });
    btn.classList.remove(inactiveClass);
    btn.classList.add(activeClass);

    document.querySelectorAll(itemSelector).forEach(el => {
        const tags = el.dataset.tags ? el.dataset.tags.split(',').map(t => t.trim()) : [];
        el.style.display = (!tag || tags.includes(tag)) ? 'block' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateActiveLanguage();
});