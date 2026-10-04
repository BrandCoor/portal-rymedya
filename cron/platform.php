<?php
/**
 * ====================================================================
 * İŞ PLATFORMU OTOMASYONLARI - ZAMANLANMIŞ GÖREV
 * ====================================================================
 * cPanel → Cron Jobs: saatte bir
 *   php /home/KULLANICI/public_html/cron/platform.php
 * - Ajansın yanıtlamadığı teslimleri ayarlanan gün sonunda otomatik onaylar
 * - Teslim tarihi yarın olan işler için atanan kişiye hatırlatma
 * - Teslim tarihi geçen ve başlangıcı yaklaşıp atanmamış işler için ekibe uyarı
 * Cron tanımlı değilse aynı kontroller personel panelinde saatte bir çalışır.
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

$r = platform_run_automations(true);
echo date('Y-m-d H:i:s') . " otomatik onay: {$r['auto_approved']}, hatırlatma: {$r['reminders']}, geciken: {$r['overdue']}, atama bekleyen: {$r['unassigned']}" . PHP_EOL;
