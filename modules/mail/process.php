<?php
/**
 * ====================================================================
 * E-POSTA KUYRUĞU - PARTİ İŞLEME (AJAX)
 * ====================================================================
 * Kampanya sayfası açıkken tarayıcı bu adresi tekrar tekrar çağırır;
 * her çağrıda ayardaki parti büyüklüğü kadar e-posta gönderilir.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || !can_access_module('mail.manage')) {
    http_response_code(403);
    echo json_encode(['error' => 'Yetkiniz yok.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['error' => 'Geçersiz istek.']);
    exit;
}
@set_time_limit(120);
$campaign = (int)($_POST['campaign_id'] ?? 0) ?: null;
$res = mail_process_queue(max(1, (int)site_setting('mail_batch_size')), null, $campaign);
if ($campaign) {
    $c = $db->prepare("SELECT status, total, sent, failed FROM mail_campaigns WHERE id = ?");
    $c->execute([$campaign]);
    $res['campaign'] = $c->fetch();
}
echo json_encode($res, JSON_UNESCAPED_UNICODE);
