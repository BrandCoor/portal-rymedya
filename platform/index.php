<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - AJANS / FREELANCER PANELİ
 * ====================================================================
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

platform_header('Panel', 'index');

// ====================================================================
// ONAY BEKLEYEN HESAP
// ====================================================================
if ($profile['status'] !== 'approved'): ?>
    <div class="max-w-xl mx-auto text-center bg-white border border-slate-200 rounded-3xl p-8 shadow-sm">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center mb-4"><i data-lucide="hourglass" class="w-8 h-8"></i></div>
        <h1 class="text-xl font-black text-slate-900">Hesabınız İnceleniyor</h1>
        <p class="text-sm text-slate-600 mt-2">
            <?= $role === 'agency'
                ? 'Ajans hesabınız platform ekibimiz tarafından onaylandığında iş talebi oluşturabileceksiniz.'
                : 'Freelancer başvurunuz değerlendiriliyor. Onaylandığınızda size uygun işler iş havuzunuzda görünecek.' ?>
        </p>
        <p class="text-xs text-slate-400 mt-4">Bu sürede profil bilgilerinizi eksiksiz doldurmanız onay sürecini hızlandırır.</p>
        <a href="<?= BASE_URL ?>/platform/profile.php" class="inline-flex mt-5 px-5 py-2.5 bg-slate-900 text-white text-sm font-bold rounded-xl">Profilimi Düzenle</a>
    </div>
<?php platform_footer(); exit; endif;

// ====================================================================
// AJANS PANELİ
// ====================================================================
if ($role === 'agency'):
    $st = $db->prepare("SELECT j.*, NULL AS agency_name FROM platform_jobs j WHERE j.agency_contact_id = ? ORDER BY j.id DESC");
    $st->execute([$cid]);
    $jobs = $st->fetchAll();
    $count = fn(array $statuses) => count(array_filter($jobs, fn($j) => in_array($j['status'], $statuses, true)));
    $action_jobs = array_filter($jobs, fn($j) => in_array($j['status'], ['quote_sent', 'delivered'], true));
    $active_jobs = array_filter($jobs, fn($j) => !in_array($j['status'], ['completed', 'cancelled'], true));
    $balance = recalculate_contact_balance($cid);
?>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Merhaba, <?= e($_SESSION['client_user']['full_name'] ?? '') ?> 👋</h1>
            <p class="text-xs text-slate-500 mt-0.5">İş taleplerinizi oluşturun, ilerlemeyi takip edin, teslimatları onaylayın.</p>
        </div>
        <a href="<?= BASE_URL ?>/platform/job_new.php" class="inline-flex items-center gap-2 px-5 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-indigo-600/30">
            <i data-lucide="plus" class="w-4 h-4"></i> Yeni İş Talebi
        </a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <?php foreach ([
            ['Devam Eden İşler', count($active_jobs), 'briefcase', 'text-indigo-600'],
            ['Onayınızı Bekleyen', count($action_jobs), 'bell-ring', 'text-amber-600'],
            ['Tamamlanan', $count(['completed']), 'check-circle-2', 'text-emerald-600'],
            ['Açık Bakiyeniz', format_money(max(0, $balance)), 'wallet', 'text-slate-900'],
        ] as [$lbl, $v, $ic, $cls]): ?>
        <div class="bg-white p-4 rounded-2xl border border-slate-200">
            <div class="flex items-center justify-between"><p class="text-[11px] font-bold text-slate-400 uppercase"><?= $lbl ?></p><i data-lucide="<?= $ic ?>" class="w-4 h-4 <?= $cls ?>"></i></div>
            <p class="text-xl font-black mt-1 <?= $cls ?>"><?= $v ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($action_jobs): ?>
    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-2xl">
        <h2 class="text-sm font-bold text-amber-900 mb-3 flex items-center gap-2"><i data-lucide="bell-ring" class="w-4 h-4"></i> Sizden Aksiyon Bekleyen İşler</h2>
        <div class="space-y-2"><?php foreach ($action_jobs as $j) platform_job_card($j, 'agency', false); ?></div>
    </div>
    <?php endif; ?>

    <h2 class="text-sm font-bold text-slate-800 mb-3">Devam Eden İşleriniz</h2>
    <?php if (!$active_jobs): ?>
        <div class="p-10 text-center bg-white border-2 border-dashed border-slate-200 rounded-2xl text-sm text-slate-500">
            Henüz aktif iş talebiniz yok. <a href="<?= BASE_URL ?>/platform/job_new.php" class="font-bold text-indigo-600">İlk işinizi oluşturun →</a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3"><?php foreach ($active_jobs as $j) platform_job_card($j, 'agency', false); ?></div>
    <?php endif; ?>

<?php
// ====================================================================
// FREELANCER PANELİ
// ====================================================================
else:
    $st = $db->prepare("SELECT j.*, NULL AS agency_name FROM platform_jobs j WHERE j.assigned_user_id = ? AND j.status NOT IN ('completed', 'cancelled') ORDER BY j.deadline IS NULL, j.deadline ASC");
    $st->execute([$uid]);
    $my_jobs = $st->fetchAll();
    $pool = visible_pool_jobs($profile);
    $pending_earn = $db->prepare("SELECT COALESCE(SUM(i.grand_total - i.paid_amount), 0) FROM platform_jobs j JOIN invoices i ON i.id = j.purchase_invoice_id WHERE j.assigned_user_id = ? AND i.payment_status != 'paid'");
    $pending_earn->execute([$uid]);
    $tier = FREELANCER_TIERS[$profile['tier']] ?? FREELANCER_TIERS['standard'];
    $limit = (int)platform_setting('platform_max_active_jobs');
    $show_agency = platform_setting('platform_show_agency_name') === '1';
?>
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Merhaba, <?= e($_SESSION['client_user']['full_name'] ?? '') ?> 👋</h1>
            <p class="text-xs text-slate-500 mt-1 flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-full border text-[10px] font-bold <?= $tier['color'] ?>"><?= $tier['label'] ?> Seviye</span>
                <?= render_stars($profile['rating_avg'] !== null ? (float)$profile['rating_avg'] : null) ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/platform/pool.php" class="inline-flex items-center gap-2 px-5 py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-emerald-600/30">
            <i data-lucide="radar" class="w-4 h-4"></i> İş Havuzu (<?= count($pool) ?>)
        </a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <?php foreach ([
            ['Aktif İşlerim', count($my_jobs) . ' / ' . $limit, 'briefcase', 'text-cyan-600'],
            ['Size Uygun İşler', count($pool), 'radar', 'text-emerald-600'],
            ['Tamamlanan', (int)$profile['completed_jobs'], 'check-circle-2', 'text-slate-900'],
            ['Bekleyen Hakediş', format_money((float)$pending_earn->fetchColumn()), 'wallet', 'text-amber-600'],
        ] as [$lbl, $v, $ic, $cls]): ?>
        <div class="bg-white p-4 rounded-2xl border border-slate-200">
            <div class="flex items-center justify-between"><p class="text-[11px] font-bold text-slate-400 uppercase"><?= $lbl ?></p><i data-lucide="<?= $ic ?>" class="w-4 h-4 <?= $cls ?>"></i></div>
            <p class="text-xl font-black mt-1 <?= $cls ?>"><?= $v ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ((int)$profile['is_available'] !== 1): ?>
        <div class="mb-4 p-3 bg-slate-100 border border-slate-200 rounded-xl text-xs text-slate-600">Durumunuz <strong>"Müsait Değil"</strong>. Yeni iş alamazsınız; profilinizden değiştirebilirsiniz.</div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
            <h2 class="text-sm font-bold text-slate-800 mb-3">Üzerimdeki İşler</h2>
            <?php if (!$my_jobs): ?>
                <p class="p-8 text-center bg-white border-2 border-dashed border-slate-200 rounded-2xl text-sm text-slate-500">Şu an üzerinizde iş yok.</p>
            <?php else: ?>
                <div class="space-y-2"><?php foreach ($my_jobs as $j) platform_job_card($j, 'freelancer', false); ?></div>
            <?php endif; ?>
        </div>
        <div>
            <h2 class="text-sm font-bold text-slate-800 mb-3">Havuzdaki Yeni İşler</h2>
            <?php if (!$pool): ?>
                <p class="p-8 text-center bg-white border-2 border-dashed border-slate-200 rounded-2xl text-sm text-slate-500">Şu an size uygun açık iş yok. Yeni işler yayınlandığında bildirim alırsınız.</p>
            <?php else: ?>
                <div class="space-y-2"><?php foreach (array_slice($pool, 0, 5) as $j) platform_job_card($j, 'freelancer', false); ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif;

platform_footer();
