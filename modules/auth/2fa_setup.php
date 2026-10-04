<?php
/**
 * ====================================================================
 * İKİ ADIMLI DOĞRULAMA - KURULUM / YÖNETİM
 * ====================================================================
 * Personel ve portal kullanıcıları kendi hesapları için açar; kurtarma
 * kodları bir kez gösterilir. Yönetici "Zorunlu" yaptıysa kapatılamaz.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (is_logged_in()) {
    require_staff_login();
    $area = 'staff';
    $uid = (int)$_SESSION['user_id'];
    $back = BASE_URL . '/modules/dashboard/index.php';
} elseif (!empty($_SESSION['client_user_id'])) {
    require_client_login(['client', 'agency', 'freelancer']);
    $area = 'portal';
    $uid = (int)$_SESSION['client_user_id'];
    $back = portal_home_url($_SESSION['client_user']['role'] ?? 'client');
} else {
    redirect(BASE_URL . '/modules/auth/login.php');
}
$self = BASE_URL . '/modules/auth/2fa_setup.php';
$mode = twofa_mode($area);
$st = $db->prepare("SELECT id, email, full_name, totp_enabled, totp_enabled_at, totp_recovery FROM users WHERE id = ?");
$st->execute([$uid]);
$me = $st->fetch();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode !== 'off') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $code = (string)($_POST['code'] ?? '');
    if ($action === 'confirm' && (int)$me['totp_enabled'] !== 1) {
        $secret = $_SESSION['2fa_setup_secret'] ?? '';
        if ($secret !== '' && totp_match($secret, $code) !== null) {
            $_SESSION['2fa_new_codes'] = twofa_enable($uid, $secret);
            unset($_SESSION['2fa_setup_secret']);
            log_activity('security', 'İki adımlı doğrulama açıldı', 'user', $uid, null, $uid);
            redirect($self);
        }
        $error = 'Kod doğrulanamadı. Uygulamadaki güncel 6 haneli kodu girin (telefonunuzun saati doğru olmalı).';
    }
    if ($action === 'disable' && (int)$me['totp_enabled'] === 1) {
        if ($mode === 'required') {
            $error = 'İki adımlı doğrulama yönetici tarafından zorunlu tutuluyor; kapatılamaz.';
        } elseif (twofa_verify_user($uid, $code)) {
            twofa_disable($uid);
            log_activity('security', 'İki adımlı doğrulama kapatıldı', 'user', $uid, null, $uid);
            set_flash('success', 'İki adımlı doğrulama kapatıldı.');
            redirect($self);
        } else {
            $error = 'Kod hatalı.';
        }
    }
    if ($action === 'regen' && (int)$me['totp_enabled'] === 1) {
        if (twofa_verify_user($uid, $code)) {
            $codes = totp_recovery_codes();
            $db->prepare("UPDATE users SET totp_recovery = ? WHERE id = ?")->execute([json_encode(array_map(fn($c) => hash('sha256', str_replace('-', '', $c)), $codes)), $uid]);
            $_SESSION['2fa_new_codes'] = $codes;
            redirect($self);
        }
        $error = 'Kod hatalı.';
    }
}

$new_codes = $_SESSION['2fa_new_codes'] ?? null;
unset($_SESSION['2fa_new_codes']);
if ((int)$me['totp_enabled'] !== 1 && $mode !== 'off') {
    $_SESSION['2fa_setup_secret'] ??= totp_new_secret();
}
$secret = $_SESSION['2fa_setup_secret'] ?? '';
$left = count(json_decode((string)$me['totp_recovery'], true) ?: []);

auth_page_open('İki adımlı doğrulama', $area === 'portal' ? 'login_portal' : 'login_staff');
?>
<h1 class="h1">İki adımlı doğrulama</h1>
<?= display_flash() ?>
<?php if ($error): ?><div class="alert alert-danger" style="margin-top:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div><?php endif; ?>

<?php if ($mode === 'off'): ?>
    <p class="small text-muted" style="margin-top:10px">İki adımlı doğrulama yönetici tarafından kapatılmış.</p>

<?php elseif ($new_codes): ?>
    <div class="alert alert-success" style="margin-top:16px"><i data-lucide="shield-check"></i><div>İki adımlı doğrulama açık. Bundan sonra girişte uygulamadaki kod istenecek.</div></div>
    <p class="small" style="margin-top:16px;font-weight:500">Kurtarma kodlarınız</p>
    <p class="xsmall text-muted">Telefonunuzu kaybederseniz bu kodlardan biriyle giriş yapabilirsiniz; her kod bir kez kullanılır. <strong>Şimdi kaydedin, bir daha gösterilmeyecek.</strong></p>
    <div class="panel" style="padding:14px;margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:6px;font-family:var(--font-mono,monospace);font-size:15px">
        <?php foreach ($new_codes as $c): ?><span><?= e($c) ?></span><?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
        <button type="button" class="btn btn-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText(<?= e(json_encode(implode("\n", $new_codes))) ?>); this.textContent='Kopyalandı'"><i data-lucide="copy"></i>Kopyala</button>
        <a href="<?= e($back) ?>" class="btn btn-primary">Kaydettim, devam et</a>
    </div>

<?php elseif ((int)$me['totp_enabled'] === 1): ?>
    <div class="alert alert-success" style="margin-top:16px"><i data-lucide="shield-check"></i><div>Açık<?= $me['totp_enabled_at'] ? ' · ' . format_date($me['totp_enabled_at'], true) . ' tarihinden beri' : '' ?>. Kalan kurtarma kodu: <strong><?= $left ?></strong>.</div></div>
    <form method="POST" action="" class="stack" style="margin-top:18px"><?= csrf_field() ?>
        <div class="field"><label class="label">Uygulamadaki güncel kod</label><input class="input" name="code" required inputmode="numeric" autocomplete="one-time-code" placeholder="123456"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-secondary" name="action" value="regen">Yeni kurtarma kodları üret</button>
            <?php if ($mode !== 'required'): ?><button class="btn btn-ghost" name="action" value="disable" onclick="return confirm('İki adımlı doğrulama kapatılsın mı?');">Kapat</button><?php endif; ?>
        </div>
    </form>
    <p class="xsmall" style="margin-top:16px"><a class="link" href="<?= e($back) ?>">Geri dön</a></p>

<?php else: ?>
    <p class="small text-muted" style="margin-top:8px"><?= $mode === 'required' ? 'Hesabınız için iki adımlı doğrulama zorunlu. ' : '' ?>Girişte şifrenize ek olarak telefonunuzdaki uygulamanın ürettiği kod istenir.</p>
    <ol class="small stack-sm" style="margin-top:16px;padding-left:18px;list-style:decimal">
        <li>Telefonunuza <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong> veya benzeri bir uygulama kurun.</li>
        <li>Uygulamada "QR kodu tara" deyip aşağıdaki kodu okutun (veya anahtarı elle girin).</li>
        <li>Uygulamanın gösterdiği 6 haneli kodu yazıp onaylayın.</li>
    </ol>
    <div style="display:flex;gap:16px;align-items:center;margin-top:16px;flex-wrap:wrap">
        <div id="qr" style="background:#fff;padding:8px;border:1px solid var(--line);border-radius:8px;width:176px;height:176px"></div>
        <div style="min-width:0;flex:1">
            <p class="xsmall text-muted">Elle giriş anahtarı</p>
            <p class="small" style="font-family:var(--font-mono,monospace);word-break:break-all;font-weight:500"><?= e(trim(chunk_split($secret, 4, ' '))) ?></p>
            <p class="xsmall text-muted" style="margin-top:4px">Hesap: <?= e($me['email']) ?></p>
        </div>
    </div>
    <form method="POST" action="" class="stack" style="margin-top:18px"><?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm">
        <div class="field"><label class="label">Uygulamadaki 6 haneli kod</label><input class="input" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" placeholder="123456" style="font-size:18px;letter-spacing:.15em"></div>
        <button class="btn btn-primary btn-block">Onayla ve aç</button>
    </form>
    <?php if ($mode !== 'required'): ?><p class="xsmall" style="margin-top:14px;text-align:center"><a class="link" href="<?= e($back) ?>">Şimdi değil</a></p><?php endif; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>try { new QRCode(document.getElementById('qr'), { text: <?= json_encode(totp_uri($secret, $me['email'])) ?>, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M }); } catch (e) { document.getElementById('qr').innerHTML = '<p class="xsmall text-muted" style="padding:8px">QR yüklenemedi; anahtarı elle girin.</p>'; }</script>
<?php endif; ?>
<?php auth_page_close();
