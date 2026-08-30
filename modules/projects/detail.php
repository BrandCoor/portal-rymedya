<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - PROJE DETAY, İŞ MODELİ & EDİT FATURALANDIRMA MOTORU
 * ====================================================================
 */

$project_id = (int)($_GET['id'] ?? 0);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

// Self-Healing DB
try {
    $db->query("SELECT workflow_model FROM projects LIMIT 1");
} catch (Exception $e) {
    $db->query("
        ALTER TABLE `projects` 
        ADD COLUMN `workflow_model` ENUM('internal_full', 'external_edit_only', 'external_shoot_only', 'outsource_full', 'outsource_edit', 'outsource_shoot') DEFAULT 'internal_full' AFTER `project_type`,
        ADD COLUMN `outsource_contact_id` INT UNSIGNED NULL AFTER `workflow_model`
    ");
}

try {
    $db->query("SELECT billing_status FROM project_revisions LIMIT 1");
} catch (Exception $e) {
    $db->query("
        ALTER TABLE `project_revisions`
        ADD COLUMN `billing_status` ENUM('unbilled', 'invoiced', 'debited') DEFAULT 'unbilled' AFTER `status`,
        ADD COLUMN `billing_fee` DECIMAL(15,2) DEFAULT 0.00 AFTER `billing_status`,
        ADD COLUMN `invoice_id` INT UNSIGNED NULL AFTER `billing_fee`
    ");
}

const WORKFLOW_MODELS = [
    'internal_full'       => ['label' => '🏢 Ajans İçi Tam Prodüksiyon (Çekim + Kurgu)', 'color' => 'bg-emerald-50 text-emerald-800 border-emerald-300'],
    'external_edit_only'  => ['label' => '✂️ Yalnızca Kurgu / Edit Hizmeti (Çekim Müşteriden)', 'color' => 'bg-purple-50 text-purple-800 border-purple-300'],
    'external_shoot_only' => ['label' => '🎬 Yalnızca Çekim Hizmeti (Kurgu Müşteride)', 'color' => 'bg-blue-50 text-blue-800 border-blue-300'],
    'outsource_full'      => ['label' => '🤝 Dış Ekip / Taşeron Prodüksiyon (Dış Çekim & Dış Edit)', 'color' => 'bg-amber-50 text-amber-800 border-amber-300'],
    'outsource_edit'      => ['label' => '👥 Çekim Bizden, Edit Dış Kurgucudan (Taşeron Edit)', 'color' => 'bg-cyan-50 text-cyan-800 border-cyan-300'],
    'outsource_shoot'     => ['label' => '🎥 Çekim Dış Ekipten, Edit Bizden (Taşeron Çekim)', 'color' => 'bg-rose-50 text-rose-800 border-rose-300']
];

// Projeyi Getir
$stmt = $db->prepare("
    SELECT p.*, c.company_title as client_name, c.phone as client_phone, c.email as client_email, c.id as client_contact_id, 
           u.full_name as creator_name, oc.company_title as outsource_contractor_name
    FROM projects p
    LEFT JOIN contacts c ON p.client_id = c.id
    LEFT JOIN contacts oc ON p.outsource_contact_id = oc.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE p.id = ?
");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    set_flash('error', 'Aradığınız proje bulunamadı.');
    redirect(BASE_URL . '/modules/projects/index.php');
}

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ==========================================
    // A. KURGU / EDİT HİZMETİNİ FATURALANDIRMA & BORÇLANDIRMA (YENİ MOTOR)
    // ==========================================
    if ($action === 'bill_edit_service') {
        $rev_id       = (int)$_POST['revision_id'];
        $edit_fee     = (float)str_replace(['.', ','], ['', '.'], $_POST['billing_fee'] ?? '0');
        $billing_type = $_POST['billing_type'] ?? 'invoice'; // 'invoice' = KDV'li Fatura, 'debit' = Faturasız Borç Dekontu
        $vat_rate     = (float)($_POST['vat_rate'] ?? 20);
        $issue_date   = $_POST['issue_date'] ?? date('Y-m-d');
        $notes        = trim($_POST['notes'] ?? '');

        $rev = $db->query("SELECT * FROM project_revisions WHERE id = {$rev_id} AND project_id = {$project_id}")->fetch();

        if ($rev && $edit_fee > 0) {
            $created_invoice_id = null;

            // 1. Seçenek: KDV'li Resmi Satış Faturası Kes
            if ($billing_type === 'invoice') {
                $tax = calculate_tax_breakdown($edit_fee, $vat_rate, '0/10', 0);
                $inv_no = generate_invoice_number('sales');

                $ins_inv = $db->prepare("
                    INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, subtotal, vat_rate, vat_amount, grand_total, payment_status, notes, created_at)
                    VALUES ('sales', ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, NOW())
                ");
                $ins_inv->execute([
                    $inv_no, $project['client_contact_id'], $project_id, $issue_date,
                    $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'],
                    ($notes ?: "{$project['project_name']} ({$rev['version_title']}) kurgu & edit hizmet bedeli")
                ]);
                $created_invoice_id = (int)$db->lastInsertId();

                $db->prepare("UPDATE contacts SET balance = balance + ? WHERE id = ?")->execute([$tax['grand_total'], $project['client_contact_id']]);
                $new_b_status = 'invoiced';
            }

            // 2. Seçenek: Faturasız Manuel Borç Dekontu Kes
            if ($billing_type === 'debit') {
                $db->prepare("
                    INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at)
                    VALUES (NULL, ?, NULL, ?, 'expense', 'Kurgu / Edit Hizmet Dekontu', ?, ?, ?, ?, NOW())
                ")->execute([
                    $project['client_contact_id'], $project_id, $edit_fee, $issue_date,
                    ($notes ?: "{$project['project_name']} ({$rev['version_title']}) faturasız edit bedeli"), $user['id']
                ]);

                $db->prepare("UPDATE contacts SET balance = balance + ? WHERE id = ?")->execute([$edit_fee, $project['client_contact_id']]);
                $new_b_status = 'debited';
            }

            // Revizyonun Faturalandırma Durumunu Güncelle
            $up_rev = $db->prepare("
                UPDATE project_revisions 
                SET billing_status = ?, billing_fee = ?, invoice_id = ?
                WHERE id = ?
            ");
            $up_rev->execute([$new_b_status, $edit_fee, $created_invoice_id, $rev_id]);

            recalculate_contact_balance($project['client_contact_id']);
            set_flash('success', "{$rev['version_title']} edit hizmeti başarıyla faturalandırıldı / müşteriye borç kaydedildi.");
            redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
        }
    }

    // ==========================================
    // B. PROJE BİLGİLERİ VE İŞ MODELİNİ DÜZENLEME
    // ==========================================
    if ($action === 'edit_project_details') {
        require_permission('projects.edit');

        $p_name           = trim($_POST['project_name'] ?? '');
        $p_type           = $_POST['project_type'] ?? 'commercial';
        $p_workflow       = $_POST['workflow_model'] ?? 'internal_full';
        $p_outsource_id   = !empty($_POST['outsource_contact_id']) ? (int)$_POST['outsource_contact_id'] : null;
        $p_budget         = (float)str_replace(['.', ','], ['', '.'], $_POST['agreed_budget'] ?? '0');
        $p_currency       = $_POST['currency'] ?? 'TRY';
        $p_start          = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $p_deadline       = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $p_desc           = trim($_POST['description'] ?? '');

        if (!empty($p_name)) {
            $up = $db->prepare("
                UPDATE projects 
                SET project_name = ?, project_type = ?, workflow_model = ?, outsource_contact_id = ?, agreed_budget = ?, currency = ?, start_date = ?, deadline = ?, description = ?
                WHERE id = ?
            ");
            $up->execute([$p_name, $p_type, $p_workflow, $p_outsource_id, $p_budget, $p_currency, $p_start, $p_deadline, $p_desc, $project_id]);
            set_flash('success', 'Proje iş modeli ve detayları güncellendi.');
            redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
        }
    }

    // ==========================================
    // C. SET GİDERİ İŞLEMLERİ
    // ==========================================
    if ($action === 'add_crew_gear') {
        $shoot_id       = (int)$_POST['shoot_id'];
        $category       = $_POST['category'];
        $item_title     = trim($_POST['item_title']);
        $fee            = (float)str_replace(['.', ','], ['', '.'], $_POST['agreed_fee'] ?? '0');
        $contact_id     = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $is_rebillable  = (int)($_POST['is_rebillable'] ?? 0);
        $vat_rate       = (float)($_POST['vat_rate'] ?? 20);
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $auto_invoice   = isset($_POST['create_purchase_invoice']) ? 1 : 0;

        $vat_amount  = round($fee * ($vat_rate / 100), 2);
        $grand_total = round($fee + $vat_amount, 2);
        $created_invoice_id = null;

        if ($auto_invoice && $contact_id && $fee > 0) {
            $inv_no = !empty($invoice_number) ? $invoice_number : 'ALIS-' . date('Ymd') . '-' . rand(100, 999);
            $inv_stmt = $db->prepare("INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, subtotal, vat_rate, vat_amount, grand_total, payment_status, notes, created_at) VALUES ('purchase', ?, ?, ?, CURRENT_DATE(), ?, ?, ?, ?, 'unpaid', ?, NOW())");
            $inv_stmt->execute([$inv_no, $contact_id, $project_id, $fee, $vat_rate, $vat_amount, $grand_total, "{$project['project_name']} ({$item_title}) gideri"]);
            $created_invoice_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE contacts SET balance = balance - ? WHERE id = ?")->execute([$grand_total, $contact_id]);
        }

        $ins_crew = $db->prepare("INSERT INTO shoot_crew_gear (shoot_id, contact_id, category, item_title, agreed_fee, currency, is_rebillable, vat_rate, vat_amount, invoice_id, invoice_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins_crew->execute([$shoot_id, $contact_id, $category, $item_title, $fee, $project['currency'], $is_rebillable, $vat_rate, $vat_amount, $created_invoice_id, $invoice_number]);

        set_flash('success', 'Set gideri başarıyla kaydedildi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'edit_crew_gear') {
        $item_id        = (int)$_POST['item_id'];
        $fee            = (float)str_replace(['.', ','], ['', '.'], $_POST['agreed_fee'] ?? '0');
        $contact_id     = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $is_rebillable  = (int)($_POST['is_rebillable'] ?? 0);
        $vat_rate       = (float)($_POST['vat_rate'] ?? 20);
        $vat_amount     = round($fee * ($vat_rate / 100), 2);
        $grand_total    = round($fee + $vat_amount, 2);

        $old_it = $db->query("SELECT * FROM shoot_crew_gear WHERE id = {$item_id}")->fetch();
        if ($old_it && !empty($old_it['invoice_id'])) {
            $old_inv = $db->query("SELECT * FROM invoices WHERE id = {$old_it['invoice_id']}")->fetch();
            if ($old_inv) {
                $db->prepare("UPDATE contacts SET balance = balance + ? WHERE id = ?")->execute([$old_inv['grand_total'], $old_inv['contact_id']]);
                $db->prepare("UPDATE invoices SET subtotal = ?, vat_rate = ?, vat_amount = ?, grand_total = ?, contact_id = ? WHERE id = ?")->execute([$fee, $vat_rate, $vat_amount, $grand_total, ($contact_id ?: $old_inv['contact_id']), $old_it['invoice_id']]);
                $db->prepare("UPDATE contacts SET balance = balance - ? WHERE id = ?")->execute([$grand_total, ($contact_id ?: $old_inv['contact_id'])]);
            }
        }

        $up = $db->prepare("UPDATE shoot_crew_gear SET category = ?, item_title = ?, agreed_fee = ?, contact_id = ?, is_rebillable = ?, vat_rate = ?, vat_amount = ? WHERE id = ?");
        $up->execute([$_POST['category'], trim($_POST['item_title']), $fee, $contact_id, $is_rebillable, $vat_rate, $vat_amount, $item_id]);

        set_flash('success', 'Gider güncellendi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'delete_crew_item') {
        $item_id = (int)$_POST['item_id'];
        $old_it = $db->query("SELECT * FROM shoot_crew_gear WHERE id = {$item_id}")->fetch();
        if ($old_it && !empty($old_it['invoice_id'])) {
            delete_invoice_cascade((int)$old_it['invoice_id']);
        }
        $db->prepare("DELETE FROM shoot_crew_gear WHERE id = ?")->execute([$item_id]);
        set_flash('success', 'Gider silindi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    // ==========================================
    // D. DİĞER MODALLAR & İŞLEMLER
    // ==========================================
    if ($action === 'add_revision') {
        $ins_rev = $db->prepare("INSERT INTO project_revisions (project_id, assigned_editor_id, version_title, preview_url, feedback_notes, status) VALUES (?, ?, ?, ?, ?, ?)");
        $ins_rev->execute([$project_id, $_POST['assigned_editor_id']?:null, $_POST['version_title'], $_POST['preview_url'], $_POST['feedback_notes'], $_POST['status']]);
        set_flash('success', 'Kurgu versiyonu eklendi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'edit_revision') {
        $up = $db->prepare("UPDATE project_revisions SET version_title = ?, preview_url = ?, assigned_editor_id = ?, feedback_notes = ?, status = ? WHERE id = ? AND project_id = ?");
        $up->execute([$_POST['version_title'], $_POST['preview_url'], $_POST['assigned_editor_id']?:null, $_POST['feedback_notes'], $_POST['status'], (int)$_POST['revision_id'], $project_id]);
        set_flash('success', 'Kurgu versiyonu güncellendi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'delete_revision') {
        $db->prepare("DELETE FROM project_revisions WHERE id = ? AND project_id = ?")->execute([(int)$_POST['revision_id'], $project_id]);
        set_flash('success', 'Kurgu versiyonu silindi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'add_shoot') {
        $ins_shoot = $db->prepare("INSERT INTO shoots (project_id, title, shoot_date, start_time, end_time, location_name, location_address, call_sheet_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins_shoot->execute([$project_id, $_POST['title'], $_POST['shoot_date'], $_POST['start_time']?:null, $_POST['end_time']?:null, $_POST['location_name'], $_POST['location_address'], $_POST['call_sheet_notes']]);
        set_flash('success', 'Çekim günü eklendi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'edit_shoot') {
        $up = $db->prepare("UPDATE shoots SET title = ?, shoot_date = ?, location_name = ?, location_address = ?, call_sheet_notes = ? WHERE id = ? AND project_id = ?");
        $up->execute([$_POST['title'], $_POST['shoot_date'], $_POST['location_name'], $_POST['location_address'], $_POST['call_sheet_notes'], (int)$_POST['shoot_id'], $project_id]);
        set_flash('success', 'Çekim günü güncellendi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'delete_shoot') {
        $db->prepare("DELETE FROM shoots WHERE id = ? AND project_id = ?")->execute([(int)$_POST['shoot_id'], $project_id]);
        set_flash('success', 'Çekim günü silindi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'complete_and_invoice') {
        $subtotal = (float)$_POST['final_billing_subtotal'];
        $vat_rate = (float)($_POST['vat_rate'] ?? 20);
        $tax = calculate_tax_breakdown($subtotal, $vat_rate, '0/10', 0);

        $inv_stmt = $db->prepare("INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, due_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at) VALUES ('sales', ?, ?, ?, ?, ?, ?, ?, '0/10', 0, 0, 0, ?, 'unpaid', ?, NOW())");
        $inv_stmt->execute([$_POST['invoice_number'], $project['client_contact_id'], $project_id, $_POST['issue_date'], $_POST['due_date']?:null, $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'], $_POST['notes']]);

        $db->prepare("UPDATE contacts SET balance = balance + ? WHERE id = ?")->execute([$tax['grand_total'], $project['client_contact_id']]);
        $db->prepare("UPDATE projects SET status = 'invoiced' WHERE id = ?")->execute([$project_id]);

        recalculate_contact_balance((int)$project['client_contact_id']);
        set_flash('success', "Proje faturalandırıldı.");
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'cancel_project_invoice') {
        delete_invoice_cascade((int)$_POST['invoice_id']);
        set_flash('success', 'Fatura iptal edildi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }

    if ($action === 'delete_project_permanent') {
        require_permission('projects.delete');
        $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$project_id]);
        set_flash('success', "Proje tamamen silindi.");
        redirect(BASE_URL . '/modules/projects/index.php');
    }

    if ($action === 'update_status') {
        $db->prepare("UPDATE projects SET status = ? WHERE id = ?")->execute([$_POST['status'], $project_id]);
        set_flash('success', 'Proje durumu güncellendi.');
        redirect(BASE_URL . "/modules/projects/detail.php?id={$project_id}");
    }
}

// 2. VERİLERİ ÇEKME & DETAYLI MALİYET HESAPLAMALARI
$shoots = $db->query("SELECT * FROM shoots WHERE project_id = {$project_id} ORDER BY shoot_date ASC")->fetchAll();

$internal_calc = $db->query("SELECT COALESCE(SUM(scg.agreed_fee), 0) as matrah, COALESCE(SUM(scg.vat_amount), 0) as vat FROM shoot_crew_gear scg JOIN shoots s ON scg.shoot_id = s.id WHERE s.project_id = {$project_id} AND scg.is_rebillable = 0")->fetch();
$internal_fee   = (float)$internal_calc['matrah'];
$internal_vat   = (float)$internal_calc['vat'];
$internal_gross = $internal_fee + $internal_vat;

$rebillable_calc = $db->query("SELECT COALESCE(SUM(scg.agreed_fee), 0) as matrah, COALESCE(SUM(scg.vat_amount), 0) as vat FROM shoot_crew_gear scg JOIN shoots s ON scg.shoot_id = s.id WHERE s.project_id = {$project_id} AND scg.is_rebillable = 1")->fetch();
$rebillable_fee   = (float)$rebillable_calc['matrah'];
$rebillable_vat   = (float)$rebillable_calc['vat'];
$rebillable_gross = $rebillable_fee + $rebillable_vat;

$base_budget            = (float)$project['agreed_budget'];
$final_billing_subtotal = $base_budget + $rebillable_fee;
$net_agency_profit      = $base_budget - $internal_fee;
$margin_percent         = $base_budget > 0 ? ($net_agency_profit / $base_budget) * 100 : 0;

$revisions = $db->query("SELECT pr.*, u.full_name as editor_name FROM project_revisions pr LEFT JOIN users u ON pr.assigned_editor_id = u.id WHERE pr.project_id = {$project_id} ORDER BY pr.id DESC")->fetchAll();
$project_invoices = $db->query("SELECT * FROM invoices WHERE project_id = {$project_id} ORDER BY id DESC")->fetchAll();

$editors = $db->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$freelancers = $db->query("SELECT id, company_title, type FROM contacts ORDER BY company_title ASC")->fetchAll();
$project_types = get_project_types();

$is_invoiced = ($project['status'] === 'invoiced' || !empty($project_invoices));
$wf_info = WORKFLOW_MODELS[$project['workflow_model'] ?? 'internal_full'] ?? ['label' => 'Ajans İçi Prodüksiyon', 'color' => 'bg-slate-100 text-slate-700'];

$page_title = e($project['project_name']) . ' | Prodüksiyon Detayı';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ 
    openInvoiceCompleteModal: false, 
    openEditProjectModal: false,
    selectedWfModel: '<?= e($project['workflow_model'] ?? 'internal_full') ?>',
    openEditRevModal: false,
    editRevData: {},
    openBillEditModal: false,
    billEditData: { revision_id: 0, title: '', fee: 0, billing_type: 'invoice', vat_rate: 20 },
    openEditShootModal: false,
    editShootData: {},
    openEditCrewModal: false,
    editCrewData: {},
    vatRate: 20, 
    withholdingRate: '0/10', 
    finalSubtotal: <?= $final_billing_subtotal ?>,
    calcInvoice() {
        let sub = this.finalSubtotal;
        let vat = sub * (parseFloat(this.vatRate) / 100);
        let withh = 0;
        if (this.withholdingRate !== '0/10' && this.withholdingRate.includes('/')) {
            let parts = this.withholdingRate.split('/');
            withh = vat * (parseFloat(parts[0]) / parseFloat(parts[1]));
        }
        let grand = (sub + vat) - withh;
        return { vat: vat.toFixed(2), withholding: withh.toFixed(2), grand: grand.toFixed(2) };
    }
}">
    <!-- Üst Başlık & İş Modeli Rozeti -->
    <div class="mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2 mb-1">
                <a href="<?= BASE_URL ?>/modules/projects/index.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800">Projeler</a>
                <span class="text-xs text-slate-400">/</span>
                <span class="text-xs font-mono font-bold text-brand-600 bg-brand-50 px-2 py-0.5 rounded border border-brand-200"><?= e($project['project_code']) ?></span>
                
                <!-- OPERASYON MODELİ ROZETİ (YENİ) -->
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $wf_info['color'] ?>">
                    <?= $wf_info['label'] ?>
                </span>
                <?php if (!empty($project['outsource_contractor_name'])): ?>
                    <span class="text-[10px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-lg border border-amber-200">
                        Taşeron: <?= e($project['outsource_contractor_name']) ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-black text-slate-900 tracking-tight"><?= e($project['project_name']) ?></h1>
                <button @click="openEditProjectModal = true" class="p-1.5 bg-slate-100 hover:bg-brand-50 text-slate-500 hover:text-brand-600 rounded-xl transition" title="Proje Bilgilerini Düzenle">
                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                </button>
            </div>
            <p class="text-xs text-slate-500 mt-0.5">
                Müşteri: <strong class="text-slate-700"><?= e($project['client_name'] ?? 'Genel') ?></strong> | 
                Tür: <strong><?= get_project_type_name($project['project_type']) ?></strong> | 
                Teslim: <strong><?= format_date($project['deadline']) ?></strong>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <?php if (!$is_invoiced): ?>
                <button @click="openInvoiceCompleteModal = true" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md shadow-emerald-600/30 transition cursor-pointer">
                    <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                    <span>Projeyi Tamamla & Carileştir</span>
                </button>
            <?php else: ?>
                <span class="inline-flex items-center gap-1.5 bg-cyan-50 text-cyan-800 border border-cyan-300 text-xs font-bold py-2 px-3.5 rounded-xl">
                    <i data-lucide="check-check" class="w-4 h-4 text-cyan-600"></i>
                    <span>Faturalandırıldı</span>
                </span>
            <?php endif; ?>

            <form method="POST" action="" class="flex items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_status">
                <select name="status" onchange="this.form.submit()" class="py-2.5 px-3 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800 shadow-xs focus:ring-2 focus:ring-brand-500 cursor-pointer">
                    <?php foreach (PROJECT_STATUSES as $st_key => $st_val): ?>
                        <option value="<?= $st_key ?>" <?= $project['status'] === $st_key ? 'selected' : '' ?>><?= $st_val['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if (has_permission('projects.delete')): ?>
            <form method="POST" action="" onsubmit="return confirm('Projeyi kalıcı olarak silmek istiyor musunuz?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_project_permanent">
                <button type="submit" class="p-2.5 bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 rounded-xl transition">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4'LÜ BÜTÇE & MALİYET KARTLARI -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Taban Sözleşme Bütçesi</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= format_money($base_budget, $project['currency']) ?></p>
            <span class="text-[10px] text-slate-400 mt-1 block">KDV Hariç Paket</span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Müşteriye Yansıtılacak (Hariç)</p>
            <p class="text-2xl font-black text-indigo-600 mt-1"><?= format_money($rebillable_fee, $project['currency']) ?></p>
            <span class="text-[10px] text-indigo-700 font-bold mt-1 block">KDV Dahil: <?= format_money($rebillable_gross, $project['currency']) ?></span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Ajans İç Set Maliyeti (Dahil)</p>
            <p class="text-2xl font-black text-rose-600 mt-1"><?= format_money($internal_gross, $project['currency']) ?></p>
            <span class="text-[10px] text-slate-500 font-medium mt-1 block">Matrah: <?= format_money($internal_fee) ?> (+<?= format_money($internal_vat) ?> KDV)</span>
        </div>

        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Net Ajans Kârı & Marj</p>
            <p class="text-2xl font-black <?= $net_agency_profit >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> mt-1">
                <?= format_money($net_agency_profit, $project['currency']) ?>
            </p>
            <span class="text-[10px] font-bold <?= $net_agency_profit >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> mt-1 block">
                Net Marj: %<?= number_format($margin_percent, 1) ?>
            </span>
        </div>
    </div>

    <!-- SEKMELER -->
    <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm mb-8" x-data="{ tab: 'revisions' }">
        <div class="flex flex-wrap border-b border-slate-200 mb-6 gap-6">
            <button @click="tab = 'shoots'" :class="tab === 'shoots' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="clapperboard" class="w-4 h-4"></i>
                <span>Çekim Günleri & Set Maliyetleri (<?= count($shoots) ?>)</span>
            </button>

            <button @click="tab = 'revisions'" :class="tab === 'revisions' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="scissors" class="w-4 h-4"></i>
                <span>Kurgu & Edit Faturalandırma Masası (<?= count($revisions) ?>)</span>
            </button>

            <button @click="tab = 'finance'" :class="tab === 'finance' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="receipt" class="w-4 h-4"></i>
                <span>Faturalar (<?= count($project_invoices) ?>)</span>
            </button>

            <button @click="tab = 'details'" :class="tab === 'details' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4"></i>
                <span>Kreatif Brief & Notlar</span>
            </button>
        </div>

        <!-- TAB 1: ÇEKİM GÜNLERİ -->
        <div x-show="tab === 'shoots'">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-slate-800">Çekim Takvimi ve Maliyet Dökümü</h3>
                <button @click="$dispatch('open-shoot-modal')" class="inline-flex items-center gap-1.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold py-2 px-3 rounded-xl transition">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    <span>Çekim Günü Ekle</span>
                </button>
            </div>

            <?php if (empty($shoots)): ?>
                <div class="py-10 text-center border-2 border-dashed border-slate-200 rounded-2xl">
                    <p class="text-xs font-medium text-slate-600">Bu projeye henüz çekim günü planlanmamış.</p>
                </div>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($shoots as $shoot): 
                        $cg_stmt = $db->prepare("SELECT scg.*, c.company_title as contact_title FROM shoot_crew_gear scg LEFT JOIN contacts c ON scg.contact_id = c.id WHERE scg.shoot_id = ?");
                        $cg_stmt->execute([$shoot['id']]);
                        $items = $cg_stmt->fetchAll();
                        $day_matrah = array_sum(array_column($items, 'agreed_fee'));
                        $day_vat    = array_sum(array_column($items, 'vat_amount'));
                        $day_gross  = $day_matrah + $day_vat;
                    ?>
                    <div class="border border-slate-200 rounded-2xl overflow-hidden bg-slate-50/50">
                        <div class="p-4 bg-white border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-brand-600"></span>
                                    <h4 class="text-sm font-bold text-slate-900"><?= e($shoot['title']) ?></h4>
                                    <span class="text-xs text-slate-500 font-medium">(<?= format_date($shoot['shoot_date']) ?>)</span>
                                </div>
                                <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                                    <i data-lucide="map-pin" class="w-3.5 h-3.5 text-slate-400"></i>
                                    <strong><?= e($shoot['location_name']) ?></strong> <?= !empty($shoot['location_address']) ? ' - ' . e($shoot['location_address']) : '' ?>
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-bold text-slate-800 bg-slate-100 px-3 py-1.5 rounded-xl border border-slate-200">
                                    Toplam: <?= format_money($day_gross, $project['currency']) ?>
                                </span>
                                
                                <a href="<?= BASE_URL ?>/modules/projects/callsheet_print.php?shoot_id=<?= $shoot['id'] ?>" target="_blank" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl" title="Call Sheet Yazdır">
                                    <i data-lucide="printer" class="w-4 h-4"></i>
                                </a>

                                <button @click="$dispatch('open-crew-modal', { shoot_id: <?= $shoot['id'] ?>, shoot_title: '<?= e($shoot['title']) ?>' })" class="inline-flex items-center gap-1 bg-brand-50 text-brand-600 hover:bg-brand-100 text-xs font-semibold py-1.5 px-3 rounded-lg border border-brand-200">
                                    <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i>
                                    <span>Gider Ekle</span>
                                </button>
                            </div>
                        </div>

                        <div class="p-4">
                            <?php if (empty($items)): ?>
                                <p class="text-xs text-slate-400 italic">Henüz gider girilmedi.</p>
                            <?php else: ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <?php foreach ($items as $it): 
                                        $is_reb = ((int)$it['is_rebillable'] === 1);
                                        $it_gross = (float)$it['agreed_fee'] + (float)$it['vat_amount'];
                                    ?>
                                    <div class="bg-white p-3.5 rounded-2xl border <?= $is_reb ? 'border-indigo-300 ring-1 ring-indigo-200' : 'border-slate-200' ?> text-xs flex items-center justify-between shadow-xs">
                                        <div>
                                            <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded <?= $is_reb ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-600' ?>">
                                                <?= $is_reb ? 'HARİÇ (Yansıtılacak)' : 'DAHİL (Ajans Gideri)' ?>
                                            </span>
                                            <p class="font-bold text-slate-900 mt-1"><?= e($it['item_title']) ?></p>
                                            <div class="mt-1">
                                                <span class="font-black text-slate-900 text-sm"><?= format_money($it_gross, $it['currency']) ?></span>
                                                <span class="text-[10px] text-slate-400 block">Matrah: <?= format_money($it['agreed_fee']) ?> (+<?= format_money($it['vat_amount']) ?> KDV)</span>
                                            </div>
                                        </div>

                                        <div class="text-right flex items-center gap-1.5">
                                            <button @click="editCrewData = {
                                                        id: '<?= $it['id'] ?>',
                                                        category: '<?= $it['category'] ?>',
                                                        item_title: '<?= e(addslashes($it['item_title'])) ?>',
                                                        agreed_fee: '<?= (float)$it['agreed_fee'] ?>',
                                                        contact_id: '<?= $it['contact_id'] ?? '' ?>',
                                                        is_rebillable: '<?= (int)$it['is_rebillable'] ?>',
                                                        vat_rate: '<?= (float)$it['vat_rate'] ?>'
                                                    }; openEditCrewModal = true"
                                                    class="p-1.5 bg-slate-100 hover:bg-brand-50 text-slate-500 rounded-lg" title="Düzenle">
                                                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                            </button>

                                            <form method="POST" action="" onsubmit="return confirm('Bu gideri silmek istiyor musunuz?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_crew_item">
                                                <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                                <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 rounded-lg">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ==================================================================== -->
        <!-- TAB 2: KURGU & EDİT FATURALANDIRMA MASASI (YENİ EKLENDİ) -->
        <!-- ==================================================================== -->
        <div x-show="tab === 'revisions'" x-cloak>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Post-Prodüksiyon & Edit Faturalandırma</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Kurgu versiyonlarını izleyebilir veya doğrudan kurgu bedelini faturalandırıp müşteriye borç yazabilirsiniz.</p>
                </div>
                <button @click="$dispatch('open-revision-modal')" class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold py-2 px-3.5 rounded-xl transition">
                    <i data-lucide="upload-cloud" class="w-3.5 h-3.5"></i>
                    <span>Yeni Kurgu Versiyonu Ekle</span>
                </button>
            </div>

            <?php if (empty($revisions)): ?>
                <div class="py-10 text-center border-2 border-dashed border-slate-200 rounded-2xl">
                    <i data-lucide="video" class="w-8 h-8 mx-auto text-slate-400 mb-2"></i>
                    <p class="text-xs font-medium text-slate-600">Henüz bir kurgu versiyonu girilmedi.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($revisions as $rev): ?>
                    <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="space-y-1.5 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-slate-900 text-sm"><?= e($rev['version_title']) ?></span>
                                <span class="text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-purple-100 text-purple-700 border border-purple-200">
                                    Editör: <?= e($rev['editor_name'] ?? 'Atanmadı') ?>
                                </span>

                                <!-- Onay Durumu -->
                                <?php if ($rev['status'] === 'approved'): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">✓ ONAYLANDI</span>
                                <?php elseif ($rev['status'] === 'revision_requested'): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-orange-100 text-orange-800 border border-orange-300">Revizyon İstendi</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">Kurgu Sürüyor</span>
                                <?php endif; ?>

                                <!-- Faturalandırma Durumu -->
                                <?php if ($rev['billing_status'] === 'invoiced'): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-cyan-100 text-cyan-800 border border-cyan-300">
                                        💼 Faturalandırıldı (<?= format_money($rev['billing_fee']) ?>)
                                    </span>
                                <?php elseif ($rev['billing_status'] === 'debited'): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                        📄 Borç Dekontu Yazıldı (<?= format_money($rev['billing_fee']) ?>)
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($rev['feedback_notes'])): ?>
                                <div class="mt-2 p-3 bg-white rounded-xl border border-slate-200 text-xs text-slate-700">
                                    <strong>Geri Bildirim / Notlar:</strong> <?= nl2br(e($rev['feedback_notes'])) ?>
                                </div>
                            <?php endif; ?>

                            <span class="text-[10px] text-slate-400 block">Tarih: <?= format_date($rev['created_at'], true) ?></span>
                        </div>

                        <!-- AKSİYONLAR -->
                        <div class="flex flex-wrap items-center gap-2 flex-shrink-0">
                            <!-- BAĞIMSIZ EDİT FATURALANDIRMA BUTONU (YENİ) -->
                            <?php if ($rev['billing_status'] === 'unbilled'): ?>
                            <button @click="billEditData = { revision_id: '<?= $rev['id'] ?>', title: '<?= e(addslashes($rev['version_title'])) ?>', fee: 0, billing_type: 'invoice', vat_rate: 20 }; openBillEditModal = true"
                                    class="inline-flex items-center gap-1 px-3 py-2 bg-indigo-50 hover:bg-indigo-600 hover:text-white text-indigo-700 font-bold rounded-xl border border-indigo-200 text-xs transition cursor-pointer">
                                <i data-lucide="receipt" class="w-3.5 h-3.5"></i>
                                <span>Edit Bedelini Faturalandır / Borçlandır</span>
                            </button>
                            <?php endif; ?>

                            <?php if (!empty($rev['preview_url'])): ?>
                                <a href="<?= e($rev['preview_url']) ?>" target="_blank" class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2 px-3.5 rounded-xl shadow-xs transition">
                                    <i data-lucide="play" class="w-3.5 h-3.5"></i>
                                    <span>İzle</span>
                                </a>
                            <?php endif; ?>

                            <button @click="editRevData = { 
                                        id: '<?= $rev['id'] ?>', 
                                        version_title: '<?= e(addslashes($rev['version_title'])) ?>', 
                                        assigned_editor_id: '<?= $rev['assigned_editor_id'] ?? '' ?>', 
                                        preview_url: '<?= e(addslashes($rev['preview_url'] ?? '')) ?>', 
                                        feedback_notes: '<?= e(addslashes($rev['feedback_notes'] ?? '')) ?>', 
                                        status: '<?= $rev['status'] ?>' 
                                    }; openEditRevModal = true" 
                                    class="p-2 bg-white hover:bg-brand-50 hover:text-brand-600 text-slate-600 border border-slate-200 rounded-xl transition" title="Versiyonu Düzenle">
                                <i data-lucide="edit-3" class="w-4 h-4"></i>
                            </button>

                            <form method="POST" action="" onsubmit="return confirm('Bu kurgu versiyonunu silmek istediğinize emin misiniz?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_revision">
                                <input type="hidden" name="revision_id" value="<?= $rev['id'] ?>">
                                <button type="submit" class="p-2 bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 rounded-xl transition" title="Versiyonu Sil">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 3: FATURALAR -->
        <div x-show="tab === 'finance'" x-cloak>
            <h3 class="text-sm font-bold text-slate-800 mb-4">Projeye Bağlı Faturalar</h3>
            <div class="space-y-3">
                <?php foreach ($project_invoices as $p_inv): 
                    $is_sales = ($p_inv['invoice_type'] === 'sales');
                ?>
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between text-xs">
                    <div>
                        <span class="font-mono font-bold text-slate-900 text-sm block"><?= e($p_inv['invoice_number']) ?></span>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded <?= $is_sales ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                            <?= $is_sales ? '↗ SATIŞ FATURASI (GELİR)' : '↘ GİDER / ALIŞ FATURASI' ?>
                        </span>
                    </div>
                    <div class="text-right">
                        <span class="text-sm font-black text-slate-900 block"><?= format_money($p_inv['grand_total']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TAB 4: BRİEF & NOTLAR -->
        <div x-show="tab === 'details'" x-cloak>
            <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 text-xs text-slate-700 whitespace-pre-line">
                <?= !empty($project['description']) ? e($project['description']) : 'Not girilmemiş.' ?>
            </div>
        </div>
    </div>

    <!-- ===================================================== -->
    <!-- MODAL 1: EDİT HİZMETİNİ FATURALANDIRMA / BORÇLANDIRMA (YENİ) -->
    <!-- ===================================================== -->
    <div x-show="openBillEditModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openBillEditModal = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Edit / Kurgu Hizmetini Faturalandır</h3>
                    <p class="text-xs text-slate-500" x-text="billEditData.title"></p>
                </div>
                <button type="button" @click="openBillEditModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bill_edit_service">
                <input type="hidden" name="revision_id" :value="billEditData.revision_id">

                <div class="p-3.5 bg-indigo-50/80 rounded-2xl border border-indigo-200 space-y-2 text-xs">
                    <label class="block font-bold text-indigo-950 uppercase text-[11px]">Borçlandırma & Fatura Seçeneği *</label>
                    
                    <label class="flex items-center gap-2 p-2 bg-white rounded-xl border border-indigo-200 cursor-pointer">
                        <input type="radio" name="billing_type" value="invoice" x-model="billEditData.billing_type" class="text-indigo-600">
                        <div>
                            <span class="font-bold text-slate-900 block text-[11px]">Resmi Satış Faturası Kes (KDV'li)</span>
                            <span class="text-[10px] text-slate-400">Satış faturası oluşturur ve KDV dahil cariye borç yazar</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-2 p-2 bg-white rounded-xl border border-indigo-200 cursor-pointer">
                        <input type="radio" name="billing_type" value="debit" x-model="billEditData.billing_type" class="text-indigo-600">
                        <div>
                            <span class="font-bold text-slate-900 block text-[11px]">Faturasız Manuel Borç Dekontu (KDV'siz)</span>
                            <span class="text-[10px] text-slate-400">Fatura kesmeden doğrudan müşterinin cari borcuna ekler</span>
                        </div>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Edit Bedeli (KDV Hariç) *</label>
                        <input type="number" step="0.01" name="billing_fee" x-model="billEditData.fee" required placeholder="0.00" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">KDV Oranı</label>
                        <select name="vat_rate" x-model="billEditData.vat_rate" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (VAT_RATES as $vr): ?>
                                <option value="<?= $vr ?>">%<?= $vr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">İşlem Tarihi</label>
                    <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <input type="text" name="notes" placeholder="Örn: 2 adet Reels revizyonu ve Color Grading bedeli" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openBillEditModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-xs shadow-md">
                        ✓ Müşteri Carisine Borç Yaz
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: PROJE BİLGİLERİ & OPERASYON MODELİ DÜZENLEME -->
    <div x-show="openEditProjectModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openEditProjectModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Proje Detayları & İş Modeli</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_project_details">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Proje Adı *</label>
                    <input type="text" name="project_name" required value="<?= e($project['project_name']) ?>" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Prodüksiyon Türü</label>
                        <select name="project_type" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach ($project_types as $pt_k => $pt_v): ?>
                                <option value="<?= e($pt_k) ?>" <?= $project['project_type'] === $pt_k ? 'selected' : '' ?>><?= e($pt_v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Taban Bütçe *</label>
                        <input type="number" step="0.01" name="agreed_budget" required value="<?= (float)$project['agreed_budget'] ?>" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <!-- OPERASYON & İŞ MODELİ SEÇİMİ -->
                <div class="p-3.5 bg-indigo-50/80 rounded-2xl border border-indigo-200 space-y-2">
                    <label class="block text-xs font-bold uppercase text-indigo-950">Operasyon & İş Modeli *</label>
                    <select name="workflow_model" x-model="selectedWfModel" class="w-full py-2.5 px-3 bg-white border border-indigo-300 rounded-xl text-xs font-bold text-indigo-950">
                        <?php foreach (WORKFLOW_MODELS as $wmk => $wmv): ?>
                            <option value="<?= $wmk ?>" <?= ($project['workflow_model'] ?? '') === $wmk ? 'selected' : '' ?>><?= $wmv['label'] ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div x-show="selectedWfModel && selectedWfModel.includes('outsource')" class="pt-2">
                        <label class="block text-[11px] font-bold uppercase text-amber-900 mb-1">Taşeron / Dış Ekip Carisi</label>
                        <select name="outsource_contact_id" class="w-full py-2 px-3 bg-white border border-amber-300 rounded-xl text-xs font-semibold text-amber-950">
                            <option value="">-- Dış Ekip Seçin --</option>
                            <?php foreach ($freelancers as $fl): ?>
                                <option value="<?= $fl['id'] ?>" <?= (int)$project['outsource_contact_id'] === (int)$fl['id'] ? 'selected' : '' ?>><?= e($fl['company_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Başlangıç</label>
                        <input type="date" name="start_date" value="<?= e($project['start_date']) ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Teslim Tarihi</label>
                        <input type="date" name="deadline" value="<?= e($project['deadline']) ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Notlar</label>
                    <textarea name="description" rows="2" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"><?= e($project['description']) ?></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditProjectModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: YENİ KURGU VERSİYONU -->
    <div x-data="{ open: false }" @open-revision-modal.window="open = true" x-show="open" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="open = false">
            <h3 class="text-sm font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Yeni Kurgu Versiyonu Ekle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_revision">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Versiyon Adı *</label>
                        <input type="text" name="version_title" required value="Kurgu v1" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Sorumlu Editör</label>
                        <select name="assigned_editor_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                            <option value="">-- Editör Seçin --</option>
                            <?php foreach ($editors as $ed): ?>
                                <option value="<?= $ed['id'] ?>"><?= e($ed['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Önizleme Linki</label>
                    <input type="url" name="preview_url" placeholder="https://..." class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Geri Bildirimler</label>
                    <textarea name="feedback_notes" rows="2" placeholder="Notlar..." class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="open = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-semibold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: KURGU DÜZENLE -->
    <div x-show="openEditRevModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="openEditRevModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Kurgu Versiyonunu Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_revision">
                <input type="hidden" name="revision_id" :value="editRevData.id">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Versiyon Adı *</label>
                        <input type="text" name="version_title" required x-model="editRevData.version_title" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Sorumlu Editör</label>
                        <select name="assigned_editor_id" x-model="editRevData.assigned_editor_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="">-- Editör Seçin --</option>
                            <?php foreach ($editors as $ed): ?>
                                <option value="<?= $ed['id'] ?>"><?= e($ed['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Önizleme Linki</label>
                    <input type="url" name="preview_url" x-model="editRevData.preview_url" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Durum</label>
                    <select name="status" x-model="editRevData.status" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <option value="in_progress">Kurgu Sürüyor</option>
                        <option value="sent_to_client">Müşteriye Sunuldu</option>
                        <option value="revision_requested">Revizyon İstendi</option>
                        <option value="approved">✓ Onaylandı</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Notlar</label>
                    <textarea name="feedback_notes" rows="2" x-model="editRevData.feedback_notes" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditRevModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 5: YENİ ÇEKİM GÜNÜ EKLE -->
    <div x-data="{ open: false }" @open-shoot-modal.window="open = true" x-show="open" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="open = false">
            <h3 class="text-sm font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Yeni Çekim Günü Planla</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_shoot">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Çekim Başlığı *</label>
                    <input type="text" name="title" required value="1. Gün Seti" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Çekim Tarihi *</label>
                        <input type="date" name="shoot_date" required value="<?= date('Y-m-d') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Mekan / Plato Adı *</label>
                        <input type="text" name="location_name" required placeholder="Plato Adı" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Mekan Adresi</label>
                    <input type="text" name="location_address" placeholder="Açık adres..." class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Call Sheet Notları</label>
                    <textarea name="call_sheet_notes" rows="2" placeholder="Giriş saati vb." class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="open = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-semibold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 6: YENİ SET GİDERİ EKLE -->
    <div x-data="{ open: false, shootId: null, shootTitle: '' }" 
         @open-crew-modal.window="open = true; shootId = $event.detail.shoot_id; shootTitle = $event.detail.shoot_title" 
         x-show="open" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="open = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <h3 class="text-base font-bold text-slate-900">Set Gideri / Kaşe Ekle</h3>
                <button type="button" @click="open = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_crew_gear">
                <input type="hidden" name="shoot_id" :value="shootId">

                <div class="p-3.5 bg-indigo-50/80 rounded-2xl border border-indigo-200">
                    <label class="block text-xs font-bold uppercase text-indigo-950 mb-2">Bütçe Dahil / Hariç Durumu *</label>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <label class="flex items-center gap-2 p-2.5 bg-white rounded-xl border border-indigo-200 cursor-pointer">
                            <input type="radio" name="is_rebillable" value="0" checked class="text-indigo-600">
                            <div>
                                <span class="font-bold text-slate-900 block text-[11px]">Bütçeye DAHİL</span>
                                <span class="text-[10px] text-slate-400">Ajans içi gideri</span>
                            </div>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 bg-white rounded-xl border border-indigo-200 cursor-pointer">
                            <input type="radio" name="is_rebillable" value="1" class="text-indigo-600">
                            <div>
                                <span class="font-bold text-indigo-700 block text-[11px]">Bütçeye HARİÇ</span>
                                <span class="text-[10px] text-slate-500">Müşteriye yansıtılır</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Kategori *</label>
                        <select name="category" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (CREW_CATEGORIES as $ckey => $cname): ?>
                                <option value="<?= $ckey ?>"><?= $cname ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tutar (KDV Hariç) *</label>
                        <input type="number" step="0.01" name="agreed_fee" required placeholder="0.00" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama *</label>
                    <input type="text" name="item_title" required placeholder="Örn: Sesçi Kaşesi veya Lens Kirası" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tedarikçi Carisi</label>
                        <select name="contact_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="">-- Cari Seçin --</option>
                            <?php foreach ($freelancers as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= e($f['company_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">KDV Oranı</label>
                        <select name="vat_rate" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (VAT_RATES as $vr): ?>
                                <option value="<?= $vr ?>">%<?= $vr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-800">
                        <input type="checkbox" name="create_purchase_invoice" value="1" checked class="rounded text-brand-600">
                        <span>Otomatik Resmi Alış Faturası Kes & Carisine Alacak İşle</span>
                    </label>
                    <input type="text" name="invoice_number" placeholder="Fatura No (Opsiyonel)" class="w-full py-1.5 px-3 bg-white border border-slate-200 rounded-lg text-xs font-mono">
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="open = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-xl text-xs shadow-md transition">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 7: PROJEYİ TAMAMLA & FATURALANDIR -->
    <div x-show="openInvoiceCompleteModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6">
        <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openInvoiceCompleteModal = false">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                <h3 class="text-base font-bold text-slate-900">Projeyi Tamamla & Faturalandır</h3>
                <button @click="openInvoiceCompleteModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="complete_and_invoice">
                <input type="hidden" name="final_billing_subtotal" value="<?= $final_billing_subtotal ?>">

                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-1.5 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Taban Bütçe:</span>
                        <strong><?= format_money($base_budget, $project['currency']) ?></strong>
                    </div>
                    <div class="flex justify-between text-indigo-700 font-bold">
                        <span>+ Yansıtılacak Ek Giderler (Hariç):</span>
                        <span><?= format_money($rebillable_fee, $project['currency']) ?></span>
                    </div>
                    <div class="pt-2 border-t border-slate-200 flex justify-between font-black text-slate-900 text-sm">
                        <span>Fatura Matrahı:</span>
                        <span><?= format_money($final_billing_subtotal, $project['currency']) ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Satış Fatura No *</label>
                        <input type="text" name="invoice_number" required value="RYM-<?= date('Y') ?>-<?= str_pad((string)$project['id'], 4, '0', STR_PAD_LEFT) ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Fatura Tarihi</label>
                        <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-200 space-y-1 text-xs">
                    <div class="flex justify-between text-emerald-900"><span>KDV:</span> <strong x-text="calcInvoice().vat + ' ₺'"></strong></div>
                    <div class="pt-2 border-t border-emerald-200 flex justify-between text-sm font-bold text-emerald-950">
                        <span>Müşteriye Net Borç:</span>
                        <span x-text="calcInvoice().grand + ' ₺'"></span>
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openInvoiceCompleteModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">Vazgeç</button>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs shadow-md transition">✓ Onayla ve Carileştir</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>