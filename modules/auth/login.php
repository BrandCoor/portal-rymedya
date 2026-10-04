<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GÜVENLİ GİRİŞ PANELİ (LOGIN)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// Zaten oturum açmışsa Dashboard'a yönlendir
if (is_logged_in()) {
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
<html lang="tr" class="h-full bg-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap | <?= APP_NAME ?></title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            900: '#4c1d95',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="h-full flex items-center justify-center p-4 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 font-sans antialiased text-slate-100">

    <div class="w-full max-w-md">
        <!-- Logo & Başlık Kartı -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-brand-600 to-indigo-500 shadow-xl shadow-brand-600/30 mb-4 ring-4 ring-brand-500/20">
                <i data-lucide="video" class="w-8 h-8 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white"><?= APP_NAME ?></h1>
            <p class="text-sm text-slate-400 mt-1">Ajans Personeli Yönetim Paneli</p>
        </div>

        <!-- Giriş Form Kartı -->
        <div class="bg-slate-800/80 backdrop-blur-xl border border-slate-700/60 rounded-3xl p-8 shadow-2xl shadow-black/40">
            
            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 flex items-center gap-3 text-sm text-rose-300">
                    <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <?= display_flash() ?>

            <form method="POST" action="" class="space-y-5">
                <?= csrf_field() ?>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">E-Posta Adresi</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="mail" class="w-5 h-5"></i>
                        </div>
                        <input type="email" name="email" required autofocus
                            value="<?= e($_POST['email'] ?? '') ?>"
                            placeholder="ornek@ajans.com" 
                            class="w-full pl-11 pr-4 py-3 bg-slate-900/70 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-all text-sm">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300">Şifre</label>
                    </div>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="lock" class="w-5 h-5"></i>
                        </div>
                        <input type="password" name="password" required 
                            value=""
                            placeholder="••••••••" 
                            class="w-full pl-11 pr-4 py-3 bg-slate-900/70 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-all text-sm">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" 
                        class="w-full flex items-center justify-center gap-2 py-3.5 px-4 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white font-medium text-sm rounded-xl shadow-lg shadow-brand-600/30 hover:shadow-brand-600/50 transition-all duration-200 cursor-pointer">
                        <span>Güvenli Giriş Yap</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>

            <!-- Portal Girişleri -->
            <div class="mt-6 pt-6 border-t border-slate-700/60">
                <p class="text-center text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-3">Müşteri, ajans veya freelancer misiniz?</p>
                <a href="<?= BASE_URL ?>/client/login.php"
                   class="w-full flex items-center justify-center gap-2 py-3 px-4 bg-white/5 hover:bg-white/10 border border-slate-600 hover:border-indigo-400 text-white font-semibold text-sm rounded-xl transition">
                    <i data-lucide="users-round" class="w-4 h-4 text-indigo-300"></i>
                    <span>Müşteri / Ajans / Freelancer Girişi</span>
                </a>
                <?php if (platform_setting('platform_agency_signup') === '1' || platform_setting('platform_freelancer_signup') === '1'): ?>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <?php if (platform_setting('platform_agency_signup') === '1'): ?>
                    <a href="<?= BASE_URL ?>/platform/register.php?type=agency" class="text-center py-2 rounded-lg text-slate-300 hover:text-white hover:bg-white/5">Ajans Kaydı</a>
                    <?php endif; ?>
                    <?php if (platform_setting('platform_freelancer_signup') === '1'): ?>
                    <a href="<?= BASE_URL ?>/platform/register.php?type=freelancer" class="text-center py-2 rounded-lg text-slate-300 hover:text-white hover:bg-white/5">Freelancer Başvurusu</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alt Bilgi -->
        <p class="text-center text-xs text-slate-500 mt-8">
            &copy; <?= date('Y') ?> <?= APP_NAME ?>. Tüm hakları saklıdır.
        </p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>