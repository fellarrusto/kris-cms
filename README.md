# 🚀 Kris 2 CMS - Builder's Guide

**Philosophy:** You write standard HTML for your design, and use the Admin Panel to manage the text and images. No database installation required.

-----

## Installation

1. Clone the repository.
2. Install dependencies:
```bash
composer install
```

*Note for shared hosting:* If you can't run Composer on your server, run it locally and upload the `vendor/` folder via FTP.
3. Configure your web server to point to the project root.
4. Visit `/editor/` to manage content.

**Requirements:**

* PHP >= 8.0
* Composer

---

## 1. The Workflow

1. **Design:** Create your HTML files in the `template/` folder.
2. **Define:** Create data entries in the Admin Panel (or `data/k_data.json`).
3. **Connect:** Use "Magic Tags" in your HTML to pull that data in.

---

## 2. Displaying Simple Content

To show text or an image, use double curly braces `{{ }}`. The name inside must match the **name** you gave the item in your data.

**In your HTML:**

```html
<h1>{{page_title}}</h1>

<img src="{{logo}}" alt="My Logo">

```

*Note: The system automatically switches between English and Italian based on the user's selection.*

---

## 3. Reusable Components (Header/Footer)

If you have a piece of code you want on every page (like a Navbar), don't copy-paste it. Save it as a separate file (e.g., `navbar.html`) and inject it.

**How to inject:**

```html
<header k-component="navbar" k-template="navbar" k-index="0"></header>

```

* `k-component`: The group name in your data.
* `k-template`: The filename of your HTML template.
* `k-index`: Usually `0` (unless you have multiple versions of a navbar).

---

## 4. Lists and Grids (The "Loop")

To create a list of items (like Features, Blog Posts, or Team Members), you don't need to duplicate HTML code. Create **one** HTML card, and the system will repeat it for every item in your data.

1. Create a snippet file (e.g., `feature-card.html`).
2. In your main page, use `k-array`:

```html
<div class="grid-container" k-array="feature" k-template="feature-card"></div>

```

---

## 4b. Nested Lists (k-array dentro k-array)

Le liste `k-array` possono essere **innestate**: un campo di un'entità può contenere a sua volta una lista di sotto-entità. Utile quando l'appartenenza è gerarchica (es. categorie → prodotti) e vuoi gestire i figli direttamente nel contesto del padre, senza filtri globali.

**Schema (`k_model.json`):** dichiara un campo di tipo `array` con `of` (schema dei figli).

```json
"product_category": [
  { "name": "title", "type": "text" },
  { "name": "products", "type": "array", "of": [
      { "name": "name", "type": "text" },
      { "name": "price", "type": "plain" }
  ]}
]
```

**Dati (`k_data.json`):** i figli vivono nel valore del campo `array`.

```json
{
  "name": "products", "type": "array", "value": [
    { "id": 0, "data": [ { "name": "name", "type": "text", "value": {"it":"T-shirt","en":"T-shirt"} }, ... ] }
  ]
}
```

**Template:** dentro il template del padre usa un altro `k-array` che punta al nome del campo locale. Il motore lo risolve **prima localmente** (nel contesto dell'entità corrente) e, se non trova nulla, fa fallback alla lookup globale — quindi i template esistenti continuano a funzionare.

```html
<!-- category-card.html -->
<div class="category">
    <h3>{{title}}</h3>
    <div k-array="products" k-template="product-card"></div>
</div>
```

Nell'Admin: aprendo una categoria trovi i prodotti come sotto-lista, con pulsanti Edit / ✕ / + Nuovo. La navigazione a più livelli è supportata tramite il parametro `path` (es. `?action=edit&group=product_category&id=0&path=products/1`). La modifica dello schema `of` al momento va fatta a mano su `k_model.json`.

---

## 5. Smart Logic (If/Else/Has)

You can change the design based on the data. Kris 2 supports standard operators and the `has` operator for comma-separated tags.

**Standard Check:**

```html
{{#if category == "new"}}
    <span class="badge">New Arrival!</span>
{{/if}}

```

**Tag Filtering (Has):**
Checks if a specific value exists in a comma-separated string (e.g., `tags: "tech, ui"`).

```html
{{#if tags has "tech"}}
    <span class="icon-tech"></span>
{{/if}}

```

---

## 6. Linking and Whitelisting

To link to a specific detail page, use this URL structure:
`index.php?page=[TEMPLATE_NAME]&key=[DATA_GROUP]&id=[ID]`

**Important: Page Whitelist**
Every new HTML template must be authorized in `config/allowed_pages.json`, otherwise the system will return a 404 error.

```json
{
  "allowed_pages": [
    "homepage",
    "project-detail"
  ]
}

```

---

## 7. Client-Side Filtering

Kris 2 includes a native JS utility to filter items instantly without reloading the page.

1. **Add data-tags** to your card template:

```html
<div class="feature-item" data-tags="{{tags}}"> ... </div>

```

2. **Add Filter Buttons** in your main page:

```html
<button class="btn btn-primary" onclick="filterItems(this, 'tech', '.feature-item')">Tech</button>
<button class="btn btn-secondary" onclick="filterItems(this, '', '.feature-item')">All</button>

```

---

## 8. Managing Content (The Admin)

You don't need to touch code to change text.

1. Go to `/editor/` in your browser.
2. **Edit:** Click a group to see all items. Change text for both languages (IT/EN).
3. **Add New:** Click "Add New" to create a new blog post or feature. The system handles the ID automatically.
4. **Save:** Updates are instant.

---

## Project Structure

```
kris2/
├── core/
│   ├── entity/          # Data management classes
│   ├── template/        # Template engine
│   └── scripts/         # Core JS utilities (script.js)
├── data/
│   ├── k_data.json      # Content storage
│   └── k_model.json     # Content structure definitions
├── template/            # HTML templates
├── editor/              # Admin panel
├── config/              # Whitelists (allowed_pages.json)
└── vendor/              # Composer dependencies

```