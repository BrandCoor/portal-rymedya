<?php
/**
 * ====================================================================
 * E-POSTA KUYRUĞU - ZAMANLANMIŞ GÖREV
 * ====================================================================
 * cPanel → Cron Jobs: her 5 dakikada bir
 *   php /home/KULLANICI/public_html/cron/mail-queue.php
 * Toplu kampanyaları ve gönderilemeyen bildirimleri arka planda gönderir.
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

$batch = max(1, (int)site_setting('mail_batch_size'));
$rounds = max(1, (int)($argv[1] ?? 5));   // tek çalıştırmada en fazla parti sayısı
$total = ['sent' => 0, 'failed' => 0];
for ($i = 0; $i < $rounds; $i++) {
    $r = mail_process_queue($batch);
    if (isset($r['error'])) {
        fwrite(STDERR, $r['error'] . PHP_EOL);
        exit(1);
    }
    $total['sent'] += $r['sent'];
    $total['failed'] += $r['failed'];
    if ($r['remaining'] === 0 || ($r['sent'] + $r['failed']) === 0) break;
    sleep(2);
}
echo date('Y-m-d H:i:s') . " gönderilen: {$total['sent']}, hatalı: {$total['failed']}" . PHP_EOL;
