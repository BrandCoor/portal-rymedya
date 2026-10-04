<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - İŞ MERKEZİ (DİSPEÇER EKRANI)
 * ====================================================================
 * Ajanslardan gelen talepler, havuzdaki işler, atamalar, kalite kontrol
 * bekleyen teslimler ve tamamlanan işlerin tek ekranda yönetimi.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');

// Yönetici adına iş oluşturma (telefonla gelen talepler veya kendi işleriniz)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_job') {
    verify_csrf();
    $title = trim($_POST['title'] ?? '');
    $deadline = valid_date($_POST['deadline'] ?? '');
    if ($title === '' || !$deadline) {
        set_flash('error', 'Başlık ve teslim tarihi zorunludur.');
        redirect(BASE_URL . '/modules/platform/index.php');
    }
    $agency = !empty($_POST['agency_contact_id']) ? (int)$_POST['agency_contact_id'] : null;
    $price  = parse_money($_POST['agency_price'] ?? '');
    $fee    = parse_money($_POST['freelancer_fee'] ?? '');
    $code   = generate_job_code();
    $db->prepare("
        INSERT INTO platform_jobs (job_code, agency_contact_id, created_by_user_id, title, category, description, deliverables, location_city, is_remote, start_date, deadline, agency_price, freelancer_fee, currency, status, priority_hours, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TRY', 'submitted', ?, NOW())
    ")->execute([
        $code, $agency, $user['id'], $title, array_key_exists($_POST['category'] ?? '', JOB_CATEGORIES) ? $_POST['category'] : 'other',
        trim($_POST['description'] ?? ''), trim($_POST['deliverables'] ?? ''), trim($_POST['location_city'] ?? '') ?: null,
        isset($_POST['is_remote']) ? 1 : 0, valid_date($_POST['start_date'] ?? ''), $deadline,
        $price > 0 ? $price : null, $fee > 0 ? $fee : null, (int)platform_setting('platform_default_priority_hours')
    ]);
    $id = (int)$db->lastInsertId();
    log_activity('platform', "İş oluşturuldu: {$code} · {$title}", 'job', $id, "/modules/platform/job.php?id={$id}");
    set_flash('success', "{$code} oluşturuldu. Görünürlük ve atama ayarlarını yapıp yayınlayabilirsiniz.");
    redirect(BASE_URL . "/modules/platform/job.php?id={$id}");
}

$groups = [
    'action'    => ['label' => 'Aksiyon Bekleyen', 'statuses' => ['submitted', 'qa_review'], 'icon' => 'bell-ring'],
    'open'      => ['label' => 'Havuzda / Atama', 'statuses' => ['open', 'quote_sent'], 'icon' => 'radar'],
    'active'    => ['label' => 'Üretimde', 'statuses' => ['assigned', 'in_progress', 'revision', 'delivered'], 'icon' => 'clapperboard'],
    'completed' => ['label' => 'Tamamlanan', 'statuses' => ['completed'], 'icon' => 'check-circle-2'],
    'cancelled' => ['label' => 'İptal', 'statuses' => ['cancelled'], 'icon' => 'x-circle'],
    'all'       => ['label' => 'Tümü', 'statuses' => array_keys(JOB_STATUSES), 'icon' => 'list'],
];
$g = array_key_exists($_GET['g'] ?? '', $groups) ? $_GET['g'] : 'action';
$q = trim($_GET['q'] ?? '');

$counts = $db->query("SELECT status, COUNT(*) FROM platform_jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$gcount = fn($key) => array_sum(array_map(fn($s) => (int)($counts[$s] ?? 0), $groups[$key]['statuses']));

$in = implode(',', array_fill(0, count($groups[$g]['statuses']), '?'));
$sql = "
    SELECT j.*, c.company_title AS agency_name, u.full_name AS assignee_name,
           (SELECT COUNT(*) FROM platform_applications a WHERE a.job_id = j.id AND a.status = 'pending') AS pending_apps
    FROM platform_jobs j
    LEFT JOIN contacts c ON j.agency_contact_id = c.id
    LEFT JOIN users u ON j.assigned_user_id = u.id
    WHERE j.status IN ({$in})
";
$params = $groups[$g]['statuses'];
if ($q !== '') {
    $sql .= " AND (j.job_code LIKE ? OR j.title LIKE ? OR c.company_title LIKE ?)";
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
}
$sql .= " ORDER BY FIELD(j.status, 'submitted', 'qa_review', 'quote_sent', 'open', 'revision', 'delivered', 'assigned', 'in_progress', 'completed', 'cancelled'), j.deadline IS NULL, j.deadline ASC LIMIT 300";
$st = $db->prepare($sql);
$st->execute($params);
$jobs = $st->fetchAll();

// Bu ayın platform ekonomisi
$month = $db->query("
    SELECT COALESCE(SUM(agency_price), 0) AS revenue, COALESCE(SUM(CASE WHEN assigned_type = 'freelancer' THEN freelancer_fee ELSE 0 END), 0) AS payouts, COUNT(*) AS cnt
    FROM platform_jobs WHERE status = 'completed' AND YEAR(completed_at) = YEAR(CURRENT_DATE()) AND MONTH(completed_at) = MONTH(CURRENT_DATE())
")->fetch();
$pending_people = (int)$db->query("SELECT (SELECT COUNT(*) FROM freelancer_profiles WHERE status = 'pending') + (SELECT COUNT(*) FROM agency_profiles WHERE status = 'pending')")->fetchColumn();
$agencies = $db->query("SELECT c.id, c.company_title FROM contacts c WHERE c.type = 'agency' ORDER BY c.company_title")->fetchAll();

$page_title = 'İş Platformu';
require_once __DIR__ . '/../../includes/header.php';
?>
<div x-data="{ openNew: false }">
<div class="mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">İş Platformu</h1>
        <p class="text-xs text-slate-500 mt-0.5">Ajans talepleri → fiyatlama → ekibe veya freelancer'a dağıtım → kalite kontrol → teslim.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/platform/freelancers.php" class="inline-flex items-center gap-1.5 px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700"><i data-lucide="users" class="w-4 h-4"></i> Freelancer'lar</a>
        <a href="<?= BASE_URL ?>/modules/platform/agencies.php" class="inline-flex items-center gap-1.5 px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700"><i data-lucide="building-2" class="w-4 h-4"></i> Ajanslar</a>
        <a href="<?= BASE_URL ?>/modules/platform/settings.php" class="inline-flex items-center gap-1.5 px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700"><i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Politika Ayarları</a>
        <button @click="openNew = true" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-bold"><i data-lucide="plus" class="w-4 h-4"></i> Yeni İş</button>
    </div>
</div>

<?php if ($pending_people > 0): ?>
    <a href="<?= BASE_URL ?>/modules/platform/freelancers.php?status=pending" class="mb-4 flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-2xl text-sm text-amber-900 hover:bg-amber-100">
        <i data-lucide="user-check" class="w-4 h-4"></i> <strong><?= $pending_people ?></strong> yeni ajans / freelancer başvurusu onayınızı bekliyor →
    </a>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
    <?php foreach ([
        ['Yeni Talep', (int)($counts['submitted'] ?? 0), 'text-sky-600'],
        ['Kalite Kontrol', (int)($counts['qa_review'] ?? 0), 'text-purple-600'],
        ['Havuzda', (int)($counts['open'] ?? 0), 'text-indigo-600'],
        ['Üretimde', $gcount('active'), 'text-cyan-600'],
        ['Bu Ay Marj', format_money((float)$month['revenue'] - (float)$month['payouts']), 'text-emerald-600'],
    ] as [$l, $v, $cls]): ?>
    <div class="bg-white p-4 rounded-2xl border border-slate-200">
        <p class="text-[11px] font-bold text-slate-400 uppercase"><?= $l ?></p>
        <p class="text-xl font-black mt-1 <?= $cls ?>"><?= $v ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="mb-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div class="flex flex-wrap gap-2">
        <?php foreach ($groups as $gk => $gv): ?>
            <a href="?g=<?= $gk ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold border <?= $g === $gk ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200' ?>">
                <i data-lucide="<?= $gv['icon'] ?>" class="w-3.5 h-3.5"></i><?= $gv['label'] ?> <span class="opacity-70">(<?= $gcount($gk) ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="GET"><input type="hidden" name="g" value="<?= e($g) ?>">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="İş kodu, başlık, ajans..." class="py-2 px-3 bg-white border border-slate-200 rounded-xl text-xs w-60"></form>
</div>

<div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead><tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
            <th class="py-3 px-4 text-left">İş</th><th class="py-3 px-4 text-left">Ajans</th><th class="py-3 px-4 text-left">Durum</th>
            <th class="py-3 px-4 text-left">Atanan</th><th class="py-3 px-4 text-left">Teslim</th><th class="py-3 px-4 text-right">Ajans Fiyatı</th><th class="py-3 px-4 text-right">Freelancer</th><th class="py-3 px-4 text-right">Marj</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (!$jobs): ?>
                <tr><td colspan="8" class="py-12 text-center text-slate-400">Bu listede iş yok.</td></tr>
            <?php else: foreach ($jobs as $j):
                $margin = ($j['agency_price'] !== null && $j['assigned_type'] !== 'internal' && $j['freelancer_fee'] !== null) ? (float)$j['agency_price'] - (float)$j['freelancer_fee'] : null;
                $late = $j['deadline'] && $j['deadline'] < date('Y-m-d') && !in_array($j['status'], ['completed', 'cancelled'], true);
            ?>
            <tr class="hover:bg-slate-50">
                <td class="py-3 px-4">
                    <a href="<?= BASE_URL ?>/modules/platform/job.php?id=<?= (int)$j['id'] ?>" class="font-bold text-slate-900 hover:text-brand-600"><?= e($j['title']) ?></a>
                    <div class="text-[10px] text-slate-400"><span class="font-mono"><?= e($j['job_code']) ?></span> · <?= e(job_category_label($j['category'])) ?>
                        <?php if ((int)$j['pending_apps'] > 0): ?><span class="ml-1 px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 font-bold"><?= (int)$j['pending_apps'] ?> başvuru</span><?php endif; ?>
                    </div>
                </td>
                <td class="py-3 px-4 text-slate-700"><?= e($j['agency_name'] ?? 'İç iş') ?></td>
                <td class="py-3 px-4"><?= job_status_badge($j['status']) ?></td>
                <td class="py-3 px-4 text-slate-700"><?= $j['assigned_type'] === 'internal' ? '🏢 Ekibimiz' : e($j['assignee_name'] ?? '-') ?></td>
                <td class="py-3 px-4 <?= $late ? 'text-rose-600 font-bold' : 'text-slate-600' ?>"><?= format_date($j['deadline']) ?></td>
                <td class="py-3 px-4 text-right font-semibold"><?= $j['agency_price'] !== null ? format_money($j['agency_price'], $j['currency']) : ($j['budget'] !== null ? '<span class="text-slate-400">Bütçe: ' . format_money($j['budget'], $j['currency']) . '</span>' : '-') ?></td>
                <td class="py-3 px-4 text-right"><?= $j['freelancer_fee'] !== null ? format_money($j['freelancer_fee'], $j['currency']) : '-' ?></td>
                <td class="py-3 px-4 text-right font-bold <?= $margin !== null && $margin < 0 ? 'text-rose-600' : 'text-emerald-700' ?>"><?= $margin !== null ? format_money($margin, $j['currency']) : '-' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- YENİ İŞ MODALI -->
<div x-show="openNew" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 flex items-center justify-center p-4">
    <form method="POST" action="" class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-3" @click.away="openNew = false">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_job">
        <h3 class="text-base font-bold text-slate-900">Yeni Platform İşi</h3>
        <p class="text-xs text-slate-500">Telefonla/e-postayla gelen talepler veya kendi dağıtmak istediğiniz işler için.</p>
        <?php $fi = 'w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs'; ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="sm:col-span-2"><label class="block text-xs font-bold text-slate-600 mb-1">Başlık *</label><input type="text" name="title" required class="<?= $fi ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Ajans (iş veren)</label>
                <select name="agency_contact_id" class="<?= $fi ?>"><option value="">— İç iş / ajans yok —</option>
                    <?php foreach ($agencies as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['company_title']) ?></option><?php endforeach; ?></select></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">İş Türü</label>
                <select name="category" class="<?= $fi ?>"><?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>"><?= e($cv['label']) ?></option><?php endforeach; ?></select></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Başlangıç / Çekim</label><input type="date" name="start_date" class="<?= $fi ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Teslim *</label><input type="date" name="deadline" required class="<?= $fi ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Ajans Fiyatı (KDV hariç)</label><input type="number" step="0.01" name="agency_price" class="<?= $fi ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Freelancer Ücreti</label><input type="number" step="0.01" name="freelancer_fee" class="<?= $fi ?>"></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Şehir</label><input type="text" name="location_city" class="<?= $fi ?>"></div>
            <label class="flex items-center gap-2 text-xs font-bold text-slate-700 mt-5"><input type="checkbox" name="is_remote" value="1" class="rounded"> Uzaktan yapılabilir</label>
            <div class="sm:col-span-2"><label class="block text-xs font-bold text-slate-600 mb-1">Brief</label><textarea name="description" rows="3" class="<?= $fi ?>"></textarea></div>
            <div class="sm:col-span-2"><label class="block text-xs font-bold text-slate-600 mb-1">Teslimatlar</label><textarea name="deliverables" rows="2" class="<?= $fi ?>"></textarea></div>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="openNew = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
            <button class="px-5 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl">Oluştur</button>
        </div>
    </form>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
