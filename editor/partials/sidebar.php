<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<aside class="sidebar" aria-label="Navigazione principale">
    <a class="brand" href="?action=dashboard"><span class="brand-mark">k<span>.</span></span><span>kris<small>CONTENT STUDIO</small></span></a>
    <div class="workspace-label"><span class="site-mark">K</span><div><strong>Il tuo sito</strong><small>Spazio di lavoro</small></div><span class="status-dot" aria-hidden="true"></span></div>
    <p class="nav-label">IL TUO SPAZIO</p>
    <nav>
        <a href="?action=dashboard" class="nav-item <?= in_array($action, ['dashboard','list','edit']) ? 'active' : '' ?>" <?= in_array($action, ['dashboard','list','edit']) ? 'aria-current="page"' : '' ?>><?= uiIcon('content') ?>Contenuti <span class="nav-count"><?= count($models) ?></span></a>
        <a href="?action=media" class="nav-item <?= $action === 'media' ? 'active' : '' ?>" <?= $action === 'media' ? 'aria-current="page"' : '' ?>><?= uiIcon('media') ?>Libreria media</a>
        <a href="?action=settings" class="nav-item <?= $action === 'settings' ? 'active' : '' ?>" <?= $action === 'settings' ? 'aria-current="page"' : '' ?>><?= uiIcon('settings') ?>Impostazioni</a>
    </nav>
    <?php if ($models): ?><div class="collection-nav"><p class="nav-label">LE TUE RACCOLTE</p><?php foreach ($models as $name => $_): ?><a href="?action=list&amp;group=<?= urlencode($name) ?>" class="collection-link <?= $group === $name ? 'selected' : '' ?>"><span><?= h(editorLabel($name)) ?></span><small><?= $counts[$name] ?? 0 ?></small></a><?php endforeach; ?></div><?php endif; ?>
    <div class="sidebar-bottom"><div class="help-card"><span class="eyebrow">UN PASSO ALLA VOLTA</span><p>I contenuti al centro.<br>Il resto, quando serve.</p></div><a href="?logout=1" class="logout-link">Esci dall’editor <span aria-hidden="true">&middot;</span></a></div>
</aside>
