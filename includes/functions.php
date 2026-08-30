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
        if (!empty($tx['account_id'])) {
            recalculate_account_balance((int)$tx['account_id']);
        }
    }

    // 2. Set gideri bağlantısını temizle
    $db->prepare("UPDATE shoot_crew_gear SET invoice_id = NULL, invoice_number = NULL WHERE invoice_id = ?")->execute([$invoice_id]);

    // 3. Proje durumunu düzelt
    if ($project_id) {
        $other_sales = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE project_id = {$project_id} AND invoice_type = 'sales' AND id != {$invoice_id}")->fetchColumn();
        if ($other_sales === 0) {
            $db->prepare("UPDATE projects SET status = 'completed' WHERE id = ?")->execute([$project_id]);
        }
    }

    // 4. Faturayı sil
    $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoice_id]);

    // 5. Cari ve Kasa bakiyelerini sıfırdan matematiksel eşitle
    recalculate_contact_balance($contact_id);
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

function generate_project_code(): string {
    global $db;
    $year = date('Y');
    $stmt = $db->query("SELECT COUNT(id) as total FROM projects WHERE YEAR(created_at) = {$year}");
    $count = (int)$stmt->fetch()['total'] + 1;
    return 'PRJ-' . $year . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

function generate_invoice_number(string $type = 'sales'): string {
    global $db;
    $year = date('Y');
    $settings = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = 'invoice_prefix'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $prefix = $settings['invoice_prefix'] ?? 'RYM-';
    
    if ($type === 'purchase') {
        $prefix = 'ALIS-';
    }

    $stmt = $db->query("SELECT COUNT(id) as total FROM invoices WHERE invoice_type = '{$type}' AND YEAR(issue_date) = {$year}");
    $count = (int)$stmt->fetch()['total'] + 1;

    return $prefix . $year . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
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