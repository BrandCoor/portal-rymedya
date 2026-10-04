<?php
/**
 * ====================================================================
 * İYZİCO ÖDEME DÖNÜŞÜ (CALLBACK)
 * ====================================================================
 * iyzico ödeme sayfası işlem bitince buraya "token" ile POST eder.
 * Oturuma güvenilmez (farklı siteden gelen POST'ta çerez gönderilmeyebilir):
 * sonuç token ile iyzico'dan sunucu tarafında sorgulanır, kayıt bulunur,
 * tahsilat işlenir ve kullanıcı Ödemeler ekranına yönlendirilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$token = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)($_POST['token'] ?? $_GET['token'] ?? ''));
$cp = $token !== '' ? iyzico_complete($token) : null;
if (!$cp) {
    http_response_code(400);
    redirect(BASE_URL . '/platform/payments.php?card=0');
}
redirect(BASE_URL . '/platform/payments.php?card=' . (int)$cp['id']);
