<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - YENİ İŞ TALEBİ (AJANS)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile = require_platform_role('agency');
$cid = (int)$_SESSION['client_contact_id'];
$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title       = trim($_POST['title'] ?? '');
    $category    = array_key_exists($_POST['category'] ?? '', JOB_CATEGORIES) ? $_POST['category'] : 'other';
    $description = trim($_POST['description'] ?? '');
    $start_date  = valid_date($_POST['start_date'] ?? '');
    $deadline    = valid_date($_POST['deadline'] ?? '');
    $budget      = parse_money($_POST['budget'] ?? '');
    $is_remote   = isset($_POST['is_remote']) ? 1 : 0;
    $currency    = array_key_exists($_POST['currency'] ?? '', CURRENCIES) ? $_POST['currency'] : 'TRY';

    if ($title === '') $errors[] = 'İş başlığı zorunludur.';
    if (mb_strlen($description) < 20) $errors[] = 'Lütfen işi en az birkaç cümleyle anlatın (brief).';
    if (!$deadline) $errors[] = 'Teslim tarihi zorunludur.';
    if ($deadline && $deadline < date('Y-m-d')) $errors[] = 'Teslim tarihi geçmiş bir tarih olamaz.';
    if ($start_date && $deadline && $deadline < $start_date) $errors[] = 'Teslim tarihi çekim/başlangıç tarihinden önce olamaz.';
    if (!$is_remote && trim($_POST['location_city'] ?? '') === '') $errors[] = 'Şehir giriniz veya "Uzaktan yapılabilir" seçiniz.';

    if (!$errors) {
        $code = generate_job_code();
        $db->prepare("
            INSERT INTO platform_jobs (job_code, agency_contact_id, created_by_user_id, title, category, description, deliverables, reference_links, location_city, location_detail, is_remote, start_date, deadline, budget, currency, status, priority_hours, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, NOW())
        ")->execute([
            $code, $cid, (int)$_SESSION['client_user_id'], $title, $category, $description,
            trim($_POST['deliverables'] ?? ''), trim($_POST['reference_links'] ?? ''),
            $is_remote ? null : trim($_POST['location_city'] ?? ''), trim($_POST['location_detail'] ?? ''), $is_remote,
            $start_date, $deadline, $budget > 0 ? $budget : null, $currency, (int)platform_setting('platform_default_priority_hours')
        ]);
        $job_id = (int)$db->lastInsertId();
        notify_staff("🆕 Yeni iş talebi: {$code} · {$title} (" . ($_SESSION['client_user']['company_name'] ?? '') . ")" . ($budget > 0 ? ' · Bütçe: ' . format_money($budget, $currency) : ''), $job_id);
        set_flash('success', "İş talebiniz alındı ({$code}). Ekibimiz inceleyip fiyat ve planlama bilgisini paylaşacak.");
        redirect(BASE_URL . "/platform/job.php?id={$job_id}");
    }
}

$val = fn($k) => e($old[$k] ?? '');
$in = 'w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none';
platform_header('Yeni İş Talebi', 'new');
?>
<div class="max-w-3xl mx-auto" x-data="{ remote: <?= !empty($old['is_remote']) ? 'true' : 'false' ?> }">
    <h1 class="text-2xl font-black text-slate-900">Yeni İş Talebi</h1>
    <p class="text-xs text-slate-500 mt-0.5 mb-6">Ne kadar detay verirseniz fiyatlama ve ekip ataması o kadar hızlı olur.</p>

    <?php if ($errors): ?>
        <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 space-y-1"><?php foreach ($errors as $er): ?><p>• <?= e($er) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="POST" action="" class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm space-y-5">
        <?= csrf_field() ?>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">İş Başlığı *</label>
            <input type="text" name="title" required value="<?= $val('title') ?>" placeholder="Örn: Ürün lansmanı için 1 günlük stüdyo çekimi + 30sn reklam kurgusu" class="<?= $in ?>">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-2">İş Türü *</label>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                <?php foreach (JOB_CATEGORIES as $ck => $cv): ?>
                <label class="cursor-pointer">
                    <input type="radio" name="category" value="<?= $ck ?>" <?= ($old['category'] ?? 'shooting') === $ck ? 'checked' : '' ?> class="peer sr-only">
                    <span class="flex flex-col items-center gap-1 p-3 rounded-xl border border-slate-200 text-[11px] font-semibold text-center peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-800 hover:border-slate-400">
                        <i data-lucide="<?= $cv['icon'] ?>" class="w-5 h-5"></i><?= e($cv['label']) ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Brief / İş Tanımı *</label>
            <textarea name="description" rows="5" required placeholder="Marka, hedef kitle, konsept, ton, mekan, oyuncu/model ihtiyacı, kullanılacak mecralar..." class="<?= $in ?>"><?= $val('description') ?></textarea>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Beklenen Teslimatlar</label>
            <textarea name="deliverables" rows="3" placeholder="- 1 adet 30sn 16:9 master&#10;- 3 adet 15sn dikey Reels&#10;- Ham görüntüler" class="<?= $in ?>"><?= $val('deliverables') ?></textarea>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">Çekim / Başlangıç Tarihi</label>
                <input type="date" name="start_date" value="<?= $val('start_date') ?>" min="<?= date('Y-m-d') ?>" class="<?= $in ?>">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">Teslim Tarihi *</label>
                <input type="date" name="deadline" required value="<?= $val('deadline') ?>" min="<?= date('Y-m-d') ?>" class="<?= $in ?>">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">Bütçeniz (KDV hariç, opsiyonel)</label>
                <div class="flex gap-1">
                    <input type="number" step="0.01" min="0" name="budget" value="<?= $val('budget') ?>" class="<?= $in ?>">
                    <select name="currency" class="py-2.5 px-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                        <?php foreach (CURRENCIES as $ck => $cn): ?><option value="<?= $ck ?>" <?= ($old['currency'] ?? 'TRY') === $ck ? 'selected' : '' ?>><?= $ck ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
            <label class="flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer">
                <input type="checkbox" name="is_remote" value="1" x-model="remote" class="rounded text-indigo-600"> Uzaktan yapılabilir (kurgu, animasyon, renk vb. - lokasyon gerekmez)
            </label>
            <div x-show="!remote" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Şehir *</label>
                    <input type="text" name="location_city" value="<?= $val('location_city') ?>" placeholder="İstanbul" class="<?= $in ?> bg-white">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-600 mb-1">Lokasyon Detayı</label>
                    <input type="text" name="location_detail" value="<?= $val('location_detail') ?>" placeholder="Stüdyo / mekan / ilçe" class="<?= $in ?> bg-white">
                </div>
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Referans Linkler</label>
            <textarea name="reference_links" rows="2" placeholder="Beğendiğiniz örnek videolar, moodboard, brief dosyası linkleri" class="<?= $in ?>"><?= $val('reference_links') ?></textarea>
        </div>
        <div class="flex justify-end gap-3 pt-2">
            <a href="<?= BASE_URL ?>/platform/index.php" class="px-5 py-3 text-sm font-semibold text-slate-500">Vazgeç</a>
            <button type="submit" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl shadow-lg">Talebi Gönder</button>
        </div>
    </form>
</div>
<?php platform_footer();
