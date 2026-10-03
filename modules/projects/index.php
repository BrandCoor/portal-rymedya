<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - PROJELER LİSTESİ, FİLTRELEME & SİLME İŞLEMİ
 * ====================================================================
 */

$page_title = 'Projeler & Prodüksiyon';
require_once __DIR__ . '/../../includes/header.php';
require_permission('projects.view');

// 1. PROJE SİLME İŞLEMİ (POST HANDLER)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project') {
    verify_csrf();
    require_permission('projects.delete');

    $del_id = (int)$_POST['project_id'];

    if ($del_id > 0) {
        // Proje, bağlı faturalar/tahsilatlar, çekimler ve revizyonlar zincirleme silinir; bakiyeler eşitlenir
        delete_project_cascade($del_id);

        set_flash('success', 'Proje ve bağlı tüm operasyon kayıtları başarıyla silindi.');
        redirect(BASE_URL . '/modules/projects/index.php');
    }
}

// 2. FİLTRELEME VE SORGULAR
$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT p.*, c.company_title as client_name, c.phone as client_phone,
           (SELECT COUNT(s.id) FROM shoots s WHERE s.project_id = p.id) as shoot_count,
           (SELECT COALESCE(SUM(scg.agreed_fee), 0) FROM shoots s JOIN shoot_crew_gear scg ON scg.shoot_id = s.id WHERE s.project_id = p.id) as total_expenses
    FROM projects p
    LEFT JOIN contacts c ON p.client_id = c.id
    WHERE 1=1
";
$params = [];

if (!empty($status_filter)) {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $sql .= " AND (p.project_name LIKE ? OR p.project_code LIKE ? OR c.company_title LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term]);
}

$sql .= " ORDER BY p.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Sayaçlar
$stats = [
    'total'       => $db->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
    'in_progress' => $db->query("SELECT COUNT(*) FROM projects WHERE status IN ('pre_production', 'shooting', 'post_production', 'revision')")->fetchColumn(),
    'completed'   => $db->query("SELECT COUNT(*) FROM projects WHERE status IN ('completed', 'invoiced')")->fetchColumn(),
    'cancelled'   => $db->query("SELECT COUNT(*) FROM projects WHERE status = 'cancelled'")->fetchColumn(),
    'budget_sum'  => $db->query("SELECT COALESCE(SUM(agreed_budget), 0) FROM projects WHERE status != 'cancelled'")->fetchColumn(),
];
?>

<!-- Üst Başlık ve Yeni Proje Başlat Butonu -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Projeler & Prodüksiyon Masası</h1>
        <p class="text-xs text-slate-500 mt-1">Aktif çekimler, kurgu aşamaları, teslimler ve iptal edilen işlerin yönetimi.</p>
    </div>
    <?php if (has_permission('projects.create')): ?>
    <a href="<?= BASE_URL ?>/modules/projects/create.php" class="inline-flex items-center justify-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold py-2.5 px-4 rounded-xl shadow-md shadow-brand-600/20 transition duration-200">
        <i data-lucide="plus" class="w-4 h-4"></i>
        <span>Yeni Proje Başlat</span>
    </a>
    <?php endif; ?>
</div>

<!-- İstatistik Özet Kartları -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Toplam Proje</p>
            <p class="text-2xl font-bold text-slate-800 mt-1"><?= number_format($stats['total']) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
            <i data-lucide="film" class="w-6 h-6"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Aktif Prodüksiyon</p>
            <p class="text-2xl font-bold text-brand-600 mt-1"><?= number_format($stats['in_progress']) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center">
            <i data-lucide="clapperboard" class="w-6 h-6"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Tamamlanan / Faturalı</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1"><?= number_format($stats['completed']) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <i data-lucide="check-circle-2" class="w-6 h-6"></i>
        </div>
    </div>

    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">İptal Edilenler</p>
            <p class="text-2xl font-bold text-rose-600 mt-1"><?= number_format($stats['cancelled']) ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
            <i data-lucide="ban" class="w-6 h-6"></i>
        </div>
    </div>
</div>

<!-- Filtreleme ve Arama Çubuğu -->
<div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-6">
    <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
        <div class="sm:col-span-6 lg:col-span-8 relative">
            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                <i data-lucide="search" class="w-4 h-4"></i>
            </div>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Proje Adı, Proje Kodu veya Müşteri Ara..."
                   class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:bg-white transition">
        </div>

        <div class="sm:col-span-4 lg:col-span-3">
            <select name="status" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:bg-white transition">
                <option value="">Tüm Proje Durumları</option>
                <?php foreach (PROJECT_STATUSES as $key => $val): ?>
                    <option value="<?= $key ?>" <?= $status_filter === $key ? 'selected' : '' ?>><?= $val['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sm:col-span-2 lg:col-span-1 flex gap-2">
            <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white text-xs font-semibold py-2.5 px-3 rounded-xl transition flex items-center justify-center">
                <span>Filtrele</span>
            </button>
            <?php if (!empty($search) || !empty($status_filter)): ?>
                <a href="<?= BASE_URL ?>/modules/projects/index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-semibold py-2.5 px-3 rounded-xl transition flex items-center justify-center">
                    ✕
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Projeler Tablosu -->
<div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50/75 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                    <th class="py-3.5 px-4">Proje & Kod</th>
                    <th class="py-3.5 px-4">Müşteri</th>
                    <th class="py-3.5 px-4">Tür</th>
                    <th class="py-3.5 px-4">Durum</th>
                    <th class="py-3.5 px-4 text-right">Bütçe</th>
                    <th class="py-3.5 px-4 text-right">Set Maliyeti</th>
                    <th class="py-3.5 px-4 text-center">Teslim</th>
                    <th class="py-3.5 px-4 text-right">İşlemler</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-xs">
                <?php if (empty($projects)): ?>
                <tr>
                    <td colspan="8" class="py-12 text-center text-slate-400">
                        <i data-lucide="film" class="w-10 h-10 mx-auto mb-2 opacity-40"></i>
                        <p class="text-sm font-medium">Kayıtlı proje bulunamadı.</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($projects as $prj): 
                        $status_info = PROJECT_STATUSES[$prj['status']] ?? ['label' => $prj['status'], 'color' => 'bg-slate-100 text-slate-700 border-slate-200'];
                    ?>
                    <tr class="hover:bg-slate-50/80 transition">
                        <td class="py-3.5 px-4">
                            <div class="font-bold text-slate-900">
                                <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= $prj['id'] ?>" class="hover:text-brand-600 transition">
                                    <?= e($prj['project_name']) ?>
                                </a>
                            </div>
                            <span class="inline-block text-[10px] font-mono font-medium text-slate-400 mt-0.5">
                                <?= e($prj['project_code']) ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4 font-medium text-slate-800"><?= e($prj['client_name'] ?? 'Genel / Belirtilmemiş') ?></td>
                        <td class="py-3.5 px-4">
                            <span class="font-medium text-slate-600 bg-slate-100 px-2 py-0.5 rounded text-[11px]">
                                <?= PROJECT_TYPES[$prj['project_type']] ?? $prj['project_type'] ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold border <?= $status_info['color'] ?>">
                                <?= $status_info['label'] ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4 text-right font-bold text-slate-900">
                            <?= format_money($prj['agreed_budget'], $prj['currency']) ?>
                        </td>
                        <td class="py-3.5 px-4 text-right">
                            <span class="font-medium text-rose-600"><?= format_money($prj['total_expenses'], $prj['currency']) ?></span>
                            <div class="text-[10px] text-slate-400 font-medium"><?= $prj['shoot_count'] ?> Çekim Günü</div>
                        </td>
                        <td class="py-3.5 px-4 text-center text-slate-600 font-medium">
                            <?= format_date($prj['deadline']) ?>
                        </td>
                        <td class="py-3.5 px-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= $prj['id'] ?>" class="inline-flex items-center gap-1 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-700 font-bold py-1.5 px-3 rounded-lg border border-slate-200 transition">
                                    <span>Detay</span>
                                </a>

                                <!-- PROJEYİ SİLME BUTONU -->
                                <?php if (has_permission('projects.delete')): ?>
                                <form method="POST" action="" onsubmit="return confirm(<?= js_val('DİKKAT: \'' . $prj['project_name'] . '\' projesini ve bağlı tüm çekim/gider kayıtlarını, faturalarını kalıcı olarak silmek istediğinize emin misiniz?') ?>);" class="inline-block">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_project">
                                    <input type="hidden" name="project_id" value="<?= $prj['id'] ?>">
                                    <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Projeyi Kalıcı Olarak Sil">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>