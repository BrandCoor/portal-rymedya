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

    if ($action === 'save_profile') {
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $city  = trim($_POST['city'] ?? '');
        if ($name === '' || $phone === '') {
            set_flash('error', 'Ad soyad ve telefon zorunludur.');
            redirect(BASE_URL . '/platform/profile.php');
        }
        if ($role === 'agency') {
            $company = trim($_POST['company_title'] ?? '') ?: $contact['company_title'];
            $db->prepare("UPDATE contacts SET company_title = ?, authorized_person = ?, phone = ?, city = ?, address = ?, tax_office = ?, tax_number = ? WHERE id = ?")
               ->execute([$company, $name, $phone, $city, trim($_POST['address'] ?? ''), trim($_POST['tax_office'] ?? ''), trim($_POST['tax_number'] ?? ''), $cid]);
            $db->prepare("UPDATE agency_profiles SET website = ? WHERE user_id = ?")->execute([trim($_POST['website'] ?? ''), $uid]);
            $_SESSION['client_user']['company_name'] = $company;
        } else {
            $skills = array_values(array_intersect(array_keys(JOB_CATEGORIES), (array)($_POST['skills'] ?? [])));
            $portfolio = trim($_POST['portfolio_url'] ?? '');
            if ($portfolio !== '' && !is_safe_url($portfolio)) {
                set_flash('error', 'Portfolyo bağlantısı http(s):// ile başlamalıdır.');
                redirect(BASE_URL . '/platform/profile.php');
            }
            $db->prepare("UPDATE contacts SET company_title = ?, authorized_person = ?, phone = ?, city = ?, iban = ? WHERE id = ?")
               ->execute([$name, $name, $phone, $city, trim($_POST['iban'] ?? ''), $cid]);
            $db->prepare("UPDATE freelancer_profiles SET title = ?, skills = ?, city = ?, bio = ?, portfolio_url = ?, day_rate = ?, equipment = ?, is_available = ? WHERE user_id = ?")
               ->execute([trim($_POST['title'] ?? ''), implode(',', $skills), $city, trim($_POST['bio'] ?? ''), $portfolio ?: null, parse_money($_POST['day_rate'] ?? '') ?: null, trim($_POST['equipment'] ?? ''), isset($_POST['is_available']) ? 1 : 0, $uid]);
            $_SESSION['client_user']['company_name'] = $name;
        }
        $db->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?")->execute([$name, $phone, $uid]);
        $_SESSION['client_user']['full_name'] = $name;
        set_flash('success', 'Profiliniz güncellendi.');
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

        <?php if ($role === 'freelancer'): ?>
        <section class="card card-pad" style="display:flex;justify-content:space-between;align-items:center;gap:16px">
            <div>
                <p style="font-weight:500">Yeni iş almaya müsaitim</p>
                <p class="small text-muted">Kapalıyken işleri görebilir ama alamaz, teklif veremezsiniz.</p>
            </div>
            <label class="switch"><input type="checkbox" name="is_available" value="1" <?= (int)$profile['is_available'] === 1 ? 'checked' : '' ?>><span></span></label>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-head"><p class="card-title"><?= $role === 'agency' ? 'Firma bilgileri' : 'Kişisel bilgiler' ?></p></div>
            <div class="card-pad grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php if ($role === 'agency'): ?>
                <div class="field sm:col-span-2"><label class="label">Ajans / firma adı</label><input class="input" type="text" name="company_title" value="<?= e($contact['company_title']) ?>"></div>
                <?php endif; ?>
                <div class="field"><label class="label"><?= $role === 'agency' ? 'Yetkili kişi' : 'Ad soyad' ?> <span class="req">*</span></label><input class="input" type="text" name="full_name" required value="<?= e($_SESSION['client_user']['full_name'] ?? '') ?>"></div>
                <div class="field"><label class="label">Telefon <span class="req">*</span></label><input class="input" type="text" name="phone" required value="<?= e($contact['phone'] ?? '') ?>"></div>
                <div class="field"><label class="label">E-posta</label><input class="input" type="email" value="<?= e($contact['email'] ?? '') ?>" disabled><span class="hint">Değişiklik için platform ekibine yazın.</span></div>
                <div class="field"><label class="label">Şehir</label><input class="input" type="text" name="city" value="<?= e($role === 'freelancer' ? ($profile['city'] ?? '') : ($contact['city'] ?? '')) ?>"><?php if ($role === 'freelancer'): ?><span class="hint">Yerinde çekim işleri şehrinize göre listelenir.</span><?php endif; ?></div>
            </div>
        </section>

        <?php if ($role === 'agency'): ?>
        <section class="card">
            <div class="card-head"><div><p class="card-title">Fatura bilgileri</p><p class="card-sub">Tamamlanan siparişlerin faturası bu bilgilerle kesilir.</p></div></div>
            <div class="card-pad grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="field"><label class="label">Web sitesi</label><input class="input" type="text" name="website" value="<?= e($profile['website'] ?? '') ?>"></div>
                <div class="field"><label class="label">Vergi dairesi</label><input class="input" type="text" name="tax_office" value="<?= e($contact['tax_office'] ?? '') ?>"></div>
                <div class="field"><label class="label">Vergi no</label><input class="input" type="text" name="tax_number" value="<?= e($contact['tax_number'] ?? '') ?>"></div>
                <div class="field sm:col-span-3"><label class="label">Fatura adresi</label><textarea class="textarea" name="address" rows="2"><?= e($contact['address'] ?? '') ?></textarea></div>
            </div>
        </section>
        <?php else: ?>
        <section class="card">
            <div class="card-head"><div><p class="card-title">Uzmanlık</p><p class="card-sub">İş havuzunda yalnızca seçtiğiniz alanlardaki işleri görürsünüz.</p></div></div>
            <div class="card-pad stack">
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <?php foreach (JOB_CATEGORIES as $ck => $cv): ?>
                    <label class="option-card" style="align-items:center;padding:10px 12px">
                        <input type="checkbox" name="skills[]" value="<?= $ck ?>" <?= in_array($ck, $skills, true) ? 'checked' : '' ?>>
                        <i data-lucide="<?= $cv['icon'] ?>" style="width:15px;height:15px;color:var(--muted)"></i><span class="small"><?= e($cv['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="field"><label class="label">Unvan</label><input class="input" type="text" name="title" value="<?= e($profile['title'] ?? '') ?>" placeholder="Görüntü yönetmeni, kurgucu"></div>
                    <div class="field"><label class="label">Günlük ücret beklentisi</label><div class="input-group"><input class="input" type="text" inputmode="decimal" name="day_rate" value="<?= e((string)($profile['day_rate'] ?? '')) ?>"><span class="addon">TL</span></div></div>
                    <div class="field"><label class="label">Portfolyo / showreel</label><input class="input" type="url" name="portfolio_url" value="<?= e($profile['portfolio_url'] ?? '') ?>" placeholder="https://"></div>
                    <div class="field"><label class="label">Ekipman</label><input class="input" type="text" name="equipment" value="<?= e($profile['equipment'] ?? '') ?>" placeholder="FX3, DJI RS3, Aputure 300d"></div>
                    <div class="field sm:col-span-2"><label class="label">Hakkımda</label><textarea class="textarea" name="bio" rows="3"><?= e($profile['bio'] ?? '') ?></textarea></div>
                </div>
            </div>
        </section>
        <section class="card">
            <div class="card-head"><div><p class="card-title">Ödeme</p><p class="card-sub">Hakedişler bu hesaba aktarılır.</p></div></div>
            <div class="card-pad"><div class="field"><label class="label">IBAN</label><input class="input mono" type="text" name="iban" value="<?= e($contact['iban'] ?? '') ?>" placeholder="TR00 0000 0000 0000 0000 0000 00"></div></div>
        </section>
        <?php endif; ?>

        <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary">Değişiklikleri kaydet</button></div>
    </form>

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
