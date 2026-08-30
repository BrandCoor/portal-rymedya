<?php
/**
 * ====================================================================
 * RY MEDYA - MÜŞTERİ PORTALI GİRİŞ EKRANI (DİNAMİK & GÜVENLİ)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Zaten müşteri oturumu açıksa Dashboard'a yönlendir
if (isset($_SESSION['client_user_id'])) {
    redirect(BASE_URL . '/client/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Lütfen e-posta ve şifrenizi giriniz.';
    } else {
        // Dinamik rol ve cari eşleştirme sorgusu (Sabit ID bağımlılığı kaldırıldı)
        $stmt = $db->prepare("
            SELECT u.*, r.role_slug, c.id as linked_contact_id, c.company_title as client_name, c.balance as client_balance 
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN contacts c ON (u.contact_id = c.id OR u.email = c.email)
            WHERE u.email = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $client = $stmt->fetch();

        // Kullanıcı bulunduysa ve rolü 'client' ise veya bir cariye bağlıysa
        if ($client && password_verify($password, $client['password'])) {
            
            // Eğer cari ID henüz kullanıcıya bağlanmadıysa otomatik bağla
            $active_contact_id = $client['linked_contact_id'];
            if (!$active_contact_id) {
                // E-posta ile cariler tablosunda ara
                $c_find = $db->prepare("SELECT id, company_title FROM contacts WHERE email = ? LIMIT 1");
                $c_find->execute([$email]);
                $found_c = $c_find->fetch();
                if ($found_c) {
                    $active_contact_id = $found_c['id'];
                    $client['client_name'] = $found_c['company_title'];
                    $db->prepare("UPDATE users SET contact_id = ? WHERE id = ?")->execute([$active_contact_id, $client['id']]);
                }
            }

            session_regenerate_id(true);

            $_SESSION['client_user_id'] = $client['id'];
            $_SESSION['client_contact_id'] = $active_contact_id;
            $_SESSION['client_user'] = [
                'id'           => $client['id'],
                'contact_id'   => $active_contact_id,
                'full_name'    => $client['full_name'],
                'company_name' => $client['client_name'] ?? $client['full_name'],
                'email'        => $client['email'],
                'phone'        => $client['phone']
            ];

            set_flash('success', 'Hoş geldiniz, ' . $client['full_name']);
            redirect(BASE_URL . '/client/index.php');
        } else {
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
    <title>Müşteri Portalı Girişi | RY Medya</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="h-full flex items-center justify-center p-4 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 font-sans antialiased text-slate-100">

    <div class="w-full max-w-md">
        <!-- Logo & Başlık -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-indigo-600 shadow-xl shadow-indigo-600/30 mb-4 ring-4 ring-indigo-500/20">
                <i data-lucide="clapperboard" class="w-8 h-8 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">MÜŞTERİ PORTALI</h1>
            <p class="text-sm text-slate-400 mt-1">RY Medya Prodüksiyon Takip Sistemi</p>
        </div>

        <!-- Giriş Kartı -->
        <div class="bg-slate-800/80 backdrop-blur-xl border border-slate-700/60 rounded-3xl p-8 shadow-2xl">
            
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
                        <input type="email" name="email" required autofocus placeholder="musteri@sirket.com" 
                            class="w-full pl-11 pr-4 py-3 bg-slate-900/70 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Portal Şifresi</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="lock" class="w-5 h-5"></i>
                        </div>
                        <input type="password" name="password" required placeholder="••••••••" 
                            class="w-full pl-11 pr-4 py-3 bg-slate-900/70 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition text-sm">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" 
                        class="w-full flex items-center justify-center gap-2 py-3.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm rounded-xl shadow-lg transition duration-200 cursor-pointer">
                        <span>Portala Giriş Yap</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>
        </div>

        <p class="text-center text-xs text-slate-500 mt-8">
            &copy; <?= date('Y') ?> RY Medya Prodüksiyon Müşteri Portalı.
        </p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>