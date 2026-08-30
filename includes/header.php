<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GLOBAL SAYFA BAŞLIĞI (HEADER)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Giriş denetimi
if (!is_logged_in()) {
    redirect(BASE_URL . '/modules/auth/login.php');
}

$user = current_user();
?>
<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'Yönetim Paneli' ?> | <?= APP_NAME ?></title>
    
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
                    <?= $page_title ?? 'Yönetim Paneli' ?>
                </h2>
            </div>

            <!-- Sağ: Hızlı Eylemler & Kullanıcı Profili -->
            <div class="flex items-center space-x-4">
                
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
                        <div class="py-1">
                            <a href="<?= BASE_URL ?>/modules/settings/index.php" class="flex items-center px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                <i data-lucide="settings" class="w-4 h-4 mr-2 text-slate-400"></i> Sistem Ayarları
                            </a>
                        </div>
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