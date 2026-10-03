<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - AKILLI & ROL BAZLI DİNAMİK DASHBOARD
 * ====================================================================
 * Bu dashboard kullanıcının rolüne (Patron, Kurgucu, Yönetmen, Muhasebe)
 * göre ekranı, metrikleri ve gizlilik kurallarını dinamik olarak uyarlar.
 */

$page_title = 'Genel Bakış & Kontrol Paneli';
require_once __DIR__ . '/../../includes/header.php';

$user_id   = (int)$user['id'];
$role_slug = $user['role_slug'] ?? '';

// Tanımlı masası olmayan roller (muhasebe, özel roller vb.) için yetkilere göre görünüm seçilir:
// finans yetkisi olanlar yönetici özetini, diğerleri yönetmen/set görünümünü görür.
if (!in_array($role_slug, ['super_admin', 'producer', 'editor', 'director'], true)) {
    if ((int)$user['role_id'] === 1 || has_permission('finance.view') || has_permission('finance.invoices')) {
        $role_slug = 'producer';
    } else {
        $role_slug = 'director';
    }
}

// ====================================================================
// A. YÖNETİCİ / SÜPER ADMİN / YAPIMCI VERİLERİ
// ====================================================================
if ($role_slug === 'super_admin' || $role_slug === 'producer') {
    // Finansal sayaçlar
    $total_cash = (float)$db->query("SELECT COALESCE(SUM(balance), 0) FROM accounts WHERE status = 'active'")->fetchColumn();
    $uncollected_receivables = (float)$db->query("SELECT COALESCE(SUM(grand_total - paid_amount), 0) FROM invoices WHERE invoice_type = 'sales' AND payment_status != 'paid'")->fetchColumn();
    $this_month_sales = (float)$db->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE invoice_type = 'sales' AND MONTH(issue_date) = MONTH(CURRENT_DATE()) AND YEAR(issue_date) = YEAR(CURRENT_DATE())")->fetchColumn();
    $active_projects_count = (int)$db->query("SELECT COUNT(*) FROM projects WHERE status NOT IN ('completed', 'invoiced', 'cancelled')")->fetchColumn();

    // Vergi ve Bordro
    $vat_sales = (float)$db->query("SELECT COALESCE(SUM(vat_amount - withholding_amount), 0) FROM invoices WHERE invoice_type = 'sales' AND MONTH(issue_date) = MONTH(CURRENT_DATE()) AND YEAR(issue_date) = YEAR(CURRENT_DATE())")->fetchColumn();
    $vat_purchases = (float)$db->query("SELECT COALESCE(SUM(vat_amount), 0) FROM invoices WHERE invoice_type = 'purchase' AND MONTH(issue_date) = MONTH(CURRENT_DATE()) AND YEAR(issue_date) = YEAR(CURRENT_DATE())")->fetchColumn();
    $net_vat_due = $vat_sales - $vat_purchases;
    $monthly_payroll = (float)$db->query("SELECT COALESCE(SUM(base_salary), 0) FROM personnel WHERE status = 'active'")->fetchColumn();

    // Çekimler & Revizyonlar
    $upcoming_shoots = $db->query("SELECT s.*, p.project_name, p.project_code, c.company_title as client_name FROM shoots s JOIN projects p ON s.project_id = p.id LEFT JOIN contacts c ON p.client_id = c.id WHERE s.shoot_date >= CURRENT_DATE() ORDER BY s.shoot_date ASC LIMIT 4")->fetchAll();
    $active_revisions = $db->query("SELECT pr.*, p.project_name, p.project_code, u.full_name as editor_name FROM project_revisions pr JOIN projects p ON pr.project_id = p.id LEFT JOIN users u ON pr.assigned_editor_id = u.id WHERE pr.status != 'approved' ORDER BY pr.id DESC LIMIT 4")->fetchAll();

    // Son 6 Aylık Grafik Verisi
    $chart_months = []; $chart_incomes = []; $chart_expenses = [];
    for ($i = 5; $i >= 0; $i--) {
        // Ayın 1'i baz alınır (ör. 31 Ekim - 1 ay = 1 Ekim hatası önlenir)
        $time = strtotime("first day of -{$i} month");
        $m = (int)date('n', $time); $y = (int)date('Y', $time);
        $chart_months[] = turkish_month($m, true) . ' ' . $y;
        $chart_incomes[] = (float)$db->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE invoice_type = 'sales' AND MONTH(issue_date) = {$m} AND YEAR(issue_date) = {$y}")->fetchColumn();
        $chart_expenses[] = (float)$db->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE invoice_type = 'purchase' AND MONTH(issue_date) = {$m} AND YEAR(issue_date) = {$y}")->fetchColumn();
    }
}

// ====================================================================
// B. KURGUCU / POST-PRODÜKSİYON MASASI VERİLERİ
// ====================================================================
if ($role_slug === 'editor') {
    // Sadece bu editöre atanmış aktif revizyonlar
    $my_revisions = $db->prepare("
        SELECT pr.*, p.project_name, p.project_code, p.deadline, c.company_title as client_name
        FROM project_revisions pr
        JOIN projects p ON pr.project_id = p.id
        LEFT JOIN contacts c ON p.client_id = c.id
        WHERE pr.assigned_editor_id = ? AND pr.status != 'approved'
        ORDER BY pr.id DESC
    ");
    $my_revisions->execute([$user_id]);
    $editor_revisions = $my_revisions->fetchAll();

    // Editörün dahil olduğu aktif projeler
    $my_projects = $db->prepare("
        SELECT DISTINCT p.*, c.company_title as client_name
        FROM projects p
        JOIN project_revisions pr ON pr.project_id = p.id
        LEFT JOIN contacts c ON p.client_id = c.id
        WHERE pr.assigned_editor_id = ? AND p.status NOT IN ('completed', 'invoiced', 'cancelled')
        ORDER BY p.deadline ASC
    ");
    $my_projects->execute([$user_id]);
    $editor_projects = $my_projects->fetchAll();
}

// ====================================================================
// C. YÖNETMEN / KREATİF DİREKTÖR / DOP MASASI VERİLERİ
// ====================================================================
if ($role_slug === 'director') {
    // Yaklaşan tüm çekim günleri ve mekanlar
    $director_shoots = $db->query("
        SELECT s.*, p.project_name, p.project_code, p.description as creative_brief, c.company_title as client_name
        FROM shoots s
        JOIN projects p ON s.project_id = p.id
        LEFT JOIN contacts c ON p.client_id = c.id
        WHERE s.shoot_date >= CURRENT_DATE()
        ORDER BY s.shoot_date ASC
    ")->fetchAll();

    // Kreatif inceleme bekleyen revizyonlar
    $review_revisions = $db->query("
        SELECT pr.*, p.project_name, p.project_code, u.full_name as editor_name
        FROM project_revisions pr
        JOIN projects p ON pr.project_id = p.id
        LEFT JOIN users u ON pr.assigned_editor_id = u.id
        WHERE pr.status = 'sent_to_client' OR pr.status = 'in_progress'
        ORDER BY pr.id DESC
        LIMIT 6
    ")->fetchAll();
}

// ====================================================================
// D. HERKES İÇİN: GÖREVLERİM, BU HAFTA AJANDASI, MÜŞTERİ HAREKETLERİ
// ====================================================================
$my_tasks = [];
$week_agenda = [];
$client_feed = [];
$pending_client_reviews = 0;
$overdue_receivables = 0.0;
if (has_permission('projects.view')) {
    $mt = $db->prepare("
        SELECT t.*, p.project_name FROM project_tasks t JOIN projects p ON t.project_id = p.id
        WHERE t.assigned_user_id = ? AND t.status != 'done'
        ORDER BY (t.due_date IS NULL), t.due_date ASC, FIELD(t.priority, 'high', 'normal', 'low') LIMIT 6
    ");
    $mt->execute([$user_id]);
    $my_tasks = $mt->fetchAll();

    $ag = $db->query("
        SELECT s.shoot_date AS d, 'shoot' AS kind, s.title, p.project_name, p.id AS project_id, s.start_time
        FROM shoots s JOIN projects p ON s.project_id = p.id
        WHERE s.shoot_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY) AND p.status != 'cancelled'
        UNION ALL
        SELECT p.deadline AS d, 'deadline' AS kind, 'Teslim tarihi' AS title, p.project_name, p.id AS project_id, NULL
        FROM projects p
        WHERE p.deadline BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY) AND p.status NOT IN ('cancelled', 'invoiced', 'completed')
        ORDER BY d ASC LIMIT 8
    ");
    $week_agenda = $ag->fetchAll();

    $client_feed = $db->query("SELECT * FROM activity_log WHERE actor_type = 'client' ORDER BY id DESC LIMIT 5")->fetchAll();
    $pending_client_reviews = (int)$db->query("SELECT COUNT(*) FROM project_revisions WHERE status = 'sent_to_client'")->fetchColumn();
}
if (has_permission('finance.view') || has_permission('finance.invoices')) {
    $overdue_receivables = (float)$db->query("SELECT COALESCE(SUM(grand_total - paid_amount), 0) FROM invoices WHERE invoice_type = 'sales' AND payment_status != 'paid' AND due_date IS NOT NULL AND due_date < CURRENT_DATE()")->fetchColumn();
}
?>

<!-- ÜST KARŞILAMA VE ROL BADGE -->
<div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-brand-50 text-brand-700 border border-brand-200">
                <?= e($user['role_name']) ?> MASASI
            </span>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Merhaba, <?= e($user['full_name']) ?> 👋</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            <?= $role_slug === 'editor' ? 'Kurgu revizyonları, teslim tarihleri ve video önizleme paneli.' : ($role_slug === 'director' ? 'Set planları, Call Sheet dökümleri ve kreatif briefler.' : 'Prodüksiyon, nakit akışı ve ajans operasyon merkezi.') ?>
        </p>
    </div>
    
    <?php if ($role_slug === 'super_admin' || $role_slug === 'producer'): ?>
    <div class="flex flex-wrap items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/projects/create.php" class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md shadow-brand-600/30 transition cursor-pointer">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Yeni Proje Başlat</span>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- ==================================================================== -->
<!-- 1. GÖRÜNÜM: YÖNETİCİ & PATRON DASHBOARD (SUPER ADMIN / PRODUCER) -->
<!-- ==================================================================== -->
<?php if ($role_slug === 'super_admin' || $role_slug === 'producer'): ?>

    <!-- 4'LÜ BÜYÜK KPI METRİK KARTLARI -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Kasa & Banka Likidite</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= format_money($total_cash) ?></p>
                <span class="text-[10px] text-emerald-600 font-semibold block mt-1">✓ Net Nakit Mevcudu</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <i data-lucide="wallet" class="w-6 h-6"></i>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Bekleyen Tahsilatlar</p>
                <p class="text-2xl font-black text-rose-600 mt-1"><?= format_money($uncollected_receivables) ?></p>
                <span class="text-[10px] text-rose-600 font-medium block mt-1">Müşteri Alacakları</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center">
                <i data-lucide="clock-alert" class="w-6 h-6"></i>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Bu Ayki Fatura Cirosu</p>
                <p class="text-2xl font-black text-brand-600 mt-1"><?= format_money($this_month_sales) ?></p>
                <span class="text-[10px] text-slate-400 block mt-1"><?= turkish_month((int)date('n')) . ' ' . date('Y') ?> Satışları</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-brand-50 text-brand-600 flex items-center justify-center">
                <i data-lucide="trending-up" class="w-6 h-6"></i>
            </div>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Aktif Prodüksiyonlar</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= $active_projects_count ?> Proje</p>
                <span class="text-[10px] text-purple-600 font-medium block mt-1">Set & Kurgu Aşamasında</span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
                <i data-lucide="clapperboard" class="w-6 h-6"></i>
            </div>
        </div>
    </div>

    <!-- HIZLI VERGİ VE MAAŞ BARI -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-slate-900 text-white p-4 rounded-2xl flex items-center justify-between">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Bu Ayki KDV Durumu</span>
                <p class="text-lg font-bold mt-0.5 <?= $net_vat_due > 0 ? 'text-rose-400' : 'text-emerald-400' ?>">
                    <?= format_money(abs($net_vat_due)) ?>
                </p>
                <span class="text-[10px] text-slate-400"><?= $net_vat_due > 0 ? 'Ödenecek KDV' : 'Devreden KDV' ?></span>
            </div>
            <i data-lucide="calculator" class="w-6 h-6 text-brand-400"></i>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 flex items-center justify-between">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Aylık Sabit Personel Maaşı</span>
                <p class="text-lg font-bold text-slate-900 mt-0.5"><?= format_money($monthly_payroll) ?></p>
                <span class="text-[10px] text-slate-500">Sabit Kadro Bordrosu</span>
            </div>
            <i data-lucide="user-check" class="w-6 h-6 text-purple-600"></i>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-slate-200 flex items-center justify-between">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Vergi Motoru & Raporlar</span>
                <p class="text-lg font-bold text-slate-900 mt-0.5">Otomatik Hesaplama</p>
                <a href="<?= BASE_URL ?>/modules/taxes/index.php" class="text-[10px] font-bold text-brand-600 hover:underline">Vergi Detayını Gör →</a>
            </div>
            <i data-lucide="file-spreadsheet" class="w-6 h-6 text-emerald-600"></i>
        </div>
    </div>

    <!-- GRAFİK & OPERASYON -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
        <div class="lg:col-span-8 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-slate-900">Gelir & Gider Trend Analizi (Son 6 Ay)</h3>
                <span class="text-xs font-bold text-brand-600 bg-brand-50 px-3 py-1 rounded-xl">Chart.js Canlı</span>
            </div>
            <div class="h-64 w-full">
                <canvas id="financeChart"></canvas>
            </div>
        </div>

        <div class="lg:col-span-4 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                    <h3 class="text-sm font-bold text-slate-900">Yaklaşan Çekimler</h3>
                    <i data-lucide="calendar" class="w-4 h-4 text-slate-400"></i>
                </div>
                <?php if (empty($upcoming_shoots)): ?>
                    <p class="text-xs text-slate-400 py-8 text-center">Yakın tarihte çekim seti yok.</p>
                <?php else: ?>
                    <div class="space-y-2.5">
                        <?php foreach ($upcoming_shoots as $us): ?>
                        <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                            <div class="flex justify-between font-bold text-slate-900">
                                <span><?= e($us['title']) ?></span>
                                <span class="text-brand-600"><?= format_date($us['shoot_date']) ?></span>
                            </div>
                            <span class="text-[11px] text-slate-500 block mt-0.5"><?= e($us['project_name']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <a href="<?= BASE_URL ?>/modules/projects/index.php" class="text-center text-xs font-bold text-brand-600 hover:underline pt-3 border-t border-slate-100">Tüm Projeler →</a>
        </div>
    </div>

<!-- ==================================================================== -->
<!-- 2. GÖRÜNÜM: KURGUCU / EDİTÖR MASASI (POST-PRODÜKSİYON EKRANI) -->
<!-- ==================================================================== -->
<?php elseif ($role_slug === 'editor'): ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
        <!-- Sol: Bana Atanan Aktif Kurgu Görevleri -->
        <div class="lg:col-span-8 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Üzerimdeki Kurgu & Revizyon Görevleri</h3>
                    <p class="text-xs text-slate-500">Müşteri geri bildirimleri ve teslim bekleyen kurgu versiyonları.</p>
                </div>
                <span class="text-xs font-bold bg-purple-50 text-purple-700 px-3 py-1.5 rounded-xl border border-purple-200">
                    <?= count($editor_revisions ?? []) ?> Aktif Kurgu
                </span>
            </div>

            <?php if (empty($editor_revisions)): ?>
                <div class="py-16 text-center text-slate-400">
                    <i data-lucide="check-circle" class="w-12 h-12 mx-auto mb-2 text-emerald-500 opacity-60"></i>
                    <p class="text-sm font-bold text-slate-700">Harika! Bekleyen kurgu göreviniz bulunmuyor.</p>
                    <p class="text-xs text-slate-400 mt-1">Yeni revizyonlar atandığında burada listelenecektir.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($editor_revisions as $rev): ?>
                    <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-md text-[10px] font-mono font-bold bg-brand-100 text-brand-800">
                                    <?= e($rev['project_code']) ?>
                                </span>
                                <h4 class="font-bold text-slate-900 text-sm"><?= e($rev['project_name']) ?></h4>
                            </div>
                            <span class="text-xs font-semibold text-purple-700 mt-1 block">Versiyon: <?= e($rev['version_title']) ?></span>
                            <?php if (!empty($rev['feedback_notes'])): ?>
                                <div class="mt-2 p-3 bg-white rounded-xl border border-slate-200 text-xs text-slate-700">
                                    <strong>Müşteri Geri Bildirimi:</strong> <?= nl2br(e($rev['feedback_notes'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center gap-2 flex-shrink-0">
                            <?php if (!empty($rev['preview_url'])): ?>
                                <a href="<?= e($rev['preview_url']) ?>" target="_blank" class="p-2.5 bg-white hover:bg-slate-100 text-brand-600 border border-slate-200 rounded-xl inline-flex items-center" title="Önizleme Linki">
                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                </a>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= $rev['project_id'] ?>" class="px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold rounded-xl transition">
                                Proje Masasına Git →
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sağ: Kurguladığım Aktif Projeler & Deadline -->
        <div class="lg:col-span-4 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Dahil Olduğum Projeler</h3>
            <?php if (empty($editor_projects)): ?>
                <p class="text-xs text-slate-400 py-6 text-center">Aktif proje kaydınız yok.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($editor_projects as $ep): ?>
                    <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                        <span class="font-bold text-slate-900 block"><?= e($ep['project_name']) ?></span>
                        <span class="text-[11px] text-slate-500 block">Müşteri: <?= e($ep['client_name']) ?></span>
                        <div class="mt-2 pt-2 border-t border-slate-200 flex justify-between items-center">
                            <span class="text-[10px] text-slate-400">Teslim: <?= format_date($ep['deadline']) ?></span>
                            <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= $ep['id'] ?>" class="text-brand-600 font-bold hover:underline">Detay →</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<!-- ==================================================================== -->
<!-- 3. GÖRÜNÜM: YÖNETMEN / KREATİF / DOP MASASI (SET EKRANI) -->
<!-- ==================================================================== -->
<?php elseif ($role_slug === 'director'): ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
        <!-- Sol: Planlanan Çekim Setleri & Call Sheet Linkleri -->
        <div class="lg:col-span-8 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Yaklaşan Çekim Günleri & Setler</h3>
                    <p class="text-xs text-slate-500">Mekanlar, set çağrı saatleri ve Call Sheet dökümleri.</p>
                </div>
                <span class="text-xs font-bold bg-amber-50 text-amber-700 px-3 py-1.5 rounded-xl border border-amber-200">
                    <?= count($director_shoots ?? []) ?> Set Planı
                </span>
            </div>

            <?php if (empty($director_shoots)): ?>
                <div class="py-16 text-center text-slate-400">
                    <i data-lucide="clapperboard" class="w-12 h-12 mx-auto mb-2 opacity-50"></i>
                    <p class="text-sm font-bold text-slate-700">Planlanmış çekim seti bulunmuyor.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($director_shoots as $ds): ?>
                    <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-1 rounded-xl text-xs font-black bg-brand-600 text-white">
                                    <?= format_date($ds['shoot_date']) ?>
                                </span>
                                <h4 class="font-bold text-slate-900 text-sm"><?= e($ds['title']) ?></h4>
                            </div>
                            <span class="text-xs font-medium text-slate-600 mt-1 block">Proje: <strong><?= e($ds['project_name']) ?></strong> (<?= e($ds['client_name']) ?>)</span>
                            <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                                <i data-lucide="map-pin" class="w-3.5 h-3.5 text-slate-400"></i>
                                <span><?= e($ds['location_name']) ?> - <?= e($ds['location_address']) ?></span>
                            </p>
                        </div>

                        <div class="flex items-center gap-2 flex-shrink-0">
                            <!-- CALL SHEET PDF ÇIKTI BUTONU -->
                            <a href="<?= BASE_URL ?>/modules/projects/callsheet_print.php?shoot_id=<?= $ds['id'] ?>" target="_blank"
                               class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer">
                                <i data-lucide="printer" class="w-4 h-4"></i>
                                <span>Call Sheet İndir</span>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sağ: Kreatif İnceleme & Revizyon Onayları -->
        <div class="lg:col-span-4 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Kreatif Kurgu İnceleme</h3>
            <?php if (empty($review_revisions)): ?>
                <p class="text-xs text-slate-400 py-6 text-center">İncelenecek video yok.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($review_revisions as $rr): ?>
                    <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                        <span class="font-bold text-slate-900 block"><?= e($rr['version_title']) ?></span>
                        <span class="text-[11px] text-slate-500"><?= e($rr['project_name']) ?></span>
                        <span class="text-[10px] text-purple-600 block mt-1">Editör: <?= e($rr['editor_name'] ?? 'Atanmadı') ?></span>
                        <?php if (!empty($rr['preview_url'])): ?>
                            <a href="<?= e($rr['preview_url']) ?>" target="_blank" class="mt-2 inline-flex items-center gap-1 text-brand-600 font-bold hover:underline">
                                <span>Videoyu İzle</span> <i data-lucide="arrow-right" class="w-3 h-3"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<!-- Chart.js Sadece Yönetici Ekranında Yüklenir -->

<!-- ==================================================================== -->
<!-- ORTAK: GÖREVLERİM, BU HAFTA, MÜŞTERİ HAREKETLERİ -->
<!-- ==================================================================== -->
<?php if (has_permission('projects.view')): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
    <!-- Görevlerim -->
    <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2"><i data-lucide="list-checks" class="w-4 h-4 text-emerald-600"></i> Görevlerim</h3>
            <a href="<?= BASE_URL ?>/modules/tasks/index.php" class="text-[11px] font-bold text-brand-600 hover:underline">Tümü →</a>
        </div>
        <?php if (empty($my_tasks)): ?>
            <p class="text-xs text-slate-400 py-6 text-center">Size atanmış açık görev yok. 🎉</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($my_tasks as $t): $late = !empty($t['due_date']) && $t['due_date'] < date('Y-m-d'); ?>
                <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= (int)$t['project_id'] ?>&tab=tasks" class="block p-2.5 rounded-xl border <?= $late ? 'border-rose-200 bg-rose-50/50' : 'border-slate-100 bg-slate-50' ?> hover:border-brand-300">
                    <p class="text-xs font-bold text-slate-900"><span class="<?= TASK_PRIORITIES[$t['priority']]['color'] ?? '' ?>">●</span> <?= e($t['title']) ?></p>
                    <p class="text-[10px] text-slate-500 mt-0.5"><?= e($t['project_name']) ?><?= !empty($t['due_date']) ? ' · ' . format_date($t['due_date']) . ($late ? ' (gecikti)' : '') : '' ?></p>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bu Hafta -->
    <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2"><i data-lucide="calendar-days" class="w-4 h-4 text-sky-600"></i> Önümüzdeki 7 Gün</h3>
            <a href="<?= BASE_URL ?>/modules/calendar/index.php" class="text-[11px] font-bold text-brand-600 hover:underline">Takvim →</a>
        </div>
        <?php if (empty($week_agenda)): ?>
            <p class="text-xs text-slate-400 py-6 text-center">Bu hafta planlı çekim veya teslim yok.</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($week_agenda as $a): ?>
                <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= (int)$a['project_id'] ?>&tab=<?= $a['kind'] === 'shoot' ? 'shoots' : 'details' ?>" class="flex items-center gap-3 p-2 rounded-xl hover:bg-slate-50">
                    <div class="w-11 text-center flex-shrink-0">
                        <div class="text-base font-black <?= $a['kind'] === 'shoot' ? 'text-brand-600' : 'text-rose-600' ?>"><?= date('j', strtotime($a['d'])) ?></div>
                        <div class="text-[9px] font-bold text-slate-400 uppercase"><?= turkish_month((int)date('n', strtotime($a['d'])), true) ?></div>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-slate-900 truncate"><?= $a['kind'] === 'shoot' ? '🎬 ' : '⏰ ' ?><?= !empty($a['start_time']) ? substr($a['start_time'], 0, 5) . ' · ' : '' ?><?= e($a['title']) ?></p>
                        <p class="text-[10px] text-slate-500 truncate"><?= e($a['project_name']) ?></p>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Müşteri Hareketleri -->
    <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2"><i data-lucide="message-square-more" class="w-4 h-4 text-indigo-600"></i> Müşteri Hareketleri</h3>
            <a href="<?= BASE_URL ?>/modules/notifications/index.php" class="text-[11px] font-bold text-brand-600 hover:underline">Tümü →</a>
        </div>
        <div class="flex flex-wrap gap-2 mb-3 text-[10px] font-bold">
            <span class="px-2 py-1 rounded-lg bg-indigo-50 text-indigo-700">Müşteri onayı bekleyen kurgu: <?= $pending_client_reviews ?></span>
            <?php if ($overdue_receivables > 0): ?>
                <a href="<?= BASE_URL ?>/modules/finance/invoices.php?status=overdue" class="px-2 py-1 rounded-lg bg-rose-50 text-rose-700">Vadesi geçen alacak: <?= format_money($overdue_receivables) ?></a>
            <?php endif; ?>
        </div>
        <?php if (empty($client_feed)): ?>
            <p class="text-xs text-slate-400 py-4 text-center">Henüz müşteri hareketi yok.</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($client_feed as $f): ?>
                <a href="<?= BASE_URL . e($f['link'] ?: '/modules/notifications/index.php') ?>" class="block p-2 rounded-xl hover:bg-slate-50">
                    <p class="text-xs text-slate-800 leading-snug"><?= e($f['message']) ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5"><?= e($f['actor_name'] ?? '') ?> · <?= time_ago($f['created_at']) ?></p>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($role_slug === 'super_admin' || $role_slug === 'producer'): ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const ctx = document.getElementById('financeChart');
    if (ctx) {
        new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_months) ?>,
                datasets: [
                    { label: 'Satış Gelirleri (₺)', data: <?= json_encode($chart_incomes) ?>, backgroundColor: 'rgba(124, 58, 237, 0.85)', borderRadius: 8 },
                    { label: 'Giderler (₺)', data: <?= json_encode($chart_expenses) ?>, backgroundColor: 'rgba(244, 63, 94, 0.85)', borderRadius: 8 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }
            }
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>