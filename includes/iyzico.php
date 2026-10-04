<?php
/**
 * ====================================================================
 * İYZİCO KREDİ KARTI TAHSİLATI (CHECKOUT FORM)
 * ====================================================================
 * Ajans açık faturalarını iyzico'nun ödeme sayfasında kartla öder.
 * Akış: başlat (initialize) → iyzico ödeme sayfası → callback (token)
 * → sonucu sunucudan sorgula (retrieve) → tahsilat faturalara işlenir.
 * Kimlik doğrulama: IYZWSv2 (HMAC-SHA256). SDK gerektirmez; cURL kullanır.
 * Ayarlar: Ayarlar → Banka ve ödeme (API anahtarları, ortam, taksit) ve
 * Platform kuralları → Ödemeler (tahsilatın işleneceği kasa/banka hesabı).
 */

function iyzico_secret(): string {
    return secret_setting('iyzico_secret_key');
}

/** Kartla ödeme kullanılabilir mi? (açık + API anahtarı + güvenlik anahtarı) */
function iyzico_enabled(): bool {
    return !iyzico_issues();
}

/**
 * Kartla ödemenin açılmasına engel olan eksikler (yönetici ekranlarında gösterilir)
 */
function iyzico_issues(): array {
    $out = [];
    if (site_setting('iyzico_enabled') !== '1') $out[] = 'Ayarlar → Banka ve ödeme → "iyzico ile kartla ödeme" kapalı.';
    if (trim((string)site_setting('iyzico_api_key')) === '') $out[] = 'iyzico API anahtarı girilmemiş.';
    if (iyzico_secret() === '') $out[] = 'iyzico güvenlik anahtarı girilmemiş (veya kayıtlı anahtar çözülemedi; yeniden girin).';
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) $out[] = 'Sunucuda PHP cURL eklentisi yok ve allow_url_fopen kapalı; hosting panelinden cURL\'u açın.';
    return $out;
}

/**
 * Kart tahsilatlarının işleneceği kasa/banka hesabı.
 * Platform kurallarında seçilmediyse "iyzico Sanal POS" hesabı kullanılır (yoksa açılır).
 */
function iyzico_account_id(): int {
    global $db;
    $id = (int)platform_setting('platform_card_account_id');
    if ($id > 0) {
        $st = $db->prepare("SELECT id FROM accounts WHERE id = ? AND status = 'active'");
        $st->execute([$id]);
        if ($found = (int)$st->fetchColumn()) return $found;
    }
    $id = (int)$db->query("SELECT id FROM accounts WHERE account_name = 'iyzico Sanal POS' AND status = 'active' LIMIT 1")->fetchColumn();
    if (!$id) {
        $db->prepare("INSERT INTO accounts (account_name, account_type, currency, bank_name, balance, status, created_at) VALUES ('iyzico Sanal POS', 'bank', 'TRY', 'iyzico', 0, 'active', NOW())")->execute();
        $id = (int)$db->lastInsertId();
    }
    return $id;
}

function iyzico_base_url(): string {
    $override = trim((string)get_setting('iyzico_base_url_override', ''));   // yalnızca test ortamı için
    if ($override !== '') return rtrim($override, '/');
    return site_setting('iyzico_mode') === 'live' ? 'https://api.iyzipay.com' : 'https://sandbox-api.iyzipay.com';
}

/** iyzico fiyat biçimi: "100.0", "1250.5" */
function iyzico_price(float $v): string {
    $s = number_format(round($v, 2), 2, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return str_contains($s, '.') ? $s : $s . '.0';
}

/**
 * IYZWSv2 yetkilendirme başlıkları
 * signature = HMAC-SHA256(secret, randomKey + uriPath + body)
 */
function iyzico_headers(string $path, string $body): array {
    $rnd = (string)round(microtime(true) * 1000) . bin2hex(random_bytes(4));
    $sig = hash_hmac('sha256', $rnd . $path . $body, iyzico_secret());
    $auth = base64_encode('apiKey:' . trim((string)site_setting('iyzico_api_key')) . '&randomKey:' . $rnd . '&signature:' . $sig);
    return ['Authorization: IYZWSv2 ' . $auth, 'x-iyzi-rnd: ' . $rnd, 'Content-Type: application/json', 'Accept: application/json'];
}

function iyzico_request(string $path, array $payload): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", iyzico_headers($path, $body)), 'content' => $body, 'timeout' => 30, 'ignore_errors' => true]]);
        $raw = @file_get_contents(iyzico_base_url() . $path, false, $ctx);
        $res = $raw === false ? null : json_decode((string)$raw, true);
        return is_array($res) ? $res : ['status' => 'failure', 'errorMessage' => 'iyzico\'ya bağlanılamadı.'];
    }
    $ch = curl_init(iyzico_base_url() . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => iyzico_headers($path, $body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 'failure', 'errorMessage' => 'iyzico\'ya bağlanılamadı: ' . $err];
    }
    $res = json_decode((string)$raw, true);
    return is_array($res) ? $res : ['status' => 'failure', 'errorMessage' => 'iyzico yanıtı okunamadı.'];
}

function run_iyzico_migrations(): void {
    global $db;
    $db->query("CREATE TABLE IF NOT EXISTS `platform_card_payments` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `contact_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NULL,
        `invoice_ids` VARCHAR(1000) NOT NULL,
        `amount` DECIMAL(14,2) NOT NULL,
        `currency` VARCHAR(3) NOT NULL DEFAULT 'TRY',
        `conversation_id` VARCHAR(64) NOT NULL,
        `token` VARCHAR(255) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'initiated',
        `payment_id` VARCHAR(64) NULL,
        `paid_price` DECIMAL(14,2) NULL,
        `installment` INT NULL,
        `error` VARCHAR(500) NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `completed_at` DATETIME NULL,
        UNIQUE KEY `uniq_conv` (`conversation_id`), KEY `idx_token` (`token`(64)), KEY `idx_contact` (`contact_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

const CARD_PAYMENT_STATUSES = [
    'initiated' => ['Ödeme sayfasında', 'neutral'],
    'success'   => ['Ödendi', 'success'],
    'failed'    => ['Başarısız', 'danger'],
];

/**
 * Ödeme başlatır. Döner: ['url' => ödeme sayfası] veya ['error' => mesaj]
 * $invoices: ajansın açık faturaları (contact_open_invoices satırları)
 */
function iyzico_start(array $contact, int $user_id, array $user, array $invoices): array {
    global $db;
    if (!iyzico_enabled()) return ['error' => 'Kartla ödeme şu an kullanılamıyor.'];
    if (!$invoices) return ['error' => 'Ödenecek fatura seçin.'];
    $amount = round(array_sum(array_map(fn($i) => (float)$i['remaining'], $invoices)), 2);
    if ($amount <= 0) return ['error' => 'Ödenecek tutar bulunamadı.'];

    $conv = 'RY' . date('ymdHis') . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO platform_card_payments (contact_id, user_id, invoice_ids, amount, currency, conversation_id, status, created_at) VALUES (?, ?, ?, ?, 'TRY', ?, 'initiated', NOW())")
       ->execute([$contact['id'], $user_id, implode(',', array_map(fn($i) => (int)$i['id'], $invoices)), $amount, $conv]);
    $pid = (int)$db->lastInsertId();

    $name = trim((string)($contact['authorized_person'] ?: $user['full_name'] ?? $contact['company_title']));
    $parts = preg_split('/\s+/u', $name) ?: [$name];
    $surname = count($parts) > 1 ? array_pop($parts) : '-';
    $first = implode(' ', $parts) ?: $name;
    $city = $contact['city'] ?: 'İstanbul';
    $address = trim((string)($contact['address'] ?? '')) ?: ($city . ', Türkiye');
    $identity = preg_replace('/\D/', '', (string)($contact['id_number'] ?: ''));
    $identity = strlen($identity) === 11 ? $identity : '11111111111';   // kurumsal müşteride TCKN zorunlu değil
    $installments = array_values(array_filter(array_map('intval', explode(',', (string)site_setting('iyzico_installments'))), fn($n) => in_array($n, [1, 2, 3, 6, 9, 12], true)));

    $payload = [
        'locale' => 'tr',
        'conversationId' => $conv,
        'price' => iyzico_price($amount),
        'paidPrice' => iyzico_price($amount),
        'currency' => 'TRY',
        'basketId' => 'CP' . $pid,
        'paymentGroup' => 'PRODUCT',
        'callbackUrl' => BASE_URL . '/platform/iyzico_callback.php',
        'enabledInstallments' => $installments ?: [1],
        'buyer' => [
            'id' => 'C' . (int)$contact['id'],
            'name' => mb_substr($first, 0, 60), 'surname' => mb_substr($surname, 0, 60),
            'gsmNumber' => preg_replace('/[^\d+]/', '', (string)$contact['phone']) ?: '+905000000000',
            'email' => $user['email'] ?? $contact['email'] ?? 'musteri@example.com',
            'identityNumber' => $identity,
            'registrationAddress' => mb_substr($address, 0, 250),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'city' => $city, 'country' => 'Turkey',
        ],
        'shippingAddress' => ['contactName' => $contact['company_title'], 'city' => $city, 'country' => 'Turkey', 'address' => mb_substr($address, 0, 250)],
        'billingAddress'  => ['contactName' => $contact['company_title'], 'city' => $city, 'country' => 'Turkey', 'address' => mb_substr($address, 0, 250)],
        'basketItems' => array_map(fn($i) => [
            'id' => 'INV' . (int)$i['id'],
            'name' => mb_substr('Fatura ' . $i['invoice_number'], 0, 100),
            'category1' => 'Prodüksiyon hizmeti',
            'itemType' => 'VIRTUAL',
            'price' => iyzico_price((float)$i['remaining']),
        ], array_values($invoices)),
    ];
    $res = iyzico_request('/payment/iyzipos/checkoutform/initialize/auth/ecom', $payload);
    if (($res['status'] ?? '') !== 'success' || empty($res['token']) || empty($res['paymentPageUrl'])) {
        $msg = (string)($res['errorMessage'] ?? 'Ödeme başlatılamadı.');
        $db->prepare("UPDATE platform_card_payments SET status = 'failed', error = ?, completed_at = NOW() WHERE id = ?")->execute([mb_substr($msg, 0, 500), $pid]);
        return ['error' => 'Ödeme başlatılamadı: ' . $msg];
    }
    $db->prepare("UPDATE platform_card_payments SET token = ? WHERE id = ?")->execute([$res['token'], $pid]);
    return ['url' => $res['paymentPageUrl'], 'id' => $pid];
}

/**
 * iyzico dönüşü: token ile sonucu sunucudan sorgular, başarılıysa tahsilatı işler.
 * Aynı ödeme iki kez işlenmez. Döner: kart ödeme satırı (güncel) veya null
 */
function iyzico_complete(string $token): ?array {
    global $db;
    $st = $db->prepare("SELECT * FROM platform_card_payments WHERE token = ?");
    $st->execute([$token]);
    $cp = $st->fetch();
    if (!$cp) return null;
    if ($cp['status'] !== 'initiated') return $cp;

    $res = iyzico_request('/payment/iyzipos/checkoutform/auth/ecom/detail', ['locale' => 'tr', 'conversationId' => $cp['conversation_id'], 'token' => $token]);
    $ok = ($res['status'] ?? '') === 'success'
        && ($res['paymentStatus'] ?? '') === 'SUCCESS'
        && (string)($res['basketId'] ?? '') === 'CP' . $cp['id']
        && (!isset($res['conversationId']) || (string)$res['conversationId'] === $cp['conversation_id'])
        && abs((float)($res['price'] ?? 0) - (float)$cp['amount']) < 0.01
        && (int)($res['fraudStatus'] ?? 1) !== -1;
    if (!$ok) {
        $msg = (string)($res['errorMessage'] ?? (($res['paymentStatus'] ?? '') !== '' ? 'Ödeme tamamlanmadı (' . $res['paymentStatus'] . ').' : 'Ödeme doğrulanamadı.'));
        $db->prepare("UPDATE platform_card_payments SET status = 'failed', error = ?, completed_at = NOW() WHERE id = ? AND status = 'initiated'")->execute([mb_substr($msg, 0, 500), $cp['id']]);
        return array_merge($cp, ['status' => 'failed', 'error' => $msg]);
    }
    // Yarış durumuna karşı: yalnızca ilk işleyen devam eder
    $lock = $db->prepare("UPDATE platform_card_payments SET status = 'success', payment_id = ?, paid_price = ?, installment = ?, completed_at = NOW() WHERE id = ? AND status = 'initiated'");
    $lock->execute([(string)($res['paymentId'] ?? ''), (float)($res['paidPrice'] ?? $cp['amount']), (int)($res['installment'] ?? 1), $cp['id']]);
    if ($lock->rowCount() === 0) {
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }
    $account = iyzico_account_id();
    $left = (float)$cp['amount'];
    $date = date('Y-m-d');
    $nums = [];
    foreach (array_filter(array_map('intval', explode(',', $cp['invoice_ids']))) as $iid) {
        $inv = $db->prepare("SELECT id, invoice_number, contact_id, ROUND(grand_total - paid_amount, 2) AS remaining FROM invoices WHERE id = ?");
        $inv->execute([$iid]);
        $inv = $inv->fetch();
        if (!$inv || (int)$inv['contact_id'] !== (int)$cp['contact_id'] || (float)$inv['remaining'] <= 0 || $left <= 0) continue;
        $amt = min($left, (float)$inv['remaining']);
        if (!record_invoice_payment((int)$inv['id'], $account, $amt, $date, null)) {
            $left = round($left - $amt, 2);
            $nums[] = $inv['invoice_number'];
        }
    }
    if ($left > 0.009 && $account) {
        // Fatura bu arada başka yolla ödendiyse fazlası cari hesaba avans olarak işlenir
        $db->prepare("INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at) VALUES (?, ?, NULL, NULL, 'income', 'Cari tahsilat', ?, ?, ?, NULL, NOW())")
           ->execute([$account, $cp['contact_id'], $left, $date, "iyzico kart ödemesi #{$cp['id']} (faturaya bağlanmadı)"]);
        recalculate_account_balance($account);
        recalculate_contact_balance((int)$cp['contact_id']);
    }
    $company = $db->query("SELECT company_title FROM contacts WHERE id = " . (int)$cp['contact_id'])->fetchColumn();
    notify_contact_users((int)$cp['contact_id'], 'Kartla ödemeniz alındı: ' . format_money((float)$cp['amount']) . ($nums ? ' · ' . implode(', ', $nums) : '') . '. Teşekkürler.', '/platform/payments.php');
    notify_staff_payment("Kartla ödeme alındı (iyzico): {$company} · " . format_money((float)$cp['amount']) . ($nums ? ' · ' . implode(', ', $nums) : ''));
    $st->execute([$token]);
    return $st->fetch() ?: null;
}
