# Kris CMS

Kris CMS is a lightweight content management system for static HTML templates. It lets you edit text, images and videos directly on the page and stores the data in a single JSON file.

## Features

- **In‑place editing** of any element marked with `k-edit`.
- **Repeatable components** using `k-component` and external template files.
- **Image and video management** with automatic uploads to the `src/` folder.
- **Two languages** (Italian and English) stored inside `k_data.json`.
- No database required and a simple authentication system.

## Installation

1. Clone this repository and place it in a directory served by PHP.
2. Ensure the `src` folder is writable so that uploaded images and videos can be stored.
3. Start the built in server with:

   ```bash
   php -S localhost:8000
   ```

   or use any other web server that can run PHP files.

## Launching the editor

1. Open `/editor/signin.php` in your browser and log in with **Admin** / **password**.
2. After logging in you are redirected to `/editor/index.php` which loads the selected template with an editing overlay.
3. Click on elements highlighted by the overlay to change their content.

## JSON data format

All editable content is stored in `k_data.json`. Each element in your templates references an entry by its `k-id` attribute.

- **Text**

  ```json
  "title": {
    "en": "Welcome",
    "it": "Benvenuto"
  }
  ```

- **Links and buttons** (include an `action` URL)

  ```json
  "button-git": {
    "en": "GitHub Repository",
    "it": "Repository GitHub",
    "action": "https://github.com/fellarrusto/kris-cms"
  }
  ```

- **Images**

  ```json
  "logo": {
    "src": "/src/logo.svg"
  }
  ```

- **Videos**

  ```json
  "intro-video": {
    "src": "/src/intro.mp4"
  }
  ```

- **Repeatable items**

  ```json
  "block-1": {
    "card-1": {
      "title": { "en": "Lightweight", "it": "Leggero" },
      "desc": { "en": "Few files", "it": "Pochi file" }
    }
  }
  ```

## Creating templates

Mark elements you want to edit with the `k-edit` attribute and give them a unique `k-id` that matches an entry in `k_data.json`:

```html
<h1 k-edit k-id="title">Welcome to Kris CMS</h1>
<img k-edit k-id="logo" src="/src/logo.svg" alt="Kris Logo">
```

To repeat structures use `k-component` pointing to a component template:

```html
<div k-component k-template="block" k-id="block-1"></div>
```

The file `templates/components/block.html` defines the markup that will be repeated for each item stored under `block-1` in `k_data.json`.

## Video support

Videos can be referenced in the JSON data like images. Each editable `<video>` (or `<iframe>`) element must have a `k-id` attribute and its `src` is taken from `k_data.json`. Repeatable items can also contain video objects. The editor provides a field to update the video URL which updates the `src` value in the JSON file.
