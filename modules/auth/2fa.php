<?php
/**
 * ====================================================================
 * İKİ ADIMLI DOĞRULAMA - KOD EKRANI
 * ====================================================================
 * Şifre doğru girildikten sonra doğrulama uygulamasındaki 6 haneli kod
 * (veya bir kurtarma kodu) istenir. Personel ve portal girişleri ortaktır.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

$p = $_SESSION['2fa_pending'] ?? null;
$login_url = BASE_URL . (($p['area'] ?? 'staff') === 'portal' ? '/client/login.php' : '/modules/auth/login.php');
if (!$p || time() - (int)$p['at'] > 600) {
    unset($_SESSION['2fa_pending']);
    set_flash('error', 'Doğrulama süresi doldu. Lütfen yeniden giriş yapın.');
    redirect($login_url);
}
$error = '';
$key = '2fa:' . (int)$p['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (is_login_locked($key)) {
        $error = 'Çok fazla hatalı deneme. ' . LOGIN_LOCKOUT_MINUTES . ' dakika sonra tekrar deneyin.';
    } elseif (twofa_verify_user((int)$p['user_id'], (string)($_POST['code'] ?? ''))) {
        clear_login_failures($key);
        if ($p['area'] === 'staff') {
            $st = $db->prepare("SELECT u.*, r.role_name, r.role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'active'");
            $st->execute([(int)$p['user_id']]);
            $u = $st->fetch();
            if (!$u) redirect($login_url);
            staff_session_start($u, true);
            set_flash('success', 'Hoş geldiniz, Sn. ' . $u['full_name']);
            redirect(BASE_URL . '/modules/dashboard/index.php');
        }
        $st = $db->prepare("SELECT u.*, r.role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'active'");
        $st->execute([(int)$p['user_id']]);
        $u = $st->fetch();
        if (!$u) redirect($login_url);
        $u['client_name'] = $p['extra']['client_name'] ?? null;
        portal_session_start($u, (int)($p['extra']['contact_id'] ?? 0), true);
        set_flash('success', 'Hoş geldiniz, ' . $u['full_name']);
        redirect(portal_home_url($_SESSION['client_user']['role']));
    } else {
        record_login_failure($key);
        security_log_login((int)$p['user_id'], (string)$p['email'], (string)$p['area'], false, 'hatalı doğrulama kodu');
        $error = 'Kod hatalı veya süresi geçmiş. Uygulamadaki güncel kodu girin.';
    }
}

auth_page_open('İki adımlı doğrulama', $p['area'] === 'portal' ? 'login_portal' : 'login_staff');
?>
<h1 class="h1">İki adımlı doğrulama</h1>
<p class="small text-muted" style="margin-top:6px">Doğrulama uygulamanızdaki (Google Authenticator, Microsoft Authenticator vb.) 6 haneli kodu girin.</p>
<div style="margin-top:24px">
    <?php if ($error): ?><div class="alert alert-danger" style="margin-bottom:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="POST" action="" class="stack"><?= csrf_field() ?>
        <div class="field">
            <label class="label" for="code">Doğrulama kodu</label>
            <input class="input" id="code" name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" placeholder="123456" style="font-size:20px;letter-spacing:.2em;text-align:center">
            <span class="hint">Telefonunuza erişemiyorsanız kurtarma kodlarınızdan birini (ör. a1b2-c3d4) yazabilirsiniz.</span>
        </div>
        <button class="btn btn-primary btn-lg btn-block">Doğrula ve giriş yap</button>
    </form>
    <p class="xsmall" style="margin-top:14px;text-align:center"><a class="link" href="<?= e($login_url) ?>">Vazgeç, girişe dön</a></p>
</div>
<?php auth_page_close();
