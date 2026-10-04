<?php
/**
 * ====================================================================
 * PERSONEL PANELİ - YAN MENÜ
 * ====================================================================
 */

$current_page = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

function nav_link(string $href, string $icon, string $label, string|array $match, ?int $count = null): string {
    global $current_page;
    $active = false;
    foreach ((array)$match as $m) {
        $active = $active || str_contains($current_page, $m);
    }
    return '<a href="' . BASE_URL . $href . '" class="nav-item' . ($active ? ' is-active' : '') . '">'
        . '<i data-lucide="' . $icon . '"></i><span>' . e($label) . '</span>'
        . ($count ? '<span class="nav-count">' . $count . '</span>' : '') . '</a>';
}

$platform_pending = 0;
if (can_access_module('platform.manage')) {
    try {
        $platform_pending = (int)$db->query("SELECT (SELECT COUNT(*) FROM platform_jobs WHERE status IN ('submitted', 'qa_review')) + (SELECT COUNT(*) FROM freelancer_profiles WHERE status = 'pending') + (SELECT COUNT(*) FROM agency_profiles WHERE status = 'pending') + (SELECT COUNT(*) FROM platform_job_issues WHERE status = 'open')")->fetchColumn();
    } catch (Throwable $e) {
    }
    // İş akışı otomasyonları (saatte en fazla bir kez, yanıt gönderildikten sonra)
    if ((int)get_setting('platform_automation_last_run', '0') < time() - 3600) {
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                while (ob_get_level() > 0) { @ob_end_flush(); }
                @fastcgi_finish_request();
            }
            ignore_user_abort(true);
            try {
                platform_run_automations();
            } catch (Throwable $e) {
                error_log('Platform otomasyonu: ' . $e->getMessage());
            }
        });
    }
}
?>
<div x-show="nav" @click="nav = false" x-cloak class="lg:hidden" style="position:fixed;inset:0;z-index:70;background:rgba(21,21,23,.45)"></div>

<aside class="sidebar" :class="nav ? 'is-open' : ''">
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="sidebar-brand"><?= brand_html('dark', site_setting('brand_tagline')) ?></a>

    <nav class="sidebar-nav">
        <?= nav_link('/modules/dashboard/index.php', 'layout-grid', 'Genel bakış', '/modules/dashboard') ?>

        <?php if (can_access_module('platform.manage')): ?>
            <div class="nav-section">İş platformu</div>
            <?= nav_link('/modules/platform/index.php', 'inbox', 'İş merkezi', ['/modules/platform/index', '/modules/platform/job', '/modules/platform/settings', '/modules/platform/routing'], $platform_pending ?: null) ?>
            <?= nav_link('/modules/platform/freelancers.php', 'users-round', 'Freelancer\'lar', ['/modules/platform/freelancers', '/modules/platform/tiers']) ?>
            <?= nav_link('/modules/platform/agencies.php', 'building-2', 'Ajanslar', '/modules/platform/agencies') ?>
            <?php if (can_access_module('platform.pricing')): ?>
                <?= nav_link('/modules/platform/catalog.php', 'tags', 'Hizmet kataloğu', '/modules/platform/catalog') ?>
            <?php endif; ?>
        <?php endif; ?>

        <div class="nav-section">Prodüksiyon</div>
        <?php if (can_access_module('proposals.manage')): ?>
            <?= nav_link('/modules/proposals/index.php', 'file-signature', 'Teklifler', '/modules/proposals') ?>
        <?php endif; ?>
        <?php if (has_permission('projects.view')): ?>
            <?= nav_link('/modules/projects/index.php', 'clapperboard', 'Projeler', '/modules/projects') ?>
            <?= nav_link('/modules/calendar/index.php', 'calendar-days', 'Takvim', '/modules/calendar') ?>
            <?= nav_link('/modules/tasks/index.php', 'list-checks', 'Görevler', '/modules/tasks') ?>
        <?php endif; ?>
        <?php if (can_access_module('inventory.manage')): ?>
            <?= nav_link('/modules/inventory/index.php', 'camera', 'Ekipman', '/modules/inventory') ?>
        <?php endif; ?>

        <?php if (has_permission('contacts.view')): ?>
            <div class="nav-section">Cariler</div>
            <?= nav_link('/modules/contacts/index.php', 'contact-round', 'Müşteri ve tedarikçiler', '/modules/contacts') ?>
        <?php endif; ?>

        <?php if (has_permission('finance.invoices') || has_permission('finance.view') || has_permission('finance.taxes') || can_access_module('reports.view')): ?>
            <div class="nav-section">Finans</div>
            <?php if (has_permission('finance.invoices')): ?><?= nav_link('/modules/finance/invoices.php', 'receipt-text', 'Faturalar', '/modules/finance/invoices') ?><?php endif; ?>
            <?php if (has_permission('finance.view')): ?><?= nav_link('/modules/finance/accounts.php', 'landmark', 'Kasa ve banka', '/modules/finance/accounts') ?><?php endif; ?>
            <?php if (can_access_module('reports.view')): ?><?= nav_link('/modules/reports/index.php', 'chart-column', 'Raporlar', '/modules/reports') ?><?php endif; ?>
            <?php if (has_permission('finance.taxes')): ?><?= nav_link('/modules/taxes/index.php', 'percent', 'Vergi', '/modules/taxes') ?><?php endif; ?>
        <?php endif; ?>

        <?php $can_mail = can_access_module('mail.manage'); ?>
        <?php if (has_permission('personnel.manage') || has_permission('settings.manage') || $can_mail): ?>
            <div class="nav-section">Yönetim</div>
            <?php if ($can_mail): ?><?= nav_link('/modules/mail/index.php', 'mail', 'E-posta', '/modules/mail') ?><?php endif; ?>
            <?php if (has_permission('personnel.manage')): ?><?= nav_link('/modules/personnel/index.php', 'id-card', 'Personel', '/modules/personnel') ?><?php endif; ?>
            <?php if (has_permission('settings.manage')): ?>
                <?= nav_link('/modules/settings/roles.php', 'shield-check', 'Roller ve kullanıcılar', '/modules/settings/roles') ?>
                <?= nav_link('/modules/settings/index.php', 'settings-2', 'Ayarlar', '/modules/settings/index') ?>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-foot">
        <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="nav-item" style="margin:0"><i data-lucide="log-out"></i><span>Çıkış yap</span></a>
    </div>
</aside>
