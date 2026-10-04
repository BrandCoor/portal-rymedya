<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - DİNAMİK SOL MENÜ (SIDEBAR)
 * ====================================================================
 */

$current_page = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

function is_active(string $path): string {
    global $current_page;
    return str_contains($current_page, $path) 
        ? 'bg-brand-600 text-white font-semibold shadow-md shadow-brand-600/30' 
        : 'text-slate-400 hover:text-white hover:bg-slate-800';
}
?>

<div x-show="sidebarOpen" @click="sidebarOpen = false" x-cloak class="fixed inset-0 z-40 bg-slate-900/80 backdrop-blur-sm lg:hidden"></div>

<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 flex flex-col transition-transform duration-300 ease-in-out lg:static lg:inset-auto">
    
    <!-- Logo Alanı -->
    <div class="h-16 flex items-center justify-between px-6 bg-slate-950/60 border-b border-slate-800">
        <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="flex items-center space-x-3">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center text-white shadow-lg shadow-brand-600/30">
                <i data-lucide="video" class="w-5 h-5"></i>
            </div>
            <div>
                <span class="text-sm font-bold text-white tracking-wide"><?= APP_NAME ?></span>
                <span class="block text-[10px] text-brand-400 font-semibold tracking-wider uppercase">Ajans Portalı</span>
            </div>
        </a>
        <button @click="sidebarOpen = false" class="lg:hidden text-slate-400 hover:text-white">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>

    <!-- Menü Linkleri -->
    <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto">
        
        <!-- DASHBOARD -->
        <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/dashboard') ?>">
            <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3"></i>
            <span>Kontrol Paneli</span>
        </a>

        <!-- İŞ PLATFORMU (AJANS & FREELANCER PAZARYERİ) -->
        <?php if (can_access_module('platform.manage')):
            $platform_pending = 0;
            try {
                $platform_pending = (int)$GLOBALS['db']->query("SELECT (SELECT COUNT(*) FROM platform_jobs WHERE status IN ('submitted', 'qa_review')) + (SELECT COUNT(*) FROM freelancer_profiles WHERE status = 'pending') + (SELECT COUNT(*) FROM agency_profiles WHERE status = 'pending')")->fetchColumn();
            } catch (Throwable $e) {
            }
        ?>
        <div class="pt-4 pb-1">
            <p class="px-3.5 text-[10px] font-bold text-slate-500 uppercase tracking-wider">İş Platformu</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/platform/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= str_contains($current_page, '/modules/platform/index') || str_contains($current_page, '/modules/platform/job') ? 'bg-brand-600 text-white font-semibold shadow-md shadow-brand-600/30' : 'text-slate-400 hover:text-white hover:bg-slate-800' ?>">
            <i data-lucide="network" class="w-4 h-4 mr-3 text-fuchsia-400"></i>
            <span class="flex-1">İş Merkezi</span>
            <?php if ($platform_pending > 0): ?><span class="ml-2 min-w-[20px] h-5 px-1.5 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center"><?= $platform_pending ?></span><?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/platform/freelancers.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/platform/freelancers') ?>">
            <i data-lucide="user-round-search" class="w-4 h-4 mr-3"></i>
            <span>Freelancer Havuzu</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/platform/agencies.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/platform/agencies') ?>">
            <i data-lucide="building-2" class="w-4 h-4 mr-3"></i>
            <span>Ajanslar</span>
        </a>
        <?php endif; ?>

        <!-- SATIŞ & PIPELINE BÖLÜMÜ -->
        <div class="pt-4 pb-1">
            <p class="px-3.5 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Satış & Teklifler</p>
        </div>

        <?php if (can_access_module('proposals.manage')): ?>
        <a href="<?= BASE_URL ?>/modules/proposals/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/proposals') ?>">
            <i data-lucide="kanban" class="w-4 h-4 mr-3 text-indigo-400"></i>
            <span>Teklifler & Pipeline</span>
        </a>
        <?php endif; ?>

        <!-- PRODÜKSİYON BÖLÜMÜ -->
        <div class="pt-4 pb-1">
            <p class="px-3.5 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Prodüksiyon Yönetimi</p>
        </div>

        <?php if (has_permission('projects.view')): ?>
        <a href="<?= BASE_URL ?>/modules/projects/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/projects') ?>">
            <i data-lucide="film" class="w-4 h-4 mr-3"></i>
            <span>Projeler & Çekimler</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/calendar/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/calendar') ?>">
            <i data-lucide="calendar-days" class="w-4 h-4 mr-3 text-sky-400"></i>
            <span>Prodüksiyon Takvimi</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/tasks/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/tasks') ?>">
            <i data-lucide="list-checks" class="w-4 h-4 mr-3 text-emerald-400"></i>
            <span>Görevler</span>
        </a>
        <?php endif; ?>

        <!-- EKİPMAN & ENVANTER -->
        <?php if (can_access_module('inventory.manage')): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/inventory') ?>">
            <i data-lucide="camera" class="w-4 h-4 mr-3 text-amber-400"></i>
            <span>Ekipman & Demirbaş</span>
        </a>
        <?php endif; ?>

        <!-- CARİ HESAPLAR -->
        <div class="pt-4 pb-1">
            <p class="px-3.5 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Cari & Rehber</p>
        </div>

        <?php if (has_permission('contacts.view')): ?>
        <a href="<?= BASE_URL ?>/modules/contacts/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/contacts') ?>">
            <i data-lucide="users" class="w-4 h-4 mr-3"></i>
            <span>Müşteri & Dış Ekipler</span>
        </a>
        <?php endif; ?>

        <!-- FİNANS & MUHASEBE -->
        <div class="pt-4 pb-1">
            <p class="px-3.5 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Finans & Muhasebe</p>
        </div>

        <?php if (has_permission('finance.invoices')): ?>
        <a href="<?= BASE_URL ?>/modules/finance/invoices.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/finance/invoices') ?>">
            <i data-lucide="receipt" class="w-4 h-4 mr-3"></i>
            <span>Faturalar (Giren/Çıkan)</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('finance.view')): ?>

        <a href="<?= BASE_URL ?>/modules/finance/accounts.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/finance/accounts') ?>">
            <i data-lucide="wallet" class="w-4 h-4 mr-3"></i>
            <span>Kasa & Banka Hesapları</span>
        </a>
        <?php endif; ?>

        <?php if (can_access_module('reports.view')): ?>
        <a href="<?= BASE_URL ?>/modules/reports/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/reports') ?>">
            <i data-lucide="bar-chart-3" class="w-4 h-4 mr-3"></i>
            <span>Yönetim Raporları</span>
        </a>
        <?php endif; ?>

        <?php if (has_permission('finance.taxes')): ?>
        <a href="<?= BASE_URL ?>/modules/taxes/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/taxes') ?>">
            <i data-lucide="calculator" class="w-4 h-4 mr-3"></i>
            <span>Otomatik Vergi Motoru</span>
        </a>
        <?php endif; ?>

        <!-- İNSAN KAYNAKLARI (İK) -->
        <div class="pt-4 pb-1">
            <p class="px-3.5 text-[10px] font-bold text-slate-500 uppercase tracking-wider">İnsan Kaynakları</p>
        </div>

        <?php if (has_permission('personnel.manage')): ?>
        <a href="<?= BASE_URL ?>/modules/personnel/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/personnel') ?>">
            <i data-lucide="user-check" class="w-4 h-4 mr-3"></i>
            <span>Personel, Maaş & Avans</span>
        </a>
        <?php endif; ?>

        <!-- AYARLAR & RBAC -->
        <?php if (has_permission('settings.manage')): ?>
        <div class="pt-4 pb-1">
            <p class="px-3.5 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Yönetim</p>
        </div>
        <a href="<?= BASE_URL ?>/modules/settings/roles.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/settings/roles') ?>">
            <i data-lucide="shield-check" class="w-4 h-4 mr-3"></i>
            <span>Roller & Yetkilendirme</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/settings/index.php" class="flex items-center px-3.5 py-2.5 text-xs font-medium rounded-xl transition-all <?= is_active('/modules/settings/index') ?>">
            <i data-lucide="settings" class="w-4 h-4 mr-3"></i>
            <span>Sistem & Şirket Ayarları</span>
        </a>
        <?php endif; ?>

    </nav>

    <div class="p-4 border-t border-slate-800 bg-slate-950/40">
        <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="flex items-center justify-center space-x-2 w-full py-2 px-3 text-xs font-medium text-rose-400 hover:text-white bg-rose-500/10 hover:bg-rose-600 rounded-xl transition duration-200">
            <i data-lucide="log-out" class="w-4 h-4"></i>
            <span>Çıkış Yap</span>
        </a>
    </div>
</aside>