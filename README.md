# Kris CMS - README

## Elementi modificabili

### k-id: Campi semplici
Sostituisce testo o href con dati dall'entity.

**HTML:**
```html
<h1 k-id="homepage.title">Titolo placeholder</h1>
<a k-id="homepage.link" href="#">Link</a>
```

**JSON:**
```json
{
    "name": "homepage",
    "data": [
        {
            "name": "title",
            "type": "text",
            "value": {
                "it": "Benvenuto",
                "en": "Welcome"
            }
        },
        {
            "name": "link",
            "type": "path",
            "value": {
                "it": "/contatti",
                "en": "/contact"
            }
        }
    ]
}
```

### k-array: Liste ripetibili
Ripete un template per ogni entity con quel nome.

**HTML:**
```html
<div k-array="product" k-template="product-card"></div>
```

**template/product-card.html:**
```html
<div class="card">
    <h3 k-id="product.name">Nome</h3>
    <p k-id="product.price">Prezzo</p>
</div>
```

**JSON:**
```json
[
    {"id": 0, "name": "product", "data": [...]},
    {"id": 1, "name": "product", "data": [...]},
    {"id": 2, "name": "product", "data": [...]}
]
```

### k-component: Componente singolo
Carica un componente specifico per id.

**HTML:**
```html
<div k-component="footer" k-template="footer" k-index="0"></div>
```

## Convertire HTML esistente

**Prima:**
```html
<section>
    <h1>La nostra azienda</h1>
    <p>Siamo leader nel settore</p>
    <a href="/about">Scopri di più</a>
</section>
```

**Dopo:**
```html
<section>
    <h1 k-id="about.title">La nostra azienda</h1>
    <p k-id="about.description">Siamo leader nel settore</p>
    <a k-id="about.cta_link" href="/about">
        <span k-id="about.cta_text">Scopri di più</span>
    </a>
</section>
```

**JSON entity:**
```json
{
    "id": 0,
    "name": "about",
    "data": [
        {
            "name": "title",
            "type": "text",
            "value": {"it": "La nostra azienda", "en": "Our company"}
        },
        {
            "name": "description",
            "type": "text",
            "value": {"it": "Siamo leader", "en": "We are leaders"}
        },
        {
            "name": "cta_text",
            "type": "text",
            "value": {"it": "Scopri di più", "en": "Learn more"}
        },
        {
            "name": "cta_link",
            "type": "path",
            "value": {"it": "/chi-siamo", "en": "/about"}
        }
    ]
}
```

## Uso
```
?page=homepage&ln=it
```

Lingua di fallback: `en`