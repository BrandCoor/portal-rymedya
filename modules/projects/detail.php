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

require_staff_login();
require_permission('projects.view');

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
$detail_url = BASE_URL . "/modules/projects/detail.php?id={$project_id}";
$client_contact_id = (int)($project['client_contact_id'] ?? 0);

// Bu projeye ait çekim mi?
$find_shoot = function (int $shoot_id) use ($db, $project_id) {
    $st = $db->prepare("SELECT * FROM shoots WHERE id = ? AND project_id = ?");
    $st->execute([$shoot_id, $project_id]);
    return $st->fetch();
};
// Bu projeye ait set gideri mi?
$find_crew_item = function (int $item_id) use ($db, $project_id) {
    $st = $db->prepare("SELECT scg.* FROM shoot_crew_gear scg JOIN shoots s ON scg.shoot_id = s.id WHERE scg.id = ? AND s.project_id = ?");
    $st->execute([$item_id, $project_id]);
    return $st->fetch();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // İşlem sonrası ilgili sekmeye geri dön
    $action_tabs = [
        'add_shoot' => 'shoots', 'edit_shoot' => 'shoots', 'delete_shoot' => 'shoots',
        'add_crew_gear' => 'shoots', 'edit_crew_gear' => 'shoots', 'delete_crew_item' => 'shoots',
        'add_revision' => 'revisions', 'edit_revision' => 'revisions', 'delete_revision' => 'revisions', 'bill_edit_service' => 'revisions',
        'complete_and_invoice' => 'finance', 'cancel_project_invoice' => 'finance',
        'add_task' => 'tasks', 'edit_task' => 'tasks', 'toggle_task' => 'tasks', 'delete_task' => 'tasks',
        'add_deliverable' => 'deliverables', 'delete_deliverable' => 'deliverables',
    ];
    if (isset($action_tabs[$action])) {
        $detail_url .= '&tab=' . $action_tabs[$action];
    }

    // Görev durumunu değiştirmek (kendi görevi ise) proje düzenleme yetkisi gerektirmez
    if ($action === 'toggle_task') {
        $t_stmt = $db->prepare("SELECT * FROM project_tasks WHERE id = ? AND project_id = ?");
        $t_stmt->execute([(int)($_POST['task_id'] ?? 0), $project_id]);
        $task = $t_stmt->fetch();
        $new_status = $_POST['status'] ?? 'done';
        if ($task && array_key_exists($new_status, TASK_STATUSES)
            && (has_permission('projects.edit') || (int)$task['assigned_user_id'] === (int)$user['id'])) {
            $db->prepare("UPDATE project_tasks SET status = ?, completed_at = ? WHERE id = ?")
               ->execute([$new_status, $new_status === 'done' ? date('Y-m-d H:i:s') : null, $task['id']]);
            if ($new_status === 'done' && !empty($task['created_by']) && (int)$task['created_by'] !== (int)$user['id']) {
                log_activity('task_done', "Görev tamamlandı: {$task['title']} ({$project['project_name']})", 'task', (int)$task['id'], "/modules/projects/detail.php?id={$project_id}&tab=tasks", (int)$task['created_by']);
            }
            set_flash('success', 'Görev durumu güncellendi.');
        }
        redirect((($_POST['return_to'] ?? '') === 'tasks') ? BASE_URL . '/modules/tasks/index.php' : $detail_url);
    }

    // Proje üzerinde değişiklik yapan tüm işlemler düzenleme yetkisi ister (silme hariç)
    if (!in_array($action, ['delete_project_permanent'], true)) {
        require_permission('projects.edit');
    }

    // ==========================================
    // A. KURGU / EDİT HİZMETİNİ FATURALANDIRMA & BORÇLANDIRMA
    // ==========================================
    if ($action === 'bill_edit_service') {
        $rev_id       = (int)$_POST['revision_id'];
        $edit_fee     = parse_money($_POST['billing_fee'] ?? '0');
        $billing_type = ($_POST['billing_type'] ?? 'invoice') === 'debit' ? 'debit' : 'invoice';
        $vat_rate     = (float)($_POST['vat_rate'] ?? 20);
        $issue_date   = valid_date($_POST['issue_date'] ?? '', date('Y-m-d'));
        $notes        = trim($_POST['notes'] ?? '');

        $rev_stmt = $db->prepare("SELECT * FROM project_revisions WHERE id = ? AND project_id = ?");
        $rev_stmt->execute([$rev_id, $project_id]);
        $rev = $rev_stmt->fetch();

        if (!$rev || $edit_fee <= 0 || $client_contact_id <= 0) {
            set_flash('error', 'Geçerli bir kurgu versiyonu, müşteri ve tutar gereklidir.');
            redirect($detail_url);
        }
        if (($rev['billing_status'] ?? 'unbilled') !== 'unbilled') {
            set_flash('error', 'Bu kurgu versiyonu zaten faturalandırılmış / borçlandırılmış.');
            redirect($detail_url);
        }

        $created_invoice_id = null;

        if ($billing_type === 'invoice') {
            // 1. Seçenek: KDV'li Resmi Satış Faturası Kes
            $tax = calculate_tax_breakdown($edit_fee, $vat_rate, '0/10', 0);
            $inv_no = generate_invoice_number('sales');

            $db->prepare("
                INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at)
                VALUES ('sales', ?, ?, ?, ?, ?, ?, ?, '0/10', 0, 0, 0, ?, 'unpaid', ?, NOW())
            ")->execute([
                $inv_no, $client_contact_id, $project_id, $issue_date,
                $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'],
                ($notes ?: "{$project['project_name']} ({$rev['version_title']}) kurgu & edit hizmet bedeli")
            ]);
            $created_invoice_id = (int)$db->lastInsertId();
            $new_b_status = 'invoiced';
        } else {
            // 2. Seçenek: Faturasız Manuel Borç Dekontu Kes (kasaya dokunmaz)
            $db->prepare("
                INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at)
                VALUES (NULL, ?, NULL, ?, 'expense', 'Kurgu / Edit Hizmet Dekontu', ?, ?, ?, ?, NOW())
            ")->execute([
                $client_contact_id, $project_id, $edit_fee, $issue_date,
                ($notes ?: "{$project['project_name']} ({$rev['version_title']}) faturasız edit bedeli"), $user['id']
            ]);
            $new_b_status = 'debited';
        }

        $db->prepare("UPDATE project_revisions SET billing_status = ?, billing_fee = ?, invoice_id = ? WHERE id = ?")
           ->execute([$new_b_status, $edit_fee, $created_invoice_id, $rev_id]);

        recalculate_contact_balance($client_contact_id);
        set_flash('success', "{$rev['version_title']} edit hizmeti başarıyla faturalandırıldı / müşteriye borç kaydedildi.");
        redirect($detail_url);
    }

    // ==========================================
    // B. PROJE BİLGİLERİ VE İŞ MODELİNİ DÜZENLEME
    // ==========================================
    if ($action === 'edit_project_details') {
        $p_name           = trim($_POST['project_name'] ?? '');
        $p_type           = $_POST['project_type'] ?? 'commercial';
        $p_workflow       = array_key_exists($_POST['workflow_model'] ?? '', WORKFLOW_MODELS) ? $_POST['workflow_model'] : 'internal_full';
        $p_outsource_id   = (!empty($_POST['outsource_contact_id']) && str_contains($p_workflow, 'outsource')) ? (int)$_POST['outsource_contact_id'] : null;
        $p_budget         = parse_money($_POST['agreed_budget'] ?? '0');
        $p_currency       = array_key_exists($_POST['currency'] ?? '', CURRENCIES) ? $_POST['currency'] : $project['currency'];
        $p_start          = valid_date($_POST['start_date'] ?? '');
        $p_deadline       = valid_date($_POST['deadline'] ?? '');
        $p_desc           = trim($_POST['description'] ?? '');

        if ($p_start && $p_deadline && $p_deadline < $p_start) {
            set_flash('error', 'Teslim tarihi başlangıç tarihinden önce olamaz.');
        } elseif ($p_name !== '') {
            $db->prepare("
                UPDATE projects 
                SET project_name = ?, project_type = ?, workflow_model = ?, outsource_contact_id = ?, agreed_budget = ?, currency = ?, start_date = ?, deadline = ?, description = ?
                WHERE id = ?
            ")->execute([$p_name, $p_type, $p_workflow, $p_outsource_id, $p_budget, $p_currency, $p_start, $p_deadline, $p_desc, $project_id]);
            set_flash('success', 'Proje iş modeli ve detayları güncellendi.');
        }
        redirect($detail_url);
    }

    // ==========================================
    // C. SET GİDERİ İŞLEMLERİ
    // ==========================================
    if ($action === 'add_crew_gear') {
        $shoot          = $find_shoot((int)($_POST['shoot_id'] ?? 0));
        $category       = array_key_exists($_POST['category'] ?? '', CREW_CATEGORIES) ? $_POST['category'] : 'other';
        $item_title     = trim($_POST['item_title'] ?? '');
        $fee            = parse_money($_POST['agreed_fee'] ?? '0');
        $contact_id     = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $is_rebillable  = (int)($_POST['is_rebillable'] ?? 0) === 1 ? 1 : 0;
        $vat_rate       = (float)($_POST['vat_rate'] ?? 20);
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $auto_invoice   = isset($_POST['create_purchase_invoice']);

        if (!$shoot || $item_title === '') {
            set_flash('error', 'Geçerli bir çekim günü ve gider başlığı giriniz.');
            redirect($detail_url);
        }

        $vat_amount  = round($fee * ($vat_rate / 100), 2);
        $grand_total = round($fee + $vat_amount, 2);
        $created_invoice_id = null;

        if ($auto_invoice && $contact_id && $fee > 0) {
            if ($invoice_number !== '' && invoice_number_exists($invoice_number, 0, 'purchase')) {
                set_flash('error', "{$invoice_number} numaralı alış faturası zaten kayıtlı.");
                redirect($detail_url);
            }
            $inv_no = $invoice_number !== '' ? $invoice_number : generate_invoice_number('purchase');
            $db->prepare("
                INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at)
                VALUES ('purchase', ?, ?, ?, ?, ?, ?, ?, '0/10', 0, 0, 0, ?, 'unpaid', ?, NOW())
            ")->execute([$inv_no, $contact_id, $project_id, $shoot['shoot_date'] ?: date('Y-m-d'), $fee, $vat_rate, $vat_amount, $grand_total, "{$project['project_name']} ({$item_title}) gideri"]);
            $created_invoice_id = (int)$db->lastInsertId();
            $invoice_number = $inv_no;
            recalculate_contact_balance($contact_id);
        }

        $db->prepare("INSERT INTO shoot_crew_gear (shoot_id, contact_id, category, item_title, agreed_fee, currency, is_rebillable, vat_rate, vat_amount, invoice_id, invoice_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$shoot['id'], $contact_id, $category, $item_title, $fee, $project['currency'], $is_rebillable, $vat_rate, $vat_amount, $created_invoice_id, $invoice_number ?: null]);

        set_flash('success', 'Set gideri başarıyla kaydedildi.');
        redirect($detail_url);
    }

    if ($action === 'edit_crew_gear') {
        $old_it = $find_crew_item((int)($_POST['item_id'] ?? 0));
        if (!$old_it) {
            set_flash('error', 'Gider kaydı bulunamadı.');
            redirect($detail_url);
        }

        $category       = array_key_exists($_POST['category'] ?? '', CREW_CATEGORIES) ? $_POST['category'] : $old_it['category'];
        $item_title     = trim($_POST['item_title'] ?? '') ?: $old_it['item_title'];
        $fee            = parse_money($_POST['agreed_fee'] ?? '0');
        $contact_id     = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        $is_rebillable  = (int)($_POST['is_rebillable'] ?? 0) === 1 ? 1 : 0;
        $vat_rate       = (float)($_POST['vat_rate'] ?? 20);
        $vat_amount     = round($fee * ($vat_rate / 100), 2);
        $grand_total    = round($fee + $vat_amount, 2);

        $affected_contacts = array_filter([(int)$old_it['contact_id'], (int)$contact_id]);

        if (!empty($old_it['invoice_id'])) {
            $inv_stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $inv_stmt->execute([(int)$old_it['invoice_id']]);
            $old_inv = $inv_stmt->fetch();
            if ($old_inv) {
                $inv_contact = $contact_id ?: (int)$old_inv['contact_id'];
                $affected_contacts[] = (int)$old_inv['contact_id'];
                $db->prepare("UPDATE invoices SET subtotal = ?, vat_rate = ?, vat_amount = ?, grand_total = ?, contact_id = ? WHERE id = ?")
                   ->execute([$fee, $vat_rate, $vat_amount, $grand_total, $inv_contact, $old_it['invoice_id']]);
                sync_invoice_payment((int)$old_it['invoice_id']);
            }
        }

        $db->prepare("UPDATE shoot_crew_gear SET category = ?, item_title = ?, agreed_fee = ?, contact_id = ?, is_rebillable = ?, vat_rate = ?, vat_amount = ? WHERE id = ?")
           ->execute([$category, $item_title, $fee, $contact_id, $is_rebillable, $vat_rate, $vat_amount, $old_it['id']]);

        foreach (array_unique($affected_contacts) as $cid) {
            recalculate_contact_balance((int)$cid);
        }
        set_flash('success', 'Gider güncellendi.');
        redirect($detail_url);
    }

    if ($action === 'delete_crew_item') {
        $old_it = $find_crew_item((int)($_POST['item_id'] ?? 0));
        if ($old_it) {
            if (!empty($old_it['invoice_id'])) {
                delete_invoice_cascade((int)$old_it['invoice_id']);
            }
            $db->prepare("DELETE FROM shoot_crew_gear WHERE id = ?")->execute([$old_it['id']]);
            set_flash('success', 'Gider ve varsa bağlı alış faturası silindi.');
        }
        redirect($detail_url);
    }

    // ==========================================
    // D. KURGU VERSİYONLARI
    // ==========================================
    $rev_statuses = ['in_progress', 'sent_to_client', 'revision_requested', 'approved'];

    if ($action === 'add_revision') {
        $title = trim($_POST['version_title'] ?? '');
        if ($title !== '') {
            $db->prepare("INSERT INTO project_revisions (project_id, assigned_editor_id, version_title, preview_url, feedback_notes, status) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([
                   $project_id, !empty($_POST['assigned_editor_id']) ? (int)$_POST['assigned_editor_id'] : null, $title,
                   trim($_POST['preview_url'] ?? ''), trim($_POST['feedback_notes'] ?? ''),
                   in_array($_POST['status'] ?? '', $rev_statuses, true) ? $_POST['status'] : 'in_progress'
               ]);
            $new_rev_id = (int)$db->lastInsertId();
            $editor_id = !empty($_POST['assigned_editor_id']) ? (int)$_POST['assigned_editor_id'] : 0;
            if ($editor_id && $editor_id !== (int)$user['id']) {
                log_activity('revision_assigned', "Kurgu ataması: {$title} ({$project['project_name']})", 'revision', $new_rev_id, "/modules/projects/detail.php?id={$project_id}&tab=revisions", $editor_id);
            }
            set_flash('success', 'Kurgu versiyonu eklendi.');
        }
        redirect($detail_url);
    }

    if ($action === 'edit_revision') {
        $title = trim($_POST['version_title'] ?? '');
        if ($title !== '') {
            $db->prepare("UPDATE project_revisions SET version_title = ?, preview_url = ?, assigned_editor_id = ?, feedback_notes = ?, status = ? WHERE id = ? AND project_id = ?")
               ->execute([
                   $title, trim($_POST['preview_url'] ?? ''), !empty($_POST['assigned_editor_id']) ? (int)$_POST['assigned_editor_id'] : null,
                   trim($_POST['feedback_notes'] ?? ''), in_array($_POST['status'] ?? '', $rev_statuses, true) ? $_POST['status'] : 'in_progress',
                   (int)$_POST['revision_id'], $project_id
               ]);
            set_flash('success', 'Kurgu versiyonu güncellendi.');
        }
        redirect($detail_url);
    }

    if ($action === 'delete_revision') {
        $rev_stmt = $db->prepare("SELECT * FROM project_revisions WHERE id = ? AND project_id = ?");
        $rev_stmt->execute([(int)$_POST['revision_id'], $project_id]);
        $rev = $rev_stmt->fetch();
        if ($rev && ($rev['billing_status'] ?? 'unbilled') !== 'unbilled') {
            set_flash('error', 'Faturalandırılmış / borçlandırılmış bir kurgu versiyonu silinemez. Önce ilgili faturayı veya dekontu iptal ediniz.');
        } elseif ($rev) {
            $db->prepare("DELETE FROM project_revisions WHERE id = ? AND project_id = ?")->execute([$rev['id'], $project_id]);
            set_flash('success', 'Kurgu versiyonu silindi.');
        }
        redirect($detail_url);
    }

    // ==========================================
    // E. ÇEKİM GÜNLERİ
    // ==========================================
    if ($action === 'add_shoot') {
        $title = trim($_POST['title'] ?? '');
        $date  = valid_date($_POST['shoot_date'] ?? '');
        if ($title === '' || !$date) {
            set_flash('error', 'Çekim başlığı ve geçerli bir tarih zorunludur.');
        } else {
            $db->prepare("INSERT INTO shoots (project_id, title, shoot_date, start_time, end_time, location_name, location_address, call_sheet_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$project_id, $title, $date, ($_POST['start_time'] ?? '') ?: null, ($_POST['end_time'] ?? '') ?: null, trim($_POST['location_name'] ?? ''), trim($_POST['location_address'] ?? ''), trim($_POST['call_sheet_notes'] ?? '')]);
            set_flash('success', 'Çekim günü eklendi.');
        }
        redirect($detail_url);
    }

    if ($action === 'edit_shoot') {
        $shoot = $find_shoot((int)($_POST['shoot_id'] ?? 0));
        $title = trim($_POST['title'] ?? '');
        $date  = valid_date($_POST['shoot_date'] ?? '');
        if ($shoot && $title !== '' && $date) {
            $db->prepare("UPDATE shoots SET title = ?, shoot_date = ?, start_time = ?, end_time = ?, location_name = ?, location_address = ?, call_sheet_notes = ? WHERE id = ? AND project_id = ?")
               ->execute([$title, $date, ($_POST['start_time'] ?? '') ?: null, ($_POST['end_time'] ?? '') ?: null, trim($_POST['location_name'] ?? ''), trim($_POST['location_address'] ?? ''), trim($_POST['call_sheet_notes'] ?? ''), $shoot['id'], $project_id]);
            set_flash('success', 'Çekim günü güncellendi.');
        } else {
            set_flash('error', 'Çekim başlığı ve geçerli bir tarih zorunludur.');
        }
        redirect($detail_url);
    }

    if ($action === 'delete_shoot') {
        $shoot = $find_shoot((int)($_POST['shoot_id'] ?? 0));
        if ($shoot) {
            // Çekim giderlerinden otomatik oluşan alış faturaları da silinir
            $items = $db->prepare("SELECT invoice_id FROM shoot_crew_gear WHERE shoot_id = ? AND invoice_id IS NOT NULL");
            $items->execute([$shoot['id']]);
            foreach ($items->fetchAll(PDO::FETCH_COLUMN) as $inv_id) {
                delete_invoice_cascade((int)$inv_id);
            }
            $db->prepare("DELETE FROM shoot_crew_gear WHERE shoot_id = ?")->execute([$shoot['id']]);
            $db->prepare("DELETE FROM shoots WHERE id = ? AND project_id = ?")->execute([$shoot['id'], $project_id]);
            set_flash('success', 'Çekim günü ve bağlı giderleri silindi.');
        }
        redirect($detail_url);
    }

    // ==========================================
    // F. PROJEYİ TAMAMLA & FATURALANDIR
    // ==========================================
    if ($action === 'complete_and_invoice') {
        $invoice_number   = trim($_POST['invoice_number'] ?? '') ?: generate_invoice_number('sales');
        $withholding_rate = array_key_exists($_POST['withholding_rate'] ?? '', WITHHOLDING_RATES) ? $_POST['withholding_rate'] : '0/10';
        $vat_rate         = (float)($_POST['vat_rate'] ?? 20);
        $issue_date       = valid_date($_POST['issue_date'] ?? '', date('Y-m-d'));
        $due_date         = valid_date($_POST['due_date'] ?? '');

        if ($client_contact_id <= 0) {
            set_flash('error', 'Projeye bağlı bir müşteri carisi bulunamadı.');
        } elseif ($project['status'] === 'invoiced' || project_main_sales_invoice_id($project_id) !== null) {
            set_flash('error', 'Bu proje zaten faturalandırılmış.');
        } elseif (invoice_number_exists($invoice_number, 0, 'sales')) {
            set_flash('error', "{$invoice_number} numaralı satış faturası zaten mevcut. Lütfen farklı bir numara giriniz.");
        } else {
            // Matrah sunucu tarafında yeniden hesaplanır (formdan gelen değere güvenilmez)
            $reb = $db->prepare("SELECT COALESCE(SUM(scg.agreed_fee), 0) FROM shoot_crew_gear scg JOIN shoots s ON scg.shoot_id = s.id WHERE s.project_id = ? AND scg.is_rebillable = 1");
            $reb->execute([$project_id]);
            $subtotal = (float)$project['agreed_budget'] + (float)$reb->fetchColumn();

            if ($subtotal <= 0) {
                set_flash('error', 'Faturalandırılacak tutar sıfır. Lütfen proje bütçesini giriniz.');
                redirect($detail_url);
            }

            $tax = calculate_tax_breakdown($subtotal, $vat_rate, $withholding_rate, 0);
            $db->prepare("INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, due_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at) VALUES ('sales', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 'unpaid', ?, NOW())")
               ->execute([$invoice_number, $client_contact_id, $project_id, $issue_date, $due_date, $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['withholding_rate'], $tax['withholding_amount'], $tax['grand_total'], trim($_POST['notes'] ?? '') ?: "{$project['project_name']} prodüksiyon hizmet bedeli"]);

            $db->prepare("UPDATE projects SET status = 'invoiced' WHERE id = ?")->execute([$project_id]);
            recalculate_contact_balance($client_contact_id);
            log_activity('invoice_created', "{$project['project_name']} faturalandırıldı: {$invoice_number} (" . format_money($tax['grand_total']) . ")", 'project', $project_id, "/modules/projects/detail.php?id={$project_id}&tab=finance");
            set_flash('success', "Proje faturalandırıldı ({$invoice_number}).");
        }
        redirect($detail_url);
    }

    if ($action === 'cancel_project_invoice') {
        $inv_id = (int)($_POST['invoice_id'] ?? 0);
        $own = $db->prepare("SELECT id FROM invoices WHERE id = ? AND project_id = ?");
        $own->execute([$inv_id, $project_id]);
        if ($own->fetch()) {
            delete_invoice_cascade($inv_id);
            set_flash('success', 'Fatura ve bağlı tahsilatları iptal edildi.');
        }
        redirect($detail_url);
    }

    if ($action === 'delete_project_permanent') {
        require_permission('projects.delete');
        delete_project_cascade($project_id);
        set_flash('success', "Proje ve bağlı tüm kayıtları tamamen silindi.");
        redirect(BASE_URL . '/modules/projects/index.php');
    }

    // ==========================================
    // G. PROJE GÖREVLERİ (TO-DO)
    // ==========================================
    if ($action === 'add_task' || $action === 'edit_task') {
        $title       = trim($_POST['title'] ?? '');
        $assignee    = !empty($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : null;
        $due_date    = valid_date($_POST['due_date'] ?? '');
        $priority    = array_key_exists($_POST['priority'] ?? '', TASK_PRIORITIES) ? $_POST['priority'] : 'normal';
        $status      = array_key_exists($_POST['status'] ?? '', TASK_STATUSES) ? $_POST['status'] : 'todo';
        $description = trim($_POST['description'] ?? '');

        if ($title === '') {
            set_flash('error', 'Görev başlığı zorunludur.');
            redirect($detail_url);
        }

        if ($action === 'add_task') {
            $db->prepare("INSERT INTO project_tasks (project_id, title, description, assigned_user_id, due_date, status, priority, created_by, completed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$project_id, $title, $description, $assignee, $due_date, $status, $priority, $user['id'], $status === 'done' ? date('Y-m-d H:i:s') : null]);
            $task_id = (int)$db->lastInsertId();
            $old_assignee = null;
            set_flash('success', 'Görev eklendi.');
        } else {
            $task_id = (int)($_POST['task_id'] ?? 0);
            $t_stmt = $db->prepare("SELECT * FROM project_tasks WHERE id = ? AND project_id = ?");
            $t_stmt->execute([$task_id, $project_id]);
            $old = $t_stmt->fetch();
            if (!$old) {
                redirect($detail_url);
            }
            $old_assignee = $old['assigned_user_id'] ? (int)$old['assigned_user_id'] : null;
            $completed_at = $status === 'done' ? ($old['completed_at'] ?: date('Y-m-d H:i:s')) : null;
            $db->prepare("UPDATE project_tasks SET title = ?, description = ?, assigned_user_id = ?, due_date = ?, status = ?, priority = ?, completed_at = ? WHERE id = ?")
               ->execute([$title, $description, $assignee, $due_date, $status, $priority, $completed_at, $task_id]);
            set_flash('success', 'Görev güncellendi.');
        }

        // Yeni atanan kişiye bildirim
        if ($assignee && $assignee !== $old_assignee && $assignee !== (int)$user['id']) {
            log_activity('task_assigned', "Size yeni görev atandı: {$title} ({$project['project_name']})" . ($due_date ? ' · Son tarih: ' . format_date($due_date) : ''), 'task', $task_id, "/modules/projects/detail.php?id={$project_id}&tab=tasks", $assignee);
        }
        redirect($detail_url);
    }

    if ($action === 'delete_task') {
        $db->prepare("DELETE FROM project_tasks WHERE id = ? AND project_id = ?")->execute([(int)($_POST['task_id'] ?? 0), $project_id]);
        set_flash('success', 'Görev silindi.');
        redirect($detail_url);
    }

    // ==========================================
    // H. TESLİM DOSYALARI (Drive / WeTransfer / Vimeo linkleri)
    // ==========================================
    if ($action === 'add_deliverable') {
        $title = trim($_POST['title'] ?? '');
        $url   = trim($_POST['url'] ?? '');
        if ($title === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            set_flash('error', 'Geçerli bir başlık ve http(s) ile başlayan bağlantı giriniz.');
            redirect($detail_url);
        }
        $visible = isset($_POST['visible_to_client']) ? 1 : 0;
        $db->prepare("INSERT INTO project_deliverables (project_id, title, url, notes, visible_to_client, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$project_id, $title, $url, trim($_POST['notes'] ?? ''), $visible, $user['id']]);
        log_activity('deliverable_added', "Teslim dosyası eklendi: {$title} ({$project['project_name']})" . ($visible ? ' · Müşteriye açık' : ''), 'project', $project_id, "/modules/projects/detail.php?id={$project_id}&tab=deliverables");
        set_flash('success', 'Teslim dosyası eklendi' . ($visible ? ' ve müşteri portalında yayınlandı.' : '.'));
        redirect($detail_url);
    }

    if ($action === 'delete_deliverable') {
        $db->prepare("DELETE FROM project_deliverables WHERE id = ? AND project_id = ?")->execute([(int)($_POST['deliverable_id'] ?? 0), $project_id]);
        set_flash('success', 'Teslim dosyası kaldırıldı.');
        redirect($detail_url);
    }

    if ($action === 'update_status') {
        $new_status = $_POST['status'] ?? '';
        if (array_key_exists($new_status, PROJECT_STATUSES)) {
            $db->prepare("UPDATE projects SET status = ? WHERE id = ?")->execute([$new_status, $project_id]);
            log_activity('project_status', "{$project['project_name']} durumu: " . PROJECT_STATUSES[$new_status]['label'], 'project', $project_id, "/modules/projects/detail.php?id={$project_id}");
            set_flash('success', 'Proje durumu güncellendi.');
        }
        redirect($detail_url);
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

// Personel listesi (müşteri portalı hesapları hariç)
$editors = $db->query("
    SELECT u.id, u.full_name FROM users u LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.status = 'active' AND (u.role_id = 1 OR (u.contact_id IS NULL AND COALESCE(r.role_slug, '') != 'client'))
    ORDER BY u.full_name ASC
")->fetchAll();

$tasks = $db->query("
    SELECT t.*, u.full_name AS assignee_name FROM project_tasks t
    LEFT JOIN users u ON t.assigned_user_id = u.id
    WHERE t.project_id = {$project_id}
    ORDER BY FIELD(t.status, 'in_progress', 'todo', 'done'), (t.due_date IS NULL), t.due_date ASC, t.id DESC
")->fetchAll();
$open_task_count = count(array_filter($tasks, fn($t) => $t['status'] !== 'done'));
$deliverables = $db->query("SELECT * FROM project_deliverables WHERE project_id = {$project_id} ORDER BY id DESC")->fetchAll();
$project_activity = $db->query("SELECT * FROM activity_log WHERE (entity_type = 'project' AND entity_id = {$project_id}) OR link LIKE '/modules/projects/detail.php?id={$project_id}&%' OR link = '/modules/projects/detail.php?id={$project_id}' ORDER BY id DESC LIMIT 15")->fetchAll();
$initial_tab = in_array($_GET['tab'] ?? '', ['shoots', 'revisions', 'finance', 'tasks', 'deliverables', 'details'], true) ? $_GET['tab'] : 'revisions';
$freelancers = $db->query("SELECT id, company_title, type FROM contacts ORDER BY company_title ASC")->fetchAll();
$project_types = get_project_types();

$has_sales_invoice = project_main_sales_invoice_id($project_id) !== null;
$is_invoiced = ($project['status'] === 'invoiced' || $has_sales_invoice);
$next_sales_invoice_no = generate_invoice_number('sales');
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
    openTaskModal: false,
    taskForm: {},
    openDeliverableModal: false,
    vatRate: '<?= (int)get_setting('default_vat_rate', '20') ?>', 
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
    <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm mb-8" x-data="{ tab: '<?= $initial_tab ?>' }">
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

            <button @click="tab = 'tasks'" :class="tab === 'tasks' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="list-checks" class="w-4 h-4"></i>
                <span>Görevler (<?= $open_task_count ?>/<?= count($tasks) ?>)</span>
            </button>

            <button @click="tab = 'deliverables'" :class="tab === 'deliverables' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="package-check" class="w-4 h-4"></i>
                <span>Teslim Dosyaları (<?= count($deliverables) ?>)</span>
            </button>

            <button @click="tab = 'details'" :class="tab === 'details' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4"></i>
                <span>Kreatif Brief & Notlar</span>
            </button>
        </div>

        <!-- TAB 1: ÇEKİM GÜNLERİ -->
        <div x-show="tab === 'shoots'" x-cloak>
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

                                <button @click="editShootData = {
                                            id: <?= (int)$shoot['id'] ?>,
                                            title: <?= js_val($shoot['title']) ?>,
                                            shoot_date: <?= js_val($shoot['shoot_date']) ?>,
                                            start_time: <?= js_val(substr((string)($shoot['start_time'] ?? ''), 0, 5)) ?>,
                                            end_time: <?= js_val(substr((string)($shoot['end_time'] ?? ''), 0, 5)) ?>,
                                            location_name: <?= js_val($shoot['location_name'] ?? '') ?>,
                                            location_address: <?= js_val($shoot['location_address'] ?? '') ?>,
                                            call_sheet_notes: <?= js_val($shoot['call_sheet_notes'] ?? '') ?>
                                        }; openEditShootModal = true"
                                        class="p-2 bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-600 rounded-xl" title="Çekim Gününü Düzenle">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </button>

                                <form method="POST" action="" onsubmit="return confirm('Bu çekim gününü ve bağlı tüm set giderlerini (otomatik alış faturaları dahil) silmek istiyor musunuz?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_shoot">
                                    <input type="hidden" name="shoot_id" value="<?= (int)$shoot['id'] ?>">
                                    <button type="submit" class="p-2 bg-slate-100 hover:bg-rose-50 text-slate-400 hover:text-rose-600 rounded-xl" title="Çekim Gününü Sil">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>

                                <button @click="$dispatch('open-crew-modal', { shoot_id: <?= $shoot['id'] ?>, shoot_title: <?= js_val($shoot['title']) ?> })" class="inline-flex items-center gap-1 bg-brand-50 text-brand-600 hover:bg-brand-100 text-xs font-semibold py-1.5 px-3 rounded-lg border border-brand-200">
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
                                                        item_title: <?= js_val($it['item_title']) ?>,
                                                        agreed_fee: '<?= (float)$it['agreed_fee'] ?>',
                                                        contact_id: '<?= $it['contact_id'] ?? '' ?>',
                                                        is_rebillable: '<?= (int)$it['is_rebillable'] ?>',
                                                        vat_rate: '<?= (int)$it['vat_rate'] ?>'
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
                            <button @click="billEditData = { revision_id: '<?= $rev['id'] ?>', title: <?= js_val($rev['version_title']) ?>, fee: 0, billing_type: 'invoice', vat_rate: 20 }; openBillEditModal = true"
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
                                        version_title: <?= js_val($rev['version_title']) ?>, 
                                        assigned_editor_id: '<?= $rev['assigned_editor_id'] ?? '' ?>', 
                                        preview_url: <?= js_val($rev['preview_url'] ?? '') ?>, 
                                        feedback_notes: <?= js_val($rev['feedback_notes'] ?? '') ?>, 
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
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <span class="text-sm font-black text-slate-900 block"><?= format_money($p_inv['grand_total']) ?></span>
                            <?php
                                $pay_labels = ['paid' => ['Ödendi', 'text-emerald-600'], 'partial' => ['Kısmi Ödendi', 'text-amber-600'], 'unpaid' => ['Ödenmedi', 'text-rose-600']];
                                $pl = $pay_labels[$p_inv['payment_status']] ?? [$p_inv['payment_status'], 'text-slate-500'];
                            ?>
                            <span class="text-[10px] font-bold <?= $pl[1] ?>"><?= e($pl[0]) ?> · <?= format_date($p_inv['issue_date']) ?></span>
                        </div>
                        <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= (int)$p_inv['id'] ?>" target="_blank" class="p-2 bg-white hover:bg-slate-100 text-slate-600 border border-slate-200 rounded-xl" title="Yazdır / PDF">
                            <i data-lucide="printer" class="w-4 h-4"></i>
                        </a>
                        <?php if (has_permission('projects.edit')): ?>
                        <form method="POST" action="" onsubmit="return confirm('Bu fatura ve bağlı tahsilatları iptal edilsin mi? Kasa ve cari bakiyeleri yeniden hesaplanacak.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel_project_invoice">
                            <input type="hidden" name="invoice_id" value="<?= (int)$p_inv['id'] ?>">
                            <button type="submit" class="p-2 bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 rounded-xl" title="Faturayı İptal Et">
                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($project_invoices)): ?>
                    <p class="text-xs text-slate-400 italic">Bu projeye bağlı fatura bulunmuyor.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: GÖREVLER -->
        <div x-show="tab === 'tasks'" x-cloak>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Proje Görevleri</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Lokasyon keşfi, cast seçimi, ses miksajı, renk düzenleme gibi işleri ekibe atayın. Atanan kişiye bildirim gider.</p>
                </div>
                <?php if (has_permission('projects.edit')): ?>
                <button @click="taskForm = { id: 0, title: '', description: '', assigned_user_id: '', due_date: '', priority: 'normal', status: 'todo' }; openTaskModal = true"
                        class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold py-2 px-3.5 rounded-xl transition">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i><span>Görev Ekle</span>
                </button>
                <?php endif; ?>
            </div>

            <?php if (empty($tasks)): ?>
                <div class="py-10 text-center border-2 border-dashed border-slate-200 rounded-2xl text-xs text-slate-500">Henüz görev eklenmedi.</div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($tasks as $t):
                        $ts = TASK_STATUSES[$t['status']] ?? TASK_STATUSES['todo'];
                        $tp = TASK_PRIORITIES[$t['priority']] ?? TASK_PRIORITIES['normal'];
                        $overdue = $t['status'] !== 'done' && !empty($t['due_date']) && $t['due_date'] < date('Y-m-d');
                        $can_toggle = has_permission('projects.edit') || (int)$t['assigned_user_id'] === (int)$user['id'];
                    ?>
                    <div class="p-3.5 bg-slate-50 rounded-2xl border <?= $overdue ? 'border-rose-300' : 'border-slate-200' ?> flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <?php if ($can_toggle): ?>
                            <form method="POST" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_task">
                                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="status" value="<?= $t['status'] === 'done' ? 'todo' : 'done' ?>">
                                <button type="submit" class="mt-0.5 w-5 h-5 rounded-md border-2 flex items-center justify-center <?= $t['status'] === 'done' ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-slate-300 bg-white hover:border-emerald-500' ?>" title="<?= $t['status'] === 'done' ? 'Yeniden aç' : 'Tamamlandı olarak işaretle' ?>">
                                    <?php if ($t['status'] === 'done'): ?><i data-lucide="check" class="w-3.5 h-3.5"></i><?php endif; ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <p class="text-sm font-bold <?= $t['status'] === 'done' ? 'line-through text-slate-400' : 'text-slate-900' ?>"><?= e($t['title']) ?></p>
                                <?php if (!empty($t['description'])): ?><p class="text-xs text-slate-500 mt-0.5 whitespace-pre-line"><?= e($t['description']) ?></p><?php endif; ?>
                                <div class="flex flex-wrap items-center gap-2 mt-1.5 text-[11px]">
                                    <span class="px-2 py-0.5 rounded-full border font-bold <?= $ts['color'] ?>"><?= $ts['label'] ?></span>
                                    <span class="font-bold <?= $tp['color'] ?>">● <?= $tp['label'] ?></span>
                                    <span class="text-slate-500">👤 <?= e($t['assignee_name'] ?? 'Atanmadı') ?></span>
                                    <?php if (!empty($t['due_date'])): ?>
                                        <span class="<?= $overdue ? 'text-rose-600 font-bold' : 'text-slate-500' ?>">📅 <?= format_date($t['due_date']) ?><?= $overdue ? ' (gecikti)' : '' ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if (has_permission('projects.edit')): ?>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <button @click="taskForm = { id: <?= (int)$t['id'] ?>, title: <?= js_val($t['title']) ?>, description: <?= js_val($t['description'] ?? '') ?>, assigned_user_id: '<?= (int)$t['assigned_user_id'] ?: '' ?>', due_date: <?= js_val($t['due_date'] ?? '') ?>, priority: '<?= e($t['priority']) ?>', status: '<?= e($t['status']) ?>' }; openTaskModal = true"
                                    class="p-2 bg-white hover:bg-brand-50 text-slate-500 hover:text-brand-600 border border-slate-200 rounded-xl" title="Düzenle">
                                <i data-lucide="edit-3" class="w-4 h-4"></i>
                            </button>
                            <form method="POST" action="" onsubmit="return confirm('Görev silinsin mi?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="p-2 bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 rounded-xl" title="Sil">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: TESLİM DOSYALARI -->
        <div x-show="tab === 'deliverables'" x-cloak>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Teslim Dosyaları & Bağlantılar</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Final videolar, ham görüntüler, müzik lisansları (Drive, WeTransfer, Vimeo, Frame.io...). "Müşteriye açık" olanlar portalda görünür.</p>
                </div>
                <?php if (has_permission('projects.edit')): ?>
                <button @click="openDeliverableModal = true" class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold py-2 px-3.5 rounded-xl transition">
                    <i data-lucide="link" class="w-3.5 h-3.5"></i><span>Bağlantı Ekle</span>
                </button>
                <?php endif; ?>
            </div>
            <?php if (empty($deliverables)): ?>
                <div class="py-10 text-center border-2 border-dashed border-slate-200 rounded-2xl text-xs text-slate-500">Henüz teslim dosyası eklenmedi.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($deliverables as $d): ?>
                    <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-bold text-slate-900 truncate"><?= e($d['title']) ?></p>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $d['visible_to_client'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>"><?= $d['visible_to_client'] ? 'Müşteriye Açık' : 'Ajans İçi' ?></span>
                            </div>
                            <a href="<?= e($d['url']) ?>" target="_blank" rel="noopener" class="text-xs text-brand-600 hover:underline break-all"><?= e($d['url']) ?></a>
                            <?php if (!empty($d['notes'])): ?><p class="text-xs text-slate-500 mt-1"><?= e($d['notes']) ?></p><?php endif; ?>
                            <p class="text-[10px] text-slate-400 mt-1"><?= format_date($d['created_at'], true) ?></p>
                        </div>
                        <?php if (has_permission('projects.edit')): ?>
                        <form method="POST" action="" onsubmit="return confirm('Bağlantı kaldırılsın mı?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_deliverable">
                            <input type="hidden" name="deliverable_id" value="<?= (int)$d['id'] ?>">
                            <button type="submit" class="p-2 text-slate-300 hover:text-rose-600" title="Kaldır"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 4: BRİEF & NOTLAR -->
        <div x-show="tab === 'details'" x-cloak>
            <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 text-xs text-slate-700 whitespace-pre-line">
                <?= !empty($project['description']) ? e($project['description']) : 'Not girilmemiş.' ?>
            </div>

            <h3 class="text-sm font-bold text-slate-800 mt-6 mb-3">Proje Geçmişi</h3>
            <?php if (empty($project_activity)): ?>
                <p class="text-xs text-slate-400">Henüz kayıtlı hareket yok.</p>
            <?php else: ?>
                <ol class="relative border-l-2 border-slate-200 ml-2 space-y-3">
                    <?php foreach ($project_activity as $pa): ?>
                    <li class="ml-4">
                        <span class="absolute -left-[7px] w-3 h-3 rounded-full <?= $pa['actor_type'] === 'client' ? 'bg-indigo-500' : 'bg-slate-400' ?>"></span>
                        <p class="text-xs text-slate-800"><?= e($pa['message']) ?></p>
                        <p class="text-[10px] text-slate-400"><?= e($pa['actor_name'] ?? '') ?><?= $pa['actor_type'] === 'client' ? ' (Müşteri)' : '' ?> · <?= format_date($pa['created_at'], true) ?></p>
                    </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
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
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Set Başlangıç Saati</label>
                        <input type="time" name="start_time" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Paket (Bitiş) Saati</label>
                        <input type="time" name="end_time" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
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

    <!-- MODAL: ÇEKİM GÜNÜNÜ DÜZENLE -->
    <div x-show="openEditShootModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="openEditShootModal = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900">Çekim Gününü Düzenle</h3>
                <button type="button" @click="openEditShootModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_shoot">
                <input type="hidden" name="shoot_id" :value="editShootData.id">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Çekim Başlığı *</label>
                    <input type="text" name="title" required x-model="editShootData.title" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tarih *</label>
                        <input type="date" name="shoot_date" required x-model="editShootData.shoot_date" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Başlangıç</label>
                        <input type="time" name="start_time" x-model="editShootData.start_time" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Bitiş</label>
                        <input type="time" name="end_time" x-model="editShootData.end_time" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Mekan / Plato Adı</label>
                    <input type="text" name="location_name" x-model="editShootData.location_name" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Mekan Adresi</label>
                    <input type="text" name="location_address" x-model="editShootData.location_address" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Call Sheet Notları</label>
                    <textarea name="call_sheet_notes" rows="2" x-model="editShootData.call_sheet_notes" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openEditShootModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-semibold rounded-xl text-xs shadow-md">Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: SET GİDERİNİ DÜZENLE -->
    <div x-show="openEditCrewModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="openEditCrewModal = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <h3 class="text-base font-bold text-slate-900">Set Giderini Düzenle</h3>
                <button type="button" @click="openEditCrewModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_crew_gear">
                <input type="hidden" name="item_id" :value="editCrewData.id">

                <div class="grid grid-cols-2 gap-2 text-xs">
                    <label class="flex items-center gap-2 p-2.5 bg-white rounded-xl border border-indigo-200 cursor-pointer">
                        <input type="radio" name="is_rebillable" value="0" x-model="editCrewData.is_rebillable" class="text-indigo-600">
                        <span class="font-bold text-slate-900 text-[11px]">Bütçeye DAHİL</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 bg-white rounded-xl border border-indigo-200 cursor-pointer">
                        <input type="radio" name="is_rebillable" value="1" x-model="editCrewData.is_rebillable" class="text-indigo-600">
                        <span class="font-bold text-indigo-700 text-[11px]">Bütçeye HARİÇ (Yansıtılır)</span>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Kategori *</label>
                        <select name="category" x-model="editCrewData.category" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (CREW_CATEGORIES as $ckey => $cname): ?>
                                <option value="<?= $ckey ?>"><?= $cname ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tutar (KDV Hariç) *</label>
                        <input type="number" step="0.01" min="0" name="agreed_fee" required x-model="editCrewData.agreed_fee" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama *</label>
                    <input type="text" name="item_title" required x-model="editCrewData.item_title" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tedarikçi Carisi</label>
                        <select name="contact_id" x-model="editCrewData.contact_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="">-- Cari Seçin --</option>
                            <?php foreach ($freelancers as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= e($f['company_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">KDV Oranı</label>
                        <select name="vat_rate" x-model="editCrewData.vat_rate" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (VAT_RATES as $vr): ?>
                                <option value="<?= $vr ?>">%<?= $vr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="text-[10px] text-slate-400">Bu gidere bağlı otomatik alış faturası varsa tutar ve cari bilgisi faturaya da yansıtılır.</p>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditCrewModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-xl text-xs shadow-md transition">Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: GÖREV EKLE / DÜZENLE -->
    <div x-show="openTaskModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="openTaskModal = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900" x-text="taskForm.id ? 'Görevi Düzenle' : 'Yeni Görev'"></h3>
                <button type="button" @click="openTaskModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" :value="taskForm.id ? 'edit_task' : 'add_task'">
                <input type="hidden" name="task_id" :value="taskForm.id">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Görev *</label>
                    <input type="text" name="title" required x-model="taskForm.title" placeholder="Örn: Lokasyon keşfi, oyuncu seçimi, renk düzenleme" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Sorumlu</label>
                        <select name="assigned_user_id" x-model="taskForm.assigned_user_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="">-- Atanmadı --</option>
                            <?php foreach ($editors as $ed): ?>
                                <option value="<?= (int)$ed['id'] ?>"><?= e($ed['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Son Tarih</label>
                        <input type="date" name="due_date" x-model="taskForm.due_date" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Öncelik</label>
                        <select name="priority" x-model="taskForm.priority" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <?php foreach (TASK_PRIORITIES as $pk => $pv): ?><option value="<?= $pk ?>"><?= $pv['label'] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Durum</label>
                        <select name="status" x-model="taskForm.status" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <?php foreach (TASK_STATUSES as $sk => $sv): ?><option value="<?= $sk ?>"><?= $sv['label'] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <textarea name="description" rows="2" x-model="taskForm.description" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openTaskModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-semibold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: TESLİM DOSYASI EKLE -->
    <div x-show="openDeliverableModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="openDeliverableModal = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900">Teslim Dosyası / Bağlantı Ekle</h3>
                <button type="button" @click="openDeliverableModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_deliverable">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Başlık *</label>
                    <input type="text" name="title" required placeholder="Örn: Final Master 4K (60sn) + Reels Dikey Versiyonlar" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Bağlantı (URL) *</label>
                    <input type="url" name="url" required placeholder="https://drive.google.com/..." class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Not</label>
                    <input type="text" name="notes" placeholder="Örn: Bağlantı 7 gün geçerlidir, şifre: ..." class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <label class="flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer">
                    <input type="checkbox" name="visible_to_client" value="1" checked class="rounded text-brand-600">
                    <span>Müşteri portalında göster</span>
                </label>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openDeliverableModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-semibold rounded-xl text-xs shadow-md">Ekle</button>
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
                        <input type="text" name="invoice_number" required value="<?= e($next_sales_invoice_no) ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Fatura Tarihi</label>
                        <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">KDV Oranı</label>
                        <select name="vat_rate" x-model="vatRate" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (VAT_RATES as $vr): ?>
                                <option value="<?= $vr ?>">%<?= $vr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tevkifat</label>
                        <select name="withholding_rate" x-model="withholdingRate" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (WITHHOLDING_RATES as $wk => $wl): ?>
                                <option value="<?= e($wk) ?>"><?= e($wl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Vade Tarihi</label>
                        <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Fatura Notu</label>
                        <input type="text" name="notes" placeholder="Opsiyonel açıklama" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-200 space-y-1 text-xs">
                    <div class="flex justify-between text-emerald-900"><span>KDV:</span> <strong x-text="calcInvoice().vat + ' ₺'"></strong></div>
                    <div class="flex justify-between text-purple-800" x-show="withholdingRate !== '0/10'"><span>Tevkifat:</span> <strong x-text="'-' + calcInvoice().withholding + ' ₺'"></strong></div>
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