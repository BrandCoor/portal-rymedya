<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - PROFİL (AJANS & FREELANCER)
 * ====================================================================
 * Seviye (tier) ve onay durumu yalnızca platform yöneticisi tarafından değiştirilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

require_client_login(['agency', 'freelancer']);
$role    = portal_role();
$profile = require_platform_role($role, false);
$uid     = (int)$_SESSION['client_user_id'];
$cid     = (int)$_SESSION['client_contact_id'];

$c = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$c->execute([$cid]);
$contact = $c->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if (legal_profile_post($uid, $_SESSION['client_user']['email'] ?? null)) {
        redirect(BASE_URL . '/platform/profile.php');
    }

    if ($action === 'email_prefs') {
        mail_save_user_prefs($uid, isset($_POST['notify_email']), isset($_POST['newsletter']));
        set_flash('success', 'E-posta tercihleriniz kaydedildi.');
        redirect(BASE_URL . '/platform/profile.php');
    }

    if ($action === 'save_profile') {
        $perrors = profile_save($role, $uid, $cid, $_POST);
        if ($perrors) {
            $_SESSION['profile_old'] = $_POST;
            set_flash('error', implode(' ', $perrors));
        } else {
            log_activity('profile', 'Profil güncellendi', $role, $uid, null, null);
            set_flash('success', 'Profiliniz güncellendi.');
        }
        redirect(BASE_URL . '/platform/profile.php');
    }

    if ($action === 'change_password') {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $hs = $db->prepare("SELECT password FROM users WHERE id = ?");
        $hs->execute([$uid]);
        $hash = (string)$hs->fetchColumn();
        if (!password_verify($cur, $hash)) {
            set_flash('error', 'Mevcut şifreniz hatalı.');
        } elseif (strlen($new) < 8) {
            set_flash('error', 'Yeni şifre en az 8 karakter olmalıdır.');
        } else {
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            set_flash('success', 'Şifreniz değiştirildi.');
        }
        redirect(BASE_URL . '/platform/profile.php');
    }
}

$skills = array_filter(explode(',', (string)($profile['skills'] ?? '')));
$pvals = profile_values($role, $uid);
if (!empty($_SESSION['profile_old'])) {   // hatalı gönderimde yazılanlar kaybolmasın
    foreach ($_SESSION['profile_old'] as $k => $v) if (array_key_exists($k, $pvals)) $pvals[$k] = is_array($v) ? implode(',', $v) : $v;
    unset($_SESSION['profile_old']);
}
[$pct, $missing] = profile_completion($role, $pvals);
$status_badge = ['pending' => ['İnceleniyor', 'warning'], 'approved' => ['Onaylı hesap', 'success'], 'suspended' => ['Askıda', 'danger']][$profile['status']] ?? [$profile['status'], 'neutral'];
$display_name = $role === 'agency' ? ($contact['company_title'] ?? '') : ($_SESSION['client_user']['full_name'] ?? '');

platform_header('Hesap', $role === 'agency' ? 'profile' : '');
?>
<div style="max-width:880px;margin:0 auto">
    <div class="page-head">
        <div style="display:flex;gap:14px;align-items:center">
            <?= ui_avatar($display_name, 'lg') ?>
            <div>
                <h1 class="h1"><?= e($display_name) ?></h1>
                <p class="sub" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <?= ui_badge($status_badge[0], $status_badge[1], true) ?>
                    <?php if ($role === 'freelancer'): ?><?= tier_badge($profile['tier']) ?><?php endif; ?>
                    <span><?= e($contact['email'] ?? '') ?></span>
                </p>
            </div>
        </div>
        <?php if ($role === 'freelancer'): ?><a href="<?= BASE_URL ?>/platform/performance.php" class="btn btn-secondary"><i data-lucide="gauge"></i>Performans karnesi</a><?php endif; ?>
    </div>

    <form method="POST" action="" class="stack-lg">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_profile">

        <?php if ($pct < 100): ?>
        <div class="card card-pad" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:220px">
                <p style="font-weight:500">Profiliniz %<?= $pct ?> tamamlandı</p>
                <p class="xsmall text-muted" style="margin-top:2px">Eksik: <?= e(implode(', ', array_slice($missing, 0, 6))) ?><?= count($missing) > 6 ? ' ve ' . (count($missing) - 6) . ' alan daha' : '' ?>. <?= $role === 'freelancer' ? 'Eksiksiz profiller atamada öne çıkar.' : 'Eksiksiz bilgiler fatura ve iletişimi hızlandırır.' ?></p>
            </div>
            <div class="pbar" style="width:180px"><span style="width:<?= $pct ?>%"></span></div>
        </div>
        <?php endif; ?>

        <?= profile_form_html($role, $pvals) ?>

        <div class="sticky-save"><button class="btn btn-primary">Değişiklikleri kaydet</button></div>
    </form>

    <?php $prefs = mail_user_prefs($uid); ?>
    <form method="POST" action="" class="card" style="margin-top:24px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email_prefs">
        <div class="card-head"><div><p class="card-title">E-posta tercihleri</p><p class="card-sub"><?= e($contact['email'] ?? '') ?></p></div></div>
        <div class="divide">
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                <div><p class="small" style="font-weight:500">İş bildirimleri</p><p class="xsmall text-muted"><?= $role === 'agency' ? 'İş onayı, teslimat, revizyon ve fatura gibi gelişmeler.' : 'Atama, teklif sonucu, kalite kontrol, ödeme ve seviye değişiklikleri.' ?></p></div>
                <label class="switch"><input type="checkbox" name="notify_email" value="1" <?= $prefs['notify'] ? 'checked' : '' ?>><span></span></label>
            </div>
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                <div><p class="small" style="font-weight:500">Duyuru ve kampanyalar</p><p class="xsmall text-muted">Yeni hizmetler, fırsatlar ve platform duyuruları.</p></div>
                <label class="switch"><input type="checkbox" name="newsletter" value="1" <?= $prefs['newsletter'] ? 'checked' : '' ?>><span></span></label>
            </div>
        </div>
        <div class="card-foot" style="display:flex;justify-content:flex-end"><button class="btn btn-secondary">Tercihleri kaydet</button></div>
    </form>

    <?= legal_profile_card($uid, $role) ?>

    <form method="POST" action="" class="card" style="margin-top:24px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="card-head"><p class="card-title">Şifre</p></div>
        <div class="card-pad grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="field"><label class="label">Mevcut şifre</label><input class="input" type="password" name="current_password" required autocomplete="current-password"></div>
            <div class="field"><label class="label">Yeni şifre</label><input class="input" type="password" name="new_password" required minlength="8" autocomplete="new-password"><span class="hint">En az 8 karakter.</span></div>
        </div>
        <div class="card-foot" style="display:flex;justify-content:flex-end"><button class="btn btn-secondary">Şifreyi güncelle</button></div>
    </form>
</div>
<?php platform_footer();
