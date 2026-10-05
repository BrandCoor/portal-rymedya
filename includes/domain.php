<?php
/**
 * ====================================================================
 * ALAN ADI TAŞIMA DESTEĞİ
 * ====================================================================
 * Sistemin adresi config/db.php içindeki BASE_URL'dir. Adres değiştiğinde:
 *  1) Eski adrese gelen her istek kalıcı (301) olarak aynı yolla yeni
 *     adrese yönlendirilir (eski e-posta bağlantıları, yer imleri çalışır).
 *     Ödeme (iyzico) dönüşü ve arka plan istekleri yönlendirilmez; hangi
 *     adresten gelirse orada işlenir.
 *  2) Veritabanında kayıtlı eski tam adresler (ayarlar, yasal metinler,
 *     e-posta kampanyaları, bekleyen e-postalar) bir kez yeni adresle
 *     değiştirilir.
 */

/** Tarayıcıdan gelen isteği BASE_URL'nin alan adına yönlendirir */
function domain_canonical_redirect(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    $want = strtolower((string)parse_url(BASE_URL, PHP_URL_HOST));
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($want === '' || $host === '' || $host === $want) return;
    // Yerel geliştirme ve IP ile erişim yönlendirilmez
    if (in_array($host, ['localhost', '127.0.0.1'], true) || filter_var($host, FILTER_VALIDATE_IP)) return;
    // Yalnızca aynı ana alan adının alt alan adlarından gelenler (ör. portal.* → platform.*)
    if (!str_ends_with($host, '.' . implode('.', array_slice(explode('.', $want), 1)))) return;
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    // Ödeme dönüşü ve arka plan istekleri olduğu yerde işlenir (POST gövdesi kaybolmasın)
    if (str_contains($script, '/ajax/') || str_ends_with($script, 'iyzico_callback.php') || str_contains($script, '/cron/')) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') return;
    $base_path = rtrim((string)parse_url(BASE_URL, PHP_URL_PATH), '/');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . rtrim(BASE_URL, '/') . ($base_path !== '' && str_starts_with($uri, $base_path) ? substr($uri, strlen($base_path)) : $uri), true, 301);
    exit;
}

/**
 * BASE_URL değiştiyse veritabanındaki eski tam adresleri yenisiyle değiştirir.
 * Her istekte yalnızca bir ayar karşılaştırması yapar.
 */
function domain_sync_stored_urls(): void {
    global $db;
    $new = rtrim(BASE_URL, '/');
    $old = rtrim((string)get_setting('base_url_last', ''), '/');
    if ($old === $new) return;
    // İlk kurulumdaki adres (bu özellik eklenmeden önce sistem bu adreste çalışıyordu)
    if ($old === '') $old = 'https://portal.rymedya.com.tr';
    try {
        $is_local = fn(string $u) => (bool)preg_match('#^https?://(localhost|127\.0\.0\.1)#i', $u);
        if ($old !== '' && !$is_local($old) && !$is_local($new)) {
            $pairs = [[$old, $new]];
            // http / https farkı da güncellensin
            $oh = (string)parse_url($old, PHP_URL_HOST);
            $nh = (string)parse_url($new, PHP_URL_HOST);
            if ($oh !== '' && $nh !== '' && $oh !== $nh) {
                $pairs[] = ['http://' . $oh, $new];
                $pairs[] = ['https://' . $oh, $new];
            }
            $targets = [
                ['system_settings', 'setting_value', "setting_key != 'base_url_last'"],
                ['legal_documents', 'body', '1=1'],
                ['mail_campaigns', 'body_html', '1=1'],
                ['mail_queue', 'body_html', "status IN ('pending','failed')"],
            ];
            foreach ($targets as [$table, $col, $where]) {
                if (!$db->query("SHOW TABLES LIKE '{$table}'")->fetch()) continue;
                foreach ($pairs as [$a, $b]) {
                    $db->prepare("UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, ?, ?) WHERE {$where} AND `{$col}` LIKE ?")
                       ->execute([$a, $b, '%' . $a . '%']);
                }
            }
            log_activity('system', "Sistem adresi değişti: {$old} → {$new}. Kayıtlı bağlantılar güncellendi.");
        }
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('base_url_last', ?, 'system') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$new]);
        get_settings(true);
    } catch (Throwable $e) {
        error_log('Adres güncelleme: ' . $e->getMessage());
    }
}
