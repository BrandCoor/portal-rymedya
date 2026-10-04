<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GÜVENLİ GİRİŞ PANELİ (LOGIN)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// Zaten oturum açmışsa Dashboard'a yönlendir (ayarlardan önizleme hariç)
$preview = isset($_GET['preview']) && is_logged_in() && has_permission('settings.manage');
if (is_logged_in() && !$preview) {
    redirect(BASE_URL . '/modules/dashboard/index.php');
}

$error = '';

// Form Post Edildiyse Giriş İşlemini Yap
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Lütfen e-posta adresinizi ve şifrenizi giriniz.';
    } elseif (is_login_locked($email)) {
        $error = 'Çok fazla hatalı giriş denemesi yapıldı. Lütfen ' . LOGIN_LOCKOUT_MINUTES . ' dakika sonra tekrar deneyiniz.';
    } else {
        $stmt = $db->prepare("
            SELECT u.*, r.role_name, r.role_slug 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password']) && is_portal_account($user)) {
            // Müşteri hesapları yönetim paneline giremez, portala yönlendirilir
            $error = 'Bu hesap bir müşteri / ajans / freelancer hesabıdır. Lütfen aşağıdaki "Müşteri / Ajans / Freelancer Girişi" butonunu kullanınız.';
        } elseif ($user && password_verify($password, $user['password'])) {
            clear_login_failures($email);

            // Oturumu Güvenli Şekilde Yenile (Session Fixation Koruması)
            session_regenerate_id(true);

            // Şifre hash algoritması güncellendiyse hash'i yenile
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user'] = [
                'id'        => $user['id'],
                'role_id'   => $user['role_id'],
                'role_name' => $user['role_name'],
                'role_slug' => $user['role_slug'],
                'full_name' => $user['full_name'],
                'email'     => $user['email'],
                'phone'     => $user['phone'],
                'avatar'    => $user['avatar']
            ];

            // Kullanıcı İzinlerini Session'a Yükle
            $_SESSION['user_permissions'] = load_user_permissions((int)$user['role_id']);

            // Son Giriş Tarihini Güncelle
            $update_stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update_stmt->execute([$user['id']]);

            set_flash('success', 'Hoş geldiniz, Sn. ' . $user['full_name']);
            redirect(BASE_URL . '/modules/dashboard/index.php');
        } else {
            record_login_failure($email);
            $error = 'E-posta adresi veya şifre hatalı!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <?php ui_head(site_setting('login_staff_title')); ?>
</head>
<body>
<div class="auth">
    <?php ui_auth_aside('login_staff'); ?>

    <main class="auth-main">
        <div class="auth-card">
            <div class="lg:hidden" style="display:flex;align-items:center;gap:10px;margin-bottom:32px">
                <?= brand_html('light') ?>
            </div>
            <h1 class="h1"><?= e(site_setting('login_staff_title')) ?></h1>
            <?php if (site_setting('login_staff_subtitle') !== ''): ?><p class="small text-muted" style="margin-top:6px"><?= e(site_setting('login_staff_subtitle')) ?></p><?php endif; ?>

            <div style="margin-top:24px">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="margin-bottom:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div>
                <?php endif; ?>
                <?= display_flash() ?>

                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="email">E-posta</label>
                        <input class="input" id="email" type="email" name="email" required autofocus autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>" placeholder="<?= e(site_setting('login_staff_email_placeholder')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="password">Şifre</label>
                        <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block">Giriş yap</button>
                </form>
            </div>

            <?php if (site_setting('login_staff_show_portal') === '1'): ?>
            <div class="hairline" style="margin:28px 0 20px"></div>

            <?php if (site_setting('login_staff_portal_question') !== ''): ?><p class="small text-muted" style="margin-bottom:10px"><?= e(site_setting('login_staff_portal_question')) ?></p><?php endif; ?>
            <a href="<?= BASE_URL ?>/client/login.php" class="btn btn-secondary btn-lg btn-block"><i data-lucide="log-in"></i><?= e(site_setting('login_staff_portal_button')) ?></a>
            <?php if (platform_setting('platform_agency_signup') === '1' || platform_setting('platform_freelancer_signup') === '1'): ?>
            <p class="xsmall text-muted" style="margin-top:14px;text-align:center">
                Hesabınız yok mu?
                <?php if (platform_setting('platform_agency_signup') === '1'): ?><a class="link" href="<?= BASE_URL ?>/platform/register.php?type=agency">Ajans Kaydı</a><?php endif; ?>
                <?php if (platform_setting('platform_agency_signup') === '1' && platform_setting('platform_freelancer_signup') === '1'): ?> · <?php endif; ?>
                <?php if (platform_setting('platform_freelancer_signup') === '1'): ?><a class="link" href="<?= BASE_URL ?>/platform/register.php?type=freelancer">Freelancer Başvurusu</a><?php endif; ?>
            </p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php ui_icons_init(); ?>
</body>
</html>
