<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

unset($_SESSION['client_user_id']);
unset($_SESSION['client_contact_id']);
unset($_SESSION['client_user']);

session_destroy();
session_start();

set_flash('info', 'Portal oturumunuz güvenle kapatıldı.');
redirect(BASE_URL . '/client/login.php');