<?php
/**
 * ====================================================================
 * ÖDEMELER: AJANS ÖDEME BİLDİRİMİ · FREELANCER ÖDEME TALEBİ
 * ====================================================================
 * Ajans, faturaları için yaptığı ödemeyi panelinden bildirir (tutar, tarih,
 * yöntem, açıklama, dekont). Ekip hesaba geçtiğini görünce onaylar; tahsilat
 * seçilen faturalara işlenir. Freelancer onaylanmış ve ödenmemiş hakedişleri
 * için ödeme talep eder; ekip ödemeyi kaydeder.
 */

const PAYMENT_METHODS = [
    'transfer' => 'Havale / EFT',
    'card'     => 'Kredi kartı',
    'cheque'   => 'Çek / senet',
    'cash'     => 'Nakit',
    'other'    => 'Diğer',
];
const PAYMENT_NOTICE_STATUSES = [
    'pending'   => ['İnceleniyor', 'warning'],
    'confirmed' => ['Onaylandı', 'success'],
    'rejected'  => ['Reddedildi', 'danger'],
];
const PAYOUT_STATUSES = [
    'pending'  => ['Talep edildi', 'warning'],
    'paid'     => ['Ödendi', 'success'],
    'rejected' => ['Reddedildi', 'danger'],
];
const RECEIPT_DIR = __DIR__ . '/../assets/uploads/receipts/';

function run_payment_migrations(): void {
    global $db;
    $db->query("CREATE TABLE IF NOT EXISTS `platform_payment_notices` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `contact_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NULL,
        `invoice_id` INT UNSIGNED NULL,
        `amount` DECIMAL(14,2) NOT NULL,
        `paid_on` DATE NOT NULL,
        `method` VARCHAR(20) NOT NULL DEFAULT 'transfer',
        `reference` VARCHAR(200) NULL,
        `note` TEXT NULL,
        `receipt_path` VARCHAR(255) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `staff_note` TEXT NULL,
        `confirmed_amount` DECIMAL(14,2) NULL,
        `reviewed_by` INT UNSIGNED NULL,
        `reviewed_at` DATETIME NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_contact` (`contact_id`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS `platform_payout_requests` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `contact_id` INT UNSIGNED NULL,
        `amount` DECIMAL(14,2) NOT NULL,
        `invoice_ids` VARCHAR(1000) NOT NULL,
        `iban` VARCHAR(64) NULL,
        `note` TEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `staff_note` TEXT NULL,
        `paid_amount` DECIMAL(14,2) NULL,
        `reviewed_by` INT UNSIGNED NULL,
        `reviewed_at` DATETIME NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_user` (`user_id`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!is_dir(RECEIPT_DIR)) {
        @mkdir(RECEIPT_DIR, 0755, true);
    }
    if (is_dir(RECEIPT_DIR) && !file_exists(RECEIPT_DIR . '.htaccess')) {
        @file_put_contents(RECEIPT_DIR . '.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
}

/** Ödeme ekranları için ekibe bildirim (işe bağlı olmayan) */
function notify_staff_payment(string $message): void {
    log_activity('platform', $message, 'payment', null, '/modules/platform/payments.php');
    try {
        mail_notify_staff_inbox($message, '/modules/platform/payments.php');
    } catch (Throwable $e) {
        error_log('Ekip e-postası: ' . $e->getMessage());
    }
}

/**
 * Carinin kalan bakiyesi olan faturaları (eskiden yeniye)
 * $type: sales (ajansın borcu) | purchase (freelancer hakedişi)
 */
function contact_open_invoices(int $contact_id, string $type): array {
    global $db;
    $st = $db->prepare("SELECT i.*, ROUND(i.grand_total - i.paid_amount, 2) AS remaining FROM invoices i WHERE i.contact_id = ? AND i.invoice_type = ? AND i.grand_total - i.paid_amount > 0.009 ORDER BY COALESCE(i.due_date, i.issue_date), i.id");
    $st->execute([$contact_id, $type]);
    return $st->fetchAll();
}

/** Dekont kaydı: PDF, JPG, PNG, WEBP · en fazla 5 MB. [yol, hata] */
function receipt_save(?array $file): array {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'Dekont yüklenemedi.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return [null, 'Dekont en fazla 5 MB olabilir.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $map = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) {
        return [null, 'Dekont PDF, JPG, PNG veya WEBP olmalı.'];
    }
    if ($map[$mime] !== 'pdf' && @getimagesize($file['tmp_name']) === false) {
        return [null, 'Dekont geçerli bir görsel değil.'];
    }
    if (!is_dir(RECEIPT_DIR) && !@mkdir(RECEIPT_DIR, 0755, true)) {
        return [null, 'Dekont klasörü oluşturulamadı.'];
    }
    $name = 'dekont-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $map[$mime];
    if (!move_uploaded_file($file['tmp_name'], RECEIPT_DIR . $name)) {
        return [null, 'Dekont kaydedilemedi.'];
    }
    return [$name, null];
}

function payment_notice_get(int $id): ?array {
    global $db;
    $st = $db->prepare("SELECT n.*, c.company_title, u.full_name, i.invoice_number FROM platform_payment_notices n JOIN contacts c ON c.id = n.contact_id LEFT JOIN users u ON u.id = n.user_id LEFT JOIN invoices i ON i.id = n.invoice_id WHERE n.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Bildirimi onaylar: tahsilatı faturalara dağıtır (kalan tutar cari hesaba).
 * $alloc: [invoice_id => tutar]. Hata varsa mesaj döner.
 */
function payment_notice_confirm(array $n, array $alloc, int $account_id, string $date, int $staff_id, string $staff_note = ''): ?string {
    global $db;
    if ($n['status'] !== 'pending') return 'Bu bildirim zaten sonuçlandı.';
    $open = [];
    foreach (contact_open_invoices((int)$n['contact_id'], 'sales') as $inv) $open[(int)$inv['id']] = $inv;
    $alloc = array_filter(array_map(fn($v) => round(max(0, (float)$v), 2), $alloc), fn($v) => $v > 0);
    $total = array_sum($alloc);
    if ($total - (float)$n['amount'] > 0.009) return 'Faturalara dağıtılan tutar bildirilen tutarı geçemez.';
    foreach ($alloc as $iid => $amt) {
        if (!isset($open[$iid])) return 'Seçilen fatura bu ajansa ait değil veya ödenmiş.';
        if ($amt - (float)$open[$iid]['remaining'] > 0.009) return "{$open[$iid]['invoice_number']} için en fazla " . format_money((float)$open[$iid]['remaining']) . ' dağıtılabilir.';
    }
    $acc = $db->prepare("SELECT id FROM accounts WHERE id = ?");
    $acc->execute([$account_id]);
    if (!$acc->fetch()) return 'Kasa / banka hesabı seçin.';
    $db->beginTransaction();
    try {
        foreach ($alloc as $iid => $amt) {
            if ($err = record_invoice_payment((int)$iid, $account_id, $amt, $date, $staff_id)) {
                throw new RuntimeException($err);
            }
        }
        $rest = round((float)$n['amount'] - $total, 2);
        if ($rest > 0.009) {
            // Faturaya bağlanmayan kısım cari hesaba avans/tahsilat olarak işlenir
            $db->prepare("INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at) VALUES (?, ?, NULL, NULL, 'income', 'Cari tahsilat', ?, ?, ?, ?, NOW())")
               ->execute([$account_id, $n['contact_id'], $rest, $date, "Ödeme bildirimi #{$n['id']} (faturaya bağlanmadı)", $staff_id]);
            recalculate_account_balance($account_id);
            recalculate_contact_balance((int)$n['contact_id']);
        }
        $db->prepare("UPDATE platform_payment_notices SET status = 'confirmed', confirmed_amount = ?, staff_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
           ->execute([$n['amount'], $staff_note !== '' ? $staff_note : null, $staff_id, $n['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return $e instanceof RuntimeException ? $e->getMessage() : 'Ödeme kaydedilemedi.';
    }
    $nums = array_map(fn($iid) => $open[$iid]['invoice_number'], array_keys($alloc));
    notify_contact_users((int)$n['contact_id'], 'Ödeme bildiriminiz onaylandı: ' . format_money((float)$n['amount']) . ($nums ? ' · ' . implode(', ', $nums) : '') . '. Teşekkürler.', '/platform/payments.php');
    log_activity('finance', "Ajans ödeme bildirimi onaylandı: {$n['company_title']} · " . format_money((float)$n['amount']), 'payment', (int)$n['id'], '/modules/platform/payments.php');
    return null;
}

function payment_notice_reject(array $n, string $reason, int $staff_id): void {
    global $db;
    if ($n['status'] !== 'pending') return;
    $db->prepare("UPDATE platform_payment_notices SET status = 'rejected', staff_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")->execute([$reason, $staff_id, $n['id']]);
    notify_contact_users((int)$n['contact_id'], 'Ödeme bildiriminiz (' . format_money((float)$n['amount']) . ') doğrulanamadı: ' . mb_substr($reason, 0, 140), '/platform/payments.php');
}

/**
 * Freelancer'ın talep edilebilir hakedişleri: kalan bakiyesi olan alış faturaları,
 * bekleyen bir talepte olmayanlar. Her satırda ilgili iş/aşama bilgisi.
 */
function payout_available(int $user_id, int $contact_id): array {
    global $db;
    $busy = [];
    $st = $db->prepare("SELECT invoice_ids FROM platform_payout_requests WHERE user_id = ? AND status = 'pending'");
    $st->execute([$user_id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ids) foreach (explode(',', $ids) as $i) $busy[(int)$i] = true;
    // Yalnızca bu freelancer'ın onaylanmış platform aşamalarına ait hakediş faturaları;
    // cari karttaki diğer (ERP, ekipman vb.) faturalar talep edilemez
    $st = $db->prepare("
        SELECT i.*, ROUND(i.grand_total - i.paid_amount, 2) AS remaining,
               m.title AS ms_title, j.id AS ms_job_id, j.job_code AS ms_job_code, j.title AS ms_job_title
        FROM platform_milestones m
        JOIN invoices i ON i.id = m.purchase_invoice_id
        JOIN platform_jobs j ON j.id = m.job_id
        WHERE m.freelancer_user_id = ? AND m.status = 'approved' AND i.invoice_type = 'purchase' AND i.contact_id = ?
          AND i.grand_total - i.paid_amount > 0.009
        ORDER BY i.issue_date, i.id
    ");
    $st->execute([$user_id, $contact_id]);
    $rows = [];
    foreach ($st->fetchAll() as $inv) {
        $inv['ms'] = ['title' => $inv['ms_title'], 'job_id' => (int)$inv['ms_job_id'], 'job_code' => $inv['ms_job_code'], 'job_title' => $inv['ms_job_title']];
        $inv['busy'] = isset($busy[(int)$inv['id']]);
        $rows[] = $inv;
    }
    return $rows;
}

function payout_get(int $id): ?array {
    global $db;
    $st = $db->prepare("SELECT r.*, u.full_name, u.email FROM platform_payout_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Talepteki faturalar (kalan tutarlarıyla) */
function payout_invoices(array $r): array {
    global $db;
    $ids = array_filter(array_map('intval', explode(',', (string)$r['invoice_ids'])));
    if (!$ids) return [];
    $rows = $db->query("SELECT i.*, ROUND(i.grand_total - i.paid_amount, 2) AS remaining,
                               (SELECT CONCAT(j.job_code, ' · ', m.title) FROM platform_milestones m JOIN platform_jobs j ON j.id = m.job_id WHERE m.purchase_invoice_id = i.id LIMIT 1) AS label
                        FROM invoices i WHERE i.id IN (" . implode(',', $ids) . ") ORDER BY i.id")->fetchAll();
    return $rows;
}

/** Talebi öder: her faturanın kalanı seçilen hesaptan ödenir */
function payout_pay(array $r, int $account_id, string $date, int $staff_id, string $staff_note = ''): ?string {
    global $db;
    if ($r['status'] !== 'pending') return 'Bu talep zaten sonuçlandı.';
    // Talepte yalnızca bu freelancer'ın aşama hakedişleri ödenir
    $own = $db->prepare("SELECT COUNT(*) FROM platform_milestones WHERE purchase_invoice_id = ? AND freelancer_user_id = ?");
    $invs = array_filter(payout_invoices($r), function ($i) use ($r, $own) {
        $own->execute([$i['id'], $r['user_id']]);
        return (float)$i['remaining'] > 0.009 && (int)$i['contact_id'] === (int)$r['contact_id'] && (int)$own->fetchColumn() > 0;
    });
    if (!$invs) return 'Talepte ödenecek bakiye kalmamış.';
    $paid = 0.0;
    $db->beginTransaction();
    try {
        foreach ($invs as $inv) {
            if ($err = record_invoice_payment((int)$inv['id'], $account_id, (float)$inv['remaining'], $date, $staff_id)) {
                throw new RuntimeException($err);
            }
            $paid += (float)$inv['remaining'];
        }
        $db->prepare("UPDATE platform_payout_requests SET status = 'paid', paid_amount = ?, staff_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
           ->execute([$paid, $staff_note !== '' ? $staff_note : null, $staff_id, $r['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return $e instanceof RuntimeException ? $e->getMessage() : 'Ödeme kaydedilemedi.';
    }
    // İş kaydına kalem kalem ödeme satırı
    foreach ($invs as $inv) {
        $m = $db->prepare("SELECT id, job_id, title FROM platform_milestones WHERE purchase_invoice_id = ? LIMIT 1");
        $m->execute([$inv['id']]);
        if ($ms = $m->fetch()) {
            job_event((int)$ms['job_id'], 'payment', 'Hakediş ödendi: ' . $ms['title'], ['amount' => (float)$inv['remaining'], 'milestone_id' => (int)$ms['id'], 'new' => "Ödeme talebi #{$r['id']}", 'visibility' => 'freelancer']);
        }
    }
    notify_user((int)$r['user_id'], 'Ödeme talebiniz ödendi: ' . format_money($paid) . '. Hesabınıza aktarıldı.', '/platform/earnings.php');
    log_activity('finance', "Freelancer ödeme talebi ödendi: {$r['full_name']} · " . format_money($paid), 'payout', (int)$r['id'], '/modules/platform/payments.php?tab=payouts');
    return null;
}

function payout_reject(array $r, string $reason, int $staff_id): void {
    global $db;
    if ($r['status'] !== 'pending') return;
    $db->prepare("UPDATE platform_payout_requests SET status = 'rejected', staff_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")->execute([$reason, $staff_id, $r['id']]);
    notify_user((int)$r['user_id'], 'Ödeme talebiniz (' . format_money((float)$r['amount']) . ') şu an işleme alınamadı: ' . mb_substr($reason, 0, 140), '/platform/earnings.php');
}

/** Bekleyen ödeme işleri (personel menü rozeti) */
function payments_pending_count(): int {
    global $db;
    try {
        return (int)$db->query("SELECT (SELECT COUNT(*) FROM platform_payment_notices WHERE status = 'pending') + (SELECT COUNT(*) FROM platform_payout_requests WHERE status = 'pending')")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
