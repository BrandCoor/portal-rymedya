<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - İŞ MERKEZİ
 * ====================================================================
 * Gelen işler, havuzdaki işler, teklifler, kalite kontrol, üretim
 * ve tamamlanan işler tek ekranda. Yönetici adına iş oluşturma,
 * toplu kalıcı silme (platform.delete).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');
$user = current_user();
$can_delete = can_access_module('platform.delete');
$self = BASE_URL . '/modules/platform/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ---------- Yönetici adına iş oluşturma ----------
    if ($action === 'create_job') {
        $mode     = ($_POST['mode'] ?? '') === 'catalog' ? 'catalog' : 'custom';
        $title    = trim($_POST['title'] ?? '');
        $start    = valid_date($_POST['start_date'] ?? '');
        $deadline = valid_date($_POST['deadline'] ?? '');
        $agency   = !empty($_POST['agency_contact_id']) ? (int)$_POST['agency_contact_id'] : null;
        $remote   = isset($_POST['is_remote']) ? 1 : 0;
        $items    = $mode === 'catalog' ? build_order_items((array)($_POST['qty'] ?? [])) : [];
        $errors   = [];
        if ($title === '' || !$deadline) $errors[] = 'Başlık ve teslim tarihi zorunlu.';
        if ($mode === 'catalog' && !$items) $errors[] = 'En az bir katalog kalemi seçin.';
        $lead = assess_lead_time($start, $deadline, $items);
        if ($lead['level'] === 'block' && empty($_POST['override_lead'])) {
            $errors[] = $lead['message'] . ' Ekip olarak yine de oluşturmak için "termin kuralını aş" seçeneğini işaretleyin.';
        }
        if ($errors) {
            set_flash('error', implode(' ', $errors));
            redirect($self);
        }
        $rush     = $lead['level'] === 'warn' && empty($_POST['no_rush_fee']);
        $category = $mode === 'catalog' ? dominant_category($items) : (array_key_exists($_POST['category'] ?? '', JOB_CATEGORIES) ? $_POST['category'] : 'other');
        if ($mode === 'catalog') {
            $p = price_order($items, $rush);
            $price = $p['agency_price']; $fee = $p['freelancer_fee']; $rush_fee = $p['rush_fee'];
        } else {
            $price = parse_money($_POST['agency_price'] ?? '') ?: null;
            $fee   = parse_money($_POST['freelancer_fee'] ?? '') ?: null;
            $rush_fee = 0;
        }
        $policy = default_job_policy($items, $category, (bool)$remote);
        $code = generate_job_code();
        $db->prepare("
            INSERT INTO platform_jobs (job_code, agency_contact_id, created_by_user_id, title, category, description, deliverables, location_city, is_remote, start_date, deadline,
                agency_price, freelancer_fee, currency, status, pricing_source, is_rush, rush_fee, visibility, dispatch_mode, min_tier, priority_tier, priority_hours, skill_match_only, city_match_only, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TRY', 'submitted', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $code, $agency, $user['id'], $title, $category, trim($_POST['description'] ?? ''), trim($_POST['deliverables'] ?? ''),
            $remote ? null : (trim($_POST['location_city'] ?? '') ?: null), $remote, $start, $deadline, $price, $fee,
            $mode, $lead['level'] === 'warn' ? 1 : 0, $rush_fee,
            $policy['visibility'], $policy['dispatch_mode'], $policy['min_tier'], $policy['priority_tier'], $policy['priority_hours'], $policy['skill_match_only'], $policy['city_match_only'],
        ]);
        $id = (int)$db->lastInsertId();
        if ($items) {
            save_job_items($id, $items);
        }
        log_job_change($id, 'staff', (int)$user['id'], 'Durum', null, 'Ekip tarafından oluşturuldu' . ($lead['level'] === 'block' ? ' (termin kuralı aşıldı)' : ''));
        log_activity('platform', "İş oluşturuldu: {$code} · {$title}", 'job', $id, "/modules/platform/job.php?id={$id}");
        set_flash('success', "{$code} oluşturuldu. Görünürlük kurallarını kontrol edip yayına alabilirsiniz.");
        redirect(BASE_URL . "/modules/platform/job.php?id={$id}");
    }

    // ---------- Toplu kalıcı silme ----------
    if ($action === 'bulk_delete') {
        if (!$can_delete) {
            set_flash('error', 'Kalıcı silme yetkiniz yok.');
            redirect($self);
        }
        $ids = array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $n = 0;
        foreach ($ids as $jid) {
            if (platform_delete_job($jid, !empty($_POST['with_invoices']))) $n++;
        }
        log_activity('platform', "{$n} platform işi kalıcı olarak silindi", 'job', null, '/modules/platform/index.php');
        set_flash('success', "{$n} iş kalıcı olarak silindi.");
        redirect($self . '?' . http_build_query(['g' => $_POST['g'] ?? 'action']));
    }
    redirect($self);
}

$groups = [
    'action'    => ['label' => 'Aksiyon bekleyen', 'statuses' => ['submitted', 'qa_review']],
    'open'      => ['label' => 'Atama', 'statuses' => ['open', 'quote_sent']],
    'active'    => ['label' => 'Üretimde', 'statuses' => ['assigned', 'in_progress', 'revision', 'delivered']],
    'completed' => ['label' => 'Tamamlanan', 'statuses' => ['completed']],
    'cancelled' => ['label' => 'İptal', 'statuses' => ['cancelled']],
    'all'       => ['label' => 'Tümü', 'statuses' => array_keys(JOB_STATUSES)],
];
$g = array_key_exists($_GET['g'] ?? '', $groups) ? $_GET['g'] : 'action';
$q = trim($_GET['q'] ?? '');

$counts = $db->query("SELECT status, COUNT(*) FROM platform_jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$gcount = fn($key) => array_sum(array_map(fn($s) => (int)($counts[$s] ?? 0), $groups[$key]['statuses']));

$in = implode(',', array_fill(0, count($groups[$g]['statuses']), '?'));
$sql = "
    SELECT j.*, c.company_title AS agency_name, u.full_name AS assignee_name,
           (SELECT COUNT(*) FROM platform_applications a WHERE a.job_id = j.id AND a.status = 'pending') AS pending_apps,
           (SELECT COUNT(*) FROM platform_job_issues pi WHERE pi.job_id = j.id AND pi.status = 'open') AS open_issues,
           (SELECT COUNT(*) FROM platform_milestones pm WHERE pm.job_id = j.id AND pm.status IN ('open', 'in_review', 'revision', 'approved')) AS ms_count,
           (SELECT COUNT(*) FROM platform_milestones pm WHERE pm.job_id = j.id AND pm.status = 'approved') AS ms_done
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
// Atama sekmesinde teklifi olan işler öne gelir
$sql .= $g === 'open'
    ? " ORDER BY pending_apps DESC, j.is_rush DESC, COALESCE(j.start_date, j.deadline) ASC LIMIT 300"
    : " ORDER BY FIELD(j.status, 'submitted', 'qa_review', 'quote_sent', 'open', 'revision', 'delivered', 'assigned', 'in_progress', 'completed', 'cancelled'), j.is_rush DESC, j.deadline IS NULL, j.deadline ASC LIMIT 300";
$st = $db->prepare($sql);
$st->execute($params);
$jobs = $st->fetchAll();

$month = $db->query("
    SELECT COALESCE(SUM(agency_price), 0) AS revenue, COALESCE(SUM(CASE WHEN assigned_type = 'freelancer' THEN freelancer_fee ELSE 0 END), 0) AS payouts, COUNT(*) AS cnt
    FROM platform_jobs WHERE status = 'completed' AND YEAR(completed_at) = YEAR(CURRENT_DATE()) AND MONTH(completed_at) = MONTH(CURRENT_DATE())
")->fetch();
$pending_apps_total = (int)$db->query("SELECT COUNT(*) FROM platform_applications a JOIN platform_jobs j ON j.id = a.job_id WHERE a.status = 'pending' AND j.status = 'open'")->fetchColumn();
$late = (int)$db->query("SELECT COUNT(*) FROM platform_jobs WHERE deadline < CURRENT_DATE() AND status IN ('" . implode("','", JOB_ACTIVE_STATUSES) . "')")->fetchColumn();
// Başlangıcına 48 saatten az kalmış ama hâlâ atanmamış işler
$at_risk = $db->query("SELECT id, job_code, title, start_date, deadline FROM platform_jobs WHERE status IN ('submitted', 'quote_sent', 'open') AND COALESCE(start_date, deadline) <= DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY) ORDER BY COALESCE(start_date, deadline) LIMIT 5")->fetchAll();
$issue_jobs = $db->query("SELECT j.id, j.job_code, COUNT(*) AS n FROM platform_job_issues i JOIN platform_jobs j ON j.id = i.job_id WHERE i.status = 'open' GROUP BY j.id, j.job_code ORDER BY MIN(i.created_at) LIMIT 8")->fetchAll();
$pending_people = (int)$db->query("SELECT (SELECT COUNT(*) FROM freelancer_profiles WHERE status = 'pending') + (SELECT COUNT(*) FROM agency_profiles WHERE status = 'pending')")->fetchColumn();
$agencies = $db->query("SELECT id, company_title FROM contacts WHERE type = 'agency' ORDER BY company_title")->fetchAll();
$services = catalog_services();
$catalog_unreviewed = platform_setting('platform_catalog_reviewed') !== '1';

$page_title = 'İş merkezi';
require_once __DIR__ . '/../../includes/header.php';
?>
<div x-data="{ openNew: false, mode: '<?= $services ? 'catalog' : 'custom' ?>', sel: [] }">
<div class="page-head">
    <div>
        <h1 class="h1">İş merkezi</h1>
        <p class="sub">İşler, atamalar, kalite kontrol ve teslimler.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/modules/platform/settings.php" class="btn btn-secondary"><i data-lucide="sliders-horizontal"></i>Kurallar</a>
        <button @click="openNew = true" class="btn btn-primary"><i data-lucide="plus"></i>Yeni iş</button>
    </div>
</div>

<?php if ($catalog_unreviewed && can_access_module('platform.pricing')): ?>
    <div class="alert alert-warning" style="margin-bottom:12px"><i data-lucide="tag"></i><div>Hizmet kataloğu örnek fiyatlarla kuruldu. Ajanslar iş girmeden önce <a class="link" href="<?= BASE_URL ?>/modules/platform/catalog.php">fiyatları gözden geçirin</a>.</div></div>
<?php endif; ?>
<?php if ($pending_people > 0): ?>
    <div class="alert alert-info" style="margin-bottom:12px"><i data-lucide="user-check"></i><div><strong><?= $pending_people ?></strong> yeni ajans / freelancer başvurusu onay bekliyor. <a class="link" href="<?= BASE_URL ?>/modules/platform/freelancers.php?status=pending">Freelancer'lar</a> · <a class="link" href="<?= BASE_URL ?>/modules/platform/agencies.php?status=pending">Ajanslar</a></div></div>
<?php endif; ?>
<?php if ($issue_jobs): ?>
    <div class="alert alert-danger" style="margin-bottom:12px"><i data-lucide="triangle-alert"></i><div>
        <strong>Açık sorun bildirimi:</strong>
        <?php foreach ($issue_jobs as $i => $r): ?><?= $i ? ', ' : ' ' ?><a class="link" href="<?= BASE_URL ?>/modules/platform/job.php?id=<?= (int)$r['id'] ?>#sorunlar"><?= e($r['job_code']) ?></a><?php endforeach; ?>
    </div></div>
<?php endif; ?>
<?php if ($at_risk): ?>
    <div class="alert alert-danger" style="margin-bottom:12px"><i data-lucide="alarm-clock"></i><div>
        <strong>Başlangıcı 48 saat içinde olan atanmamış işler:</strong>
        <?php foreach ($at_risk as $i => $r): ?><?= $i ? ', ' : ' ' ?><a class="link" href="<?= BASE_URL ?>/modules/platform/job.php?id=<?= (int)$r['id'] ?>"><?= e($r['job_code']) ?></a> (<?= format_date($r['start_date'] ?: $r['deadline']) ?>)<?php endforeach; ?>
    </div></div>
<?php endif; ?>

<div class="card" style="margin:12px 0 24px">
    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="kpi"><div class="kpi-label">Onay bekleyen</div><div class="kpi-value"><?= (int)($counts['submitted'] ?? 0) ?></div><div class="kpi-meta">iş / özel talep</div></div>
        <div class="kpi"><div class="kpi-label">Bekleyen teklif</div><div class="kpi-value"><?= $pending_apps_total ?></div><div class="kpi-meta">freelancer teklifi</div></div>
        <div class="kpi"><div class="kpi-label">Kalite kontrol</div><div class="kpi-value" style="<?= ($counts['qa_review'] ?? 0) ? 'color:var(--accent)' : '' ?>"><?= (int)($counts['qa_review'] ?? 0) ?></div><div class="kpi-meta">teslim inceleme</div></div>
        <div class="kpi"><div class="kpi-label">Üretimde</div><div class="kpi-value"><?= $gcount('active') ?></div><div class="kpi-meta"><?= $late ? "<span style='color:var(--danger)'>{$late} geciken</span>" : 'gecikme yok' ?></div></div>
        <div class="kpi"><div class="kpi-label">Bu ay marj</div><div class="kpi-value"><?= format_money((float)$month['revenue'] - (float)$month['payouts']) ?></div><div class="kpi-meta"><?= (int)$month['cnt'] ?> iş · ciro <?= format_money((float)$month['revenue']) ?></div></div>
    </div>
</div>

<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
    <nav class="tabs" style="border:0">
        <?php foreach ($groups as $gk => $gv): ?>
            <a href="?g=<?= $gk ?>" class="tab <?= $g === $gk ? 'is-active' : '' ?>"><?= e($gv['label']) ?><span class="count"><?= $gcount($gk) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <form method="GET" class="searchbox"><input type="hidden" name="g" value="<?= e($g) ?>"><i data-lucide="search"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="İş kodu, başlık, ajans" style="width:240px"></form>
</div>

<form method="POST" action="" class="card" onsubmit="return confirm('Seçili işler ve tüm kayıtları kalıcı olarak silinsin mi? Bu işlem geri alınamaz.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_delete"><input type="hidden" name="g" value="<?= e($g) ?>">
    <?php if ($can_delete): ?>
    <div x-show="sel.length" x-cloak class="card-head" style="background:var(--surface-2)">
        <span class="small"><span x-text="sel.length"></span> iş seçildi</span>
        <div style="display:flex;gap:10px;align-items:center">
            <label class="check xsmall"><input type="checkbox" name="with_invoices" value="1">Faturalarıyla birlikte</label>
            <button class="btn btn-danger-solid btn-sm"><i data-lucide="trash-2"></i>Kalıcı sil</button>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!$jobs): ?>
        <?= ui_empty('Bu listede iş yok', $g === 'action' ? 'Onay veya kalite kontrol bekleyen iş bulunmuyor.' : '', 'inbox') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <?php if ($can_delete): ?><th style="width:32px"><input type="checkbox" @change="sel = $event.target.checked ? [...document.querySelectorAll('[data-jid]')].map(x => x.value) : []"></th><?php endif; ?>
                <th>İş</th><th>Ajans</th><th>Durum</th><th>Atanan</th><th>Tarih</th><th class="r">Ajans</th><th class="r">Freelancer</th><th class="r">Marj</th>
            </tr></thead>
            <tbody>
            <?php foreach ($jobs as $j):
                $margin = ($j['agency_price'] !== null && $j['assigned_type'] !== 'internal' && $j['freelancer_fee'] !== null) ? (float)$j['agency_price'] - (float)$j['freelancer_fee'] : null;
                $is_late = $j['deadline'] && $j['deadline'] < date('Y-m-d') && !in_array($j['status'], ['completed', 'cancelled'], true);
                $url = BASE_URL . '/modules/platform/job.php?id=' . (int)$j['id'];
            ?>
                <tr>
                    <?php if ($can_delete): ?><td><input type="checkbox" name="ids[]" value="<?= (int)$j['id'] ?>" data-jid x-model="sel"></td><?php endif; ?>
                    <td style="max-width:340px">
                        <a href="<?= $url ?>" style="font-weight:500" class="hover:underline"><?= e($j['title']) ?></a>
                        <div class="xsmall text-muted" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:2px">
                            <span class="code-tag"><?= e($j['job_code']) ?></span>
                            <span><?= e(job_category_label($j['category'])) ?></span>
                            <?php if ((int)$j['is_rush'] === 1): ?><?= ui_badge('Acil', 'accent') ?><?php endif; ?>
                            <?php if ($j['pricing_source'] === 'custom'): ?><?= ui_badge('Özel', 'neutral') ?><?php endif; ?>
                            <?php if ((int)$j['pending_apps'] > 0): ?><?= ui_badge($j['pending_apps'] . ' teklif', 'info') ?><?php endif; ?>
                            <?php if ((int)$j['open_issues'] > 0): ?><?= ui_badge('Sorun bildirildi', 'danger', true) ?><?php endif; ?>
                            <?php if ((int)$j['ms_count'] > 1 && !in_array($j['status'], ['completed', 'cancelled'], true)): ?><span class="xsmall text-muted"><?= (int)$j['ms_done'] ?>/<?= (int)$j['ms_count'] ?> aşama</span><?php endif; ?>
                        </div>
                    </td>
                    <td class="small"><?= e($j['agency_name'] ?? 'İç iş') ?></td>
                    <td><?= job_status_badge($j['status']) ?></td>
                    <td class="small"><?= $j['assigned_type'] === 'internal' ? 'Ekibimiz' : e($j['assignee_name'] ?? '—') ?></td>
                    <td class="small" style="white-space:nowrap">
                        <?php if ($j['start_date']): ?><div class="text-muted"><?= format_date($j['start_date']) ?></div><?php endif; ?>
                        <div style="<?= $is_late ? 'color:var(--danger);font-weight:500' : '' ?>"><?= format_date($j['deadline']) ?></div>
                    </td>
                    <td class="r money"><?= $j['agency_price'] !== null ? format_money((float)$j['agency_price'], $j['currency']) : ($j['budget'] !== null ? '<span class="text-muted xsmall">bütçe ' . format_money((float)$j['budget'], $j['currency']) . '</span>' : '—') ?></td>
                    <td class="r num"><?= $j['freelancer_fee'] !== null ? format_money((float)$j['freelancer_fee'], $j['currency']) : '—' ?></td>
                    <td class="r num" style="color:<?= $margin !== null && $margin < 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= $margin !== null ? format_money($margin, $j['currency']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</form>

<!-- YENİ İŞ -->
<div x-show="openNew" x-cloak class="modal-backdrop" @keydown.escape.window="openNew = false">
    <form method="POST" action="" class="modal modal-lg" @click.outside="openNew = false">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_job"><input type="hidden" name="mode" :value="mode">
        <div class="modal-head">
            <div><p class="h3">Yeni iş</p><p class="small text-muted">Telefon / e-posta ile gelen talepler veya kendi dağıtacağınız işler.</p></div>
            <button type="button" class="icon-btn" @click="openNew = false" aria-label="Kapat"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body stack">
            <?php if ($services): ?>
            <div class="seg"><a @click="mode = 'catalog'" :class="mode === 'catalog' && 'is-active'">Katalogdan</a><a @click="mode = 'custom'" :class="mode === 'custom' && 'is-active'">Özel fiyat</a></div>
            <?php endif; ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="field sm:col-span-2"><label class="label">Başlık <span class="req">*</span></label><input class="input" name="title" required></div>
                <div class="field"><label class="label">Ajans</label><select class="select" name="agency_contact_id"><option value="">— İç iş / ajans yok —</option><?php foreach ($agencies as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['company_title']) ?></option><?php endforeach; ?></select></div>
                <div class="field" x-show="mode === 'custom'"><label class="label">İş türü</label><select class="select" name="category"><?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>"><?= e($cv['label']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">Başlangıç / çekim</label><input class="input" type="date" name="start_date"></div>
                <div class="field"><label class="label">Teslim <span class="req">*</span></label><input class="input" type="date" name="deadline" required></div>
                <div class="field"><label class="label">Şehir</label><input class="input" name="location_city"></div>
                <label class="check" style="align-self:end;padding-bottom:8px"><input type="checkbox" name="is_remote" value="1">Uzaktan yapılabilir</label>
            </div>
            <?php if ($services): ?>
            <div x-show="mode === 'catalog'" class="panel" style="padding:4px 0;max-height:260px;overflow-y:auto">
                <?php foreach ($services as $s): ?>
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:7px 14px">
                        <span class="small"><?= e($s['name']) ?> <span class="text-muted xsmall">· <?= format_money((float)$s['agency_price']) ?> / <?= e($s['unit']) ?></span></span>
                        <input class="input input-sm" type="number" min="0" step="0.5" name="qty[<?= (int)$s['id'] ?>]" placeholder="0" style="width:80px">
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div x-show="mode === 'custom'" class="grid grid-cols-2 gap-4">
                <div class="field"><label class="label">Ajans fiyatı (KDV hariç)</label><input class="input" type="number" step="0.01" name="agency_price"></div>
                <div class="field"><label class="label">Freelancer ücreti</label><input class="input" type="number" step="0.01" name="freelancer_fee"></div>
            </div>
            <div class="field"><label class="label">Brief</label><textarea class="textarea" name="description" rows="3"></textarea></div>
            <div class="field"><label class="label">Teslimatlar</label><textarea class="textarea" name="deliverables" rows="2"></textarea></div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <label class="check xsmall"><input type="checkbox" name="override_lead" value="1">Termin kuralını aş (aynı gün / kısa süreli iş)</label>
                <label class="check xsmall" x-show="mode === 'catalog'"><input type="checkbox" name="no_rush_fee" value="1">Acil iş farkı uygulama</label>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" @click="openNew = false">Vazgeç</button>
            <button class="btn btn-primary">Oluştur</button>
        </div>
    </form>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
