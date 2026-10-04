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
    $back = [portal_home_url($_SESSION['client_user']['role'] ?? 'client'), 'Panele dön', 'layout-dashboard'];
} elseif (is_logged_in()) {
    $back = [BASE_URL . '/modules/dashboard/index.php', 'Panele dön', 'layout-dashboard'];
} else {
    $back = [BASE_URL . '/client/login.php', 'Giriş yap', 'log-in'];
}
$icons = [
    'kullanim-kosullari' => 'file-text', 'ajans-sozlesmesi' => 'building-2', 'freelancer-sozlesmesi' => 'user-round-check',
    'kvkk' => 'shield-check', 'acik-riza' => 'circle-check', 'ticari-ileti' => 'mail', 'gizlilik' => 'lock-keyhole',
    'cerez' => 'cookie', 'mesafeli' => 'credit-card', 'iptal-iade' => 'rotate-ccw', 'iletisim' => 'phone',
];
$toc = $doc ? legal_toc($doc['body']) : [];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <?php ui_head($doc ? $doc['title'] : 'Yasal metinler'); ?>
</head>
<body>
<div class="lg">
    <header class="lg-top">
        <div class="lg-top-in">
            <a href="<?= e(BASE_URL . '/legal/index.php') ?>" class="lg-brand"><?= brand_html('light') ?></a>
            <a class="btn btn-secondary btn-sm" href="<?= e($back[0]) ?>"><i data-lucide="<?= $back[2] ?>"></i><?= e($back[1]) ?></a>
        </div>
    </header>

    <section class="lg-hero">
        <div class="lg-hero-in">
            <p class="eyebrow"><?= $doc ? '<a href="' . e(BASE_URL . '/legal/index.php') . '" style="color:inherit">Yasal metinler</a>' : 'Sözleşmeler ve politikalar' ?></p>
            <h1><?= e($doc ? $doc['title'] : 'Yasal metinler') ?></h1>
            <div class="lg-meta">
                <?php if ($doc): ?>
                    <span class="pill"><i data-lucide="file-badge"></i>Sürüm <?= (int)$doc['version'] ?></span>
                    <span class="pill"><i data-lucide="calendar"></i>Yürürlük <?= e(format_date($doc['published_at'])) ?></span>
                    <?php if ($doc['updated_at'] !== $doc['published_at']): ?><span>Son düzenleme <?= e(format_date($doc['updated_at'])) ?></span><?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm no-print" onclick="window.print()"><i data-lucide="printer"></i>Yazdır / PDF</button>
                <?php else: ?>
                    <span><?= e(site_setting('company_name')) ?> tarafından işletilen platformun sözleşme, KVKK ve politika metinleri.</span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="lg-body">
        <?php if ($doc): ?>
        <aside class="lg-side no-print">
            <?php if (count($toc) > 1): ?>
            <nav class="lg-box lg-toc" aria-label="Bu sayfada">
                <p class="lg-box-title">Bu sayfada</p>
                <?php foreach ($toc as [$id, $t]): ?><a href="#<?= e($id) ?>"><?= e($t) ?></a><?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <nav class="lg-box lg-nav" aria-label="Yasal metinler">
                <p class="lg-box-title">Diğer metinler</p>
                <?php foreach (LEGAL_DOCS as $s => $m): ?>
                    <a href="<?= e(legal_url($s)) ?>" class="<?= $s === $slug ? 'is-active' : '' ?>"><?= e($m['title']) ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <main style="min-width:0;display:flex;flex-direction:column;gap:14px">
            <select class="select lg-jump no-print" aria-label="Metin seçin" onchange="location.href=this.value">
                <?php foreach (LEGAL_DOCS as $s => $m): ?><option value="<?= e(legal_url($s)) ?>" <?= $s === $slug ? 'selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?>
            </select>
            <article class="lg-doc">
                <div class="lg-prose"><?= legal_render($doc['body']) ?></div>
            </article>
        </main>
        <?php elseif ($slug !== ''): ?>
        <main style="grid-column:1/-1"><div class="card"><?= ui_empty('Metin bulunamadı', 'Aradığınız yasal metin mevcut değil.', 'file-x', '<a class="btn btn-secondary btn-sm" href="' . e(BASE_URL . '/legal/index.php') . '">Tüm metinler</a>') ?></div></main>
        <?php else: ?>
        <main style="grid-column:1/-1">
            <div class="lg-cards">
                <?php foreach (LEGAL_DOCS as $s => $m): $d = legal_doc($s); ?>
                    <a href="<?= e(legal_url($s)) ?>" class="lg-card">
                        <span class="ic"><i data-lucide="<?= e($icons[$s] ?? 'file-text') ?>"></i></span>
                        <span style="margin:0;min-width:0"><strong><?= e($m['title']) ?></strong><span>Sürüm <?= (int)$d['version'] ?> · <?= e(format_date($d['published_at'])) ?></span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </main>
        <?php endif; ?>
    </div>

    <footer class="lg-foot no-print">
        <div class="lg-foot-in">
            <span><?= e(site_footer_text()) ?></span>
            <?= legal_footer_links() ?>
        </div>
    </footer>
</div>
<?= legal_cookie_notice() ?>
<?php ui_icons_init(); ?>
</body>
</html>
