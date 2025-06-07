# Kris CMS

Kris CMS is a lightweight content management system that lets you edit HTML templates directly in place.

## Video Support

Videos can be referenced in the JSON data like images. Each editable `<video>` (or `<iframe>`) element must have a `k-id` attribute and its `src` is taken from `k_data.json`.

Example:

```json
{
  "intro-video": {
    "src": "/src/intro.mp4"
  }
}
```

Repeatable items can also contain a `video` object:

```json
{
  "block-1": {
    "card-1": {
      "title": { "it": "Titolo", "en": "Title" },
      "video": { "src": "https://example.com/movie.mp4" }
    }
  }
}
```

The editor provides a simple field to update the video URL which updates the `src` value in `k_data.json`.
