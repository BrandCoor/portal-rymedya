<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GÜVENLİ ÇIKIŞ İŞLEMİ (LOGOUT)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

session_start();
set_flash('info', 'Başarıyla çıkış yaptınız.');
redirect(BASE_URL . '/modules/auth/login.php');