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
        $hash = $db->query("SELECT password FROM users WHERE id = {$uid}")->fetchColumn();
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
$in = 'w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm';
$status_labels = ['pending' => ['Onay Bekliyor', 'bg-amber-100 text-amber-800'], 'approved' => ['Onaylı', 'bg-emerald-100 text-emerald-800'], 'suspended' => ['Askıda', 'bg-rose-100 text-rose-800']];
$sl = $status_labels[$profile['status']] ?? [$profile['status'], 'bg-slate-100'];

platform_header('Profil', 'profile');
?>
<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-black text-slate-900">Profil</h1>
        <div class="flex items-center gap-2 text-xs">
            <span class="px-2.5 py-1 rounded-full font-bold <?= $sl[1] ?>"><?= $sl[0] ?></span>
            <?php if ($role === 'freelancer'): $t = FREELANCER_TIERS[$profile['tier']] ?? FREELANCER_TIERS['standard']; ?>
                <span class="px-2.5 py-1 rounded-full border font-bold <?= $t['color'] ?>"><?= $t['label'] ?> Seviye</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="" class="bg-white border border-slate-200 rounded-3xl p-6 space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_profile">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php if ($role === 'agency'): ?>
            <div class="sm:col-span-2"><label class="block text-xs font-bold text-slate-600 mb-1">Ajans / Firma Adı</label><input type="text" name="company_title" value="<?= e($contact['company_title']) ?>" class="<?= $in ?>"></div>
            <?php endif; ?>
            <div><label class="block text-xs font-bold text-slate-600 mb-1"><?= $role === 'agency' ? 'Yetkili' : 'Ad Soyad' ?> *</label><input type="text" name="full_name" required value="<?= e($_SESSION['client_user']['full_name'] ?? '') ?>" class="<?= $in ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Telefon *</label><input type="text" name="phone" required value="<?= e($contact['phone'] ?? '') ?>" class="<?= $in ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">E-posta</label><input type="email" value="<?= e($contact['email'] ?? '') ?>" disabled class="<?= $in ?> opacity-60"><p class="text-[10px] text-slate-400 mt-1">Değişiklik için platform ekibine yazın.</p></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Şehir</label><input type="text" name="city" value="<?= e($role === 'freelancer' ? ($profile['city'] ?? '') : ($contact['city'] ?? '')) ?>" class="<?= $in ?>"></div>
        </div>

        <?php if ($role === 'agency'): ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Web Sitesi</label><input type="text" name="website" value="<?= e($profile['website'] ?? '') ?>" class="<?= $in ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Vergi Dairesi</label><input type="text" name="tax_office" value="<?= e($contact['tax_office'] ?? '') ?>" class="<?= $in ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Vergi No</label><input type="text" name="tax_number" value="<?= e($contact['tax_number'] ?? '') ?>" class="<?= $in ?>"></div>
        </div>
        <div><label class="block text-xs font-bold text-slate-600 mb-1">Fatura Adresi</label><textarea name="address" rows="2" class="<?= $in ?>"><?= e($contact['address'] ?? '') ?></textarea></div>
        <?php else: ?>
        <label class="flex items-center gap-2 p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm font-bold text-emerald-900 cursor-pointer">
            <input type="checkbox" name="is_available" value="1" <?= (int)$profile['is_available'] === 1 ? 'checked' : '' ?> class="rounded text-emerald-600"> Yeni iş almaya müsaitim
        </label>
        <div><label class="block text-xs font-bold text-slate-600 mb-1">Unvan</label><input type="text" name="title" value="<?= e($profile['title'] ?? '') ?>" class="<?= $in ?>"></div>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-2">Uzmanlık Alanları</label>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                <?php foreach (JOB_CATEGORIES as $ck => $cv): ?>
                <label class="flex items-center gap-2 p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs cursor-pointer">
                    <input type="checkbox" name="skills[]" value="<?= $ck ?>" <?= in_array($ck, $skills, true) ? 'checked' : '' ?> class="rounded text-emerald-600"><?= e($cv['label']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Portfolyo / Showreel</label><input type="url" name="portfolio_url" value="<?= e($profile['portfolio_url'] ?? '') ?>" class="<?= $in ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Günlük Ücret (₺)</label><input type="number" step="0.01" name="day_rate" value="<?= e((string)($profile['day_rate'] ?? '')) ?>" class="<?= $in ?>"></div>
        </div>
        <div><label class="block text-xs font-bold text-slate-600 mb-1">Ekipman</label><input type="text" name="equipment" value="<?= e($profile['equipment'] ?? '') ?>" class="<?= $in ?>"></div>
        <div><label class="block text-xs font-bold text-slate-600 mb-1">Hakkımda</label><textarea name="bio" rows="3" class="<?= $in ?>"><?= e($profile['bio'] ?? '') ?></textarea></div>
        <div><label class="block text-xs font-bold text-slate-600 mb-1">IBAN</label><input type="text" name="iban" value="<?= e($contact['iban'] ?? '') ?>" class="<?= $in ?> font-mono"></div>
        <?php endif; ?>
        <div class="flex justify-end"><button class="px-6 py-2.5 bg-slate-900 text-white text-sm font-bold rounded-xl">Kaydet</button></div>
    </form>

    <form method="POST" action="" class="bg-white border border-slate-200 rounded-3xl p-6 space-y-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <h2 class="text-sm font-bold text-slate-900">Şifre Değiştir</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input type="password" name="current_password" required placeholder="Mevcut şifre" class="<?= $in ?>">
            <input type="password" name="new_password" required minlength="8" placeholder="Yeni şifre (en az 8 karakter)" class="<?= $in ?>">
        </div>
        <div class="flex justify-end"><button class="px-5 py-2.5 bg-white border border-slate-300 text-sm font-bold rounded-xl">Şifreyi Güncelle</button></div>
    </form>
</div>
<?php platform_footer();
