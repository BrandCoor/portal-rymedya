<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Sadece müşteri portalı oturumu kapatılır (yönetim paneli oturumu etkilenmez)
unset($_SESSION['client_user_id'], $_SESSION['client_contact_id'], $_SESSION['client_user']);
session_regenerate_id(true);

set_flash('info', 'Portal oturumunuz güvenle kapatıldı.');
redirect(BASE_URL . '/client/login.php');