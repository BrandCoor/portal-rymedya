<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞ HAVUZU (FREELANCER)
 * ====================================================================
 * Yalnızca platform politikasına göre bu freelancer'a görünür kılınan
 * açık işler listelenir (seviye, öncelik süresi, uzmanlık, şehir, seçili liste).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile = require_platform_role('freelancer');
$jobs = visible_pool_jobs($profile);

$cat = $_GET['category'] ?? '';
if ($cat !== '' && array_key_exists($cat, JOB_CATEGORIES)) {
    $jobs = array_values(array_filter($jobs, fn($j) => $j['category'] === $cat));
}
$mine_only = !empty($_GET['skills']);
if ($mine_only) {
    $skills = array_filter(explode(',', (string)$profile['skills']));
    $jobs = array_values(array_filter($jobs, fn($j) => in_array($j['category'], $skills, true)));
}

$active = freelancer_active_job_count((int)$profile['user_id']);
$limit  = (int)platform_setting('platform_max_active_jobs');
$pool_open = platform_setting('platform_pool_enabled') === '1';

platform_header('İş Havuzu', 'pool');
?>
<div class="mb-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black text-slate-900">İş Havuzu</h1>
        <p class="text-xs text-slate-500 mt-0.5">Size açılmış işler. "İlk alan alır" işlerde hızlı olun; başvurulu işlerde ekip sizi seçer.</p>
    </div>
    <form method="GET" class="flex flex-wrap items-center gap-2 text-xs">
        <select name="category" onchange="this.form.submit()" class="py-2 px-3 bg-white border border-slate-200 rounded-xl">
            <option value="">Tüm iş türleri</option>
            <?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>" <?= $cat === $ck ? 'selected' : '' ?>><?= e($cv['label']) ?></option><?php endforeach; ?>
        </select>
        <label class="flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 rounded-xl cursor-pointer">
            <input type="checkbox" name="skills" value="1" <?= $mine_only ? 'checked' : '' ?> onchange="this.form.submit()" class="rounded text-emerald-600"> Sadece uzmanlıklarım
        </label>
    </form>
</div>

<?php if (!$pool_open): ?>
    <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-2xl text-sm text-amber-800">İş havuzu şu an kapalıdır. Size doğrudan atanan işler "İşlerim" sayfasında görünür.</div>
<?php elseif ($active >= $limit): ?>
    <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-2xl text-sm text-amber-800">Eşzamanlı iş limitinize ulaştınız (<?= $active ?>/<?= $limit ?>). Mevcut işlerinizi teslim ettikçe yeni iş alabilirsiniz.</div>
<?php endif; ?>

<?php if (!$jobs): ?>
    <div class="p-12 text-center bg-white border-2 border-dashed border-slate-200 rounded-2xl">
        <i data-lucide="radar" class="w-10 h-10 mx-auto text-slate-300 mb-2"></i>
        <p class="text-sm text-slate-500">Şu an size uygun açık iş bulunmuyor.</p>
        <p class="text-xs text-slate-400 mt-1">Profilinizdeki uzmanlık alanlarını ve şehir bilginizi güncel tutun.</p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <?php foreach ($jobs as $j): ?>
        <div class="relative">
            <?php platform_job_card($j, 'freelancer', false); ?>
            <span class="absolute bottom-3 right-4 text-[10px] font-bold <?= $j['dispatch_mode'] === 'first_come' ? 'text-emerald-600' : 'text-indigo-600' ?>">
                <?= $j['dispatch_mode'] === 'first_come' ? '⚡ İlk alan alır' : '📝 Başvuru ile' ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php platform_footer();
