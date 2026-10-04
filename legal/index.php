<?php
/**
 * ====================================================================
 * YASAL METİNLER (herkese açık)
 * ====================================================================
 * /legal/index.php            → metin listesi
 * /legal/index.php?d=kvkk     → tek metin (yazdırılabilir)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$slug = (string)($_GET['d'] ?? '');
$doc = $slug !== '' ? legal_doc($slug) : null;
if ($slug !== '' && !$doc) {
    http_response_code(404);
}

if (is_client_logged_in()) {
    $back = [portal_home_url($_SESSION['client_user']['role'] ?? 'client'), 'Panele dön'];
} elseif (is_logged_in()) {
    $back = [BASE_URL . '/modules/dashboard/index.php', 'Panele dön'];
} else {
    $back = [BASE_URL . '/client/login.php', 'Giriş yap'];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <?php ui_head($doc ? $doc['title'] : 'Yasal metinler'); ?>
</head>
<body class="legal-page">
<header class="legal-top no-print">
    <a href="<?= e($back[0]) ?>" class="legal-brand"><?= brand_html('light') ?></a>
    <a class="btn btn-ghost btn-sm" href="<?= e($back[0]) ?>"><i data-lucide="arrow-left"></i><?= e($back[1]) ?></a>
</header>

<div class="legal-wrap">
    <?php if ($doc): ?>
    <select class="select legal-jump no-print" aria-label="Metin seçin" onchange="location.href=this.value">
        <?php foreach (LEGAL_DOCS as $s => $m): ?><option value="<?= e(legal_url($s)) ?>" <?= $s === $slug ? 'selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <aside class="legal-nav no-print<?= $doc ? ' has-doc' : '' ?>">
        <p class="eyebrow" style="margin-bottom:10px">Yasal metinler</p>
        <?php foreach (LEGAL_DOCS as $s => $m): ?>
            <a href="<?= e(legal_url($s)) ?>" class="<?= $s === $slug ? 'is-active' : '' ?>"><?= e($m['title']) ?></a>
        <?php endforeach; ?>
    </aside>

    <main class="legal-main">
        <?php if ($doc): ?>
            <article class="legal-doc">
                <h1><?= e($doc['title']) ?></h1>
                <p class="legal-meta">
                    Sürüm <?= (int)$doc['version'] ?> · Yürürlük: <?= e(format_date($doc['published_at'])) ?>
                    <?php if ($doc['updated_at'] !== $doc['published_at']): ?> · Son düzenleme: <?= e(format_date($doc['updated_at'])) ?><?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm no-print" onclick="window.print()" style="margin-left:6px"><i data-lucide="printer"></i>Yazdır / PDF</button>
                </p>
                <?= legal_render($doc['body']) ?>
            </article>
        <?php elseif ($slug !== ''): ?>
            <?= ui_empty('Metin bulunamadı', 'Aradığınız yasal metin mevcut değil.', 'file-x') ?>
        <?php else: ?>
            <h1 class="h1">Yasal metinler</h1>
            <p class="small text-muted" style="margin-top:6px"><?= e(site_setting('company_name')) ?> tarafından işletilen platformun sözleşme ve politikaları.</p>
            <div class="legal-list">
                <?php foreach (LEGAL_DOCS as $s => $m): $d = legal_doc($s); ?>
                    <a href="<?= e(legal_url($s)) ?>" class="panel">
                        <span><strong><?= e($m['title']) ?></strong><span class="xsmall text-muted">Sürüm <?= (int)$d['version'] ?> · <?= e(format_date($d['published_at'])) ?></span></span>
                        <i data-lucide="chevron-right"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<footer class="legal-foot no-print">
    <span><?= e(site_footer_text()) ?></span>
    <?= legal_footer_links() ?>
</footer>
<?= legal_cookie_notice() ?>
<?php ui_icons_init(); ?>
</body>
</html>
