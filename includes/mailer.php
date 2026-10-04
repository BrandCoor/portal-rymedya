<?php
/**
 * ====================================================================
 * E-POSTA ALTYAPISI
 * ====================================================================
 * - Gönderim: SMTP (TLS/SSL, AUTH LOGIN) veya sunucunun mail() işlevi
 * - Kuyruk : mail_queue tablosu. İşe bağlı bildirimler istek bitince
 *            (yanıt kullanıcıya gittikten sonra) gönderilir; toplu
 *            kampanyalar parti parti işlenir (ekran veya cron).
 * - Şablon : marka logosu, vurgu rengi ve alt bilgi ayarlardan gelir.
 */

const MAIL_KINDS = ['notice' => 'Bildirim', 'campaign' => 'Kampanya', 'system' => 'Sistem', 'test' => 'Test'];
const MAIL_STATUSES = ['queued' => ['Kuyrukta', 'info'], 'sending' => ['Gönderiliyor', 'warning'], 'sent' => ['Gönderildi', 'success'], 'failed' => ['Başarısız', 'danger'], 'skipped' => ['Atlandı', 'neutral']];
const MAIL_SUBSCRIBER_KINDS = ['client' => 'Müşteri', 'agency' => 'Ajans', 'freelancer' => 'Freelancer', 'supplier' => 'Tedarikçi', 'staff' => 'Personel', 'manual' => 'Manuel eklenen'];
const MAIL_MAX_ATTEMPTS = 3;

/**
 * ====================================================================
 * MIGRATION (v5)
 * ====================================================================
 */
function run_mail_migrations(): void {
    global $db;
    $db->query("CREATE TABLE IF NOT EXISTS `mail_queue` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `kind` VARCHAR(20) NOT NULL DEFAULT 'notice',
        `campaign_id` INT UNSIGNED NULL,
        `subscriber_id` INT UNSIGNED NULL,
        `user_id` INT UNSIGNED NULL,
        `to_email` VARCHAR(190) NOT NULL,
        `to_name` VARCHAR(190) NULL,
        `subject` VARCHAR(255) NOT NULL,
        `body_html` MEDIUMTEXT NOT NULL,
        `body_text` MEDIUMTEXT NULL,
        `unsubscribe_url` VARCHAR(500) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
        `attempts` TINYINT NOT NULL DEFAULT 0,
        `last_error` VARCHAR(500) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `sent_at` DATETIME NULL,
        KEY `idx_status` (`status`, `id`),
        KEY `idx_campaign` (`campaign_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `mail_subscribers` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `email` VARCHAR(190) NOT NULL UNIQUE,
        `name` VARCHAR(190) NULL,
        `company` VARCHAR(190) NULL,
        `kind` VARCHAR(20) NOT NULL DEFAULT 'manual',
        `user_id` INT UNSIGNED NULL,
        `contact_id` INT UNSIGNED NULL,
        `tags` VARCHAR(500) NULL,
        `consent` TINYINT(1) NOT NULL DEFAULT 0,
        `status` VARCHAR(20) NOT NULL DEFAULT 'subscribed',
        `token` CHAR(32) NOT NULL,
        `source` VARCHAR(30) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `unsubscribed_at` DATETIME NULL,
        KEY `idx_kind` (`kind`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `mail_campaigns` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(190) NOT NULL,
        `subject` VARCHAR(255) NOT NULL,
        `preheader` VARCHAR(255) NULL,
        `body_html` MEDIUMTEXT NOT NULL,
        `audience` TEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `total` INT NOT NULL DEFAULT 0,
        `sent` INT NOT NULL DEFAULT 0,
        `failed` INT NOT NULL DEFAULT 0,
        `created_by` INT UNSIGNED NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `queued_at` DATETIME NULL,
        `finished_at` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!column_exists('users', 'notify_email')) {
        $db->query("ALTER TABLE `users` ADD COLUMN `notify_email` TINYINT(1) NOT NULL DEFAULT 1");
    }
    ensure_permission('mail.manage', 'E-posta merkezi: aboneler, toplu gönderim, gönderim kayıtları', 'mail', 'settings.manage');
}

/**
 * ====================================================================
 * AYARLAR & YARDIMCILAR
 * ====================================================================
 */
function mail_enabled(): bool {
    return site_setting('mail_enabled') === '1' && filter_var(mail_from_email(), FILTER_VALIDATE_EMAIL);
}

function mail_from_email(): string {
    $f = site_setting('mail_from_email');
    if ($f !== '') return $f;
    return site_setting('company_email');
}

function mail_from_name(): string {
    return site_setting('mail_from_name') ?: site_setting('company_brand_name');
}

function mail_header_encode(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function mail_address(string $email, ?string $name = null): string {
    $email = str_replace(["\r", "\n", '<', '>'], '', $email);
    if ($name === null || trim($name) === '') return $email;
    $name = str_replace(["\r", "\n", '"'], '', $name);
    return mail_header_encode($name) . " <{$email}>";
}

function mail_html_to_text(string $html): string {
    $html = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $html);
    $html = preg_replace('#<li\b[^>]*>#i', '- ', $html);
    $html = preg_replace_callback('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', fn($m) => trim(strip_tags($m[2])) . ' (' . html_entity_decode($m[1]) . ')', $html);
    $text = html_entity_decode(strip_tags(preg_replace('#<(style|head)\b.*?</\1>#is', '', $html)), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text));
}

/**
 * ====================================================================
 * ŞABLON
 * ====================================================================
 * $content_html güvenli (kaçışlanmış veya temizlenmiş) HTML olmalıdır.
 */
function mail_render(string $content_html, array $opts = []): string {
    $accent = valid_hex_color(site_setting('brand_accent_color')) ? site_setting('brand_accent_color') : '#D2462F';
    $brand  = site_setting('company_brand_name');
    $logo   = site_image('brand_logo');
    $pre    = $opts['preheader'] ?? '';
    $button = $opts['button'] ?? null; // [label, url]
    $unsub  = $opts['unsubscribe_url'] ?? '';
    $footer = site_setting('mail_footer_text');
    $company_line = trim(site_setting('company_name') . (site_setting('company_address') !== '' ? ' · ' . preg_replace('/\s+/', ' ', site_setting('company_address')) : ''));

    $head = $logo !== ''
        ? '<img src="' . e($logo) . '" alt="' . e($brand) . '" style="height:32px;width:auto;border:0;display:block">'
        : '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:' . $accent . ';color:#fff;font-weight:700;font-size:12px;width:30px;height:30px;text-align:center;border-radius:7px">' . e(mb_substr(site_setting('brand_mark_text'), 0, 3)) . '</td><td style="padding-left:10px;font-weight:600;font-size:15px;color:#151517">' . e($brand) . '</td></tr></table>';

    $btn = '';
    if ($button && !empty($button[1])) {
        $btn = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 4px"><tr><td style="background:' . $accent . ';border-radius:8px">'
             . '<a href="' . e($button[1]) . '" style="display:inline-block;padding:11px 20px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px">' . e($button[0]) . '</a></td></tr></table>';
    }

    return '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($opts['title'] ?? $brand) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#F4F3F0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;color:#26262A">'
        . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . e($pre) . '</div>' : '')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F3F0"><tr><td align="center" style="padding:32px 16px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        . '<tr><td style="padding:0 4px 18px">' . $head . '</td></tr>'
        . '<tr><td style="background:#ffffff;border:1px solid #E4E2DD;border-radius:12px;padding:28px 28px 26px;font-size:15px;line-height:1.6;color:#26262A">'
        . $content_html . $btn
        . '</td></tr>'
        . '<tr><td style="padding:18px 6px 0;font-size:12px;line-height:1.55;color:#8A8990">'
        . ($footer !== '' ? nl2br(e($footer)) . '<br>' : '')
        . e($company_line)
        . ($unsub !== '' ? '<br><a href="' . e($unsub) . '" style="color:#8A8990;text-decoration:underline">Bu listeden çık</a>' : '')
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * Bildirim metnini e-posta içeriğine çevirir
 */
function mail_notice_html(string $greeting_name, string $message, string $extra = ''): string {
    $hi = trim($greeting_name) !== '' ? 'Merhaba ' . e(trim($greeting_name)) . ',' : 'Merhaba,';
    return '<p style="margin:0 0 14px">' . $hi . '</p><p style="margin:0 0 10px;font-size:16px;color:#151517">' . nl2br(e($message)) . '</p>' . $extra;
}

/**
 * ====================================================================
 * KUYRUK
 * ====================================================================
 */
function mail_queue_add(string $to, ?string $name, string $subject, string $html, array $meta = []): ?int {
    global $db;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $db->prepare("INSERT INTO mail_queue (kind, campaign_id, subscriber_id, user_id, to_email, to_name, subject, body_html, body_text, unsubscribe_url, status, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', NOW())")
       ->execute([$meta['kind'] ?? 'notice', $meta['campaign_id'] ?? null, $meta['subscriber_id'] ?? null, $meta['user_id'] ?? null,
                  mb_strtolower($to), $name, mb_substr($subject, 0, 255), $html, mail_html_to_text($html), $meta['unsubscribe_url'] ?? null]);
    $id = (int)$db->lastInsertId();
    if (!empty($meta['send_after_response'])) {
        mail_defer($id);
    }
    return $id;
}

/**
 * İstek bittiğinde (yanıt gönderildikten sonra) gönderilecek kayıtlar
 */
function mail_defer(int $id): void {
    static $registered = false;
    $GLOBALS['__mail_deferred'][] = $id;
    if (!$registered) {
        $registered = true;
        register_shutdown_function(function () {
            $ids = $GLOBALS['__mail_deferred'] ?? [];
            if (!$ids) return;
            // Kullanıcı yanıtı beklemesin
            if (function_exists('fastcgi_finish_request')) {
                while (ob_get_level() > 0) { @ob_end_flush(); }
                @fastcgi_finish_request();
            }
            ignore_user_abort(true);
            @set_time_limit(60);
            try {
                mail_process_queue(50, $ids);
            } catch (Throwable $e) {
                error_log('E-posta gönderimi: ' . $e->getMessage());
            }
        });
    }
}

/**
 * Kuyruğu işler. $only_ids verilirse yalnızca o kayıtlar; $campaign_id ile kampanya.
 * Dönüş: ['sent' => n, 'failed' => n, 'remaining' => n]
 */
function mail_process_queue(int $limit = 20, ?array $only_ids = null, ?int $campaign_id = null): array {
    global $db;
    $res = ['sent' => 0, 'failed' => 0, 'remaining' => 0];
    if (!mail_enabled()) {
        return $res + ['error' => 'E-posta gönderimi kapalı veya gönderen adresi tanımlı değil.'];
    }
    // Takılı kalan kayıtlar (10 dk'dan uzun "sending") kuyruğa döner
    $db->query("UPDATE mail_queue SET status = 'queued' WHERE status = 'sending' AND created_at < NOW() - INTERVAL 10 MINUTE AND (sent_at IS NULL)");

    $sql = "SELECT * FROM mail_queue WHERE status = 'queued' AND attempts < " . MAIL_MAX_ATTEMPTS;
    $params = [];
    if ($only_ids) {
        $sql .= " AND id IN (" . implode(',', array_map('intval', $only_ids)) . ")";
    }
    if ($campaign_id) {
        $sql .= " AND campaign_id = ?";
        $params[] = $campaign_id;
    }
    $st = $db->prepare($sql . " ORDER BY FIELD(kind, 'test', 'notice', 'system', 'campaign'), id LIMIT " . max(1, $limit));
    $st->execute($params);
    $rows = $st->fetchAll();
    if (!$rows) {
        return $res;
    }

    $transport = mail_transport();
    $claim = $db->prepare("UPDATE mail_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'queued'");
    $ok    = $db->prepare("UPDATE mail_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?");
    $fail  = $db->prepare("UPDATE mail_queue SET status = IF(attempts >= " . MAIL_MAX_ATTEMPTS . ", 'failed', 'queued'), last_error = ? WHERE id = ?");
    $touched_campaigns = [];
    try {
        foreach ($rows as $m) {
            $claim->execute([$m['id']]);
            if ($claim->rowCount() === 0) continue;
            try {
                $transport->send($m['to_email'], $m['to_name'], $m['subject'], $m['body_html'], (string)$m['body_text'], (string)$m['unsubscribe_url']);
                $ok->execute([$m['id']]);
                $res['sent']++;
            } catch (Throwable $e) {
                $fail->execute([mb_substr($e->getMessage(), 0, 500), $m['id']]);
                $res['failed']++;
            }
            if ($m['campaign_id']) $touched_campaigns[(int)$m['campaign_id']] = true;
        }
    } finally {
        $transport->close();
    }
    foreach (array_keys($touched_campaigns) as $cid) {
        mail_campaign_refresh($cid);
    }
    $res['remaining'] = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'queued' AND attempts < " . MAIL_MAX_ATTEMPTS . ($campaign_id ? " AND campaign_id = " . (int)$campaign_id : ''))->fetchColumn();
    return $res;
}

function mail_campaign_refresh(int $cid): void {
    global $db;
    $c = $db->prepare("SELECT SUM(status = 'sent') AS s, SUM(status = 'failed') AS f, SUM(status IN ('queued', 'sending')) AS q FROM mail_queue WHERE campaign_id = ?");
    $c->execute([$cid]);
    $r = $c->fetch();
    $db->prepare("UPDATE mail_campaigns SET sent = ?, failed = ?, status = IF(? = 0 AND status = 'sending', 'sent', status), finished_at = IF(? = 0 AND finished_at IS NULL, NOW(), finished_at) WHERE id = ?")
       ->execute([(int)$r['s'], (int)$r['f'], (int)$r['q'], (int)$r['q'], $cid]);
}

/**
 * Tek seferlik doğrudan gönderim (test e-postası). Hata varsa mesaj döner.
 */
function mail_send_direct(string $to, string $subject, string $html): ?string {
    if (!mail_enabled()) {
        return 'E-posta gönderimi kapalı veya gönderen adresi tanımlı değil.';
    }
    $t = mail_transport();
    try {
        $t->send($to, null, $subject, $html, mail_html_to_text($html), '');
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    } finally {
        $t->close();
    }
}

/**
 * ====================================================================
 * TAŞIYICILAR (SMTP / mail())
 * ====================================================================
 */
function mail_transport(): MailTransport {
    return site_setting('mail_transport') === 'smtp' ? new SmtpTransport() : new PhpMailTransport();
}

abstract class MailTransport {
    abstract public function send(string $to, ?string $name, string $subject, string $html, string $text, string $unsubscribe_url): void;
    public function close(): void {}

    protected function build(string $to, ?string $name, string $subject, string $html, string $text, string $unsubscribe_url): array {
        $from = mail_from_email();
        $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $headers = [
            'Date' => date('r'),
            'From' => mail_address($from, mail_from_name()),
            'To' => mail_address($to, $name),
            'Subject' => mail_header_encode($subject),
            'Message-ID' => '<' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"{$boundary}\"",
            'X-Mailer' => 'RYPortal',
        ];
        $reply = site_setting('mail_reply_to');
        if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = $reply;
        }
        if ($unsubscribe_url !== '') {
            $headers['List-Unsubscribe'] = '<' . $unsubscribe_url . '>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text !== '' ? $text : mail_html_to_text($html)))
              . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . "--{$boundary}--\r\n";
        return [$headers, $body];
    }
}

class PhpMailTransport extends MailTransport {
    public function send(string $to, ?string $name, string $subject, string $html, string $text, string $unsubscribe_url): void {
        [$headers, $body] = $this->build($to, $name, $subject, $html, $text, $unsubscribe_url);
        $subj = $headers['Subject'];
        $to_h = $headers['To'];
        unset($headers['Subject'], $headers['To']);
        $hs = '';
        foreach ($headers as $k => $v) $hs .= "{$k}: {$v}\r\n";
        $from = mail_from_email();
        $ok = @mail($to_h, $subj, $body, rtrim($hs), '-f' . $from);
        if (!$ok) {
            // Bazı sunucular -f parametresine izin vermez
            $ok = @mail($to_h, $subj, $body, rtrim($hs));
        }
        if (!$ok) {
            throw new RuntimeException('Sunucunun mail() işlevi e-postayı kabul etmedi. SMTP kullanmayı deneyin.');
        }
    }
}

class SmtpTransport extends MailTransport {
    private $sock = null;

    private function read(): array {
        $data = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        if ($data === '') {
            $meta = stream_get_meta_data($this->sock);
            throw new RuntimeException($meta['timed_out'] ?? false ? 'SMTP sunucusu yanıt vermedi (zaman aşımı).' : 'SMTP bağlantısı kapandı.');
        }
        return [(int)substr($data, 0, 3), trim($data)];
    }

    private function cmd(string $line, array $expect, string $mask = ''): array {
        fwrite($this->sock, $line . "\r\n");
        [$code, $msg] = $this->read();
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException('SMTP hatası (' . ($mask ?: strtok($line, ' ')) . '): ' . mb_substr($msg, 0, 300));
        }
        return [$code, $msg];
    }

    private function connect(): void {
        $host = site_setting('smtp_host');
        $port = (int)site_setting('smtp_port') ?: 587;
        $sec  = site_setting('smtp_secure');
        if ($host === '') {
            throw new RuntimeException('SMTP sunucusu tanımlı değil.');
        }
        $verify = site_setting('smtp_verify_ssl') === '1';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $this->sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) {
            $this->sock = null;
            throw new RuntimeException("SMTP sunucusuna bağlanılamadı ({$host}:{$port}): {$errstr}");
        }
        stream_set_timeout($this->sock, 20);
        [$code, $msg] = $this->read();
        if ($code !== 220) {
            throw new RuntimeException('SMTP karşılama hatası: ' . $msg);
        }
        $ehlo = preg_replace('/[^a-z0-9.-]/i', '', parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost') ?: 'localhost';
        [, $caps] = $this->cmd("EHLO {$ehlo}", [250]);
        if ($sec === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0))) {
                throw new RuntimeException('STARTTLS şifrelemesi başlatılamadı (sertifika doğrulaması için ayarı kontrol edin).');
            }
            [, $caps] = $this->cmd("EHLO {$ehlo}", [250]);
        }
        $user = site_setting('smtp_user');
        if ($user !== '') {
            $pass = smtp_password();
            if (stripos($caps, 'AUTH') !== false && stripos($caps, 'LOGIN') === false && stripos($caps, 'PLAIN') !== false) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), [235], 'AUTH');
            } else {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($user), [334], 'AUTH kullanıcı');
                $this->cmd(base64_encode($pass), [235], 'AUTH şifre');
            }
        }
    }

    public function send(string $to, ?string $name, string $subject, string $html, string $text, string $unsubscribe_url): void {
        if (!$this->sock) {
            $this->connect();
        } else {
            $this->cmd('RSET', [250]);
        }
        [$headers, $body] = $this->build($to, $name, $subject, $html, $text, $unsubscribe_url);
        $this->cmd('MAIL FROM:<' . mail_from_email() . '>', [250]);
        $this->cmd('RCPT TO:<' . str_replace(['<', '>', "\r", "\n"], '', $to) . '>', [250, 251]);
        $this->cmd('DATA', [354]);
        $data = '';
        foreach ($headers as $k => $v) $data .= "{$k}: {$v}\r\n";
        $data .= "\r\n" . $body;
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));
        fwrite($this->sock, $data . "\r\n.\r\n");
        [$code, $msg] = $this->read();
        if ($code !== 250) {
            throw new RuntimeException('SMTP gönderimi reddetti: ' . mb_substr($msg, 0, 300));
        }
    }

    public function close(): void {
        if ($this->sock) {
            @fwrite($this->sock, "QUIT\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }
}

/**
 * SMTP şifresi veritabanında uygulama anahtarıyla şifreli saklanır
 */
function mail_secret_key(): string {
    return hash('sha256', (defined('APP_SECRET') ? APP_SECRET : '') . __DIR__ . 'ry-mail', true);
}

function smtp_password(): string {
    $v = get_setting('smtp_pass', '');
    if ($v === '' || !str_starts_with($v, 'enc:')) return $v;
    $raw = base64_decode(substr($v, 4));
    $iv = substr($raw, 0, 16);
    $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', mail_secret_key(), OPENSSL_RAW_DATA, $iv);
    return $out === false ? '' : $out;
}

function smtp_password_encrypt(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(16);
    return 'enc:' . base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', mail_secret_key(), OPENSSL_RAW_DATA, $iv));
}

/**
 * ====================================================================
 * İŞE BAĞLI BİLDİRİM E-POSTALARI
 * ====================================================================
 * log_activity() kişiye özel bir bildirim yazdığında çağrılır.
 */
function mail_on_activity(int $user_id, string $message, ?string $link): void {
    global $db;
    if (!mail_enabled()) return;
    $st = $db->prepare("SELECT u.id, u.full_name, u.email, u.status, u.contact_id, u.notify_email, r.role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
    $st->execute([$user_id]);
    $u = $st->fetch();
    if (!$u || $u['status'] !== 'active' || (int)$u['notify_email'] !== 1) return;
    $portal = in_array($u['role_slug'], ['client', 'agency', 'freelancer'], true);
    if (site_setting($portal ? 'mail_notify_portal' : 'mail_notify_staff') !== '1') return;
    mail_send_notice($u['email'], $u['full_name'], $message, $link, (int)$u['id']);
}

function mail_send_notice(string $email, ?string $name, string $message, ?string $link, ?int $user_id = null, string $kind = 'notice'): ?int {
    $subject_prefix = site_setting('mail_subject_prefix');
    $subject = ($subject_prefix !== '' ? $subject_prefix . ' ' : '') . mb_strimwidth(preg_replace('/\s+/', ' ', $message), 0, 110, '…');
    $url = $link ? (preg_match('#^https?://#', $link) ? $link : BASE_URL . $link) : '';
    $html = mail_render(mail_notice_html((string)$name, $message), [
        'preheader' => mb_substr($message, 0, 120),
        'button' => $url !== '' ? [site_setting('mail_button_text') ?: 'Görüntüle', $url] : null,
        'title' => $subject,
    ]);
    return mail_queue_add($email, $name, $subject, $html, ['kind' => $kind, 'user_id' => $user_id, 'send_after_response' => true]);
}

/**
 * Bir carinin (müşteri / ajans) portal kullanıcılarına; hiç kullanıcısı yoksa
 * cari kartındaki e-postaya bildirim gönderir.
 */
function mail_notify_contact(?int $contact_id, string $message, ?string $link): void {
    global $db;
    if (!$contact_id || !mail_enabled() || site_setting('mail_notify_portal') !== '1') return;
    $st = $db->prepare("SELECT id FROM users WHERE contact_id = ? AND status = 'active'");
    $st->execute([$contact_id]);
    $users = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($users) {
        foreach ($users as $uid) mail_on_activity((int)$uid, $message, $link);
        return;
    }
    $c = $db->prepare("SELECT company_title, authorized_person, email FROM contacts WHERE id = ?");
    $c->execute([$contact_id]);
    $ct = $c->fetch();
    if ($ct && filter_var($ct['email'], FILTER_VALIDATE_EMAIL)) {
        mail_send_notice($ct['email'], $ct['authorized_person'] ?: $ct['company_title'], $message, null);
    }
}

/**
 * Ekip için ortak gelen kutusu (ör. operasyon@): platform bildirimlerinin kopyası
 */
function mail_notify_staff_inbox(string $message, ?string $link): void {
    $to = site_setting('mail_staff_inbox');
    if ($to === '' || !mail_enabled()) return;
    mail_send_notice($to, site_setting('platform_team_name'), $message, $link);
}

/**
 * ====================================================================
 * ABONELER
 * ====================================================================
 */
function mail_subscriber_token(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Cari kartlar, portal hesapları ve personeli abone listesine ekler/günceller.
 * Abonelikten çıkmış kişilerin durumu korunur.
 */
function mail_sync_subscribers(): int {
    global $db;
    $map = ['client' => 'client', 'agency' => 'agency', 'freelancer' => 'freelancer', 'equipment_rental' => 'supplier', 'studio' => 'supplier', 'supplier' => 'supplier', 'other' => 'manual'];
    $up = $db->prepare("INSERT INTO mail_subscribers (email, name, company, kind, user_id, contact_id, status, token, source, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'subscribed', ?, 'sync', NOW())
                        ON DUPLICATE KEY UPDATE name = COALESCE(NULLIF(VALUES(name), ''), name), company = COALESCE(NULLIF(VALUES(company), ''), company),
                            kind = IF(kind = 'manual' OR source = 'sync', VALUES(kind), kind),
                            user_id = COALESCE(VALUES(user_id), user_id), contact_id = COALESCE(VALUES(contact_id), contact_id)");
    $n = 0;
    // Portal hesapları ve personel (kullanıcı e-postası)
    foreach ($db->query("SELECT u.id, u.full_name, u.email, u.contact_id, r.role_slug, c.company_title FROM users u LEFT JOIN roles r ON r.id = u.role_id LEFT JOIN contacts c ON c.id = u.contact_id WHERE u.status = 'active'")->fetchAll() as $u) {
        if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        $kind = in_array($u['role_slug'], ['client', 'agency', 'freelancer'], true) ? $u['role_slug'] : 'staff';
        $up->execute([mb_strtolower($u['email']), $u['full_name'], $u['company_title'], $kind, $u['id'], $u['contact_id'], mail_subscriber_token()]);
        $n++;
    }
    // Cari kartlar
    foreach ($db->query("SELECT id, type, company_title, authorized_person, email FROM contacts WHERE email IS NOT NULL AND email != ''")->fetchAll() as $c) {
        if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) continue;
        $up->execute([mb_strtolower($c['email']), $c['authorized_person'] ?: $c['company_title'], $c['company_title'], $map[$c['type']] ?? 'manual', null, $c['id'], mail_subscriber_token()]);
        $n++;
    }
    return $n;
}

/**
 * Hedef kitle: ['kinds' => [...], 'tiers' => [...], 'tag' => '', 'consent_only' => bool, 'cities' => '']
 */
function mail_audience_query(array $aud): array {
    $where = ["s.status = 'subscribed'"];
    $params = [];
    $kinds = array_values(array_intersect(array_keys(MAIL_SUBSCRIBER_KINDS), (array)($aud['kinds'] ?? [])));
    if (!$kinds) {
        return ['1 = 0', []];
    }
    $kind_sql = [];
    foreach ($kinds as $k) {
        if ($k === 'freelancer' && !empty($aud['tiers'])) {
            $tiers = array_values(array_intersect(array_keys(FREELANCER_TIERS), (array)$aud['tiers']));
            if ($tiers) {
                $kind_sql[] = "(s.kind = 'freelancer' AND fp.tier IN (" . implode(',', array_fill(0, count($tiers), '?')) . "))";
                $params = array_merge($params, $tiers);
                continue;
            }
        }
        $kind_sql[] = "s.kind = ?";
        $params[] = $k;
    }
    $where[] = '(' . implode(' OR ', $kind_sql) . ')';
    if (!empty($aud['consent_only'])) {
        $where[] = 's.consent = 1';
    }
    if (trim((string)($aud['tag'] ?? '')) !== '') {
        $where[] = "FIND_IN_SET(?, REPLACE(s.tags, ', ', ','))";
        $params[] = trim($aud['tag']);
    }
    return [implode(' AND ', $where), $params];
}

function mail_audience(array $aud, bool $count_only = false) {
    global $db;
    [$where, $params] = mail_audience_query($aud);
    $sql = "FROM mail_subscribers s LEFT JOIN freelancer_profiles fp ON fp.user_id = s.user_id WHERE {$where}";
    if ($count_only) {
        $st = $db->prepare("SELECT COUNT(DISTINCT s.id) {$sql}");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }
    $st = $db->prepare("SELECT DISTINCT s.* {$sql} ORDER BY s.id");
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Kampanya içeriğindeki birleştirme etiketleri: {ad} {firma} {eposta}
 */
function mail_merge(string $html, array $sub): string {
    $first = trim(explode(' ', trim((string)($sub['name'] ?? '')))[0] ?? '');
    return strtr($html, [
        '{ad}' => e($first !== '' ? $first : 'Değerli iş ortağımız'),
        '{adsoyad}' => e($sub['name'] ?? ''),
        '{firma}' => e($sub['company'] ?? ''),
        '{eposta}' => e($sub['email'] ?? ''),
    ]);
}

function mail_unsubscribe_url(array $sub): string {
    return BASE_URL . '/mail/unsubscribe.php?t=' . $sub['token'];
}

/**
 * Kampanya içeriği için HTML temizleyici (izinli etiket ve nitelikler)
 */
function mail_sanitize_html(string $html): string {
    $allowed = ['p' => [], 'br' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'h1' => [], 'h2' => [], 'h3' => [],
                'ul' => [], 'ol' => [], 'li' => [], 'a' => ['href', 'data-button'], 'img' => ['src', 'alt', 'width'], 'blockquote' => [], 'hr' => [], 'div' => [], 'span' => [], 'font' => []];
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('__root') ?: $doc->documentElement;
    $walk = function (DOMNode $node) use (&$walk, $allowed, $doc) {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'link', 'meta'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                $walk($child);
                if (!isset($allowed[$tag])) {
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $an = strtolower($attr->name);
                    $keep = in_array($an, $allowed[$tag], true);
                    if ($keep && in_array($an, ['href', 'src'], true)) {
                        $v = trim($attr->value);
                        $keep = (bool)preg_match($an === 'href' ? '#^(https?://|mailto:)#i' : '#^https://#i', $v);
                    }
                    if (!$keep) $child->removeAttribute($attr->name);
                }
                if ($tag === 'a') {
                    if ($child->hasAttribute('data-button')) {
                        $accent = valid_hex_color(site_setting('brand_accent_color')) ? site_setting('brand_accent_color') : '#D2462F';
                        $child->setAttribute('style', "display:inline-block;background:{$accent};color:#ffffff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:8px;margin:6px 0");
                    } else {
                        $child->setAttribute('style', 'color:inherit;text-decoration:underline');
                    }
                    $child->setAttribute('target', '_blank');
                }
                if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                    $child->setAttribute('style', 'margin:18px 0 8px;line-height:1.3;color:#151517;font-size:' . ['h1' => '24px', 'h2' => '20px', 'h3' => '17px'][$tag]);
                }
                if ($tag === 'p') {
                    $child->setAttribute('style', 'margin:0 0 12px');
                }
                if ($tag === 'img') {
                    $child->setAttribute('style', 'max-width:100%;height:auto;border:0;display:block;margin:12px 0;border-radius:8px');
                }
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

/**
 * Kullanıcının e-posta tercihleri: iş bildirimleri ve toplu e-posta (bülten)
 */
function mail_user_prefs(int $user_id): array {
    global $db;
    $st = $db->prepare("SELECT u.email, u.notify_email, s.status AS sub_status FROM users u LEFT JOIN mail_subscribers s ON s.email = LOWER(u.email) WHERE u.id = ?");
    $st->execute([$user_id]);
    $r = $st->fetch() ?: [];
    return ['notify' => (int)($r['notify_email'] ?? 1) === 1, 'newsletter' => ($r['sub_status'] ?? 'subscribed') === 'subscribed'];
}

function mail_save_user_prefs(int $user_id, bool $notify, bool $newsletter): void {
    global $db;
    $db->prepare("UPDATE users SET notify_email = ? WHERE id = ?")->execute([$notify ? 1 : 0, $user_id]);
    $u = $db->prepare("SELECT u.email, u.full_name, u.contact_id, r.role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
    $u->execute([$user_id]);
    $u = $u->fetch();
    if (!$u) return;
    $kind = in_array($u['role_slug'], ['client', 'agency', 'freelancer'], true) ? $u['role_slug'] : 'staff';
    $db->prepare("INSERT INTO mail_subscribers (email, name, kind, user_id, contact_id, consent, status, token, source, created_at, unsubscribed_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'profile', NOW(), ?)
                  ON DUPLICATE KEY UPDATE status = VALUES(status), consent = IF(VALUES(status) = 'subscribed', 1, consent), unsubscribed_at = VALUES(unsubscribed_at)")
       ->execute([mb_strtolower($u['email']), $u['full_name'], $kind, $user_id, $u['contact_id'], $newsletter ? 1 : 0, $newsletter ? 'subscribed' : 'unsubscribed', mail_subscriber_token(), $newsletter ? null : date('Y-m-d H:i:s')]);
}
