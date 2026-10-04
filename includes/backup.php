<?php
/**
 * ====================================================================
 * OTOMATİK VERİTABANI YEDEĞİ
 * ====================================================================
 * Tüm tabloların yapısı ve verisi sıkıştırılmış SQL (.sql.gz) olarak alınır.
 * mysqldump gerektirmez (paylaşımlı hostingde exec kapalı olabilir); PDO ile yazar.
 * Konum: Ayarlar → Güvenlik'te belirtilen klasör; boşsa web kökünün bir üstündeki
 * "rymedya-yedek" klasörü (web'den erişilemez). O da yazılamıyorsa proje
 * içindeki "storage/backups" kullanılır ve .htaccess ile dışarıya kapatılır.
 * cron/backup.php günde bir; cron yoksa personel paneli açıldıkça günde bir çalışır.
 */

function backup_dir(): string {
    $custom = trim((string)site_setting('backup_path'));
    $candidates = array_filter([
        $custom,
        dirname(__DIR__, 2) . '/rymedya-yedek',
        dirname(__DIR__) . '/storage/backups',
    ]);
    foreach ($candidates as $dir) {
        $dir = rtrim($dir, '/');
        if ((is_dir($dir) || @mkdir($dir, 0750, true)) && is_writable($dir)) {
            if (str_starts_with(realpath($dir) ?: $dir, realpath(dirname(__DIR__)) ?: dirname(__DIR__))) {
                // Proje (web) klasörü içindeyse dışarıya kapat
                if (!file_exists($dir . '/.htaccess')) {
                    @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
                }
                if (!file_exists($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
            }
            return $dir;
        }
    }
    return '';
}

/** Yedek dosyaları (yeniden eskiye) */
function backup_list(): array {
    $dir = backup_dir();
    if ($dir === '') return [];
    $files = glob($dir . '/db-*.sql.gz') ?: [];
    rsort($files);
    return array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
}

/**
 * Yedek alır. Döner: [dosya adı, hata]
 */
function backup_run(): array {
    global $db;
    $dir = backup_dir();
    if ($dir === '') return [null, 'Yedek klasörü oluşturulamadı veya yazılamıyor. Ayarlar → Güvenlik bölümünden yazılabilir bir klasör yolu girin.'];
    @set_time_limit(300);
    $name = 'db-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql.gz';
    $path = $dir . '/' . $name;
    $gz = @gzopen($path, 'wb6');
    if (!$gz) return [null, 'Yedek dosyası yazılamadı: ' . $dir];
    try {
        gzwrite($gz, "-- RY Medya veritabanı yedeği\n-- " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            $st = $db->query("SELECT * FROM `{$table}`");
            $batch = [];
            $cols = null;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    } catch (Throwable $e) {
        @gzclose($gz);
        @unlink($path);
        return [null, 'Yedek alınamadı: ' . $e->getMessage()];
    }
    @chmod($path, 0640);
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('backup_last_run', ?, 'security') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)time()]);
    backup_prune();
    return [$name, null];
}

/** Saklama süresinden eski yedekleri siler (en son yedek her zaman kalır) */
function backup_prune(): int {
    $keep = max(1, (int)site_setting('backup_keep_days'));
    $files = backup_list();
    $removed = 0;
    foreach (array_slice($files, 1) as $f) {
        if ($f['time'] < time() - $keep * 86400) {
            @unlink(backup_dir() . '/' . $f['name']);
            $removed++;
        }
    }
    return $removed;
}

/** Adı doğrulanmış yedeğin tam yolu (indirme / silme için) */
function backup_file_path(string $name): ?string {
    if (!preg_match('/^db-\d{8}-\d{6}-[a-f0-9]{6}\.sql\.gz$/', $name)) return null;
    $p = backup_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}

/** Günlük yedek zamanı geldi mi? */
function backup_due(): bool {
    return site_setting('backup_enabled') === '1' && (int)get_setting('backup_last_run', '0') < time() - 86400 + 600;
}
