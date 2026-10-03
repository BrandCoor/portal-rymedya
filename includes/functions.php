<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GÜVENLİK, VERGİ, CARİ & KASA EŞİTLEME MOTORU
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: " . $url);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): bool {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die("Geçersiz veya süresi dolmuş CSRF güvenlik doğrulaması!");
    }
    return true;
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash_message'] = [
        'type'    => $type,
        'message' => $message
    ];
}

function display_flash(): string {
    if (!isset($_SESSION['flash_message'])) {
        return '';
    }

    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);

    $colors = [
        'success' => 'bg-emerald-50 text-emerald-800 border-emerald-300',
        'error'   => 'bg-rose-50 text-rose-800 border-rose-300',
        'warning' => 'bg-amber-50 text-amber-800 border-amber-300',
        'info'    => 'bg-sky-50 text-sky-800 border-sky-300'
    ];

    $color = $colors[$flash['type']] ?? $colors['info'];

    return '
    <div class="mb-4 p-4 rounded-xl border flex items-center justify-between text-sm shadow-sm ' . $color . '" role="alert">
        <div class="flex items-center space-x-2">
            <span>' . e($flash['message']) . '</span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" class="opacity-70 hover:opacity-100 font-bold ml-4">✕</button>
    </div>';
}

function format_money(float|int|null $amount, string $currency = 'TRY'): string {
    $amount = (float)$amount;
    $formatted = number_format($amount, 2, ',', '.');
    
    $symbols = [
        'TRY' => ' ₺',
        'USD' => ' $',
        'EUR' => ' €',
        'GBP' => ' £'
    ];
    
    return $formatted . ($symbols[$currency] ?? ' ' . $currency);
}

function format_date(?string $date, bool $show_time = false): string {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($date);
    $months = [
        'January' => 'Ocak', 'February' => 'Şubat', 'March' => 'Mart',
        'April' => 'Nisan', 'May' => 'Mayıs', 'June' => 'Haziran',
        'July' => 'Temmuz', 'August' => 'Ağustos', 'September' => 'Eylül',
        'October' => 'Ekim', 'November' => 'Kasım', 'December' => 'Aralık'
    ];

    $format = $show_time ? 'd F Y, H:i' : 'd F Y';
    $english_date = date($format, $timestamp);

    return strtr($english_date, $months);
}

/**
 * ====================================================================
 * MATEMATİKSEL KASA & BANKA BAKİYESİ YENİDEN HESAPLAMA MOTORU (YENİ)
 * ====================================================================
 * Kasa hareketlerini toplayıp kasadaki gerçek mevcudu hesaplar.
 * Hareket yoksa bakiyeyi anında 0,00 ₺ yapar!
 */
function recalculate_account_balance(?int $account_id = null): void {
    global $db;
    $accounts = $account_id ? [$account_id] : $db->query("SELECT id FROM accounts")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($accounts as $acc_id) {
        $incomes = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = {$acc_id} AND type = 'income'")->fetchColumn();
        $expenses = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = {$acc_id} AND type = 'expense'")->fetchColumn();
        $net_balance = $incomes - $expenses;

        $db->prepare("UPDATE accounts SET balance = ? WHERE id = ?")->execute([$net_balance, $acc_id]);
    }
}

/**
 * ====================================================================
 * MATEMATİKSEL CARİ BAKİYE YENİDEN HESAPLAMA MOTORU
 * ====================================================================
 */
function recalculate_contact_balance(int $contact_id): float {
    global $db;

    $sales_total = (float)$db->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE contact_id = {$contact_id} AND invoice_type = 'sales'")->fetchColumn();
    $purchase_total = (float)$db->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE contact_id = {$contact_id} AND invoice_type = 'purchase'")->fetchColumn();
    $income_tx = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE contact_id = {$contact_id} AND type = 'income'")->fetchColumn();
    $expense_tx = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE contact_id = {$contact_id} AND type = 'expense'")->fetchColumn();

    $calculated_balance = ($sales_total + $expense_tx) - ($purchase_total + $income_tx);
    $db->prepare("UPDATE contacts SET balance = ? WHERE id = ?")->execute([$calculated_balance, $contact_id]);

    return $calculated_balance;
}

/**
 * ====================================================================
 * ZİNCİRLEME FATURA & KASA SİLME MOTORU
 * ====================================================================
 */
function delete_invoice_cascade(int $invoice_id): bool {
    global $db;

    $inv_stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
    $inv_stmt->execute([$invoice_id]);
    $invoice = $inv_stmt->fetch();

    if (!$invoice) {
        return false;
    }

    $contact_id = (int)$invoice['contact_id'];
    $project_id = !empty($invoice['project_id']) ? (int)$invoice['project_id'] : null;

    // 1. Bu faturaya bağlı tahsilatları bul ve sil
    $tx_stmt = $db->prepare("SELECT * FROM transactions WHERE invoice_id = ?");
    $tx_stmt->execute([$invoice_id]);
    $linked_transactions = $tx_stmt->fetchAll();

    foreach ($linked_transactions as $tx) {
        $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$tx['id']]);
    }

    // 2. Set gideri bağlantısını temizle
    $db->prepare("UPDATE shoot_crew_gear SET invoice_id = NULL, invoice_number = NULL WHERE invoice_id = ?")->execute([$invoice_id]);

    // 3. Kurgu revizyonu faturalandırma bağlantısını sıfırla (kolon yoksa sessizce geç)
    try {
        $db->prepare("UPDATE project_revisions SET billing_status = 'unbilled', billing_fee = 0, invoice_id = NULL WHERE invoice_id = ?")->execute([$invoice_id]);
    } catch (Throwable $e) {
        // project_revisions faturalandırma kolonları henüz oluşturulmamış olabilir
    }

    // 4. Proje durumu fatura silindikten sonra düzeltilir (aşağıda)
    // 5. Faturayı sil
    $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoice_id]);

    if ($project_id && $invoice['invoice_type'] === 'sales' && project_main_sales_invoice_id($project_id) === null) {
        $db->prepare("UPDATE projects SET status = 'completed' WHERE id = ? AND status = 'invoiced'")->execute([$project_id]);
    }

    // 6. Cari ve Kasa bakiyelerini sıfırdan matematiksel eşitle
    if ($contact_id > 0) {
        recalculate_contact_balance($contact_id);
    }
    recalculate_account_balance();

    return true;
}

/**
 * ====================================================================
 * FATURA ÖDEME DURUMU SENKRONİZASYONU
 * ====================================================================
 * Faturanın ödenen tutarını bağlı tahsilat/ödeme hareketlerinden yeniden
 * hesaplar ve ödeme durumunu (unpaid / partial / paid) günceller.
 */
function sync_invoice_payment(int $invoice_id): void {
    global $db;

    $inv_stmt = $db->prepare("SELECT grand_total FROM invoices WHERE id = ?");
    $inv_stmt->execute([$invoice_id]);
    $grand_total = $inv_stmt->fetchColumn();
    if ($grand_total === false) {
        return;
    }

    $paid_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE invoice_id = ?");
    $paid_stmt->execute([$invoice_id]);
    $paid = round((float)$paid_stmt->fetchColumn(), 2);

    if ($paid <= 0) {
        $status = 'unpaid';
    } elseif ($paid + 0.009 >= (float)$grand_total) {
        $status = 'paid';
    } else {
        $status = 'partial';
    }

    $db->prepare("UPDATE invoices SET paid_amount = ?, payment_status = ? WHERE id = ?")->execute([$paid, $status, $invoice_id]);
}

/**
 * Faturaya tahsilat / ödeme kaydeder. Fazla ödemeyi engeller, kasa, cari ve
 * fatura durumunu otomatik eşitler. Başarılıysa null, hata varsa mesaj döner.
 */
function record_invoice_payment(int $invoice_id, int $account_id, float $amount, string $date, ?int $user_id): ?string {
    global $db;

    $inv_stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
    $inv_stmt->execute([$invoice_id]);
    $inv = $inv_stmt->fetch();

    if (!$inv) {
        return 'Fatura bulunamadı.';
    }
    if ($account_id <= 0 || $amount <= 0) {
        return 'Lütfen geçerli bir kasa/banka hesabı ve tutar giriniz.';
    }

    $acc_chk = $db->prepare("SELECT id FROM accounts WHERE id = ?");
    $acc_chk->execute([$account_id]);
    if (!$acc_chk->fetch()) {
        return 'Seçilen kasa / banka hesabı bulunamadı.';
    }

    sync_invoice_payment($invoice_id);
    $paid_stmt = $db->prepare("SELECT paid_amount FROM invoices WHERE id = ?");
    $paid_stmt->execute([$invoice_id]);
    $remaining = round((float)$inv['grand_total'] - (float)$paid_stmt->fetchColumn(), 2);

    if ($remaining <= 0) {
        return 'Bu faturanın tamamı zaten ödenmiş.';
    }
    if ($amount - $remaining > 0.009) {
        return 'Girilen tutar faturanın kalan bakiyesinden (' . format_money($remaining) . ') fazla olamaz.';
    }

    $tx_type = ($inv['invoice_type'] === 'sales') ? 'income' : 'expense';
    $category = ($tx_type === 'income') ? 'Fatura Tahsilatı' : 'Fatura Ödemesi';

    $db->prepare("
        INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $account_id, $inv['contact_id'], $invoice_id, $inv['project_id'], $tx_type, $category,
        round($amount, 2), $date ?: date('Y-m-d'), "Fatura No: {$inv['invoice_number']} ödemesi", $user_id
    ]);

    recalculate_account_balance($account_id);
    if (!empty($inv['contact_id'])) {
        recalculate_contact_balance((int)$inv['contact_id']);
    }
    sync_invoice_payment($invoice_id);

    return null;
}

/**
 * ====================================================================
 * ZİNCİRLEME PROJE SİLME MOTORU
 * ====================================================================
 * Projeye bağlı satış faturalarını, set giderlerinden otomatik oluşan alış
 * faturalarını, faturasız borç dekontlarını, çekimleri ve revizyonları siler;
 * cari ve kasa bakiyelerini yeniden hesaplar.
 */
function delete_project_cascade(int $project_id): bool {
    global $db;

    $p_stmt = $db->prepare("SELECT id, client_id FROM projects WHERE id = ?");
    $p_stmt->execute([$project_id]);
    $project = $p_stmt->fetch();
    if (!$project) {
        return false;
    }

    $affected_contacts = [];
    if (!empty($project['client_id'])) {
        $affected_contacts[] = (int)$project['client_id'];
    }

    // 1. Projeye bağlı tüm faturalar (satış + set giderinden doğan alış faturaları)
    $inv_stmt = $db->prepare("
        SELECT id, contact_id FROM invoices WHERE project_id = ?
        UNION
        SELECT i.id, i.contact_id FROM invoices i
        JOIN shoot_crew_gear scg ON scg.invoice_id = i.id
        JOIN shoots s ON scg.shoot_id = s.id
        WHERE s.project_id = ?
    ");
    $inv_stmt->execute([$project_id, $project_id]);
    foreach ($inv_stmt->fetchAll() as $inv) {
        if (!empty($inv['contact_id'])) {
            $affected_contacts[] = (int)$inv['contact_id'];
        }
        delete_invoice_cascade((int)$inv['id']);
    }

    // 2. Kasaya dokunmayan faturasız borç dekontlarını sil, kasa hareketlerinin proje bağını kopar
    $tx_stmt = $db->prepare("SELECT DISTINCT contact_id FROM transactions WHERE project_id = ? AND contact_id IS NOT NULL");
    $tx_stmt->execute([$project_id]);
    foreach ($tx_stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $affected_contacts[] = (int)$cid;
    }
    $db->prepare("DELETE FROM transactions WHERE project_id = ? AND account_id IS NULL")->execute([$project_id]);
    $db->prepare("UPDATE transactions SET project_id = NULL WHERE project_id = ?")->execute([$project_id]);

    // 3. Ekipman ve teklif bağlantılarını temizle
    try {
        $db->prepare("UPDATE equipment SET status = 'in_office', current_project_id = NULL WHERE current_project_id = ?")->execute([$project_id]);
    } catch (Throwable $e) {
    }
    try {
        $db->prepare("UPDATE proposals SET converted_project_id = NULL WHERE converted_project_id = ?")->execute([$project_id]);
    } catch (Throwable $e) {
    }

    // 4. Çekim giderleri, çekimler, revizyonlar ve proje
    $db->prepare("DELETE scg FROM shoot_crew_gear scg JOIN shoots s ON scg.shoot_id = s.id WHERE s.project_id = ?")->execute([$project_id]);
    $db->prepare("DELETE FROM shoots WHERE project_id = ?")->execute([$project_id]);
    $db->prepare("DELETE FROM project_revisions WHERE project_id = ?")->execute([$project_id]);
    $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$project_id]);

    foreach (array_unique($affected_contacts) as $cid) {
        recalculate_contact_balance($cid);
    }
    recalculate_account_balance();

    return true;
}

/**
 * ====================================================================
 * ZİNCİRLEME CARİ SİLME MOTORU
 * ====================================================================
 */
function delete_contact_cascade(int $contact_id): bool {
    global $db;

    $c_stmt = $db->prepare("SELECT id FROM contacts WHERE id = ?");
    $c_stmt->execute([$contact_id]);
    if (!$c_stmt->fetch()) {
        return false;
    }

    // 1. Müşterinin projeleri (bağlı faturalar dahil)
    $p_stmt = $db->prepare("SELECT id FROM projects WHERE client_id = ?");
    $p_stmt->execute([$contact_id]);
    foreach ($p_stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        delete_project_cascade((int)$pid);
    }

    // 2. Cariye ait kalan faturalar
    $i_stmt = $db->prepare("SELECT id FROM invoices WHERE contact_id = ?");
    $i_stmt->execute([$contact_id]);
    foreach ($i_stmt->fetchAll(PDO::FETCH_COLUMN) as $iid) {
        delete_invoice_cascade((int)$iid);
    }

    // 3. Cari hareketleri, portal kullanıcıları ve bağlantılar
    $db->prepare("DELETE FROM transactions WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("DELETE FROM users WHERE contact_id = ? AND role_id != 1")->execute([$contact_id]);
    $db->prepare("UPDATE shoot_crew_gear SET contact_id = NULL WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("UPDATE projects SET outsource_contact_id = NULL WHERE outsource_contact_id = ?")->execute([$contact_id]);
    try {
        $db->prepare("UPDATE equipment SET status = 'in_office', rental_contact_id = NULL, rental_start_date = NULL, rental_end_date = NULL WHERE rental_contact_id = ?")->execute([$contact_id]);
    } catch (Throwable $e) {
    }
    try {
        $db->prepare("DELETE FROM proposals WHERE client_id = ?")->execute([$contact_id]);
    } catch (Throwable $e) {
    }
    try {
        $db->prepare("DELETE FROM contact_change_logs WHERE contact_id = ?")->execute([$contact_id]);
    } catch (Throwable $e) {
    }

    $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$contact_id]);
    recalculate_account_balance();

    return true;
}

function get_project_types(): array {
    global $db;
    $db->query("
        CREATE TABLE IF NOT EXISTS `project_types` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `type_key` VARCHAR(50) NOT NULL UNIQUE,
          `type_name` VARCHAR(100) NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $count = (int)$db->query("SELECT COUNT(*) FROM project_types")->fetchColumn();
    if ($count === 0) {
        $defaults = [
            'commercial'   => 'Reklam Filmi',
            'music_video'  => 'Müzik Klibi',
            'promo'        => 'Tanıtım / Kurumsal Film',
            'documentary'  => 'Belgesel',
            'social_media' => 'Sosyal Medya / Reels',
            'event'        => 'Etkinlik / Konser Çekimi',
            'podcast'      => 'Podcast / YouTube Programı',
            'other'        => 'Diğer Prodüksiyon'
        ];
        $ins = $db->prepare("INSERT IGNORE INTO project_types (type_key, type_name) VALUES (?, ?)");
        foreach ($defaults as $k => $v) {
            $ins->execute([$k, $v]);
        }
    }

    return $db->query("SELECT type_key, type_name FROM project_types ORDER BY id ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function get_project_type_name(?string $key): string {
    $types = get_project_types();
    return $types[$key] ?? ($key ?: 'Belirtilmemiş');
}

/**
 * Belirtilen tablodaki "ÖNEK-YIL-0001" formatındaki kodların en büyüğünü
 * bulup bir sonrakini üretir (silinen kayıtlar sonrası çakışma oluşmaz).
 */
function next_sequential_code(string $table, string $column, string $prefix, string $extra_where = '', array $extra_params = []): string {
    global $db;
    $base = $prefix . date('Y') . '-';

    $sql = "SELECT `{$column}` FROM `{$table}` WHERE `{$column}` LIKE ?" . ($extra_where ? " AND {$extra_where}" : '');
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$base . '%'], $extra_params));

    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $suffix = substr((string)$code, strlen($base));
        if (ctype_digit($suffix)) {
            $max = max($max, (int)$suffix);
        }
    }

    $exists = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
    do {
        $max++;
        $candidate = $base . str_pad((string)$max, 4, '0', STR_PAD_LEFT);
        $exists->execute([$candidate]);
    } while ((int)$exists->fetchColumn() > 0);

    return $candidate;
}

function generate_project_code(): string {
    $prefix = get_setting('project_prefix', 'PRJ-') ?: 'PRJ-';
    return next_sequential_code('projects', 'project_code', $prefix);
}

function generate_invoice_number(string $type = 'sales'): string {
    $prefix = ($type === 'purchase') ? 'ALIS-' : (get_setting('invoice_prefix', 'RYM-') ?: 'RYM-');
    return next_sequential_code('invoices', 'invoice_number', $prefix);
}

function generate_proposal_code(): string {
    return next_sequential_code('proposals', 'proposal_code', 'TKL-');
}

/**
 * Projenin ana (bütçe) satış faturası var mı? Kurgu/edit hizmeti için ayrıca
 * kesilen satış faturaları bu kontrole dahil edilmez.
 */
function project_main_sales_invoice_id(int $project_id): ?int {
    global $db;
    try {
        $stmt = $db->prepare("
            SELECT i.id FROM invoices i
            WHERE i.project_id = ? AND i.invoice_type = 'sales'
              AND i.id NOT IN (SELECT invoice_id FROM project_revisions WHERE project_id = ? AND invoice_id IS NOT NULL)
            ORDER BY i.id ASC LIMIT 1
        ");
        $stmt->execute([$project_id, $project_id]);
    } catch (Throwable $e) {
        // project_revisions.invoice_id kolonu henüz yoksa tüm satış faturaları sayılır
        $stmt = $db->prepare("SELECT id FROM invoices WHERE project_id = ? AND invoice_type = 'sales' ORDER BY id ASC LIMIT 1");
        $stmt->execute([$project_id]);
    }
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Aynı numaralı başka bir fatura var mı? (Düzenlemede kendi kaydı hariç)
 */
function invoice_number_exists(string $invoice_number, int $exclude_id = 0, ?string $invoice_type = null): bool {
    global $db;
    $sql = "SELECT COUNT(*) FROM invoices WHERE invoice_number = ? AND id != ?";
    $params = [$invoice_number, $exclude_id];
    if ($invoice_type !== null) {
        $sql .= " AND invoice_type = ?";
        $params[] = $invoice_type;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return $_SESSION['user'] ?? null;
}

function has_permission(string $permission_key): bool {
    if (!is_logged_in()) {
        return false;
    }
    if (isset($_SESSION['user']['role_id']) && (int)$_SESSION['user']['role_id'] === 1) {
        return true;
    }
    $permissions = $_SESSION['user_permissions'] ?? [];
    return in_array($permission_key, $permissions, true);
}

function require_permission(string $permission_key): void {
    if (!is_logged_in()) {
        redirect(BASE_URL . '/index.php');
    }
    if (!has_permission($permission_key)) {
        set_flash('error', 'Bu işlemi yapmak için yetkiniz bulunmuyor!');
        redirect(BASE_URL . '/modules/dashboard/index.php');
    }
}

function load_user_permissions(int $role_id): array {
    global $db;
    if ($role_id === 1) {
        $stmt = $db->query("SELECT permission_key FROM permissions");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $stmt = $db->prepare("
        SELECT p.permission_key 
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role_id = ?
    ");
    $stmt->execute([$role_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function calculate_tax_breakdown(float $subtotal, float $vat_rate = 20.0, string $withholding_rate = '0/10', float $stoppage_rate = 0.0): array {
    $vat_amount = $subtotal * ($vat_rate / 100);
    $withholding_amount = 0.0;

    if ($withholding_rate !== '0/10' && str_contains($withholding_rate, '/')) {
        [$pay, $payda] = explode('/', $withholding_rate);
        if ((float)$payda > 0) {
            $withholding_amount = $vat_amount * ((float)$pay / (float)$payda);
        }
    }

    $stoppage_amount = $subtotal * ($stoppage_rate / 100);
    $grand_total = ($subtotal + $vat_amount) - $withholding_amount - $stoppage_amount;

    return [
        'subtotal'           => round($subtotal, 2),
        'vat_rate'           => $vat_rate,
        'vat_amount'         => round($vat_amount, 2),
        'withholding_rate'   => $withholding_rate,
        'withholding_amount' => round($withholding_amount, 2),
        'stoppage_rate'      => $stoppage_rate,
        'stoppage_amount'    => round($stoppage_amount, 2),
        'grand_total'        => round($grand_total, 2)
    ];
}

/**
 * Müşteri portalı değişiklik günlüğü tablosu (cari detay ve portal profilinde kullanılır)
 */
function ensure_contact_change_logs_table(): void {
    global $db;
    static $done = false;
    if ($done) {
        return;
    }
    $db->query("
        CREATE TABLE IF NOT EXISTS `contact_change_logs` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `contact_id` INT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `user_name` VARCHAR(150) NOT NULL,
          `field_key` VARCHAR(50) NOT NULL,
          `field_label` VARCHAR(100) NOT NULL,
          `old_value` TEXT NULL,
          `new_value` TEXT NULL,
          `ip_address` VARCHAR(50) NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $done = true;
}

/**
 * ====================================================================
 * SİSTEM AYARLARI (ÖNBELLEKLİ)
 * ====================================================================
 */
function get_settings(bool $refresh = false): array {
    global $db;
    static $cache = null;
    if ($cache === null || $refresh) {
        try {
            $cache = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function get_setting(string $key, string $default = ''): string {
    $settings = get_settings();
    return (isset($settings[$key]) && $settings[$key] !== '') ? (string)$settings[$key] : $default;
}

/**
 * Formdan gelen para tutarını güvenli şekilde sayıya çevirir.
 * "1500.50" (number input), "1.500,50" ve "1500,50" (TR formatı) desteklenir.
 */
function parse_money($value): float {
    $value = trim((string)$value);
    if ($value === '') {
        return 0.0;
    }
    $value = preg_replace('/[^0-9,.\-]/', '', $value);

    $has_dot   = str_contains($value, '.');
    $has_comma = str_contains($value, ',');

    if ($has_dot && $has_comma) {
        // Son görülen ayraç ondalık ayracıdır
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($has_comma) {
        $value = str_replace(',', '.', $value);
    } elseif (substr_count($value, '.') > 1) {
        // 1.250.000 gibi binlik ayraçlı yazım
        $value = str_replace('.', '', $value);
    }

    return round((float)$value, 2);
}

/**
 * Tarih girdisini doğrular; geçersizse varsayılanı döner.
 */
function valid_date($value, ?string $default = null): ?string {
    $value = trim((string)$value);
    if ($value !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if ($d && $d->format('Y-m-d') === $value) {
            return $value;
        }
    }
    return $default;
}

/**
 * Alpine.js / JS attribute içine güvenli şekilde değer yazar.
 */
function js_val($value): string {
    return e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
}

/**
 * Türkçe ay adı
 */
function turkish_month(int $month, bool $short = false): string {
    $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $name = $months[$month] ?? (string)$month;
    return $short ? mb_substr($name, 0, 3, 'UTF-8') : $name;
}

/**
 * ====================================================================
 * EK MODÜL İZİNLERİ (Teklif & Envanter)
 * ====================================================================
 * İzin tabloda yoksa oluşturulur ve mevcut davranış bozulmasın diye tüm
 * rollere atanır. Yönetici daha sonra Roller ekranından kaldırabilir.
 */
function ensure_permission(string $key, string $description, string $module): bool {
    global $db;
    try {
        $chk = $db->prepare("SELECT id FROM permissions WHERE permission_key = ? LIMIT 1");
        $chk->execute([$key]);
        if ($chk->fetch()) {
            return true;
        }
        $db->prepare("INSERT INTO permissions (permission_key, description, module) VALUES (?, ?, ?)")->execute([$key, $description, $module]);
        $perm_id = (int)$db->lastInsertId();
        $roles = $db->query("SELECT id, role_slug FROM roles")->fetchAll();
        $grant = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($roles as $r) {
            if ((int)$r['id'] !== 1 && $r['role_slug'] !== 'client') {
                $grant->execute([(int)$r['id'], $perm_id]);
            }
        }
        if (is_logged_in()) {
            $_SESSION['user_permissions'] = load_user_permissions((int)$_SESSION['user']['role_id']);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

const MODULE_PERMISSIONS = [
    'proposals.manage' => ['description' => 'Teklifleri Görüntüleme & Yönetme', 'module' => 'proposals'],
    'inventory.manage' => ['description' => 'Ekipman & Demirbaş Envanteri Yönetimi', 'module' => 'inventory'],
];

/**
 * Modül izni var mı? İzin tanımlanamadıysa (eski şema) erişimi engellemez.
 */
function can_access_module(string $key): bool {
    if (!is_logged_in()) {
        return false;
    }
    $def = MODULE_PERMISSIONS[$key] ?? ['description' => $key, 'module' => 'other'];
    if (!ensure_permission($key, $def['description'], $def['module'])) {
        return true;
    }
    return has_permission($key);
}

function require_module_permission(string $key): void {
    if (!is_logged_in()) {
        redirect(BASE_URL . '/modules/auth/login.php');
    }
    if (!can_access_module($key)) {
        set_flash('error', 'Bu işlemi yapmak için yetkiniz bulunmuyor!');
        redirect(BASE_URL . '/modules/dashboard/index.php');
    }
}

/**
 * ====================================================================
 * OTURUM DOĞRULAMA (Her istekte kullanıcı durumu ve izinler tazelenir)
 * ====================================================================
 */
function refresh_staff_session(): void {
    global $db;
    if (!is_logged_in()) {
        return;
    }

    $stmt = $db->prepare("
        SELECT u.id, u.role_id, u.contact_id, u.full_name, u.email, u.phone, u.avatar, u.status, r.role_name, r.role_slug
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active' || is_portal_account($row)) {
        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['user_permissions']);
        set_flash('error', 'Oturumunuz sonlandırıldı. Hesabınız pasif veya yetkisiz olabilir.');
        redirect(BASE_URL . '/modules/auth/login.php');
    }

    $_SESSION['user'] = [
        'id'        => $row['id'],
        'role_id'   => $row['role_id'],
        'role_name' => $row['role_name'],
        'role_slug' => $row['role_slug'],
        'full_name' => $row['full_name'],
        'email'     => $row['email'],
        'phone'     => $row['phone'],
        'avatar'    => $row['avatar']
    ];
    $_SESSION['user_permissions'] = load_user_permissions((int)$row['role_id']);
}

/**
 * Yönetim paneli sayfaları için giriş zorunluluğu (header'dan önce POST işleyen sayfalar).
 */
function require_staff_login(): void {
    global $user;
    if (!is_logged_in()) {
        redirect(BASE_URL . '/modules/auth/login.php');
    }
    refresh_staff_session();
    $user = current_user();
}

/**
 * Müşteri portalı sayfaları için giriş zorunluluğu.
 */
function require_client_login(): void {
    global $db;
    if (empty($_SESSION['client_user_id']) || empty($_SESSION['client_contact_id'])) {
        unset($_SESSION['client_user_id'], $_SESSION['client_contact_id'], $_SESSION['client_user']);
        redirect(BASE_URL . '/client/login.php');
    }
    $stmt = $db->prepare("SELECT u.status, u.contact_id, c.id AS cid FROM users u LEFT JOIN contacts c ON c.id = ? WHERE u.id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['client_contact_id'], (int)$_SESSION['client_user_id']]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'active' || empty($row['cid'])) {
        unset($_SESSION['client_user_id'], $_SESSION['client_contact_id'], $_SESSION['client_user']);
        set_flash('error', 'Portal oturumunuz sonlandırıldı.');
        redirect(BASE_URL . '/client/login.php');
    }
}

/**
 * Kullanıcı bir müşteri portalı hesabı mı? (client rolü veya bir cariye bağlı, süper admin hariç)
 */
function is_portal_account(array $u): bool {
    if ((int)($u['role_id'] ?? 0) === 1) {
        return false;
    }
    return ($u['role_slug'] ?? '') === 'client' || !empty($u['contact_id']);
}

/**
 * Müşteri portalı rolünün ID'sini döner; rol yoksa oluşturur.
 */
function get_client_role_id(): int {
    global $db;
    $id = $db->query("SELECT id FROM roles WHERE role_slug = 'client' LIMIT 1")->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $db->prepare("INSERT INTO roles (role_name, role_slug, description) VALUES (?, 'client', ?)")
       ->execute(['Müşteri (Portal)', 'Sadece müşteri portalına erişebilen müşteri hesapları']);
    return (int)$db->lastInsertId();
}

function is_client_logged_in(): bool {
    return !empty($_SESSION['client_user_id']) && !empty($_SESSION['client_contact_id']);
}

/**
 * ====================================================================
 * GİRİŞ DENEMESİ SINIRLAMA (BRUTE-FORCE KORUMASI)
 * ====================================================================
 */
const LOGIN_MAX_ATTEMPTS   = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

function login_attempts_table(): bool {
    global $db;
    static $ready = null;
    if ($ready === null) {
        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `login_attempts` (
                  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  `ip_address` VARCHAR(64) NOT NULL,
                  `email` VARCHAR(190) NOT NULL,
                  `attempted_at` DATETIME NOT NULL,
                  KEY `idx_ip_time` (`ip_address`, `attempted_at`),
                  KEY `idx_email_time` (`email`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function is_login_locked(string $email): bool {
    global $db;
    if (!login_attempts_table()) {
        return false;
    }
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE (ip_address = ? OR email = ?) AND attempted_at > (NOW() - INTERVAL " . LOGIN_LOCKOUT_MINUTES . " MINUTE)
    ");
    $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', mb_strtolower($email)]);
    return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function record_login_failure(string $email): void {
    global $db;
    if (!login_attempts_table()) {
        return;
    }
    $db->prepare("INSERT INTO login_attempts (ip_address, email, attempted_at) VALUES (?, ?, NOW())")
       ->execute([$_SERVER['REMOTE_ADDR'] ?? '', mb_strtolower($email)]);
    $db->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
}

function clear_login_failures(string $email): void {
    global $db;
    if (!login_attempts_table()) {
        return;
    }
    $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email = ?")
       ->execute([$_SERVER['REMOTE_ADDR'] ?? '', mb_strtolower($email)]);
}

// POST işleyicilerinde (header.php yüklenmeden önce) kullanılabilmesi için aktif kullanıcı
$user = current_user();
