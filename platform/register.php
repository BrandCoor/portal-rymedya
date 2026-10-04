<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - AJANS & FREELANCER KAYIT
 * ====================================================================
 * Kayıt sonrası hesap "onay bekliyor" durumundadır; platform yöneticisi
 * onaylayana kadar iş girilemez / iş alınamaz.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$type = ($_GET['type'] ?? $_POST['type'] ?? 'freelancer') === 'agency' ? 'agency' : 'freelancer';
$signup_open = platform_setting($type === 'agency' ? 'platform_agency_signup' : 'platform_freelancer_signup') === '1';

if (is_client_logged_in()) {
    redirect(portal_home_url($_SESSION['client_user']['role'] ?? 'client'));
}

$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $signup_open) {
    verify_csrf();

    $full_name = trim($_POST['full_name'] ?? '');
    $company   = trim($_POST['company_title'] ?? '');
    $email     = mb_strtolower(trim($_POST['email'] ?? ''));
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $city      = normalize_city($_POST['city'] ?? '');

    // Bot tuzağı (görünmez alan dolu ise kayıt reddedilir)
    if (!empty($_POST['website_url_hp'])) {
        $errors[] = 'Kayıt alınamadı.';
    }
    if (is_login_locked('register:' . ($_SERVER['REMOTE_ADDR'] ?? ''))) {
        $errors[] = 'Çok fazla deneme yapıldı. Lütfen biraz sonra tekrar deneyiniz.';
    }
    if ($full_name === '') {
        $errors[] = 'Ad soyad zorunludur.';
    }
    if ($type === 'agency' && $company === '') {
        $errors[] = 'Ajans / firma adı zorunludur.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Geçerli bir e-posta adresi giriniz.';
    }
    if ($phone === '') {
        $errors[] = 'Telefon zorunludur.';
    }
    if ($city === '') {
        $errors[] = 'Listeden il seçin.';
    }
    if ($perr = password_policy_error($password)) {
        $errors[] = $perr;
    } elseif ($password !== $password2) {
        $errors[] = 'Şifreler eşleşmiyor.';
    }
    if (empty($_POST['terms'])) {
        $errors[] = 'Kullanım koşullarını ve ' . ($type === 'agency' ? 'Ajans Hizmet Sözleşmesi' : 'Freelancer Hizmet Sağlayıcı Sözleşmesi') . '\'ni kabul etmelisiniz.';
    }
    if (empty($_POST['kvkk'])) {
        $errors[] = 'KVKK aydınlatma metnini okuduğunuzu onaylamalısınız.';
    }

    $skills = array_values(array_intersect(array_keys(JOB_CATEGORIES), (array)($_POST['skills'] ?? [])));
    if ($type === 'freelancer' && empty($skills)) {
        $errors[] = 'En az bir uzmanlık alanı seçiniz.';
    }
    $portfolio = trim($_POST['portfolio_url'] ?? '');
    if ($portfolio !== '' && !is_safe_url($portfolio)) {
        $errors[] = 'Portfolyo bağlantısı http(s):// ile başlamalıdır.';
    }

    if (!$errors) {
        $dup = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $dup->execute([$email]);
        if ((int)$dup->fetchColumn() > 0) {
            $errors[] = 'Bu e-posta adresiyle zaten bir hesap var. Giriş yapmayı deneyin.';
        }
    }

    if (!$errors) {
        record_login_failure('register:' . ($_SERVER['REMOTE_ADDR'] ?? '')); // IP başına kayıt hız sınırı
        $role_id = get_role_id_by_slug($type, $type === 'agency' ? 'Ajans (Platform İş Veren)' : 'Freelancer (Platform İş Alan)');

        $db->prepare("
            INSERT INTO contacts (type, company_title, authorized_person, phone, email, city, tax_office, tax_number, iban, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $type, $type === 'agency' ? $company : $full_name, $full_name, $phone, $email, $city,
            trim($_POST['tax_office'] ?? ''), trim($_POST['tax_number'] ?? ''), trim($_POST['iban'] ?? ''),
            'Platform kaydı ile oluşturuldu'
        ]);
        $contact_id = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO users (role_id, contact_id, full_name, email, password, phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())")
           ->execute([$role_id, $contact_id, $full_name, $email, password_hash($password, PASSWORD_DEFAULT), $phone]);
        $user_id = (int)$db->lastInsertId();

        // Sözleşme onay kayıtları (IP, tarayıcı, sürüm ve metin özetiyle)
        $accepted = legal_required_slugs($type);
        if (!empty($_POST['consent']))    $accepted[] = 'acik-riza';
        if (!empty($_POST['newsletter'])) $accepted[] = 'ticari-ileti';
        legal_record($user_id, $email, $accepted, 'register');

        if ($type === 'agency') {
            $db->prepare("INSERT INTO agency_profiles (user_id, contact_id, website, status) VALUES (?, ?, ?, 'pending')")
               ->execute([$user_id, $contact_id, trim($_POST['website'] ?? '')]);
        } else {
            $db->prepare("INSERT INTO freelancer_profiles (user_id, contact_id, title, skills, city, bio, portfolio_url, day_rate, equipment, tier, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'standard', 'pending')")
               ->execute([$user_id, $contact_id, trim($_POST['title'] ?? ''), implode(',', $skills), $city, trim($_POST['bio'] ?? ''), $portfolio ?: null, parse_money($_POST['day_rate'] ?? '') ?: null, trim($_POST['equipment'] ?? '')]);
        }

        // Otomatik giriş
        session_regenerate_id(true);
        $_SESSION['client_user_id']    = $user_id;
        $_SESSION['client_contact_id'] = $contact_id;
        $_SESSION['client_user'] = [
            'role' => $type, 'id' => $user_id, 'contact_id' => $contact_id, 'full_name' => $full_name,
            'company_name' => $type === 'agency' ? $company : $full_name, 'email' => $email, 'phone' => $phone,
        ];

        log_activity('platform_signup', ($type === 'agency' ? 'Yeni ajans kaydı: ' . $company : 'Yeni freelancer başvurusu: ' . $full_name . ' (' . implode(', ', array_map('job_category_label', $skills)) . ')') . ' — onay bekliyor', $type, $user_id, '/modules/platform/' . ($type === 'agency' ? 'agencies.php' : 'freelancers.php') . '?status=pending');

        // Toplu e-posta listesi (onay verdiyse ticari ileti izni ile)
        $db->prepare("INSERT INTO mail_subscribers (email, name, company, kind, user_id, contact_id, consent, status, token, source, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 'subscribed', ?, 'register', NOW())
                      ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), contact_id = VALUES(contact_id), kind = VALUES(kind), consent = GREATEST(consent, VALUES(consent))")
           ->execute([mb_strtolower($email), $full_name, $type === 'agency' ? $company : null, $type, $user_id, $contact_id, !empty($_POST['newsletter']) ? 1 : 0, mail_subscriber_token()]);

        if (site_setting('mail_register_confirm') === '1') {
            mail_send_notice($email, $full_name, ($type === 'agency' ? 'Ajans hesabınız oluşturuldu.' : 'Freelancer başvurunuz alındı.') . ' Ekibimiz bilgilerinizi inceledikten sonra hesabınız aktifleşecek ve size e-posta ile haber vereceğiz.', '/platform/index.php', $user_id, 'system');
        }

        set_flash('success', 'Kaydınız alındı! Hesabınız platform ekibimiz tarafından incelendikten sonra aktifleşecek.');
        redirect(BASE_URL . '/platform/index.php');
    }
}

$is_agency = $type === 'agency';
$val = fn($k) => e(is_array($old[$k] ?? null) ? '' : ($old[$k] ?? ''));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <?php ui_head($is_agency ? 'Ajans kaydı' : 'Freelancer başvurusu'); ?>
</head>
<body>
<div class="auth">
    <?php ui_auth_aside($is_agency ? 'register_agency' : 'register_freelancer'); ?>

    <main class="auth-main" style="justify-content:flex-start">
        <div class="auth-card" style="max-width:520px">
            <div class="seg" style="margin-bottom:24px">
                <a href="?type=agency" class="<?= $is_agency ? 'is-active' : '' ?>">Ajans</a>
                <a href="?type=freelancer" class="<?= !$is_agency ? 'is-active' : '' ?>">Freelancer</a>
            </div>
            <h1 class="h1"><?= e(site_setting($is_agency ? 'register_agency_title' : 'register_freelancer_title')) ?></h1>
            <?php if (site_setting('register_note') !== ''): ?><p class="small text-muted" style="margin-top:6px"><?= e(site_setting('register_note')) ?></p><?php endif; ?>

            <?php if (!$signup_open): ?>
                <div class="alert alert-neutral" style="margin-top:24px"><i data-lucide="lock"></i><span><?= e(site_setting('register_closed_text')) ?></span></div>
            <?php else: ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger" style="margin-top:20px"><i data-lucide="alert-circle"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
            <?php endif; ?>

            <form method="POST" action="" class="stack" style="margin-top:24px">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="text" name="website_url_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

                <?php if ($is_agency): ?>
                <div class="field"><label class="label">Ajans / firma adı <span class="req">*</span></label><input class="input" type="text" name="company_title" required value="<?= $val('company_title') ?>"></div>
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="field"><label class="label"><?= $is_agency ? 'Yetkili ad soyad' : 'Ad soyad' ?> <span class="req">*</span></label><input class="input" type="text" name="full_name" required value="<?= $val('full_name') ?>"></div>
                    <div class="field"><label class="label">Telefon <span class="req">*</span></label><input class="input" type="tel" name="phone" required value="<?= $val('phone') ?>" placeholder="05xx xxx xx xx"></div>
                    <div class="field"><label class="label">E-posta <span class="req">*</span></label><input class="input" type="email" name="email" required value="<?= $val('email') ?>"><span class="hint">Giriş adresiniz olur.</span></div>
                    <div class="field"><label class="label">İl <span class="req">*</span></label><?= city_select('city', is_string($_POST['city'] ?? null) ? $_POST['city'] : '', ['required' => true]) ?><?php if (!$is_agency): ?><span class="hint">Yerinde çekim işleri ilinize göre listelenir.</span><?php endif; ?></div>
                    <div class="field"><label class="label">Şifre <span class="req">*</span></label><input class="input" type="password" name="password" required minlength="8" autocomplete="new-password"><span class="hint">En az 8 karakter.</span></div>
                    <div class="field"><label class="label">Şifre tekrar <span class="req">*</span></label><input class="input" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
                </div>

                <?php if ($is_agency): ?>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="field"><label class="label">Web sitesi</label><input class="input" type="text" name="website" value="<?= $val('website') ?>"></div>
                    <div class="field"><label class="label">Vergi dairesi</label><input class="input" type="text" name="tax_office" value="<?= $val('tax_office') ?>"></div>
                    <div class="field"><label class="label">Vergi no</label><input class="input" type="text" name="tax_number" value="<?= $val('tax_number') ?>"></div>
                </div>
                <?php else: ?>
                <div class="field"><label class="label">Unvan</label><input class="input" type="text" name="title" value="<?= $val('title') ?>" placeholder="Görüntü yönetmeni, kurgucu, colorist..."></div>
                <div class="field">
                    <label class="label">Uzmanlık alanları <span class="req">*</span></label>
                    <span class="hint" style="margin-top:-2px">Havuzda size bu alanlardaki işler gösterilir.</span>
                    <div class="grid grid-cols-2 gap-2" style="margin-top:4px">
                        <?php foreach (JOB_CATEGORIES as $ck => $cv): ?>
                        <label class="option-card" style="padding:9px 11px">
                            <input type="checkbox" name="skills[]" value="<?= $ck ?>" <?= in_array($ck, (array)($old['skills'] ?? []), true) ? 'checked' : '' ?>>
                            <span class="small"><?= e($cv['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="field"><label class="label">Portfolyo / showreel</label><input class="input" type="url" name="portfolio_url" value="<?= $val('portfolio_url') ?>" placeholder="https://vimeo.com/..."></div>
                    <div class="field"><label class="label">Günlük ücret beklentisi</label><div class="input-group"><input class="input" type="number" step="0.01" name="day_rate" value="<?= $val('day_rate') ?>"><span class="addon">₺</span></div></div>
                </div>
                <div class="field"><label class="label">Ekipman</label><input class="input" type="text" name="equipment" value="<?= $val('equipment') ?>" placeholder="Sony FX6, DJI Mavic 3, DaVinci Resolve Studio"></div>
                <div class="field"><label class="label">Kısaca deneyiminiz</label><textarea class="textarea" name="bio" rows="3"><?= $val('bio') ?></textarea></div>
                <div class="field"><label class="label">IBAN</label><input class="input mono" type="text" name="iban" value="<?= $val('iban') ?>" placeholder="TR.."><span class="hint">Hakediş ödemeleri için.</span></div>
                <?php endif; ?>

                <div class="consent">
                    <p class="consent-head">Sözleşmeler ve izinler</p>
                    <label class="consent-row"><input type="checkbox" name="terms" value="1" required <?= !empty($old['terms']) ? 'checked' : '' ?>><span><?= legal_link('kullanim-kosullari', 'Kullanım Koşulları ve Üyelik Sözleşmesi') ?>'ni ve <?= $is_agency ? legal_link('ajans-sozlesmesi', 'Ajans Hizmet Sözleşmesi') : legal_link('freelancer-sozlesmesi', 'Freelancer Hizmet Sağlayıcı Sözleşmesi') ?>'ni okudum, kabul ediyorum.<span class="tag req">Zorunlu</span></span></label>
                    <label class="consent-row"><input type="checkbox" name="kvkk" value="1" required <?= !empty($old['kvkk']) ? 'checked' : '' ?>><span><?= legal_link('kvkk', 'KVKK Aydınlatma Metni') ?>'ni okudum; kişisel verilerimin işlenmesi hakkında bilgilendirildim.<span class="tag req">Zorunlu</span></span></label>
                    <label class="consent-row"><input type="checkbox" name="consent" value="1" <?= !empty($old['consent']) ? 'checked' : '' ?>><span><?= legal_link('acik-riza', 'Açık Rıza Metni') ?> kapsamında kişisel verilerimin işlenmesine ve aktarılmasına onay veriyorum.<span class="tag opt">İsteğe bağlı</span></span></label>
                    <label class="consent-row"><input type="checkbox" name="newsletter" value="1" <?= !empty($old['newsletter']) ? 'checked' : '' ?>><span><?= e(site_setting('register_newsletter_text')) ?> (<?= legal_link('ticari-ileti', 'ileti onay metni') ?>)<span class="tag opt">İsteğe bağlı</span></span></label>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block"><?= $is_agency ? 'Hesabı oluştur' : 'Başvuruyu gönder' ?></button>
            </form>
            <?php endif; ?>

            <p class="small text-muted" style="margin-top:20px;text-align:center">Hesabınız var mı? <a class="link" href="<?= BASE_URL ?>/client/login.php">Giriş yapın</a></p>
        </div>
        <?= legal_auth_links() ?>
    </main>
</div>
<?php ui_icons_init(); ?>
</body>
</html>
