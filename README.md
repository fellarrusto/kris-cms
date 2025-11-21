# 🚀 Kris 2 CMS - Builder's Guide

**Philosophy:** You write standard HTML for your design, and use the Admin Panel to manage the text and images. No database installation required.

-----

## Installation

1. Clone the repository
2. Install dependencies:
```bash
composer install
```
3. Configure your web server to point to the project root
4. Visit `/editor/` to manage content

**Requirements:**
- PHP >= 8.0
- Composer

-----

## 1\. The Workflow

1.  **Design:** Create your HTML files in the `template/` folder.
2.  **Define:** Create data entries in the Admin Panel (or `data/k_data.json`).
3.  **Connect:** Use "Magic Tags" in your HTML to pull that data in.

-----

## 2\. Displaying Simple Content

To show text or an image, use double curly braces `{{ }}`. The name inside must match the **name** you gave the item in your data.

**In your HTML:**
```html
<h1>{{page_title}}</h1>

<img src="{{logo}}" alt="My Logo">
```

*Note: The system automatically switches between English and Italian based on the user's selection.*

-----

## 3\. Reusable Components (Header/Footer)

If you have a piece of code you want on every page (like a Navbar), don't copy-paste it. Save it as a separate file (e.g., `navbar.html`) and inject it.

**How to inject:**
```html
<header k-component="navbar" k-template="navbar" k-index="0"></header>
```

  * `k-component`: The group name in your data.
  * `k-template`: The filename of your HTML template.
  * `k-index`: Usually `0` (unless you have multiple versions of a navbar).

-----

## 4\. Lists and Grids (The "Loop")

To create a list of items (like Features, Blog Posts, or Team Members), you don't need to duplicate HTML code. Create **one** HTML card, and the system will repeat it for every item in your data.

1.  Create a snippet file (e.g., `feature-card.html`).
2.  In your main page, use `k-array`:
```html
<div class="grid-container" k-array="feature" k-template="feature-card"></div>
```

-----

## 5\. Smart Logic (If/Else)

You can change the design based on the data.
```html
{{#if category == "new"}}
    <span class="badge-green">New Arrival!</span>
{{#elif category == "sale"}}
    <span class="badge-red">On Sale</span>
{{#else}}
    <span>Standard Item</span>
{{/if}}
```

-----

## 6\. Linking Pages

To link to a specific detail page (like a specific blog post), use this URL structure:

`index.php?page=[TEMPLATE_NAME]&key=[DATA_GROUP]&id=[ID]`

**Example:**
```html
<a href="index.php?page=detail&key=feature&id=1">Read More</a>
```

-----

## 7\. Managing Content (The Admin)

You don't need to touch code to change text.

1.  Go to `/editor/` in your browser.
2.  **Edit:** Click a group to see all items. Change text for both languages (IT/EN).
3.  **Add New:** Click "Add New" to create a new blog post or feature. The system handles the ID automatically.
4.  **Save:** Updates are instant.

-----

## Project Structure
```
kris2/
├── core/
│   ├── entity/          # Data management classes
│   └── template/        # Template engine
├── data/
│   ├── k_data.json      # Content storage
│   └── k_model.json     # Content structure definitions
├── template/            # HTML templates
├── editor/              # Admin panel
├── vendor/              # Composer dependencies (auto-generated)
└── composer.json        # Dependencies configuration
```

**Note:** The `vendor/` folder is auto-generated. Run `composer install` after cloning.