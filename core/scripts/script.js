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
  
  document.querySelector(`[onclick*="'${currentLang}'"]`).classList.add('active');
}

document.addEventListener('DOMContentLoaded', updateActiveLanguage);