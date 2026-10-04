<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞLERİM (AJANS & FREELANCER)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

require_client_login(['agency', 'freelancer']);
$role    = portal_role();
$profile = require_platform_role($role);
$filter  = in_array($_GET['f'] ?? '', ['active', 'completed', 'all', 'applications'], true) ? $_GET['f'] : 'active';

$where = $role === 'agency' ? 'j.agency_contact_id = ?' : 'j.assigned_user_id = ?';
$param = $role === 'agency' ? (int)$_SESSION['client_contact_id'] : (int)$_SESSION['client_user_id'];
if ($filter === 'active') {
    $where .= " AND j.status NOT IN ('completed', 'cancelled')";
} elseif ($filter === 'completed') {
    $where .= " AND j.status IN ('completed', 'cancelled')";
}

$applications = [];
if ($role === 'freelancer' && $filter === 'applications') {
    $st = $db->prepare("SELECT a.*, j.job_code, j.title, j.status AS job_status, j.id AS job_id FROM platform_applications a JOIN platform_jobs j ON a.job_id = j.id WHERE a.user_id = ? ORDER BY a.id DESC");
    $st->execute([(int)$_SESSION['client_user_id']]);
    $applications = $st->fetchAll();
    $jobs = [];
} else {
    $st = $db->prepare("SELECT j.*, NULL AS agency_name FROM platform_jobs j WHERE {$where} ORDER BY j.id DESC");
    $st->execute([$param]);
    $jobs = $st->fetchAll();
}

$tabs = ['active' => 'Devam Eden', 'completed' => 'Tamamlanan / İptal', 'all' => 'Tümü'];
if ($role === 'freelancer') {
    $tabs['applications'] = 'Başvurularım';
}
$app_labels = ['pending' => ['Değerlendiriliyor', 'bg-amber-100 text-amber-800'], 'accepted' => ['Kabul Edildi', 'bg-emerald-100 text-emerald-800'], 'rejected' => ['Başkası Seçildi', 'bg-slate-100 text-slate-600'], 'withdrawn' => ['Geri Çekildi', 'bg-slate-100 text-slate-500']];

platform_header('İşlerim', 'jobs');
?>
<div class="mb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <h1 class="text-2xl font-black text-slate-900">İşlerim</h1>
    <div class="inline-flex bg-white border border-slate-200 rounded-xl p-1 text-xs font-bold">
        <?php foreach ($tabs as $k => $v): ?>
            <a href="?f=<?= $k ?>" class="px-3 py-1.5 rounded-lg <?= $filter === $k ? 'bg-slate-900 text-white' : 'text-slate-500' ?>"><?= $v ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($filter === 'applications'): ?>
    <?php if (!$applications): ?>
        <p class="p-10 text-center bg-white border-2 border-dashed border-slate-200 rounded-2xl text-sm text-slate-500">Henüz başvurunuz yok.</p>
    <?php else: ?>
    <div class="bg-white border border-slate-200 rounded-2xl divide-y divide-slate-100">
        <?php foreach ($applications as $a): $al = $app_labels[$a['status']] ?? [$a['status'], 'bg-slate-100']; ?>
        <a href="<?= BASE_URL ?>/platform/job.php?id=<?= (int)$a['job_id'] ?>" class="flex items-center justify-between p-4 hover:bg-slate-50">
            <div>
                <p class="text-sm font-bold text-slate-900"><span class="font-mono text-[10px] text-slate-400"><?= e($a['job_code']) ?></span> <?= e($a['title']) ?></p>
                <p class="text-[11px] text-slate-500"><?= format_date($a['created_at'], true) ?><?= $a['proposed_fee'] !== null ? ' · Önerdiğiniz ücret: ' . format_money($a['proposed_fee']) : '' ?></p>
            </div>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= $al[1] ?>"><?= $al[0] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php elseif (!$jobs): ?>
    <p class="p-10 text-center bg-white border-2 border-dashed border-slate-200 rounded-2xl text-sm text-slate-500">Bu listede iş yok.</p>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3"><?php foreach ($jobs as $j) platform_job_card($j, $role, false); ?></div>
<?php endif; ?>
<?php platform_footer();
