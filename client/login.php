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
                session_regenerate_id(true);

                $_SESSION['client_user_id'] = $client['id'];
                $_SESSION['client_contact_id'] = $active_contact_id;
                $_SESSION['client_user'] = [
                    'role'         => portal_role_from_slug($client['role_slug'] ?? ''),
                    'id'           => $client['id'],
                    'contact_id'   => $active_contact_id,
                    'full_name'    => $client['full_name'],
                    'company_name' => $client['client_name'] ?? $client['full_name'],
                    'email'        => $client['email'],
                    'phone'        => $client['phone']
                ];

                $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$client['id']]);

                set_flash('success', 'Hoş geldiniz, ' . $client['full_name']);
                redirect(portal_home_url($_SESSION['client_user']['role']));
            }
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
    <?php ui_head('Portal girişi'); ?>
</head>
<body>
<div class="auth">
    <aside class="auth-aside">
        <div class="frame-lines"></div>
        <div style="position:relative;display:flex;align-items:center;gap:10px">
            <span class="brand-mark">RY</span>
            <span style="font-weight:600;color:#F4F4F5"><?= e(get_setting('company_brand_name', 'RY Medya')) ?></span>
        </div>
        <div style="position:relative">
            <p class="eyebrow" style="color:#77767E;margin-bottom:18px"><span class="rec-dot" style="margin-right:10px"></span>Prodüksiyon platformu</p>
            <p class="auth-quote">Siparişi verin, ekibi biz kuralım. <em>Teslime kadar</em> her adımı buradan izleyin.</p>
        </div>
        <div class="auth-points">
            <div><i data-lucide="building-2"></i><span><b>Ajanslar</b> hizmetleri seçip anında fiyat görür, işi tek adımda sipariş eder.</span></div>
            <div><i data-lucide="users-round"></i><span><b>Freelancer'lar</b> seviyelerine uygun işleri alır, teslim eder, kazancını takip eder.</span></div>
            <div><i data-lucide="film"></i><span><b>Müşteriler</b> projelerini, kurgu versiyonlarını ve faturalarını görür.</span></div>
        </div>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <div class="lg:hidden" style="display:flex;align-items:center;gap:10px;margin-bottom:32px">
                <span class="brand-mark">RY</span><span style="font-weight:600"><?= e(get_setting('company_brand_name', 'RY Medya')) ?></span>
            </div>
            <h1 class="h1">Portal girişi</h1>
            <p class="small text-muted" style="margin-top:6px">Müşteri, ajans ve freelancer hesapları için ortak giriş.</p>

            <div style="margin-top:24px">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="margin-bottom:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div>
                <?php endif; ?>
                <?= display_flash() ?>

                <form method="POST" action="" class="stack">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="email">E-posta</label>
                        <input class="input" id="email" type="email" name="email" required autofocus autocomplete="username" placeholder="ornek@sirket.com">
                    </div>
                    <div class="field">
                        <label class="label" for="password">Şifre</label>
                        <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block">Giriş yap</button>
                </form>
            </div>

            <?php if (platform_setting('platform_agency_signup') === '1' || platform_setting('platform_freelancer_signup') === '1'): ?>
            <div class="hairline" style="margin:28px 0 20px"></div>
            <p class="small text-muted" style="margin-bottom:10px">Henüz hesabınız yok mu?</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <?php if (platform_setting('platform_agency_signup') === '1'): ?>
                <a href="<?= BASE_URL ?>/platform/register.php?type=agency" class="card card-hover card-pad-sm" style="display:block">
                    <i data-lucide="building-2" style="width:17px;height:17px"></i>
                    <p class="small" style="font-weight:600;margin-top:8px">Ajans Kaydı</p>
                    <p class="xsmall text-muted">İş yaptırmak istiyorum</p>
                </a>
                <?php endif; ?>
                <?php if (platform_setting('platform_freelancer_signup') === '1'): ?>
                <a href="<?= BASE_URL ?>/platform/register.php?type=freelancer" class="card card-hover card-pad-sm" style="display:block">
                    <i data-lucide="user-plus" style="width:17px;height:17px"></i>
                    <p class="small" style="font-weight:600;margin-top:8px">Freelancer Başvurusu</p>
                    <p class="xsmall text-muted">İş almak istiyorum</p>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <p class="xsmall text-muted" style="margin-top:24px;text-align:center"><a class="link" href="<?= BASE_URL ?>/modules/auth/login.php">RY Medya personeli girişi</a></p>
        </div>
    </main>
</div>
<?php ui_icons_init(); ?>
</body>
</html>
