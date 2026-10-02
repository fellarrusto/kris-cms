<?php
declare(strict_types=1);

/**
 * Canonical, hreflang e sitemap; raccolta riservata Privacy e cookie.
 */

use Kris\Template\Seo;

const SEO_BASE = 'https://www.esempio.it';

test('l URL canonico omette i parametri predefiniti', function () {
    assertSame('https://www.esempio.it/', Seo::url(SEO_BASE, 'homepage', 'homepage', 0, [], 'it', 'it'));
    assertSame('https://www.esempio.it/?ln=en', Seo::url(SEO_BASE, 'homepage', 'homepage', 0, [], 'en', 'it'));
    assertSame('https://www.esempio.it/?page=post&path=posts/2&ln=en', Seo::url(SEO_BASE, 'post', 'homepage', 0, ['posts', '2'], 'en', 'it'));
    assertSame('https://www.esempio.it/?page=progetto&key=progetti&id=3', Seo::url(SEO_BASE, 'progetto', 'progetti', 3, [], 'it', 'it'));
});

test('canonical e hreflang per ogni lingua, con x-default sulla principale', function () {
    $tags = Seo::headTags(SEO_BASE, 'homepage', 'homepage', 0, [], 'en', ['it', 'en']);
    assertContains('<link rel="canonical" href="https://www.esempio.it/?ln=en">', $tags);
    assertContains('hreflang="it" href="https://www.esempio.it/"', $tags);
    assertContains('hreflang="en" href="https://www.esempio.it/?ln=en"', $tags);
    assertContains('hreflang="x-default" href="https://www.esempio.it/"', $tags);
});

test('con una sola lingua niente hreflang', function () {
    assertNotContains('hreflang', Seo::headTags(SEO_BASE, 'homepage', 'homepage', 0, [], 'it', ['it']));
});

test('i tag vanno prima di </head> e non doppiano un canonical del template', function () {
    $out = Seo::inject("<html><head><title>x</title></HEAD><body></body></html>", '<link rel="canonical" href="u">');
    assertContains('<link rel="canonical" href="u">' . "\n</HEAD>", $out);
    $own = '<html><head><link href="mio" rel=canonical></head></html>';
    assertSame($own, Seo::inject($own, '<link rel="canonical" href="u">'));
    assertSame('<p>frammento</p>', Seo::inject('<p>frammento</p>', '<link>'));
});

test('l indirizzo dalla richiesta scarta host non validi', function () {
    $config = ['base_url' => null];
    assertSame('https://sito.it/sotto', Seo::baseUrl($config, ['HTTPS' => 'on', 'HTTP_HOST' => 'sito.it', 'SCRIPT_NAME' => '/sotto/index.php']));
    assertSame('http://localhost', Seo::baseUrl($config, ['HTTP_HOST' => 'evil.com/"><script>', 'SCRIPT_NAME' => '/index.php']));
    assertSame('https://fisso.it', Seo::baseUrl(['base_url' => 'https://fisso.it'], ['HTTP_HOST' => 'altro.it']));
});

test('la sitemap segue le regole: pagina singola, intera raccolta, elementi di una lista', function () {
    $data = [
        ['id' => 0, 'name' => 'homepage', 'data' => [['name' => 'posts', 'type' => 'array', 'value' => [['id' => 4, 'data' => []], ['id' => 7, 'data' => []]]]]],
        ['id' => 1, 'name' => 'progetti', 'data' => []],
        ['id' => 5, 'name' => 'progetti', 'data' => []],
    ];
    $rules = [
        ['page' => 'homepage'],
        ['page' => 'progetto', 'key' => 'progetti'],
        ['page' => 'post', 'key' => 'homepage', 'id' => 0, 'children' => 'posts'],
        ['page' => 'segreta'],                                   // non autorizzata
        ['page' => 'progetto', 'key' => 'progetti', 'id' => 9],  // inesistente
    ];
    $entries = Seo::sitemapEntries($data, $rules, ['homepage', 'progetto', 'post']);
    assertSame([
        ['homepage', 'homepage', 0, []],
        ['progetto', 'progetti', 1, []],
        ['progetto', 'progetti', 5, []],
        ['post', 'homepage', 0, ['posts', '4']],
        ['post', 'homepage', 0, ['posts', '7']],
    ], $entries);
});

test('la sitemap XML elenca ogni lingua con le alternative', function () {
    $xml = Seo::sitemapXml([['homepage', 'homepage', 0, []]], SEO_BASE, ['it', 'en']);
    $doc = new DOMDocument();
    assertTrue($doc->loadXML($xml), 'XML non valido');
    assertSame(2, $doc->getElementsByTagName('url')->length);
    assertContains('<loc>https://www.esempio.it/?ln=en</loc>', $xml);
    assertContains('<xhtml:link rel="alternate" hreflang="it" href="https://www.esempio.it/"/>', $xml);
});

test('sitemap.php risponde con l XML generato dai contenuti', function () {
    $out = runScript(projectSandbox(), 'sitemap.php');
    assertContains('<urlset', $out);
    assertContains('<loc>http://localhost/</loc>', $out);
});

test('la pagina pubblica ha canonical e hreflang', function () {
    $html = renderPage(['page' => 'homepage', 'ln' => 'en'])['output'];
    assertContains('<link rel="canonical" href="http://localhost/?ln=en">', $html);
    assertContains('hreflang="x-default"', $html);
});

// --- Privacy e cookie -------------------------------------------------------

/** Esegue la migrazione 0002 sui file dati indicati. */
function runLegalMigration(array $files): array
{
    $m = require KRIS_ROOT . '/kris/migrations/0002_privacy_e_cookie.php';
    return ($m['up'])($files);
}

test('0002 aggiunge la raccolta kris_legal con schema e contenuto vuoto', function () {
    $out = runLegalMigration(['k_data' => [], 'k_model' => [], 'cms_settings' => ['languages' => ['it', 'en']]]);
    $names = array_column($out['k_model']['kris_legal'], 'name');
    foreach (['owner_name', 'privacy_policy', 'cookie_policy', 'banner_text', 'banner_accept', 'banner_reject'] as $field) {
        assertTrue(in_array($field, $names, true), "manca {$field}");
    }
    assertSame('kris_legal', $out['k_data'][0]['name']);
    assertSame(0, $out['k_data'][0]['id']);
    $values = array_column($out['k_data'][0]['data'], 'value', 'name');
    assertSame(['it' => 'Accetta', 'en' => 'Accept'], $values['banner_accept']);
    assertSame(['it' => '', 'en' => ''], $values['privacy_policy']);
    assertSame('', $values['owner_name']);
});

test('0002 e idempotente e non tocca valori gia compilati', function () {
    $once = runLegalMigration(['k_data' => [], 'k_model' => [], 'cms_settings' => null]);
    foreach ($once['k_data'][0]['data'] as &$field) {
        if ($field['name'] === 'owner_name') $field['value'] = 'Rossi srl';
    }
    unset($field);
    $twice = runLegalMigration($once);
    assertSame(1, count($twice['k_data']));
    assertSame(count($once['k_model']['kris_legal']), count($twice['k_model']['kris_legal']));
    assertSame('Rossi srl', array_column($twice['k_data'][0]['data'], 'value', 'name')['owner_name']);
});

test('i template leggono kris_legal come un qualunque componente', function () {
    $entity = makeEntity([fieldText('banner_text', ['it' => 'Usiamo cookie', 'en' => 'We use cookies'])], 0, 'kris_legal');
    assertSame('<p>We use cookies</p>', trim(renderTemplate('<p>{{banner_text}}</p>', $entity, 'en')));
});
