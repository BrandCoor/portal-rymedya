<?php
/**
 * ====================================================================
 * CANLI YOKLAMA (JSON)
 * ====================================================================
 * GET  ?since=<son bildirim id>&job=<iş id>  → yeni bildirimler, okunmamış sayısı, iş imzası
 * POST action=seen&up_to=<id>                → bildirimleri okundu say
 * Personel ve portal (ajans / freelancer / müşteri) oturumlarıyla çalışır.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = fn(array $d, int $code = 200) => (function () use ($d, $code) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
})();

if (is_logged_in()) {
    $area = 'staff';
    $uid = (int)$_SESSION['user_id'];
    $st = $db->prepare("SELECT status FROM users WHERE id = ?");
    $st->execute([$uid]);
    if ($st->fetchColumn() !== 'active') $out(['auth' => false], 401);
    $cid = 0;
} elseif (!empty($_SESSION['client_user_id'])) {
    $uid = (int)$_SESSION['client_user_id'];
    $cid = (int)($_SESSION['client_contact_id'] ?? 0);
    $st = $db->prepare("SELECT status FROM users WHERE id = ?");
    $st->execute([$uid]);
    if ($st->fetchColumn() !== 'active') $out(['auth' => false], 401);
    $area = portal_role();
} else {
    $out(['auth' => false], 401);
}

// Yoklama oturumu canlı tutmaz: hareketsizlik süresi dolduysa sayfa yenilenip çıkış yapılır
$idle = (int)site_setting($area === 'staff' ? 'security_idle_staff' : 'security_idle_portal');
$last = (int)($_SESSION[$area === 'staff' ? 'last_activity' : 'client_last_activity'] ?? time());
if ($idle > 0 && time() - $last > $idle * 60) $out(['auth' => false, 'expired' => true], 401);
session_write_close();   // diğer sekmelerin isteklerini bekletme

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) $out(['ok' => false], 403);
    if (($_POST['action'] ?? '') === 'seen') {
        mark_notifications_read($uid, (int)($_POST['up_to'] ?? 0) ?: null);
    }
    $out(['ok' => true]);
}

$since = max(0, (int)($_GET['since'] ?? 0));
$notif = $area === 'staff' ? get_notifications($uid, 15) : portal_notifications($uid, 15);
$fallback = $area === 'staff' ? '/modules/notifications/index.php' : ($area === 'client' ? '/client/index.php' : '/platform/notifications.php');
$new = array_values(array_filter($notif['items'], fn($n) => (int)$n['id'] > $since));
$res = ['auth' => true, 'unread' => (int)$notif['unread'], 'items' => array_map(fn($n) => live_item($n, $fallback, $area === 'staff'), array_reverse($new))];

$job = (int)($_GET['job'] ?? 0);
if ($job > 0) {
    $res['sig'] = live_job_sig($job, $area, $uid, $cid);
}
$out($res);
