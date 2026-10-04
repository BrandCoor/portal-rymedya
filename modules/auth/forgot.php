<?php
/**
 * ====================================================================
 * ŞİFREMİ UNUTTUM
 * ====================================================================
 * E-posta adresine tek kullanımlık, süreli sıfırlama bağlantısı gönderilir.
 * Hesabın var olup olmadığı dışarıya belli edilmez; IP başına hız sınırı vardır.
 * ?for=portal → ajans / freelancer / müşteri hesapları
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

$area = ($_GET['for'] ?? $_POST['for'] ?? '') === 'portal' ? 'portal' : 'staff';
$login_url = BASE_URL . ($area === 'portal' ? '/client/login.php' : '/modules/auth/login.php');
$sent = false;
$error = '';
$rl = 'reset:' . client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    if (is_login_locked($rl)) {
        $error = 'Çok fazla talep gönderildi. Lütfen ' . LOGIN_LOCKOUT_MINUTES . ' dakika sonra tekrar deneyin.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir e-posta adresi girin.';
    } else {
        record_login_failure($rl);   // hız sınırı sayacı
        password_reset_request($email, $area);
        $sent = true;
    }
}

auth_page_open('Şifremi unuttum', $area === 'portal' ? 'login_portal' : 'login_staff');
?>
<h1 class="h1">Şifremi unuttum</h1>
<?php if ($sent): ?>
    <div class="alert alert-success" style="margin-top:20px"><i data-lucide="mail-check"></i><div>Bu e-posta adresiyle kayıtlı bir hesap varsa şifre sıfırlama bağlantısı gönderildi. Gelen kutunuzu (ve istenmeyen klasörünü) kontrol edin. Bağlantı <?= (int)site_setting('security_reset_minutes') ?> dakika geçerlidir.</div></div>
<?php else: ?>
    <p class="small text-muted" style="margin-top:6px">Hesabınıza kayıtlı e-posta adresini yazın; size yeni şifre belirleme bağlantısı gönderelim.</p>
    <?php if ($error): ?><div class="alert alert-danger" style="margin-top:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="POST" action="" class="stack" style="margin-top:20px"><?= csrf_field() ?>
        <input type="hidden" name="for" value="<?= $area ?>">
        <div class="field"><label class="label" for="email">E-posta</label><input class="input" id="email" type="email" name="email" required autofocus autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></div>
        <button class="btn btn-primary btn-lg btn-block">Sıfırlama bağlantısı gönder</button>
    </form>
<?php endif; ?>
<p class="xsmall" style="margin-top:16px;text-align:center"><a class="link" href="<?= e($login_url) ?>">Girişe dön</a></p>
<?php auth_page_close();
