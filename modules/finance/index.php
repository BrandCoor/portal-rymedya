<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - ESKİ VERGİ PANELİ (YÖNLENDİRME)
 * ====================================================================
 * Bu sayfa "Otomatik Vergi Motoru" (modules/taxes/index.php) ile birebir
 * aynı hesaplamaları içeren eski bir kopyaydı. Tek bir doğru kaynak olması
 * için vergi paneline yönlendirilir; eski bağlantılar çalışmaya devam eder.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
redirect(BASE_URL . '/modules/taxes/index.php' . ($query !== '' ? '?' . $query : ''));
