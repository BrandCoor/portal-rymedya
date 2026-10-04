<?php
/**
 * ====================================================================
 * YENİ ŞİFRE BELİRLEME (sıfırlama bağlantısı)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = password_reset_find($token);
$area = $row && is_portal_account($row) ? 'portal' : 'staff';
$error = '';

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if ($err = password_policy_error($p1)) {
        $error = $err;
    } elseif ($p1 !== $p2) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        password_reset_complete($row, $p1);
        set_flash('success', 'Şifreniz değiştirildi. Yeni şifrenizle giriş yapabilirsiniz.');
        redirect(BASE_URL . ($area === 'portal' ? '/client/login.php' : '/modules/auth/login.php'));
    }
}

auth_page_open('Yeni şifre', $area === 'portal' ? 'login_portal' : 'login_staff');
?>
<h1 class="h1">Yeni şifre belirleyin</h1>
<?php if (!$row): ?>
    <div class="alert alert-danger" style="margin-top:20px"><i data-lucide="link-2-off"></i><div>Bağlantı geçersiz, kullanılmış veya süresi dolmuş. Yeni bir sıfırlama bağlantısı isteyin.</div></div>
    <p class="small" style="margin-top:16px"><a class="link" href="<?= BASE_URL ?>/modules/auth/forgot.php">Şifremi unuttum</a> · <a class="link" href="<?= BASE_URL ?>/client/login.php">Portal girişi</a></p>
<?php else: ?>
    <p class="small text-muted" style="margin-top:6px"><?= e($row['email']) ?> hesabı için yeni şifre. En az 8 karakter; harf ve rakam içermeli.</p>
    <?php if ($error): ?><div class="alert alert-danger" style="margin-top:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="POST" action="" class="stack" style="margin-top:20px"><?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label class="label">Yeni şifre</label><input class="input" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></div>
        <div class="field"><label class="label">Yeni şifre (tekrar)</label><input class="input" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
        <button class="btn btn-primary btn-lg btn-block">Şifremi değiştir</button>
    </form>
<?php endif; ?>
<?php auth_page_close();
