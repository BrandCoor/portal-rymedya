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
<html lang="tr" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(html_entity_decode($page_title ?? 'Yönetim Paneli', ENT_QUOTES, 'UTF-8')) ?> | <?= APP_NAME ?></title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            900: '#4c1d95',
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- Alpine.js (Modal ve dropdown etkileşimleri için) -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="h-full flex overflow-hidden text-slate-800 font-sans antialiased" x-data="{ sidebarOpen: false }">

    <!-- SOL MENÜ (SIDEBAR) -->
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- SAĞ ANA İÇERİK ALANI -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-100">
        
        <!-- ÜST BAR (TOPBAR) -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-10">
            
            <!-- Sol: Mobil Menü Butonu & Sayfa Başlığı -->
            <div class="flex items-center space-x-3">
                <button @click="sidebarOpen = true" class="lg:hidden p-2 rounded-lg text-slate-600 hover:bg-slate-100 focus:outline-none">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>
                <h2 class="text-lg font-bold text-slate-800 tracking-tight flex items-center gap-2">
                    <?= e(html_entity_decode($page_title ?? 'Yönetim Paneli', ENT_QUOTES, 'UTF-8')) ?>
                </h2>
            </div>

            <!-- Sağ: Hızlı Eylemler & Kullanıcı Profili -->
            <div class="flex items-center space-x-4">

                <!-- Global Arama -->
                <form method="GET" action="<?= BASE_URL ?>/modules/search/index.php" class="hidden md:block">
                    <div class="relative">
                        <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                        <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Proje, cari, fatura, teklif ara..."
                               class="w-64 pl-9 pr-3 py-2 bg-slate-100 border border-slate-200 rounded-xl text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>
                </form>

                <!-- Bildirimler -->
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="relative p-2 rounded-xl text-slate-500 hover:bg-slate-100 focus:outline-none" title="Bildirimler">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                        <?php if ($notif['unread'] > 0): ?>
                            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center"><?= $notif['unread'] > 9 ? '9+' : $notif['unread'] ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak class="origin-top-right absolute right-0 mt-2 w-80 rounded-2xl shadow-xl bg-white ring-1 ring-black/5 z-50 overflow-hidden">
                        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-800">Bildirimler</span>
                            <a href="<?= BASE_URL ?>/modules/notifications/index.php" class="text-[11px] font-bold text-brand-600 hover:underline">Tümünü Gör</a>
                        </div>
                        <div class="max-h-80 overflow-y-auto divide-y divide-slate-100">
                            <?php if (empty($notif['items'])): ?>
                                <p class="px-4 py-6 text-center text-xs text-slate-400">Henüz bildirim yok.</p>
                            <?php else: foreach ($notif['items'] as $n): ?>
                                <a href="<?= BASE_URL . e($n['link'] ?: '/modules/notifications/index.php') ?>" class="block px-4 py-3 hover:bg-slate-50 <?= $n['is_unread'] ? 'bg-brand-50/60' : '' ?>">
                                    <p class="text-xs text-slate-800 leading-snug"><?= e($n['message']) ?></p>
                                    <p class="text-[10px] text-slate-400 mt-1"><?= e($n['actor_name'] ?? '') ?> · <?= time_ago($n['created_at']) ?></p>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tarih Badge -->
                <div class="hidden sm:flex items-center space-x-2 text-xs font-medium text-slate-500 bg-slate-100 py-1.5 px-3 rounded-lg border border-slate-200">
                    <i data-lucide="calendar" class="w-4 h-4 text-brand-600"></i>
                    <span><?= format_date(date('Y-m-d')) ?></span>
                </div>

                <!-- Kullanıcı Dropdown Menüsü -->
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="flex items-center space-x-3 focus:outline-none p-1 rounded-xl hover:bg-slate-100 transition">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-600 flex items-center justify-center text-white font-bold text-sm shadow-md">
                            <?= strtoupper(mb_substr($user['full_name'], 0, 1, 'UTF-8')) ?>
                        </div>
                        <div class="hidden md:block text-left">
                            <p class="text-xs font-semibold text-slate-800 leading-tight"><?= e($user['full_name']) ?></p>
                            <p class="text-[11px] text-slate-500 font-medium"><?= e($user['role_name']) ?></p>
                        </div>
                        <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400"></i>
                    </button>

                    <!-- Dropdown Menü -->
                    <div x-show="open" @click.away="open = false" x-cloak
                         class="origin-top-right absolute right-0 mt-2 w-52 rounded-2xl shadow-xl bg-white ring-1 ring-black/5 divide-y divide-slate-100 py-1.5 z-50">
                        <div class="px-4 py-2.5">
                            <p class="text-xs text-slate-500">Giriş Yapıldı</p>
                            <p class="text-xs font-bold text-slate-800 truncate"><?= e($user['email']) ?></p>
                        </div>
                        <?php if (has_permission('settings.manage')): ?>
                        <div class="py-1">
                            <a href="<?= BASE_URL ?>/modules/settings/index.php" class="flex items-center px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                <i data-lucide="settings" class="w-4 h-4 mr-2 text-slate-400"></i> Sistem Ayarları
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="py-1">
                            <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="flex items-center px-4 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50">
                                <i data-lucide="log-out" class="w-4 h-4 mr-2 text-rose-500"></i> Güvenli Çıkış
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- DİNAMİK İÇERİK BAŞLANGICI (SCROLLABLE) -->
        <main class="flex-1 overflow-y-auto p-4 lg:p-8">
            <!-- Flash Bildirim Alanı -->
            <?= display_flash() ?>