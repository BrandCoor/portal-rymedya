<?php
require_once __DIR__ . '/config/db.php';

$yeni_sifre = 'Admin123!';
$yeni_hash = password_hash($yeni_sifre, PASSWORD_DEFAULT);

$stmt = $db->prepare("UPDATE users SET password = ? WHERE email = 'admin@ajansadresi.com'");
$sonuc = $stmt->execute([$yeni_hash]);

if ($sonuc) {
    echo "<h2 style='color:green;font-family:sans-serif;'>✅ Şifre sunucu üzerinde başarıyla üretildi ve güncellendi!</h2>";
    echo "<p style='font-family:sans-serif;'>E-Posta: <b>admin@ajansadresi.com</b><br>Şifre: <b>Admin123!</b></p>";
    echo "<a href='index.php' style='display:inline-block;padding:10px 20px;background:#7c3aed;color:white;text-decoration:none;border-radius:8px;font-family:sans-serif;'>Giriş Sayfasına Git</a>";
} else {
    echo "<h2 style='color:red;'>Bir hata oluştu!</h2>";
}