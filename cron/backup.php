<?php
/**
 * ====================================================================
 * GÜNLÜK VERİTABANI YEDEĞİ - ZAMANLANMIŞ GÖREV
 * ====================================================================
 * cPanel → Cron Jobs: günde bir (ör. 03:15)
 *   php /home/KULLANICI/public_html/cron/backup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Yalnızca komut satırından çalıştırılabilir.');
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

[$name, $err] = backup_run();
if ($err) {
    fwrite(STDERR, $err . PHP_EOL);
    exit(1);
}
echo date('Y-m-d H:i:s') . " yedek alındı: {$name} → " . backup_dir() . PHP_EOL;
