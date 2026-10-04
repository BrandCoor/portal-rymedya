<?php
/**
 * ====================================================================
 * ÇEREZ ONAYI KAYDI
 * ====================================================================
 * Ziyaretçinin çerez tercihini (onay kimliği, sürüm, kategoriler, IP,
 * tarayıcı, tarih) saklar. Giriş gerektirmez; yalnızca POST.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
$id = (string)($_POST['id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    http_response_code(400);
    exit('{"ok":false}');
}
// Aynı IP'den aşırı kayıt engeli (dakikada 20)
$ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$n = $db->prepare("SELECT COUNT(*) FROM cookie_consents WHERE ip = ? AND created_at > NOW() - INTERVAL 1 MINUTE");
$n->execute([$ip]);
if ((int)$n->fetchColumn() >= 20) {
    http_response_code(429);
    exit('{"ok":false}');
}
$uid = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (!empty($_SESSION['client_user_id']) ? (int)$_SESSION['client_user_id'] : null);
session_write_close();
$db->prepare("INSERT INTO cookie_consents (consent_id, version, analytics, marketing, user_id, ip, user_agent, page, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
   ->execute([$id, max(1, (int)($_POST['v'] ?? 1)), !empty($_POST['analytics']) ? 1 : 0, !empty($_POST['marketing']) ? 1 : 0, $uid, $ip,
              mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), mb_substr((string)($_POST['page'] ?? ''), 0, 255)]);
echo '{"ok":true}';
