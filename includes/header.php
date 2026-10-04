<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GLOBAL SAYFA BAŞLIĞI (HEADER)
 * ====================================================================
 */

// Çıktı tamponlama: header.php'den sonra çalışan POST işleyicilerinin
// yönlendirmeleri (Location) "headers already sent" hatasına düşmesin.
// (Sunucudaki output_buffering=4096 tamponu sayfa büyüyünce boşaltıldığı için
// her durumda sınırsız boyutlu ayrı bir tampon açılır.)
ob_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Giriş denetimi
if (!is_logged_in()) {
    redirect(BASE_URL . '/modules/auth/login.php');
}

// Kullanıcı durumu ve izinleri her istekte veritabanından tazelenir
refresh_staff_session();
$user = current_user();
$notif = get_notifications((int)$user['id'], 8);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <?php ui_head(html_entity_decode($page_title ?? 'Panel', ENT_QUOTES, 'UTF-8'), ['chart' => true]); ?>
</head>
<body x-data="{ nav: false }">
<div class="shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:10px;min-width:0">
                <button @click="nav = true" class="icon-btn lg:hidden" aria-label="Menü"><i data-lucide="menu"></i></button>
                <span class="topbar-title"><?= e(html_entity_decode($page_title ?? 'Panel', ENT_QUOTES, 'UTF-8')) ?></span>
            </div>

            <div style="display:flex;align-items:center;gap:6px">
                <form method="GET" action="<?= BASE_URL ?>/modules/search/index.php" class="searchbox hidden md:block">
                    <i data-lucide="search"></i>
                    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Proje, cari, fatura, iş kodu ara">
                </form>

                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="icon-btn" aria-label="Bildirimler">
                        <i data-lucide="bell"></i>
                        <?php if ($notif['unread'] > 0): ?><span class="dot-count"><?= $notif['unread'] > 9 ? '9+' : $notif['unread'] ?></span><?php endif; ?>
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak class="menu" style="right:0;top:42px;width:340px;padding:0">
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line-2)">
                            <span class="h3">Bildirimler</span>
                            <a href="<?= BASE_URL ?>/modules/notifications/index.php" class="small link">Tümü</a>
                        </div>
                        <div style="max-height:360px;overflow-y:auto" class="divide">
                            <?php if (empty($notif['items'])): ?>
                                <p class="small text-muted" style="padding:28px 14px;text-align:center">Yeni bildirim yok.</p>
                            <?php else: foreach ($notif['items'] as $n): ?>
                                <a href="<?= BASE_URL . e($n['link'] ?: '/modules/notifications/index.php') ?>" style="display:block;padding:11px 14px;<?= $n['is_unread'] ? 'background:#FBF8F4' : '' ?>">
                                    <p class="small text-ink" style="line-height:1.45"><?= e($n['message']) ?></p>
                                    <p class="xsmall text-muted" style="margin-top:3px"><?= e($n['actor_name'] ?? '') ?> · <?= time_ago($n['created_at']) ?></p>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>

                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" style="display:flex;align-items:center;gap:9px;padding:4px 6px 4px 4px;border-radius:9px" class="hover:bg-slate-100">
                        <?= ui_avatar($user['full_name']) ?>
                        <span class="hidden md:block" style="text-align:left;line-height:1.2">
                            <span class="small" style="display:block;font-weight:500"><?= e($user['full_name']) ?></span>
                            <span class="xsmall text-muted"><?= e($user['role_name']) ?></span>
                        </span>
                        <i data-lucide="chevron-down" style="width:14px;height:14px;color:var(--muted)"></i>
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak class="menu" style="right:0;top:46px">
                        <div style="padding:8px 9px 10px">
                            <p class="small" style="font-weight:500"><?= e($user['full_name']) ?></p>
                            <p class="xsmall text-muted"><?= e($user['email']) ?></p>
                        </div>
                        <div class="menu-sep"></div>
                        <?php if (has_permission('settings.manage')): ?>
                            <a href="<?= BASE_URL ?>/modules/settings/index.php" class="menu-item"><i data-lucide="settings-2"></i>Ayarlar</a>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/modules/notifications/index.php" class="menu-item"><i data-lucide="activity"></i>Aktivite</a>
                        <div class="menu-sep"></div>
                        <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="menu-item"><i data-lucide="log-out"></i>Çıkış yap</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="content">
            <div class="page">
                <?= display_flash() ?>
