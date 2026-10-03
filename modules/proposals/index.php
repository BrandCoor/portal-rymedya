<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - TEKLİF & SATIŞ BORU HATTI (KANBAN PIPELINE)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('proposals.manage');

// Self-Healing DB: Teklif tablosunu otomatik oluştur
$db->query("
    CREATE TABLE IF NOT EXISTS `proposals` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `proposal_code` VARCHAR(50) NOT NULL UNIQUE,
      `client_id` INT UNSIGNED NOT NULL,
      `title` VARCHAR(200) NOT NULL,
      `project_type` VARCHAR(50) DEFAULT 'commercial',
      `workflow_model` VARCHAR(50) DEFAULT 'internal_full',
      `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
      `vat_rate` DECIMAL(5,2) DEFAULT 20.00,
      `grand_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
      `currency` VARCHAR(10) DEFAULT 'TRY',
      `valid_until` DATE NULL,
      `status` ENUM('draft', 'sent', 'negotiating', 'approved', 'rejected') DEFAULT 'draft',
      `scope_items` TEXT NULL,
      `terms` TEXT NULL,
      `converted_project_id` INT UNSIGNED NULL,
      `created_by` INT UNSIGNED NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`client_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

const PROPOSAL_STATUSES = [
    'draft'       => ['label' => 'Taslak Hazırlanıyor', 'badge' => 'bg-slate-100 text-slate-700 border-slate-300', 'color' => 'slate'],
    'sent'        => ['label' => 'Müşteriye İletildi', 'badge' => 'bg-blue-100 text-blue-800 border-blue-300', 'color' => 'blue'],
    'negotiating' => ['label' => 'Pazarlık / Revizyonda', 'badge' => 'bg-amber-100 text-amber-800 border-amber-300', 'color' => 'amber'],
    'approved'    => ['label' => '✓ Kabul Edildi (Kazanıldı)', 'badge' => 'bg-emerald-100 text-emerald-800 border-emerald-300', 'color' => 'emerald'],
    'rejected'    => ['label' => '✕ Reddedildi (Kaybedildi)', 'badge' => 'bg-rose-100 text-rose-800 border-rose-300', 'color' => 'rose']
];

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // A. Durum Değiştirme
    if ($action === 'change_status') {
        $prop_id    = (int)$_POST['proposal_id'];
        $new_status = $_POST['new_status'] ?? 'draft';
        if (array_key_exists($new_status, PROPOSAL_STATUSES)) {
            $db->prepare("UPDATE proposals SET status = ? WHERE id = ?")->execute([$new_status, $prop_id]);
            set_flash('success', 'Teklif aşaması güncellendi.');
        }
        redirect(BASE_URL . '/modules/proposals/index.php');
    }

    // B. TEKLİFİ TEK TIKLA RESMİ PROJEYE DÖNÜŞTÜRME
    if ($action === 'convert_to_project') {
        $prop_id = (int)$_POST['proposal_id'];
        $prop = $db->query("SELECT * FROM proposals WHERE id = {$prop_id}")->fetch();

        if ($prop && !empty($prop['converted_project_id'])) {
            set_flash('error', 'Bu teklif zaten projeye dönüştürülmüş.');
            redirect(BASE_URL . '/modules/proposals/index.php');
        }

        if ($prop && empty($prop['converted_project_id'])) {
            require_permission('projects.create');
            $new_code = generate_project_code();
            $ins_p = $db->prepare("
                INSERT INTO projects (client_id, project_code, project_name, project_type, workflow_model, status, agreed_budget, currency, start_date, deadline, description, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, 'pre_production', ?, ?, CURRENT_DATE(), NULL, ?, ?, NOW())
            ");
            $ins_p->execute([
                $prop['client_id'], $new_code, $prop['title'], $prop['project_type'], $prop['workflow_model'],
                $prop['subtotal'], $prop['currency'],
                "Teklif No: {$prop['proposal_code']} kabul edilerek projeye dönüştürüldü.\n\nKapsam:\n{$prop['scope_items']}",
                $user['id']
            ]);
            $new_proj_id = (int)$db->lastInsertId();

            // Teklifi Onaylandı ve Dönüştürüldü Yap
            $db->prepare("UPDATE proposals SET status = 'approved', converted_project_id = ? WHERE id = ?")->execute([$new_proj_id, $prop_id]);

            set_flash('success', "Tebrikler! Teklif başarıyla resmi projeye ({$new_code}) dönüştürüldü.");
            redirect(BASE_URL . "/modules/projects/detail.php?id={$new_proj_id}");
        }
    }

    // C. Teklif Silme
    if ($action === 'delete_proposal') {
        $del_id = (int)$_POST['proposal_id'];
        $db->prepare("DELETE FROM proposals WHERE id = ?")->execute([$del_id]);
        set_flash('success', 'Teklif silindi.');
        redirect(BASE_URL . '/modules/proposals/index.php');
    }
}

// 2. VERİLERİ ÇEKME
$proposals = $db->query("
    SELECT pr.*, c.company_title as client_name, c.phone as client_phone, u.full_name as creator_name
    FROM proposals pr
    JOIN contacts c ON pr.client_id = c.id
    LEFT JOIN users u ON pr.created_by = u.id
    ORDER BY pr.id DESC
")->fetchAll();

// Sayaçlar
$total_proposals = count($proposals);
$pipeline_val    = (float)$db->query("SELECT COALESCE(SUM(grand_total), 0) FROM proposals WHERE status IN ('sent', 'negotiating')")->fetchColumn();
$won_val         = (float)$db->query("SELECT COALESCE(SUM(grand_total), 0) FROM proposals WHERE status = 'approved'")->fetchColumn();
$view_mode       = $_GET['view'] ?? 'kanban';

$page_title = 'Teklifler & Satış Boru Hattı (Pipeline)';
require_once __DIR__ . '/../../includes/header.php';
?>

<div>
    <!-- Üst Başlık & Hızlı Eylemler -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Teklifler & Satış Boru Hattı (Pipeline)</h1>
            <p class="text-xs text-slate-500 mt-0.5">Müşteri teklifleri, pazarlık süreçleri, fiyat dökümleri ve proje dönüşümleri.</p>
        </div>

        <div class="flex items-center gap-2">
            <!-- Görünüm Seçici (Kanban / Tablo) -->
            <div class="bg-white p-1 rounded-xl border border-slate-200 flex items-center gap-1 shadow-xs">
                <a href="<?= BASE_URL ?>/modules/proposals/index.php?view=kanban" class="p-1.5 rounded-lg <?= $view_mode === 'kanban' ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-100' ?>" title="Kanban Pano">
                    <i data-lucide="kanban" class="w-4 h-4"></i>
                </a>
                <a href="<?= BASE_URL ?>/modules/proposals/index.php?view=table" class="p-1.5 rounded-lg <?= $view_mode === 'table' ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-100' ?>" title="Tablo Görünümü">
                    <i data-lucide="table" class="w-4 h-4"></i>
                </a>
            </div>

            <a href="<?= BASE_URL ?>/modules/proposals/create.php" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md shadow-indigo-600/30 transition cursor-pointer">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Yeni Teklif Hazırla</span>
            </a>
        </div>
    </div>

    <!-- 3'LÜ BÜYÜK SAYAÇ KARTI -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Pazardaki Aktif Teklif Hacmi</p>
            <p class="text-2xl font-black text-indigo-600 mt-1"><?= format_money($pipeline_val) ?></p>
            <span class="text-[10px] text-indigo-600 font-semibold block mt-1">Müşteriye İletilen & Pazarlıkta Olanlar</span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Kazanılan & Projeye Dönen</p>
            <p class="text-2xl font-black text-emerald-600 mt-1"><?= format_money($won_val) ?></p>
            <span class="text-[10px] text-emerald-600 font-semibold block mt-1">✓ Onaylanan Toplam Teklif Hacmi</span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Toplam Hazırlanan Teklif</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= $total_proposals ?> Adet</p>
            <span class="text-[10px] text-slate-400 block mt-1">Sistemdeki Tüm Fiyat Teklifleri</span>
        </div>
    </div>

    <!-- ==================================================================== -->
    <!-- GÖRÜNÜM 1: KANBAN PIPELINE MASASI (TRELLO TARZI SÜRÜKLE / BIRAK) -->
    <!-- ==================================================================== -->
    <?php if ($view_mode === 'kanban'): ?>
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 overflow-x-auto pb-4 items-start">
        <?php foreach (PROPOSAL_STATUSES as $status_key => $status_info): 
            $column_items = array_filter($proposals, fn($item) => $item['status'] === $status_key);
            $column_sum   = array_sum(array_column($column_items, 'grand_total'));
        ?>
        <div class="bg-slate-100/90 rounded-3xl p-3 border border-slate-200 flex flex-col gap-3 min-w-[240px]">
            
            <!-- Kolon Başlığı -->
            <div class="p-2 flex items-center justify-between">
                <div>
                    <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider"><?= $status_info['label'] ?></h3>
                    <span class="text-[10px] text-slate-400 font-bold"><?= count($column_items) ?> Teklif (<?= format_money($column_sum) ?>)</span>
                </div>
            </div>

            <!-- Kartlar -->
            <div class="space-y-3">
                <?php foreach ($column_items as $prop): ?>
                <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs hover:border-indigo-400 transition space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-100">
                            <?= e($prop['proposal_code']) ?>
                        </span>
                        <span class="flex items-center gap-1">
                            <?php if (empty($prop['converted_project_id'])): ?>
                            <a href="<?= BASE_URL ?>/modules/proposals/create.php?id=<?= $prop['id'] ?>" class="p-1 text-slate-400 hover:text-indigo-600" title="Teklifi Düzenle">
                                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                            </a>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/modules/proposals/print.php?id=<?= $prop['id'] ?>" target="_blank" class="p-1 text-slate-400 hover:text-slate-700" title="A4 Fiyat Teklifi Yazdır">
                                <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                            </a>
                        </span>
                    </div>

                    <h4 class="font-bold text-slate-900 text-xs line-clamp-2"><?= e($prop['title']) ?></h4>
                    <p class="text-[11px] text-slate-500 font-medium truncate">🏢 <?= e($prop['client_name']) ?></p>

                    <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-[10px] text-slate-400"><?= format_date($prop['valid_until']) ?></span>
                        <strong class="text-slate-900 font-black"><?= format_money($prop['grand_total'], $prop['currency']) ?></strong>
                    </div>

                    <!-- Aşama Değiştirici Form & Projeye Dönüştür -->
                    <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-1">
                        <form method="POST" action="" class="w-full">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="proposal_id" value="<?= $prop['id'] ?>">
                            <select name="new_status" onchange="this.form.submit()" class="w-full py-1 px-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-bold text-slate-700 cursor-pointer">
                                <?php foreach (PROPOSAL_STATUSES as $sk => $sv): ?>
                                    <option value="<?= $sk ?>" <?= $prop['status'] === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <?php if ($prop['status'] === 'approved' && empty($prop['converted_project_id'])): ?>
                            <form method="POST" action="" onsubmit="return confirm('Bu teklifi resmi projeye dönüştürmek istiyor musunuz?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="convert_to_project">
                                <input type="hidden" name="proposal_id" value="<?= $prop['id'] ?>">
                                <button type="submit" class="p-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-bold" title="Projeye Dönüştür">
                                    <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===================================================== -->
    <!-- GÖRÜNÜM 2: TABLO LİSTESİ -->
    <!-- ===================================================== -->
    <?php else: ?>
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                        <th class="py-3.5 px-4">Teklif No & Başlık</th>
                        <th class="py-3.5 px-4">Müşteri</th>
                        <th class="py-3.5 px-4">Tür</th>
                        <th class="py-3.5 px-4">Geçerlilik</th>
                        <th class="py-3.5 px-4 text-right">Tutar (KDV Dahil)</th>
                        <th class="py-3.5 px-4 text-center">Aşama</th>
                        <th class="py-3.5 px-4 text-right">İşlemler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($proposals)): ?>
                        <tr><td colspan="7" class="py-12 text-center text-slate-400">Henüz teklif hazırlanmadı.</td></tr>
                    <?php else: ?>
                        <?php foreach ($proposals as $prop): 
                            $st_info = PROPOSAL_STATUSES[$prop['status']] ?? ['label' => $prop['status'], 'badge' => 'bg-slate-100 text-slate-700'];
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3.5 px-4">
                                <span class="font-mono font-bold text-indigo-600 block"><?= e($prop['proposal_code']) ?></span>
                                <span class="font-bold text-slate-900"><?= e($prop['title']) ?></span>
                            </td>
                            <td class="py-3.5 px-4 font-bold text-slate-800"><?= e($prop['client_name']) ?></td>
                            <td class="py-3.5 px-4 text-slate-600"><?= get_project_type_name($prop['project_type']) ?></td>
                            <td class="py-3.5 px-4 text-slate-600"><?= format_date($prop['valid_until']) ?></td>
                            <td class="py-3.5 px-4 text-right font-black text-slate-900 text-sm"><?= format_money($prop['grand_total'], $prop['currency']) ?></td>
                            <td class="py-3.5 px-4 text-center">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border <?= $st_info['badge'] ?>">
                                    <?= $st_info['label'] ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <?php if (empty($prop['converted_project_id'])): ?>
                                    <a href="<?= BASE_URL ?>/modules/proposals/create.php?id=<?= $prop['id'] ?>" class="p-1.5 bg-slate-100 hover:bg-indigo-50 text-slate-700 hover:text-indigo-600 rounded-lg" title="Teklifi Düzenle">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?= BASE_URL ?>/modules/proposals/print.php?id=<?= $prop['id'] ?>" target="_blank" class="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg" title="A4 Teklif PDF Yazdır">
                                        <i data-lucide="printer" class="w-4 h-4"></i>
                                    </a>

                                    <?php if ($prop['status'] === 'approved' && empty($prop['converted_project_id'])): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Bu teklifi projeye dönüştürmek istiyor musunuz?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="convert_to_project">
                                        <input type="hidden" name="proposal_id" value="<?= $prop['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs flex items-center gap-1">
                                            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                                            <span>Projeye Çevir</span>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="POST" action="" onsubmit="return confirm('Teklifi silmek istiyor musunuz?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_proposal">
                                        <input type="hidden" name="proposal_id" value="<?= $prop['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 transition" title="Sil">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>