function setLanguage(lang) {
    const params = new URLSearchParams(location.search);
    params.set('ln', lang);
    location.href = location.pathname + '?' + params;
}

function updateActiveLanguage() {
    const params = new URLSearchParams(location.search);
    const currentLang = params.get('ln') || document.documentElement.lang || 'it';
  
    document.querySelectorAll('.lang-btn').forEach(btn => {
        btn.classList.remove('active');
    });
  
    const activeBtn = document.querySelector(`[data-kris-lang="${currentLang}"]`) || document.querySelector(`[onclick*="'${currentLang}'"]`);
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

// Senza gestori inline (onclick), che una Content-Security-Policy rigida blocca:
// <a href="?ln=en" data-kris-lang="en">EN</a> cambia lingua;
// <button data-kris-filter="tech" data-kris-filter-items=".card" data-kris-filter-active="on" data-kris-filter-inactive="off"> filtra.
document.addEventListener('click', (event) => {
    const lang = event.target.closest('[data-kris-lang]');
    if (lang) {
        event.preventDefault();
        setLanguage(lang.dataset.krisLang);
        return;
    }
    const filter = event.target.closest('[data-kris-filter]');
    if (filter) {
        filterItems(filter, filter.dataset.krisFilter, filter.dataset.krisFilterItems || '[data-tags]',
            filter.dataset.krisFilterActive || 'filter-active', filter.dataset.krisFilterInactive || 'filter-inactive');
    }
});

document.addEventListener('DOMContentLoaded', () => {
    updateActiveLanguage();
});