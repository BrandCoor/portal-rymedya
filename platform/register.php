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
    $city      = trim($_POST['city'] ?? '');

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
    if (strlen($password) < 8) {
        $errors[] = 'Şifre en az 8 karakter olmalıdır.';
    } elseif ($password !== $password2) {
        $errors[] = 'Şifreler eşleşmiyor.';
    }
    if (empty($_POST['kvkk'])) {
        $errors[] = 'Kullanım koşullarını ve KVKK aydınlatma metnini onaylamalısınız.';
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

        log_activity('platform_signup', ($type === 'agency' ? '🏢 Yeni ajans kaydı: ' . $company : '🎥 Yeni freelancer başvurusu: ' . $full_name . ' (' . implode(', ', array_map('job_category_label', $skills)) . ')') . ' — onay bekliyor', $type, $user_id, '/modules/platform/' . ($type === 'agency' ? 'agencies.php' : 'freelancers.php') . '?status=pending');

        set_flash('success', 'Kaydınız alındı! Hesabınız platform ekibimiz tarafından incelendikten sonra aktifleşecek.');
        redirect(BASE_URL . '/platform/index.php');
    }
}

$is_agency = $type === 'agency';
$accent = $is_agency ? 'indigo' : 'emerald';
$val = fn($k) => e($old[$k] ?? '');
?>
<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_agency ? 'Ajans Kaydı' : 'Freelancer Başvurusu' ?> | RY Medya Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-full bg-gradient-to-br from-slate-950 via-slate-900 to-<?= $accent ?>-950 font-sans antialiased text-slate-100 py-10 px-4">
    <div class="max-w-2xl mx-auto">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-<?= $accent ?>-600 shadow-xl mb-3">
                <i data-lucide="<?= $is_agency ? 'building-2' : 'user-plus' ?>" class="w-7 h-7 text-white"></i>
            </div>
            <h1 class="text-2xl font-black text-white"><?= $is_agency ? 'Ajans Olarak Kayıt Olun' : 'Freelancer Olarak Başvurun' ?></h1>
            <p class="text-sm text-slate-400 mt-1">
                <?= $is_agency
                    ? 'Çekim, kurgu ve prodüksiyon işlerinizi platforma girin; ekibimiz planlasın, teslim etsin.'
                    : 'Profilinizi oluşturun, onaylandıktan sonra size uygun prodüksiyon işlerini alın.' ?>
            </p>
            <div class="mt-4 inline-flex bg-white/5 border border-slate-700 rounded-xl p-1 text-xs font-bold">
                <a href="?type=agency" class="px-4 py-1.5 rounded-lg <?= $is_agency ? 'bg-indigo-600 text-white' : 'text-slate-400' ?>">Ajans</a>
                <a href="?type=freelancer" class="px-4 py-1.5 rounded-lg <?= !$is_agency ? 'bg-emerald-600 text-white' : 'text-slate-400' ?>">Freelancer</a>
            </div>
        </div>

        <div class="bg-white text-slate-800 rounded-3xl p-6 sm:p-8 shadow-2xl">
            <?php if (!$signup_open): ?>
                <p class="text-center text-sm text-slate-600 py-8">Bu kayıt türü şu an yeni başvurulara kapalıdır. Lütfen daha sonra tekrar deneyiniz.</p>
            <?php else: ?>
            <?php if ($errors): ?>
                <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 space-y-1">
                    <?php foreach ($errors as $er): ?><p>• <?= e($er) ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="text" name="website_url_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

                <?php $in = 'w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-' . $accent . '-500 focus:outline-none'; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php if ($is_agency): ?>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-600 mb-1">Ajans / Firma Adı *</label>
                        <input type="text" name="company_title" required value="<?= $val('company_title') ?>" class="<?= $in ?>">
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1"><?= $is_agency ? 'Yetkili Ad Soyad *' : 'Ad Soyad *' ?></label>
                        <input type="text" name="full_name" required value="<?= $val('full_name') ?>" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Telefon *</label>
                        <input type="tel" name="phone" required value="<?= $val('phone') ?>" placeholder="05xx xxx xx xx" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">E-posta (giriş adresiniz) *</label>
                        <input type="email" name="email" required value="<?= $val('email') ?>" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Şehir</label>
                        <input type="text" name="city" value="<?= $val('city') ?>" placeholder="İstanbul" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Şifre *</label>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Şifre (Tekrar) *</label>
                        <input type="password" name="password2" required minlength="8" autocomplete="new-password" class="<?= $in ?>">
                    </div>
                </div>

                <?php if ($is_agency): ?>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Web Sitesi</label>
                        <input type="text" name="website" value="<?= $val('website') ?>" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Vergi Dairesi</label>
                        <input type="text" name="tax_office" value="<?= $val('tax_office') ?>" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Vergi No</label>
                        <input type="text" name="tax_number" value="<?= $val('tax_number') ?>" class="<?= $in ?>">
                    </div>
                </div>
                <?php else: ?>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Unvan</label>
                    <input type="text" name="title" value="<?= $val('title') ?>" placeholder="Örn: Görüntü Yönetmeni, Kurgucu, Colorist" class="<?= $in ?>">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-2">Uzmanlık Alanları * <span class="font-normal text-slate-400">(size uygun işler bu alanlara göre gösterilir)</span></label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <?php foreach (JOB_CATEGORIES as $ck => $cv): ?>
                        <label class="flex items-center gap-2 p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer hover:border-emerald-400">
                            <input type="checkbox" name="skills[]" value="<?= $ck ?>" <?= in_array($ck, (array)($old['skills'] ?? []), true) ? 'checked' : '' ?> class="rounded text-emerald-600">
                            <span><?= e($cv['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Portfolyo / Showreel Linki</label>
                        <input type="url" name="portfolio_url" value="<?= $val('portfolio_url') ?>" placeholder="https://vimeo.com/..." class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Günlük Ücret Beklentisi (₺)</label>
                        <input type="number" step="0.01" name="day_rate" value="<?= $val('day_rate') ?>" class="<?= $in ?>">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Ekipmanlarınız</label>
                    <input type="text" name="equipment" value="<?= $val('equipment') ?>" placeholder="Örn: Sony FX6, DJI Mavic 3, DaVinci Resolve Studio" class="<?= $in ?>">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Kısaca Kendinizden Bahsedin</label>
                    <textarea name="bio" rows="3" class="<?= $in ?>"><?= $val('bio') ?></textarea>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">IBAN (ödemeleriniz için)</label>
                    <input type="text" name="iban" value="<?= $val('iban') ?>" placeholder="TR..." class="<?= $in ?> font-mono">
                </div>
                <?php endif; ?>

                <label class="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                    <input type="checkbox" name="kvkk" value="1" required class="mt-0.5 rounded">
                    <span>Platform kullanım koşullarını ve KVKK aydınlatma metnini okudum, kabul ediyorum. Bilgilerimin iş eşleştirme amacıyla işlenmesine onay veriyorum.</span>
                </label>

                <button type="submit" class="w-full py-3.5 bg-<?= $accent ?>-600 hover:bg-<?= $accent ?>-700 text-white font-bold text-sm rounded-xl shadow-lg transition">
                    <?= $is_agency ? 'Ajans Hesabı Oluştur' : 'Başvurumu Gönder' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <p class="text-center text-sm text-slate-400 mt-6">
            Zaten hesabınız var mı? <a href="<?= BASE_URL ?>/client/login.php" class="font-bold text-white underline">Giriş yapın</a>
        </p>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
