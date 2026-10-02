<?php
// Sitemap del sito, generata dal framework in kris/: questo file resta fisso.
if (is_file(__DIR__ . '/kris/sitemap.php')) {
    require __DIR__ . '/kris/sitemap.php';
} else {
    http_response_code(404);
}
