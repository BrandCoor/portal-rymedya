<?php
/**
 * ====================================================================
 * RY MEDYA - MÜŞTERİ PORTALI GİRİŞ EKRANI (DİNAMİK & GÜVENLİ)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Zaten müşteri oturumu açıksa Dashboard'a yönlendir
if (is_client_logged_in()) {
    redirect(portal_home_url($_SESSION['client_user']['role'] ?? 'client'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Lütfen e-posta ve şifrenizi giriniz.';
    } elseif (is_login_locked($email)) {
        $error = 'Çok fazla hatalı giriş denemesi yapıldı. Lütfen ' . LOGIN_LOCKOUT_MINUTES . ' dakika sonra tekrar deneyiniz.';
    } else {
        $stmt = $db->prepare("
            SELECT u.*, r.role_slug, c.id as linked_contact_id, c.company_title as client_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN contacts c ON u.contact_id = c.id
            WHERE u.email = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $client = $stmt->fetch();

        if ($client && password_verify($password, $client['password'])) {
            $active_contact_id = $client['linked_contact_id'];

            // Cari bağlantısı yoksa ve hesap bir müşteri hesabıysa e-posta ile eşleştir
            $is_portal_account = ($client['role_slug'] === 'client' || !empty($client['contact_id'])) && (int)$client['role_id'] !== 1;
            if (!$active_contact_id && $is_portal_account) {
                $c_find = $db->prepare("SELECT id, company_title FROM contacts WHERE email = ? LIMIT 1");
                $c_find->execute([$email]);
                $found_c = $c_find->fetch();
                if ($found_c) {
                    $active_contact_id = $found_c['id'];
                    $client['client_name'] = $found_c['company_title'];
                    $db->prepare("UPDATE users SET contact_id = ? WHERE id = ?")->execute([$active_contact_id, $client['id']]);
                }
            }

            if (!$is_portal_account || !$active_contact_id) {
                // Personel hesapları veya cariye bağlanmamış hesaplar portala giremez
                $error = 'Bu hesap için müşteri portalı erişimi tanımlanmamış. Lütfen ajansınızla iletişime geçiniz.';
            } else {
                clear_login_failures($email);
                if (twofa_mode('portal') !== 'off' && (int)($client['totp_enabled'] ?? 0) === 1) {
                    twofa_begin($client, 'portal', ['contact_id' => (int)$active_contact_id, 'client_name' => $client['client_name'] ?? null]);
                }
                portal_session_start($client, (int)$active_contact_id);
                set_flash('success', 'Hoş geldiniz, ' . $client['full_name']);
                redirect(portal_home_url($_SESSION['client_user']['role']));
            }
        } else {
            record_login_failure($email);
            security_log_login($client['id'] ?? null, $email, 'portal', false, 'hatalı şifre');
            $error = 'E-posta adresi veya şifre hatalı!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <?php ui_head(site_setting('login_portal_title')); ?>
</head>
<body>
<div class="auth">
    <?php ui_auth_aside('login_portal'); ?>

    <main class="auth-main">
        <div class="auth-card">
            <div class="lg:hidden" style="display:flex;align-items:center;gap:10px;margin-bottom:32px">
                <?= brand_html('light') ?>
            </div>
            <h1 class="h1"><?= e(site_setting('login_portal_title')) ?></h1>
            <?php if (site_setting('login_portal_subtitle') !== ''): ?><p class="small text-muted" style="margin-top:6px"><?= e(site_setting('login_portal_subtitle')) ?></p><?php endif; ?>

            <div style="margin-top:24px">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="margin-bottom:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div>
                <?php endif; ?>
                <?= display_flash() ?>

                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="email">E-posta</label>
                        <input class="input" id="email" type="email" name="email" required autofocus autocomplete="username" placeholder="<?= e(site_setting('login_portal_email_placeholder')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="password">Şifre</label>
                        <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block">Giriş yap</button>
                </form>
                <p class="xsmall" style="margin-top:12px;text-align:right"><a class="link" href="<?= BASE_URL ?>/modules/auth/forgot.php?for=portal">Şifremi unuttum</a></p>
            </div>

            <?php if (platform_setting('platform_agency_signup') === '1' || platform_setting('platform_freelancer_signup') === '1'): ?>
            <div class="hairline" style="margin:28px 0 20px"></div>
            <p class="small text-muted" style="margin-bottom:10px"><?= e(site_setting('login_portal_signup_question')) ?></p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <?php if (platform_setting('platform_agency_signup') === '1'): ?>
                <a href="<?= BASE_URL ?>/platform/register.php?type=agency" class="card card-hover card-pad-sm" style="display:block">
                    <i data-lucide="building-2" style="width:17px;height:17px"></i>
                    <p class="small" style="font-weight:600;margin-top:8px"><?= e(site_setting('login_portal_agency_card')) ?></p>
                    <p class="xsmall text-muted"><?= e(site_setting('login_portal_agency_hint')) ?></p>
                </a>
                <?php endif; ?>
                <?php if (platform_setting('platform_freelancer_signup') === '1'): ?>
                <a href="<?= BASE_URL ?>/platform/register.php?type=freelancer" class="card card-hover card-pad-sm" style="display:block">
                    <i data-lucide="user-plus" style="width:17px;height:17px"></i>
                    <p class="small" style="font-weight:600;margin-top:8px"><?= e(site_setting('login_portal_freelancer_card')) ?></p>
                    <p class="xsmall text-muted"><?= e(site_setting('login_portal_freelancer_hint')) ?></p>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (site_setting('login_portal_show_staff_link') === '1'): ?><p class="xsmall text-muted" style="margin-top:24px;text-align:center"><a class="link" href="<?= BASE_URL ?>/modules/auth/login.php"><?= e(site_setting('login_portal_staff_link')) ?></a></p><?php endif; ?>
        </div>
        <?= legal_auth_links() ?>
    </main>
</div>
<?php ui_icons_init(); ?>
</body>
</html>
