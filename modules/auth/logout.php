<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GÜVENLİ ÇIKIŞ İŞLEMİ (LOGOUT)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// Sadece yönetim paneli oturumu kapatılır (aynı tarayıcıdaki müşteri portalı oturumu korunur)
unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['user_permissions'], $_SESSION['csrf_token']);
session_regenerate_id(true);

set_flash('info', 'Başarıyla çıkış yaptınız.');
redirect(BASE_URL . '/modules/auth/login.php');