<?php
/**
 * ====================================================================
 * VERİTABANI & OTURUM YAPILANDIRMASI - ÖRNEK DOSYA
 * ====================================================================
 * Bu dosyayı "config/db.php" adıyla kopyalayıp kendi bilgilerinizle
 * doldurun. config/db.php .gitignore'dadır, GitHub'a yüklenmez.
 */

// Oturum çerezi güvenlik ayarları (session_start'tan ÖNCE olmalı)
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $is_https,   // Sadece HTTPS üzerinden gönderilir
        'httponly' => true,        // JavaScript erişemez
        'samesite' => 'Lax',       // CSRF'e karşı ek koruma
    ]);
    session_start();
}

// Sitenin kök adresi (sonunda / olmadan)
define('BASE_URL', 'https://platform.rymedya.com.tr');

// Üretimde hataları ekrana basma, log dosyasına yaz
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Europe/Istanbul');

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=VERITABANI_ADI;charset=utf8mb4',
        'VERITABANI_KULLANICISI',
        'VERITABANI_SIFRESI',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $db->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    error_log('DB bağlantı hatası: ' . $e->getMessage());
    http_response_code(500);
    die('Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyiniz.');
}
