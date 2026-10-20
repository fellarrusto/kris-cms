<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<aside class="sidebar" aria-label="Navigazione principale">
    <a class="brand" href="?action=dashboard"><span class="brand-mark">k<span>.</span></span><span>kris<small>CONTENT STUDIO</small></span></a>
    <p class="nav-label">IL TUO SPAZIO</p>
    <nav>
        <?php $inContents = in_array($action, ['dashboard','list','edit']) && !$inPosts && !$inLegal && !$inSite; ?>
        <a href="?action=dashboard" class="nav-item <?= $inContents ? 'active' : '' ?>" <?= $inContents ? 'aria-current="page"' : '' ?>><?= uiIcon('content') ?>Contenuti <span class="nav-count"><?= count($userModels) ?></span></a>
        <?php if ($postSources): ?><a href="?action=posts" class="nav-item <?= $inPosts ? 'active' : '' ?>" <?= $inPosts ? 'aria-current="page"' : '' ?>><?= uiIcon('posts') ?>Posts <span class="nav-count"><?= count($postSources) ?></span></a><?php endif; ?>
        <a href="?action=media" class="nav-item <?= $action === 'media' ? 'active' : '' ?>" <?= $action === 'media' ? 'aria-current="page"' : '' ?>><?= uiIcon('media') ?>Libreria media</a>
        <?php if (isset($models['kris_legal'])): ?><a href="?action=edit&amp;group=kris_legal&amp;id=0" class="nav-item <?= $inLegal ? 'active' : '' ?>" <?= $inLegal ? 'aria-current="page"' : '' ?>><?= uiIcon('check') ?>Privacy e cookie</a><?php endif; ?>
        <?php if (isset($models['kris_site'])): ?><a href="?action=edit&amp;group=kris_site&amp;id=0" class="nav-item <?= $inSite ? 'active' : '' ?>" <?= $inSite ? 'aria-current="page"' : '' ?>><?= uiIcon('media') ?>Favicon</a><?php endif; ?>
        <?php if ($stagingVisible): ?><a href="?action=staging" class="nav-item <?= $action === 'staging' ? 'active' : '' ?>" <?= $action === 'staging' ? 'aria-current="page"' : '' ?>><?= uiIcon('structure') ?>Da pubblicare<?php if ($stagingCount): ?> <span class="nav-count"><?= $stagingCount ?></span><?php endif; ?></a><?php endif; ?>
        <a href="?action=settings" class="nav-item <?= $action === 'settings' ? 'active' : '' ?>" <?= $action === 'settings' ? 'aria-current="page"' : '' ?>><?= uiIcon('settings') ?>Impostazioni</a>
    </nav>
    <?php if ($userModels): ?><div class="collection-nav"><p class="nav-label">LE TUE RACCOLTE</p><?php foreach ($userModels as $name => $_): ?><a href="?action=list&amp;group=<?= urlencode($name) ?>" class="collection-link <?= $group === $name ? 'selected' : '' ?>"><span><?= h(collectionLabel($name)) ?></span><small><?= $counts[$name] ?? 0 ?></small></a><?php endforeach; ?></div><?php endif; ?>
    <div class="sidebar-bottom"><button type="button" class="theme-toggle" data-theme-toggle aria-live="polite">Tema: automatico</button><a href="?action=settings&amp;tab=versione" class="version-link" title="Versione di Kris installata">Kris <?= h($krisVersion) ?></a><a href="?logout=1" class="logout-link">Esci dall’editor <span aria-hidden="true">&middot;</span></a></div>
</aside>
