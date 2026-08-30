<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - EKİPMAN ENVANTERİ & DIŞARIYA KİRALAMA MOTORU
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    redirect(BASE_URL . '/modules/auth/login.php');
}

// Self-Healing DB: Kiralama kolonlarını kontrol et / ekle
try {
    $db->query("SELECT rental_contact_id FROM equipment LIMIT 1");
} catch (Exception $e) {
    $db->query("
        ALTER TABLE `equipment` 
        ADD COLUMN `rental_contact_id` INT UNSIGNED NULL AFTER `current_project_id`,
        ADD COLUMN `rental_start_date` DATE NULL AFTER `rental_contact_id`,
        ADD COLUMN `rental_end_date` DATE NULL AFTER `rental_start_date`,
        ADD COLUMN `rental_total_fee` DECIMAL(15,2) DEFAULT 0.00 AFTER `rental_end_date`
    ");
}

// Ekipman Kategorileri
const GEAR_CATEGORIES = [
    'camera'  => ['label' => 'Kamera & Gövde', 'icon' => 'video'],
    'lens'    => ['label' => 'Lens & Optik', 'icon' => 'aperture'],
    'light'   => ['label' => 'Işık & Aydınlatma', 'icon' => 'sun'],
    'sound'   => ['label' => 'Ses & Mikrofon', 'icon' => 'mic'],
    'drone'   => ['label' => 'Drone & Hava Çekimi', 'icon' => 'navigation'],
    'gimbal'  => ['label' => 'Gimbal & Stabilizer', 'icon' => 'crosshair'],
    'monitor' => ['label' => 'Monitör & Kablosuz', 'icon' => 'tv'],
    'grip'    => ['label' => 'Tripod, C-Stand & Grip', 'icon' => 'hammer'],
    'other'   => ['label' => 'Diğer Aksesuar', 'icon' => 'package']
];

// Ekipman Durumları
const GEAR_STATUSES = [
    'in_office'   => ['label' => 'Depoda / Hazır', 'color' => 'bg-emerald-100 text-emerald-800 border-emerald-300'],
    'on_set'      => ['label' => 'Sette / Kullanımda', 'color' => 'bg-indigo-100 text-indigo-800 border-indigo-300'],
    'maintenance' => ['label' => 'Bakımda / Serviste', 'color' => 'bg-amber-100 text-amber-800 border-amber-300'],
    'rented_out'  => ['label' => 'Dışarıya Kiraya Verildi', 'color' => 'bg-purple-100 text-purple-800 border-purple-300'],
    'retired'     => ['label' => 'Hurda / Devredildi', 'color' => 'bg-rose-100 text-rose-800 border-rose-300']
];

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ==========================================
    // A. EKİPMANI MÜŞTERİYE KİRAYA VERME & FATURALANDIRMA MOTORU
    // ==========================================
    if ($action === 'rent_out_equipment') {
        $eq_id        = (int)$_POST['equipment_id'];
        $contact_id   = (int)$_POST['contact_id'];
        $start_date   = $_POST['start_date'] ?? date('Y-m-d');
        $end_date     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $rental_fee   = (float)str_replace(['.', ','], ['', '.'], $_POST['rental_fee'] ?? '0');
        $billing_type = $_POST['billing_type'] ?? 'invoice'; // 'invoice' = KDV'li Fatura, 'debit' = Faturasız Borç Dekontu, 'none' = Faturasız
        $vat_rate     = (float)($_POST['vat_rate'] ?? 20);

        $eq = $db->query("SELECT * FROM equipment WHERE id = {$eq_id}")->fetch();

        if ($eq && $contact_id > 0 && $rental_fee >= 0) {
            
            // 1. Seçenek: Resmi Satış Faturası Kes
            if ($billing_type === 'invoice' && $rental_fee > 0) {
                $tax = calculate_tax_breakdown($rental_fee, $vat_rate, '0/10', 0);
                $inv_no = generate_invoice_number('sales');

                $ins_inv = $db->prepare("
                    INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, due_date, subtotal, vat_rate, vat_amount, grand_total, payment_status, notes, created_at)
                    VALUES ('sales', ?, NULL, ?, ?, ?, ?, ?, 'unpaid', ?, NOW())
                ");
                $ins_inv->execute([
                    $inv_no, $contact_id, $start_date, $end_date,
                    $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'],
                    "{$eq['item_name']} ekipman kiralama bedeli ({$start_date} - " . ($end_date ?: 'Teslim') . ")"
                ]);

                recalculate_contact_balance($contact_id);
            }

            // 2. Seçenek: Faturasız Manuel Borç Dekontu Kes
            if ($billing_type === 'debit' && $rental_fee > 0) {
                $db->prepare("
                    INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at)
                    VALUES (NULL, ?, NULL, NULL, 'expense', 'Ekipman Kiralama Dekontu', ?, ?, ?, ?, NOW())
                ")->execute([
                    $contact_id, $rental_fee, $start_date,
                    "{$eq['item_name']} faturasız ekipman kira bedeli", $user['id']
                ]);

                recalculate_contact_balance($contact_id);
            }

            // 3. Ekipmanın Durumunu 'rented_out' Yap ve Müşteriyi Bağla
            $up_eq = $db->prepare("
                UPDATE equipment 
                SET status = 'rented_out', rental_contact_id = ?, rental_start_date = ?, rental_end_date = ?, rental_total_fee = ?, current_project_id = NULL
                WHERE id = ?
            ");
            $up_eq->execute([$contact_id, $start_date, $end_date, $rental_fee, $eq_id]);

            set_flash('success', "{$eq['item_name']} başarıyla kiraya verildi ve muhasebeye işlendi.");
            redirect(BASE_URL . '/modules/inventory/index.php');
        }
    }

    // B. Kiradan Teslim Al (Depoya Al)
    if ($action === 'return_from_rental') {
        $eq_id = (int)$_POST['equipment_id'];
        $db->prepare("
            UPDATE equipment 
            SET status = 'in_office', rental_contact_id = NULL, rental_start_date = NULL, rental_end_date = NULL
            WHERE id = ?
        ")->execute([$eq_id]);
        
        set_flash('success', 'Ekipman kiradan teslim alındı ve depoya kaydedildi.');
        redirect(BASE_URL . '/modules/inventory/index.php');
    }

    // C. Yeni Ekipman Kaydı
    if ($action === 'create_equipment') {
        $item_name        = trim($_POST['item_name'] ?? '');
        $category         = $_POST['category'] ?? 'camera';
        $serial_number    = trim($_POST['serial_number'] ?? '');
        $purchase_price   = (float)str_replace(['.', ','], ['', '.'], $_POST['purchase_price'] ?? '0');
        $purchase_date    = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $storage_location = trim($_POST['storage_location'] ?? '');
        $status           = $_POST['status'] ?? 'in_office';
        $assigned_user_id = !empty($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : null;
        $notes            = trim($_POST['notes'] ?? '');

        if (!empty($item_name)) {
            $stmt = $db->prepare("
                INSERT INTO equipment (item_name, category, serial_number, purchase_price, purchase_date, storage_location, status, assigned_user_id, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$item_name, $category, $serial_number, $purchase_price, $purchase_date, $storage_location, $status, $assigned_user_id, $notes]);
            set_flash('success', "{$item_name} demirbaş envanterine kaydedildi.");
            redirect(BASE_URL . '/modules/inventory/index.php');
        }
    }

    // D. Ekipman Düzenleme
    if ($action === 'edit_equipment') {
        $eq_id            = (int)$_POST['equipment_id'];
        $item_name        = trim($_POST['item_name'] ?? '');
        $category         = $_POST['category'] ?? 'camera';
        $serial_number    = trim($_POST['serial_number'] ?? '');
        $purchase_price   = (float)str_replace(['.', ','], ['', '.'], $_POST['purchase_price'] ?? '0');
        $storage_location = trim($_POST['storage_location'] ?? '');
        $status           = $_POST['status'] ?? 'in_office';
        $assigned_user_id = !empty($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : null;
        $notes            = trim($_POST['notes'] ?? '');

        if ($eq_id > 0 && !empty($item_name)) {
            $up = $db->prepare("
                UPDATE equipment 
                SET item_name = ?, category = ?, serial_number = ?, purchase_price = ?, storage_location = ?, status = ?, assigned_user_id = ?, notes = ?
                WHERE id = ?
            ");
            $up->execute([$item_name, $category, $serial_number, $purchase_price, $storage_location, $status, $assigned_user_id, $notes, $eq_id]);
            set_flash('success', 'Ekipman bilgileri ve durumu güncellendi.');
            redirect(BASE_URL . '/modules/inventory/index.php');
        }
    }

    // E. Hızlı Durum Değiştirme
    if ($action === 'quick_status_change') {
        $eq_id      = (int)$_POST['equipment_id'];
        $new_status = $_POST['new_status'] ?? 'in_office';

        if ($eq_id > 0) {
            $proj_update = ($new_status === 'on_set') ? "current_project_id = current_project_id" : "current_project_id = NULL";
            $rent_update = ($new_status === 'rented_out') ? "rental_contact_id = rental_contact_id" : "rental_contact_id = NULL";
            $db->prepare("UPDATE equipment SET status = ?, {$proj_update}, {$rent_update} WHERE id = ?")->execute([$new_status, $eq_id]);
            set_flash('success', 'Ekipman durumu güncellendi.');
            redirect(BASE_URL . '/modules/inventory/index.php');
        }
    }

    // F. Sete Çıkar
    if ($action === 'assign_to_set') {
        $eq_id      = (int)$_POST['equipment_id'];
        $project_id = (int)$_POST['project_id'];

        if ($eq_id > 0 && $project_id > 0) {
            $db->prepare("UPDATE equipment SET status = 'on_set', current_project_id = ?, rental_contact_id = NULL WHERE id = ?")->execute([$project_id, $eq_id]);
            set_flash('success', 'Ekipman sete çıkarıldı ve projeye zimmetlendi.');
            redirect(BASE_URL . '/modules/inventory/index.php');
        }
    }

    // G. Depoya Al
    if ($action === 'return_to_office') {
        $eq_id = (int)$_POST['equipment_id'];
        $db->prepare("UPDATE equipment SET status = 'in_office', current_project_id = NULL, rental_contact_id = NULL WHERE id = ?")->execute([$eq_id]);
        set_flash('success', 'Ekipman depoya teslim alındı.');
        redirect(BASE_URL . '/modules/inventory/index.php');
    }

    // H. Ekipman Silme
    if ($action === 'delete_equipment') {
        $eq_id = (int)$_POST['equipment_id'];
        $db->prepare("DELETE FROM equipment WHERE id = ?")->execute([$eq_id]);
        set_flash('success', 'Ekipman silindi.');
        redirect(BASE_URL . '/modules/inventory/index.php');
    }
}

// 2. FİLTRELER VE SORGULAR
$cat_filter    = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');

$sql = "
    SELECT e.*, u.full_name as assigned_user_name, p.project_name, p.project_code,
           c.company_title as rental_client_name
    FROM equipment e
    LEFT JOIN users u ON e.assigned_user_id = u.id
    LEFT JOIN projects p ON e.current_project_id = p.id
    LEFT JOIN contacts c ON e.rental_contact_id = c.id
    WHERE 1=1
";
$params = [];

if (!empty($cat_filter)) {
    $sql .= " AND e.category = ?";
    $params[] = $cat_filter;
}
if (!empty($status_filter)) {
    $sql .= " AND e.status = ?";
    $params[] = $status_filter;
}
if (!empty($search)) {
    $sql .= " AND (e.item_name LIKE ? OR e.serial_number LIKE ? OR e.storage_location LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term; $params[] = $term; $params[] = $term;
}

$sql .= " ORDER BY e.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$equipment_list = $stmt->fetchAll();

// Sayaçlar
$total_items     = (int)$db->query("SELECT COUNT(*) FROM equipment WHERE status != 'retired'")->fetchColumn();
$in_office_cnt   = (int)$db->query("SELECT COUNT(*) FROM equipment WHERE status = 'in_office'")->fetchColumn();
$on_set_cnt      = (int)$db->query("SELECT COUNT(*) FROM equipment WHERE status = 'on_set'")->fetchColumn();
$maint_cnt       = (int)$db->query("SELECT COUNT(*) FROM equipment WHERE status = 'maintenance'")->fetchColumn();
$rented_out_cnt  = (int)$db->query("SELECT COUNT(*) FROM equipment WHERE status = 'rented_out'")->fetchColumn();
$total_asset_val = (float)$db->query("SELECT COALESCE(SUM(purchase_price), 0) FROM equipment WHERE status != 'retired'")->fetchColumn();

$active_projects = $db->query("SELECT id, project_name, project_code FROM projects WHERE status NOT IN ('completed', 'invoiced', 'cancelled') ORDER BY id DESC")->fetchAll();
$clients_list = $db->query("SELECT id, company_title, type FROM contacts ORDER BY company_title ASC")->fetchAll();
$users_list = $db->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

$page_title = 'Ekipman & Demirbaş Envanteri';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ 
    openCreateModal: false, 
    openEditModal: false, 
    editData: {},
    openAssignModal: false,
    assignData: { id: '', name: '' },
    openRentModal: false,
    rentData: { id: '', name: '', fee: 0, billing_type: 'invoice', vat_rate: 20 }
}">
    <!-- Üst Başlık & Eylemler -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Ekipman & Demirbaş Envanteri</h1>
            <p class="text-xs text-slate-500 mt-0.5">Kameralar, lensler, ışıklar, dışarıya kiralama ve set zimmet hareketleri.</p>
        </div>

        <button @click="openCreateModal = true" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md shadow-brand-600/30 transition cursor-pointer">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Yeni Ekipman Tanımla</span>
        </button>
    </div>

    <!-- 5'Lİ DEMİRBAŞ KPI SAYAÇLARI -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Toplam Demirbaş</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= $total_items ?> Parça</p>
            <span class="text-[10px] text-slate-400 block mt-1"><?= format_money($total_asset_val) ?></span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Depoda / Hazır</p>
            <p class="text-2xl font-black text-emerald-600 mt-1"><?= $in_office_cnt ?> Parça</p>
            <span class="text-[10px] text-emerald-600 font-semibold block mt-1">✓ Sete Çıkmaya Hazır</span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Sette / Kullanımda</p>
            <p class="text-2xl font-black text-indigo-600 mt-1"><?= $on_set_cnt ?> Parça</p>
            <span class="text-[10px] text-indigo-600 font-semibold block mt-1">🎬 Aktif Çekimlerde</span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Dışarıya Kirada</p>
            <p class="text-2xl font-black text-purple-600 mt-1"><?= $rented_out_cnt ?> Parça</p>
            <span class="text-[10px] text-purple-600 font-semibold block mt-1">💼 Müşteride Kirada</span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Bakım / Serviste</p>
            <p class="text-2xl font-black text-amber-600 mt-1"><?= $maint_cnt ?> Parça</p>
            <span class="text-[10px] text-amber-600 block mt-1">Tamirde / Kalibrasyonda</span>
        </div>
    </div>

    <!-- Filtreleme Çubuğu -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-6">
        <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-6 lg:col-span-7 relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </div>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Model Adı, Seri No veya Çanta/Raf Ara..."
                       class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:ring-2 focus:ring-brand-500">
            </div>

            <div class="sm:col-span-3 lg:col-span-2">
                <select name="category" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                    <option value="">Tüm Kategoriler</option>
                    <?php foreach (GEAR_CATEGORIES as $gk => $gv): ?>
                        <option value="<?= $gk ?>" <?= $cat_filter === $gk ? 'selected' : '' ?>><?= $gv['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-3 lg:col-span-2">
                <select name="status" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                    <option value="">Tüm Durumlar</option>
                    <?php foreach (GEAR_STATUSES as $sk => $sv): ?>
                        <option value="<?= $sk ?>" <?= $status_filter === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-12 lg:col-span-1 flex gap-2">
                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold py-2.5 px-4 rounded-xl transition">
                    Filtrele
                </button>
            </div>
        </form>
    </div>

    <!-- Ekipman Tablosu -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50/75 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                        <th class="py-3.5 px-4">Ekipman / Model</th>
                        <th class="py-3.5 px-4">Kategori</th>
                        <th class="py-3.5 px-4">Seri No / Raf</th>
                        <th class="py-3.5 px-4">Zimmetli Kişi</th>
                        <th class="py-3.5 px-4">Durum (Hızlı Değiştir)</th>
                        <th class="py-3.5 px-4 text-right">Alış Değeri</th>
                        <th class="py-3.5 px-4 text-right">İşlemler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($equipment_list)): ?>
                        <tr><td colspan="7" class="py-12 text-center text-slate-400">Envanterde kayıtlı ekipman bulunamadı.</td></tr>
                    <?php else: ?>
                        <?php foreach ($equipment_list as $eq): 
                            $cat_info = GEAR_CATEGORIES[$eq['category']] ?? ['label' => $eq['category'], 'icon' => 'package'];
                            $st_info  = GEAR_STATUSES[$eq['status']] ?? ['label' => $eq['status'], 'color' => 'bg-slate-100 text-slate-700'];
                        ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="py-3.5 px-4">
                                <span class="font-bold text-slate-900 text-sm block"><?= e($eq['item_name']) ?></span>
                                <?php if (!empty($eq['notes'])): ?>
                                    <span class="text-[11px] text-slate-400"><?= e($eq['notes']) ?></span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3.5 px-4">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-slate-100 text-slate-700 font-semibold text-[11px]">
                                    <i data-lucide="<?= $cat_info['icon'] ?>" class="w-3.5 h-3.5 text-slate-500"></i>
                                    <span><?= $cat_info['label'] ?></span>
                                </span>
                            </td>

                            <td class="py-3.5 px-4">
                                <span class="font-mono font-bold text-slate-800 block"><?= e($eq['serial_number'] ?: '-') ?></span>
                                <span class="text-[11px] text-slate-400">Konum: <?= e($eq['storage_location'] ?: 'Depo') ?></span>
                            </td>

                            <td class="py-3.5 px-4 font-medium text-slate-700">
                                <?= e($eq['assigned_user_name'] ?: 'Genel Depo') ?>
                            </td>

                            <!-- HIZLI DURUM SEÇİCİ -->
                            <td class="py-3.5 px-4">
                                <form method="POST" action="" class="inline-block">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="quick_status_change">
                                    <input type="hidden" name="equipment_id" value="<?= $eq['id'] ?>">
                                    <select name="new_status" onchange="this.form.submit()" 
                                            class="py-1 px-2.5 rounded-full text-[10px] font-bold border cursor-pointer <?= $st_info['color'] ?>">
                                        <?php foreach (GEAR_STATUSES as $sk => $sv): ?>
                                            <option value="<?= $sk ?>" <?= $eq['status'] === $sk ? 'selected' : '' ?>><?= $sv['label'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>

                                <?php if ($eq['status'] === 'on_set' && !empty($eq['project_name'])): ?>
                                    <span class="text-[10px] font-bold text-indigo-600 block mt-1">
                                        🎬 <?= e($eq['project_name']) ?>
                                    </span>
                                <?php elseif ($eq['status'] === 'rented_out' && !empty($eq['rental_client_name'])): ?>
                                    <span class="text-[10px] font-bold text-purple-700 block mt-1">
                                        💼 <?= e($eq['rental_client_name']) ?> (<?= format_money($eq['rental_total_fee']) ?>)
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3.5 px-4 text-right font-black text-slate-900">
                                <?= format_money($eq['purchase_price']) ?>
                            </td>

                            <td class="py-3.5 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    
                                    <!-- DURUMA GÖRE KİRALAMA & SET BUTONLARI -->
                                    <?php if ($eq['status'] === 'in_office'): ?>
                                        <!-- Sete Çıkar -->
                                        <button @click="assignData = { id: '<?= $eq['id'] ?>', name: '<?= e(addslashes($eq['item_name'])) ?>' }; openAssignModal = true"
                                                class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-600 hover:text-white text-indigo-700 font-bold rounded-lg border border-indigo-200 transition text-[11px]">
                                            Sete Çıkar
                                        </button>

                                        <!-- Müşteriye Kirala Butonu -->
                                        <button @click="rentData = { id: '<?= $eq['id'] ?>', name: '<?= e(addslashes($eq['item_name'])) ?>', fee: 0, billing_type: 'invoice', vat_rate: 20 }; openRentModal = true"
                                                class="px-2.5 py-1 bg-purple-50 hover:bg-purple-600 hover:text-white text-purple-700 font-bold rounded-lg border border-purple-200 transition text-[11px]">
                                            Kiraya Ver
                                        </button>
                                    <?php elseif ($eq['status'] === 'on_set'): ?>
                                        <form method="POST" action="" class="inline-block">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="return_to_office">
                                            <input type="hidden" name="equipment_id" value="<?= $eq['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 font-bold rounded-lg border border-emerald-200 transition text-[11px]">
                                                ✓ Depoya Al
                                            </button>
                                        </form>
                                    <?php elseif ($eq['status'] === 'rented_out'): ?>
                                        <form method="POST" action="" class="inline-block">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="return_from_rental">
                                            <input type="hidden" name="equipment_id" value="<?= $eq['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 font-bold rounded-lg border border-emerald-200 transition text-[11px]" title="Kiradan Depoya Teslim Al">
                                                ✓ Kiradan Teslim Al
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- DÜZENLE -->
                                    <button @click="editData = {
                                                id: '<?= $eq['id'] ?>',
                                                item_name: '<?= e(addslashes($eq['item_name'])) ?>',
                                                category: '<?= $eq['category'] ?>',
                                                serial_number: '<?= e(addslashes($eq['serial_number'] ?? '')) ?>',
                                                purchase_price: '<?= (float)$eq['purchase_price'] ?>',
                                                storage_location: '<?= e(addslashes($eq['storage_location'] ?? '')) ?>',
                                                status: '<?= $eq['status'] ?>',
                                                assigned_user_id: '<?= $eq['assigned_user_id'] ?? '' ?>',
                                                notes: '<?= e(addslashes($eq['notes'] ?? '')) ?>'
                                            }; openEditModal = true"
                                            class="p-1.5 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-600 rounded-lg transition" title="Düzenle">
                                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                    </button>

                                    <!-- SİL -->
                                    <form method="POST" action="" onsubmit="return confirm('Bu ekipmanı envanterden silmek istiyor musunuz?');" class="inline-block">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_equipment">
                                        <input type="hidden" name="equipment_id" value="<?= $eq['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 transition" title="Sil">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
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

    <!-- ===================================================== -->
    <!-- MODAL: EKİPMANI MÜŞTERİYE KİRAYA VERME & FATURALANDIRMA -->
    <!-- ===================================================== -->
    <div x-show="openRentModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openRentModal = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Ekipmanı Dışarıya Kiraya Ver</h3>
                    <p class="text-xs text-slate-500" x-text="rentData.name"></p>
                </div>
                <button type="button" @click="openRentModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rent_out_equipment">
                <input type="hidden" name="equipment_id" :value="rentData.id">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kiralanacak Müşteri / Cari *</label>
                    <select name="contact_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <option value="">-- Müşteri Seçin --</option>
                        <?php foreach ($clients_list as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['company_title']) ?> (<?= CONTACT_TYPES[$c['type']] ?? $c['type'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Kira Başlangıç Tarihi *</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Planlanan İade Tarihi</label>
                        <input type="date" name="end_date" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Toplam Kira Bedeli (₺) *</label>
                        <input type="number" step="0.01" name="rental_fee" x-model="rentData.fee" required placeholder="0.00" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">KDV Oranı</label>
                        <select name="vat_rate" x-model="rentData.vat_rate" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (VAT_RATES as $vr): ?>
                                <option value="<?= $vr ?>">%<?= $vr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- FATURALANDIRMA SEÇENEKLERİ (KDV'Lİ / SADECE BORÇLANDIRMA) -->
                <div class="p-3.5 bg-purple-50/80 rounded-2xl border border-purple-200 space-y-2 text-xs">
                    <label class="block font-bold text-purple-950 uppercase text-[11px]">Muhasebe & Faturalandırma Yöntemi *</label>
                    
                    <label class="flex items-center gap-2 p-2 bg-white rounded-xl border border-purple-200 cursor-pointer">
                        <input type="radio" name="billing_type" value="invoice" x-model="rentData.billing_type" class="text-purple-600">
                        <div>
                            <span class="font-bold text-slate-900 block text-[11px]">Resmi Satış Faturası Kes (KDV'li)</span>
                            <span class="text-[10px] text-slate-400">Otomatik satış faturası keser ve KDV dahil cariye borç yazar</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-2 p-2 bg-white rounded-xl border border-purple-200 cursor-pointer">
                        <input type="radio" name="billing_type" value="debit" x-model="rentData.billing_type" class="text-purple-600">
                        <div>
                            <span class="font-bold text-slate-900 block text-[11px]">Faturasız Manuel Borç Dekontu (KDV'siz)</span>
                            <span class="text-[10px] text-slate-400">Fatura kesmeden doğrudan müşterinin cari borcuna ekler</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-2 p-2 bg-white rounded-xl border border-purple-200 cursor-pointer">
                        <input type="radio" name="billing_type" value="none" x-model="rentData.billing_type" class="text-purple-600">
                        <div>
                            <span class="font-bold text-slate-900 block text-[11px]">Yalnızca Ekipman Durumunu Güncelle</span>
                            <span class="text-[10px] text-slate-400">Cariye borç yazmaz, sadece ürünün kirada olduğunu gösterir</span>
                        </div>
                    </label>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openRentModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl text-xs shadow-md">
                        💼 Kiraya Ver ve İşle
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: YENİ EKİPMAN -->
    <div x-show="openCreateModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openCreateModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Yeni Ekipman / Demirbaş Tanımla</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_equipment">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Ekipman / Model Adı *</label>
                        <input type="text" name="item_name" required placeholder="Sony FX3" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kategori *</label>
                        <select name="category" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (GEAR_CATEGORIES as $gk => $gv): ?>
                                <option value="<?= $gk ?>"><?= $gv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Seri No</label>
                        <input type="text" name="serial_number" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Alış Fiyatı (₺)</label>
                        <input type="number" step="0.01" name="purchase_price" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Durum</label>
                        <select name="status" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (GEAR_STATUSES as $sk => $sv): ?>
                                <option value="<?= $sk ?>"><?= $sv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openCreateModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: EKİPMAN DÜZENLE -->
    <div x-show="openEditModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openEditModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Ekipman Bilgilerini Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_equipment">
                <input type="hidden" name="equipment_id" :value="editData.id">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Model Adı *</label>
                        <input type="text" name="item_name" required x-model="editData.item_name" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kategori</label>
                        <select name="category" x-model="editData.category" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (GEAR_CATEGORIES as $gk => $gv): ?>
                                <option value="<?= $gk ?>"><?= $gv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Seri No</label>
                        <input type="text" name="serial_number" x-model="editData.serial_number" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Alış Fiyatı</label>
                        <input type="number" step="0.01" name="purchase_price" x-model="editData.purchase_price" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Durum</label>
                        <select name="status" x-model="editData.status" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (GEAR_STATUSES as $sk => $sv): ?>
                                <option value="<?= $sk ?>"><?= $sv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: SETE ÇIKAR -->
    <div x-show="openAssignModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openAssignModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-1">Ekipmanı Sete Çıkar</h3>
            <p class="text-xs text-slate-500 mb-4" x-text="assignData.name"></p>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_to_set">
                <input type="hidden" name="equipment_id" :value="assignData.id">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Kullanılacağı Proje *</label>
                    <select name="project_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <option value="">-- Proje Seçin --</option>
                        <?php foreach ($active_projects as $ap): ?>
                            <option value="<?= $ap['id'] ?>"><?= e($ap['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openAssignModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 text-white font-bold rounded-xl text-xs shadow-md">🎬 Sete Çıkışını Onayla</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>