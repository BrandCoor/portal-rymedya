<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - ANA GİRİŞ & YÖNLENDİRME
 * ====================================================================
 */

// Henüz kurulmamışsa kurulum sihirbazına yönlendir
if (!is_file(__DIR__ . '/config/db.php')) {
    header('Location: ' . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/install.php');
    exit;
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect(BASE_URL . '/modules/dashboard/index.php');
} else {
    redirect(BASE_URL . '/modules/auth/login.php');
}