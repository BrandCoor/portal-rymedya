<?php
/**
 * ====================================================================
 * GÜVENLİK: İKİ ADIMLI DOĞRULAMA · ŞİFRE SIFIRLAMA · GİRİŞ KAYDI
 *           HAREKETSİZLİKTE ÇIKIŞ · GÜVENLİK BAŞLIKLARI
 * ====================================================================
 * Ayarlar → Güvenlik bölümünü yalnızca süper yönetici (rol 1) değiştirebilir.
 * İki adımlı doğrulama TOTP (RFC 6238) ile çalışır: Google Authenticator,
 * Microsoft Authenticator, Authy vb. uygulamalardan 6 haneli kod.
 */

function run_security_migrations(): void {
    global $db;
    $add = function (string $table, string $column, string $ddl) use ($db) {
        if (!column_exists($table, $column)) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        }
    };
    $add('users', 'totp_secret', "`totp_secret` VARCHAR(255) NULL");
    $add('users', 'totp_enabled', "`totp_enabled` TINYINT(1) NOT NULL DEFAULT 0");
    $add('users', 'totp_recovery', "`totp_recovery` TEXT NULL");
    $add('users', 'totp_last_step', "`totp_last_step` BIGINT NULL");
    $add('users', 'totp_enabled_at', "`totp_enabled_at` DATETIME NULL");
    $db->query("CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME NULL,
        `ip` VARCHAR(45) NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_token` (`token_hash`), KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS `login_log` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NULL,
        `email` VARCHAR(190) NULL,
        `area` VARCHAR(10) NOT NULL DEFAULT 'staff',
        `ip` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `device_hash` CHAR(64) NULL,
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        `reason` VARCHAR(100) NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_user` (`user_id`), KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Süper yönetici mi? (güvenlik ayarları, yedekler, giriş kayıtları) */
function is_super_admin(): bool {
    return is_logged_in() && (int)($_SESSION['user']['role_id'] ?? 0) === 1;
}

/* ------------------------------------------------------------------
 * GÜVENLİK BAŞLIKLARI
 * ------------------------------------------------------------------ */
function send_security_headers(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');                         // tıklama tuzağı (clickjacking)
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self)');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/* ------------------------------------------------------------------
 * ŞİFRELEME (uygulama anahtarıyla)
 * ------------------------------------------------------------------ */
function app_encrypt(string $plain): string {
    return smtp_password_encrypt($plain);
}

function app_decrypt(?string $v): string {
    $v = (string)$v;
    if ($v === '' || !str_starts_with($v, 'enc:')) return $v;
    $raw = base64_decode(substr($v, 4));
    $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', mail_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $out === false ? '' : $out;
}

/* ------------------------------------------------------------------
 * TOTP (RFC 6238)
 * ------------------------------------------------------------------ */
function base32_encode_str(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode_str(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string {
    return base32_encode_str(random_bytes(20));
}

function totp_code(string $secret, int $step): string {
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode_str($secret), true);
    $o = ord($hash[19]) & 0x0f;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Kodu doğrular (±30 sn tolerans); eşleşen zaman adımını döner, yoksa null */
function totp_match(string $secret, string $code, ?int $last_step = null): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') return null;
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $step = $now + $i;
        if ($last_step !== null && $step <= $last_step) continue;   // aynı kod tekrar kullanılamaz
        if (hash_equals(totp_code($secret, $step), $code)) return $step;
    }
    return null;
}

function totp_uri(string $secret, string $email): string {
    $issuer = site_setting('company_brand_name') ?: 'RY Medya';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

function totp_recovery_codes(): array {
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $h = bin2hex(random_bytes(4));
        $codes[] = substr($h, 0, 4) . '-' . substr($h, 4, 4);
    }
    return $codes;
}

/** Ayar: off | optional | required — alan: staff | portal */
function twofa_mode(string $area): string {
    $v = site_setting($area === 'staff' ? 'security_2fa_staff' : 'security_2fa_portal');
    return in_array($v, ['off', 'optional', 'required'], true) ? $v : 'off';
}

/**
 * Kullanıcının girdiği 6 haneli kodu veya kurtarma kodunu doğrular.
 * Başarılıysa true; kurtarma kodu kullanıldıysa listeden düşer.
 */
function twofa_verify_user(int $user_id, string $input): bool {
    global $db;
    $st = $db->prepare("SELECT totp_secret, totp_recovery, totp_last_step FROM users WHERE id = ? AND totp_enabled = 1");
    $st->execute([$user_id]);
    $u = $st->fetch();
    if (!$u) return false;
    $input = trim($input);
    if (preg_match('/^\d{6}$/', preg_replace('/\s/', '', $input))) {
        $step = totp_match(app_decrypt($u['totp_secret']), $input, $u['totp_last_step'] !== null ? (int)$u['totp_last_step'] : null);
        if ($step !== null) {
            $db->prepare("UPDATE users SET totp_last_step = ? WHERE id = ?")->execute([$step, $user_id]);
            return true;
        }
        return false;
    }
    $hashes = json_decode((string)$u['totp_recovery'], true) ?: [];
    $h = hash('sha256', strtolower(preg_replace('/[^a-f0-9]/i', '', $input)));
    $idx = array_search($h, $hashes, true);
    if ($idx === false) return false;
    unset($hashes[$idx]);
    $db->prepare("UPDATE users SET totp_recovery = ? WHERE id = ?")->execute([json_encode(array_values($hashes)), $user_id]);
    log_activity('security', 'İki adımlı doğrulamada kurtarma kodu kullanıldı', 'user', $user_id, null, $user_id);
    return true;
}

function twofa_enable(int $user_id, string $secret): array {
    global $db;
    $codes = totp_recovery_codes();
    $hashes = array_map(fn($c) => hash('sha256', str_replace('-', '', $c)), $codes);
    $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery = ?, totp_last_step = ?, totp_enabled_at = NOW() WHERE id = ?")
       ->execute([app_encrypt($secret), json_encode($hashes), intdiv(time(), 30), $user_id]);
    return $codes;
}

function twofa_disable(int $user_id): void {
    global $db;
    $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery = NULL, totp_last_step = NULL, totp_enabled_at = NULL WHERE id = ?")->execute([$user_id]);
}

/* ------------------------------------------------------------------
 * GİRİŞ KAYDI · YENİ CİHAZ UYARISI
 * ------------------------------------------------------------------ */
function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Aynı tarayıcı + aynı ağ bloğu = aynı cihaz kabul edilir */
function device_hash(): string {
    $ip = client_ip();
    $net = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 4)) : implode('.', array_slice(explode('.', $ip), 0, 3));
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . $net);
}

function security_log_login(?int $user_id, string $email, string $area, bool $success, string $reason = ''): void {
    global $db;
    try {
        $db->prepare("INSERT INTO login_log (user_id, email, area, ip, user_agent, device_hash, success, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$user_id, mb_substr(mb_strtolower($email), 0, 190), $area, client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), device_hash(), $success ? 1 : 0, $reason ?: null]);
        if (mt_rand(1, 50) === 1) {
            $db->query("DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 180 DAY");
        }
    } catch (Throwable $e) {
        error_log('Giriş kaydı: ' . $e->getMessage());
    }
}

/** Başarılı girişten sonra: bilinmeyen cihazsa kullanıcıya e-posta */
function security_new_device_check(array $u, string $area): void {
    global $db;
    if (site_setting('security_new_device_mail') !== '1' || empty($u['email'])) return;
    $prev = $db->prepare("SELECT COUNT(*) AS total, SUM(device_hash = ?) AS same FROM login_log WHERE user_id = ? AND success = 1 AND id < (SELECT MAX(id) FROM login_log WHERE user_id = ?)");
    $prev->execute([device_hash(), $u['id'], $u['id']]);
    $r = $prev->fetch();
    if ((int)$r['total'] === 0 || (int)$r['same'] > 0) return;   // ilk giriş veya bilinen cihaz
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'bilinmiyor'), 0, 160);
    $msg = 'Hesabınıza yeni bir cihaz veya konumdan giriş yapıldı.<br><br><strong>Zaman:</strong> ' . e(date('d.m.Y H:i')) . '<br><strong>IP adresi:</strong> ' . e(client_ip()) . '<br><strong>Tarayıcı:</strong> ' . e($ua)
         . '<br><br>Bu giriş size ait değilse hemen şifrenizi değiştirin ve yöneticinize haber verin.';
    try {
        $html = mail_render(mail_notice_html($u['full_name'] ?? '', $msg), ['title' => 'Yeni cihazdan giriş', 'button' => ['Şifremi değiştir', BASE_URL . '/modules/auth/forgot.php' . ($area === 'portal' ? '?for=portal' : '')]]);
        mail_send_direct($u['email'], 'Hesabınıza yeni cihazdan giriş yapıldı', $html);
    } catch (Throwable $e) {
        error_log('Yeni cihaz e-postası: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------
 * OTURUM BAŞLATMA (şifre + gerekiyorsa ikinci adım sonrası)
 * ------------------------------------------------------------------ */

/** İkinci adım gerekiyorsa bekleyen girişi saklar ve kod ekranına yönlendirir */
function twofa_begin(array $u, string $area, array $extra = []): void {
    session_regenerate_id(true);
    $_SESSION['2fa_pending'] = ['user_id' => (int)$u['id'], 'area' => $area, 'at' => time(), 'email' => $u['email'], 'extra' => $extra];
    redirect(BASE_URL . '/modules/auth/2fa.php');
}

function staff_session_start(array $user, bool $via_2fa = false): void {
    global $db;
    session_regenerate_id(true);
    unset($_SESSION['2fa_pending']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = [
        'id' => $user['id'], 'role_id' => $user['role_id'], 'role_name' => $user['role_name'], 'role_slug' => $user['role_slug'],
        'full_name' => $user['full_name'], 'email' => $user['email'], 'phone' => $user['phone'], 'avatar' => $user['avatar'],
    ];
    $_SESSION['user_permissions'] = load_user_permissions((int)$user['role_id']);
    $_SESSION['last_activity'] = time();
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    security_log_login((int)$user['id'], $user['email'], 'staff', true, $via_2fa ? '2fa' : '');
    security_new_device_check($user, 'staff');
}

function portal_session_start(array $client, int $contact_id, bool $via_2fa = false): void {
    global $db;
    session_regenerate_id(true);
    unset($_SESSION['2fa_pending']);
    $_SESSION['client_user_id'] = $client['id'];
    $_SESSION['client_contact_id'] = $contact_id;
    $_SESSION['client_user'] = [
        'role' => portal_role_from_slug($client['role_slug'] ?? ''), 'id' => $client['id'], 'contact_id' => $contact_id,
        'full_name' => $client['full_name'], 'company_name' => $client['client_name'] ?? $client['full_name'],
        'email' => $client['email'], 'phone' => $client['phone'],
    ];
    $_SESSION['client_last_activity'] = time();
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$client['id']]);
    security_log_login((int)$client['id'], $client['email'], 'portal', true, $via_2fa ? '2fa' : '');
    security_new_device_check($client, 'portal');
}

/** Bu istek iki adımlı doğrulama kurulum / çıkış sayfası mı? (zorunlu kurulum yönlendirmesinden muaf) */
function twofa_exempt_page(): bool {
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($s, ['2fa_setup.php', 'logout.php'], true);
}

/**
 * Personel oturum kontrolleri (her istekte): hareketsizlik süresi ve zorunlu 2FA
 */
function security_staff_guard(array $row): void {
    $idle = (int)site_setting('security_idle_staff');
    $last = (int)($_SESSION['last_activity'] ?? time());
    if ($idle > 0 && time() - $last > $idle * 60) {
        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['user_permissions'], $_SESSION['last_activity']);
        set_flash('info', "{$idle} dakika işlem yapılmadığı için güvenlik amacıyla oturumunuz kapatıldı. Lütfen yeniden giriş yapın.");
        redirect(BASE_URL . '/modules/auth/login.php');
    }
    $_SESSION['last_activity'] = time();
    if (twofa_mode('staff') === 'required' && (int)($row['totp_enabled'] ?? 0) !== 1 && !twofa_exempt_page()) {
        set_flash('warning', 'Hesabınız için iki adımlı doğrulama zorunlu. Devam etmek için kurulumu tamamlayın.');
        redirect(BASE_URL . '/modules/auth/2fa_setup.php');
    }
}

function security_portal_guard(array $row): void {
    $idle = (int)site_setting('security_idle_portal');
    $last = (int)($_SESSION['client_last_activity'] ?? time());
    if ($idle > 0 && time() - $last > $idle * 60) {
        unset($_SESSION['client_user_id'], $_SESSION['client_contact_id'], $_SESSION['client_user'], $_SESSION['client_last_activity']);
        set_flash('info', "{$idle} dakika işlem yapılmadığı için oturumunuz kapatıldı. Lütfen yeniden giriş yapın.");
        redirect(BASE_URL . '/client/login.php');
    }
    $_SESSION['client_last_activity'] = time();
    if (twofa_mode('portal') === 'required' && (int)($row['totp_enabled'] ?? 0) !== 1 && !twofa_exempt_page()) {
        set_flash('warning', 'Hesabınız için iki adımlı doğrulama zorunlu. Devam etmek için kurulumu tamamlayın.');
        redirect(BASE_URL . '/modules/auth/2fa_setup.php');
    }
}

/* ------------------------------------------------------------------
 * ŞİFRE SIFIRLAMA
 * ------------------------------------------------------------------ */

/**
 * Sıfırlama bağlantısı gönderir. Hesap olup olmadığı dışarıya belli edilmez.
 * $area: staff | portal (hangi giriş ekranından istendiği)
 */
function password_reset_request(string $email, string $area): void {
    global $db;
    $email = mb_strtolower(trim($email));
    $st = $db->prepare("SELECT u.*, r.role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE LOWER(u.email) = ? AND u.status = 'active' LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) return;
    $is_portal = is_portal_account($u);
    if (($area === 'portal') !== $is_portal) return;   // personel hesabı portal ekranından sıfırlanmaz (ve tersi)
    $token = bin2hex(random_bytes(32));
    $minutes = max(10, (int)site_setting('security_reset_minutes'));
    $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$u['id']]);
    $db->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, ip, created_at) VALUES (?, ?, NOW() + INTERVAL {$minutes} MINUTE, ?, NOW())")
       ->execute([$u['id'], hash('sha256', $token), client_ip()]);
    $link = BASE_URL . '/modules/auth/reset.php?token=' . $token;
    $msg = 'Şifrenizi sıfırlamak için bir talep aldık. Aşağıdaki bağlantı ' . $minutes . ' dakika geçerlidir ve yalnızca bir kez kullanılabilir.<br><br>Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz; şifreniz değişmez.';
    try {
        $html = mail_render(mail_notice_html($u['full_name'] ?? '', $msg), ['title' => 'Şifre sıfırlama', 'button' => ['Yeni şifre belirle', $link]]);
        $err = mail_send_direct($u['email'], 'Şifre sıfırlama bağlantınız', $html);
        if ($err) error_log('Şifre sıfırlama e-postası gönderilemedi: ' . $err);
    } catch (Throwable $e) {
        error_log('Şifre sıfırlama e-postası: ' . $e->getMessage());
    }
    log_activity('security', "Şifre sıfırlama bağlantısı istendi: {$u['email']}", 'user', (int)$u['id'], null, (int)$u['id']);
}

/** Geçerli sıfırlama kaydı (kullanıcıyla) veya null */
function password_reset_find(string $token): ?array {
    global $db;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $db->prepare("SELECT pr.id AS reset_id, u.*, r.role_slug FROM password_resets pr JOIN users u ON u.id = pr.user_id LEFT JOIN roles r ON r.id = u.role_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = 'active'");
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

function password_reset_complete(array $row, string $password): void {
    global $db;
    $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$row['id']]);
    clear_login_failures($row['email']);
    log_activity('security', "Şifre sıfırlandı: {$row['email']}", 'user', (int)$row['id'], null, (int)$row['id']);
    try {
        $html = mail_render(mail_notice_html($row['full_name'] ?? '', 'Hesabınızın şifresi ' . e(date('d.m.Y H:i')) . ' tarihinde değiştirildi (IP: ' . e(client_ip()) . '). Bu işlemi siz yapmadıysanız hemen yöneticinize haber verin.'), ['title' => 'Şifreniz değiştirildi']);
        mail_send_direct($row['email'], 'Şifreniz değiştirildi', $html);
    } catch (Throwable $e) {
        error_log('Şifre değişti e-postası: ' . $e->getMessage());
    }
}

/** Şifre kuralı: en az 8 karakter, harf ve rakam */
function password_policy_error(string $p): ?string {
    if (mb_strlen($p) < 8) return 'Şifre en az 8 karakter olmalı.';
    if (!preg_match('/[A-Za-zÇĞİÖŞÜçğıöşü]/u', $p) || !preg_match('/\d/', $p)) return 'Şifre en az bir harf ve bir rakam içermeli.';
    return null;
}

/**
 * Giriş ekranları için ortak sayfa iskeleti (2FA, şifre sıfırlama)
 */
function auth_page_open(string $title, string $aside = 'login_staff'): void {
    ?><!DOCTYPE html>
<html lang="tr">
<head><?php ui_head($title); ?></head>
<body>
<div class="auth">
    <?php ui_auth_aside($aside); ?>
    <main class="auth-main">
        <div class="auth-card">
            <div class="lg:hidden" style="display:flex;align-items:center;gap:10px;margin-bottom:32px"><?= brand_html('light') ?></div>
    <?php
}

function auth_page_close(): void {
    ?>
        </div>
        <?= legal_auth_links() ?>
    </main>
</div>
<?php ui_icons_init(); ?>
</body>
</html><?php
}
