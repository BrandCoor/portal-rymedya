<?php
/**
 * ====================================================================
 * ÖDEME DEKONTU GÖRÜNTÜLEME
 * ====================================================================
 * Dekontlar doğrudan erişime kapalı klasörde tutulur; yalnızca bildirimi
 * yapan ajans ve platform yetkisi olan personel görebilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$n = payment_notice_get((int)($_GET['id'] ?? 0));
$staff_ok = is_logged_in() && can_access_module('platform.manage');
$owner_ok = is_client_logged_in() && $n && (int)($_SESSION['client_contact_id'] ?? 0) === (int)$n['contact_id'];
if (!$n || !$n['receipt_path'] || (!$staff_ok && !$owner_ok) || !preg_match('/^dekont-[0-9]{14}-[a-f0-9]{12}\.(pdf|jpg|png|webp)$/', $n['receipt_path'])) {
    http_response_code(404);
    exit('Dekont bulunamadı.');
}
$file = RECEIPT_DIR . $n['receipt_path'];
if (!is_file($file)) {
    http_response_code(404);
    exit('Dekont bulunamadı.');
}
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext = pathinfo($file, PATHINFO_EXTENSION);
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: inline; filename="dekont-' . (int)$n['id'] . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
readfile($file);
