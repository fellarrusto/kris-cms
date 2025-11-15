# Kris 2 CMS

Transform your static HTML site into a dynamic multilingual site managed through JSON.

## Base Setup

1. Add core files to your structure:
```
/core
  /entity
  /template
  /scripts
/data
  k_data.json
/template
index.php
```

2. Create your JSON file in `data/k_data.json`:
```json
[
  {
    "id": 0,
    "name": "homepage",
    "data": [
      {
        "name": "page_title",
        "type": "text",
        "value": {
          "it": "Titolo",
          "en": "Title"
        }
      }
    ]
  }
]
```

## Make Your HTML Dynamic

### Simple text
```html
<h1 k-id="page_title">Static title</h1>
```

### Images
```html
<img k-id="logo" src="placeholder.jpg" alt="Logo">
```

### Links
```html
<a k-id="link_download" href="#">Download</a>
```

### Paths (for href/src)
In JSON use type `"path"`:
```json
{
  "name": "logo",
  "type": "path",
  "value": {
    "it": "assets/img/logo.svg",
    "en": "assets/img/logo.svg"
  }
}
```

### Reusable components
```html
<header k-component="navbar" k-template="navbar" k-index="0"></header>
```

### Dynamic lists
```html
<div k-array="feature" k-template="feature-card"></div>
```

Each entity with the same `name` must be indexed by an integer incremental `id`:
```json
[
  {
    "id": 0,
    "name": "feature",
    "data": [...]
  },
  {
    "id": 1,
    "name": "feature",
    "data": [...]
  },
  {
    "id": 2,
    "name": "feature",
    "data": [...]
  }
]
```

## Routing

```
index.php?page=homepage&key=homepage&id=0&ln=en
```

- `page`: template to use
- `key`: entity name in JSON
- `id`: entity index
- `ln`: language (it/en)

## Multilingual

You can use the function setLanguage(ln) to change website language:
```html
<a onclick="setLanguage('en')">EN</a>
<a onclick="setLanguage('it')">IT</a>
```

Include the core script:
```html
<script src="core/scripts/script.js"></script>
```