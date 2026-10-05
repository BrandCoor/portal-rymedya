<?php
/**
 * ====================================================================
 * RY MEDYA - MÜŞTERİ PROFİL & GÜVENLİK YÖNETİMİ (DENETİM İZLİ)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Müşteri Giriş Kontrolü
require_client_login();

$contact_id  = (int)$_SESSION['client_contact_id'];
$client_id   = (int)$_SESSION['client_user_id'];
$client_user = $_SESSION['client_user'];

// Otomatik Tablo Kontrolü
ensure_contact_change_logs_table();

// Cari Bilgilerini Getir
$c_stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$c_stmt->execute([$contact_id]);
$contact = $c_stmt->fetch();

// 1. PROFİL GÜNCELLEME VE DENETİM İZİ KAYDI (POST HANDLER)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if (legal_profile_post($client_id, $client_user['email'] ?? null, 'client')) {
        redirect(BASE_URL . '/client/profile.php#sozlesmeler');
    }

    if ($action === 'email_prefs') {
        mail_save_user_prefs($client_id, isset($_POST['notify_email']), isset($_POST['newsletter']));
        set_flash('success', 'E-posta tercihleriniz kaydedildi.');
        redirect(BASE_URL . '/client/profile.php');
    }

    if ($action === 'update_profile') {
        $new_authorized_person = trim($_POST['authorized_person'] ?? '');
        $new_phone             = trim($_POST['phone'] ?? '');
        $new_email             = trim($_POST['email'] ?? '');
        $new_city              = normalize_city($_POST['city'] ?? '');
        $new_district          = trim($_POST['district'] ?? '');
        $new_address           = trim($_POST['address'] ?? '');
        $new_password          = $_POST['new_password'] ?? '';
        $current_password      = $_POST['current_password'] ?? '';

        $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor';

        // E-posta aynı zamanda portal giriş adresidir: geçerli ve benzersiz olmalı
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Lütfen geçerli bir e-posta adresi giriniz.');
            redirect(BASE_URL . '/client/profile.php');
        }
        $em_chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $em_chk->execute([$new_email, $client_id]);
        if ((int)$em_chk->fetchColumn() > 0) {
            set_flash('error', 'Bu e-posta adresi başka bir hesap tarafından kullanılıyor.');
            redirect(BASE_URL . '/client/profile.php');
        }
        if (!empty($new_password) && ($perr = password_policy_error($new_password))) {
            set_flash('error', $perr);
            redirect(BASE_URL . '/client/profile.php');
        }
        $changes_count = 0;

        // Kontrol edilecek ve loglanacak alanlar
        $fields_to_track = [
            'authorized_person' => ['label' => 'Yetkili Kişi Adı', 'old' => $contact['authorized_person'] ?? '', 'new' => $new_authorized_person],
            'phone'             => ['label' => 'İletişim Telefonu', 'old' => $contact['phone'] ?? '', 'new' => $new_phone],
            'email'             => ['label' => 'İletişim E-Postası', 'old' => $contact['email'] ?? '', 'new' => $new_email],
            'city'              => ['label' => 'Şehir',             'old' => $contact['city'] ?? '', 'new' => $new_city],
            'district'          => ['label' => 'İlçe',              'old' => $contact['district'] ?? '', 'new' => $new_district],
            'address'           => ['label' => 'Açık Fatura/Teslimat Adresi', 'old' => $contact['address'] ?? '', 'new' => $new_address],
        ];

        $log_stmt = $db->prepare("
            INSERT INTO contact_change_logs (contact_id, user_id, user_name, field_key, field_label, old_value, new_value, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        // Değişiklikleri tespit et ve logla
        foreach ($fields_to_track as $f_key => $f_info) {
            if ($f_info['old'] !== $f_info['new']) {
                $log_stmt->execute([
                    $contact_id,
                    $client_id,
                    $client_user['full_name'],
                    $f_key,
                    $f_info['label'],
                    $f_info['old'],
                    $f_info['new'],
                    $client_ip
                ]);
                $changes_count++;
            }
        }

        // Cari İletişim Bilgilerini Güncelle (Vergi/VKN dokunulmaz!)
        $up_stmt = $db->prepare("
            UPDATE contacts 
            SET authorized_person = ?, phone = ?, email = ?, city = ?, district = ?, address = ?
            WHERE id = ?
        ");
        $up_stmt->execute([$new_authorized_person, $new_phone, $new_email, $new_city, $new_district, $new_address, $contact_id]);

        // Şifre Değiştirme İsteği Varsa
        if (!empty($new_password)) {
            // Mevcut şifre doğrulaması
            $u_stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $u_stmt->execute([$client_id]);
            $current_hash = $u_stmt->fetchColumn();

            if (empty($current_password) || !password_verify($current_password, $current_hash)) {
                set_flash('error', 'Bilgiler güncellendi ancak ŞİFRE DEĞİŞTİRİLEMEDİ: Mevcut şifrenizi hatalı girdiniz.');
                redirect(BASE_URL . '/client/profile.php');
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$new_hash, $client_id]);

                // Şifre değişikliğini de güvenlik loguna işle
                $log_stmt->execute([$contact_id, $client_id, $client_user['full_name'], 'password', 'Portal Giriş Şifresi', '******', 'Şifre Değiştirildi', $client_ip]);
                $changes_count++;
            }
        }

        // Kullanıcının adını/telefonunu users tablosunda da senkronize et
        $db->prepare("UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?")
           ->execute([$new_authorized_person ?: $contact['company_title'], $new_phone, $new_email, $client_id]);

        $_SESSION['client_user']['full_name'] = $new_authorized_person ?: $contact['company_title'];
        $_SESSION['client_user']['email']     = $new_email;

        if ($changes_count > 0) {
            log_activity('client_profile', "Müşteri iletişim bilgilerini güncelledi: {$contact['company_title']} ({$changes_count} alan)", 'contact', $contact_id, "/modules/contacts/detail.php?id={$contact_id}");
        }
        set_flash('success', 'Şirket iletişim bilgileriniz başarıyla güncellendi ve denetim kaydına işlendi.');
        redirect(BASE_URL . '/client/profile.php');
    }
}
?>
<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-50">
<head>
    <?php ui_head('Profil'); ?>
</head>
<body class="h-full flex flex-col font-sans text-slate-800 antialiased bg-slate-100">

    <!-- ÜST MENÜ -->
    <nav style="background:var(--sidebar)" class="h-16 border-b border-slate-800 px-4 sm:px-8 flex items-center justify-between sticky top-0 z-50">
        <a href="<?= BASE_URL ?>/client/index.php" class="flex items-center gap-2 text-white text-xs font-bold hover:text-indigo-300 transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>Portal Ana Sayfasına Dön</span>
        </a>
        <span class="text-xs text-slate-300 font-bold"><?= e($contact['company_title']) ?></span>
    </nav>

    <!-- ANA İÇERİK -->
    <main class="flex-1 max-w-4xl w-full mx-auto p-4 sm:p-8 space-y-6">
        <?= display_flash() ?>
        <?php if (legal_pending_now()): ?><?= legal_profile_card($client_id, 'client') ?><?php endif; ?>

        <div class="mb-4">
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Şirket & İletişim Bilgileri</h1>
            <p class="text-xs text-slate-500 mt-1">İletişim adresinizi güncelleyebilir veya portal giriş şifrenizi değiştirebilirsiniz.</p>
        </div>

        <form method="POST" action="" class="space-y-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">

            <!-- 1. DEĞİŞTİRİLEMEZ KİLİTLİ RESMİ VERİLER -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900">Resmi & Değiştirilemez Vergi Bilgileri</h3>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                        KİLİTLİ ALAN
                    </span>
                </div>

                <div class="p-3.5 bg-amber-50/60 rounded-2xl border border-amber-200 text-xs text-amber-900 flex items-start gap-2.5">
                    <i data-lucide="shield-alert" class="w-4 h-4 flex-shrink-0 mt-0.5 text-amber-600"></i>
                    <span><strong>Güvenlik & Maliye Mevzuatı Uyarısı:</strong> Şirket yasal unvanı ve vergi numarası resmi fatura/beyanname düzenini korumak amacıyla müşteriler tarafından doğrudan değiştirilemez. Unvan veya VKN değişikliği talepleriniz için lütfen ajans yöneticinizle irtibata geçiniz.</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-400 mb-1">Şirket Resmi Ünvanı</label>
                        <input type="text" value="<?= e($contact['company_title']) ?>" disabled
                               class="w-full py-2.5 px-3.5 bg-slate-100 border border-slate-200 rounded-xl text-xs font-bold text-slate-600 cursor-not-allowed">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1">Vergi Dairesi</label>
                        <input type="text" value="<?= e($contact['tax_office'] ?: 'Belirtilmemiş') ?>" disabled
                               class="w-full py-2.5 px-3.5 bg-slate-100 border border-slate-200 rounded-xl text-xs font-medium text-slate-600 cursor-not-allowed">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1">Vergi Numarası (VKN) / TCKN</label>
                        <input type="text" value="<?= e($contact['tax_number'] ?: $contact['id_number'] ?: 'Belirtilmemiş') ?>" disabled
                               class="w-full py-2.5 px-3.5 bg-slate-100 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-600 cursor-not-allowed">
                    </div>
                </div>
            </div>

            <!-- 2. DÜZENLENEBİLİR İLETİŞİM & ADRES BİLGİLERİ -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-4">
                <div class="pb-3 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <i data-lucide="map-pin" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900">İletişim & Fatura/Teslimat Adresi</h3>
                    </div>
                    <span class="text-[11px] text-slate-400">Değişiklikler güvenlik günlüğüne işlenir.</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Yetkili Ad Soyad *</label>
                        <input type="text" name="authorized_person" value="<?= e($contact['authorized_person']) ?>" required
                               class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Telefon Numarası *</label>
                        <input type="text" name="phone" value="<?= e($contact['phone']) ?>" required
                               class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">İletişim E-Postası *</label>
                        <input type="email" name="email" value="<?= e($contact['email']) ?>" required
                               class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Şehir</label>
                        <?= city_select('city', $contact['city'] ?? '', ['class' => 'w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900']) ?>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">İlçe</label>
                        <input type="text" name="district" value="<?= e($contact['district']) ?>" placeholder="Kadıköy"
                               class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Açık Fatura / Sevkiyat Adresi</label>
                    <textarea name="address" rows="3" placeholder="Sözleşme ve teslimat adresi..."
                              class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500"><?= e($contact['address']) ?></textarea>
                </div>
            </div>

            <!-- 3. GÜVENLİK & ŞİFRE YENİLEME -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-4">
                <div class="pb-3 border-b border-slate-100 flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center">
                        <i data-lucide="key" class="w-4 h-4"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-900">Portal Giriş Şifresi Değiştirme (Opsiyonel)</h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Mevcut Şifreniz</label>
                        <input type="password" name="current_password" placeholder="••••••••"
                               class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                        <span class="text-[11px] text-slate-400 mt-1 block">Yalnızca şifre değiştirirken doldurunuz.</span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Yeni Şifreniz</label>
                        <input type="password" name="new_password" placeholder="••••••••"
                               class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                        <span class="text-[11px] text-slate-400 mt-1 block">En az 8 karakter; harf ve rakam içermeli.</span>
                    </div>
                </div>
                <?php if (twofa_mode('portal') !== 'off'): ?>
                <a href="<?= BASE_URL ?>/modules/auth/2fa_setup.php" class="inline-flex items-center gap-2 mt-4 text-xs font-semibold text-indigo-600 hover:underline"><i data-lucide="smartphone" class="w-4 h-4"></i>İki adımlı doğrulama</a>
                <?php endif; ?>
            </div>

            <!-- Kaydet Butonu -->
            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="<?= BASE_URL ?>/client/index.php" class="px-5 py-2.5 text-xs font-semibold text-slate-500 hover:text-slate-700">İptal</a>
                <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-3 px-8 rounded-xl shadow-lg transition cursor-pointer">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Bilgilerimi Güncelle & Kaydet</span>
                </button>
            </div>
        </form>
        <?php $prefs = mail_user_prefs($client_id); ?>
        <form method="POST" action="" class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-4" style="margin-top:24px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="email_prefs">
            <h2 class="text-sm font-bold text-slate-900">E-posta tercihleri</h2>
            <label class="flex items-center justify-between gap-4 text-sm"><span>Proje bildirimleri <span class="block text-xs text-slate-500">Yeni kurgu versiyonu, teslim dosyası, teklif ve fatura.</span></span><span class="switch"><input type="checkbox" name="notify_email" value="1" <?= $prefs['notify'] ? 'checked' : '' ?>><span></span></span></label>
            <label class="flex items-center justify-between gap-4 text-sm"><span>Duyuru ve kampanyalar</span><span class="switch"><input type="checkbox" name="newsletter" value="1" <?= $prefs['newsletter'] ? 'checked' : '' ?>><span></span></span></label>
            <div class="flex justify-end"><button class="btn btn-secondary">Tercihleri kaydet</button></div>
        </form>
        <?php if (!legal_pending_now()): ?><?= legal_profile_card($client_id, 'client') ?><?php endif; ?>

    </main>
    <?= legal_portal_footer() ?>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>