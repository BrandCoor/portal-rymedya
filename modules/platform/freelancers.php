<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - FREELANCER'LAR (PERFORMANS KARNELERİ)
 * ====================================================================
 * Başvuru onayı, seviye (otomatik / sabitlenmiş), eşzamanlı iş limiti,
 * performans metrikleri, askıya alma, kalıcı silme (platform.delete).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');
$can_delete = can_access_module('platform.delete');
$back = BASE_URL . '/modules/platform/freelancers.php?' . http_build_query(array_filter(['status' => $_GET['status'] ?? null, 'tier' => $_GET['tier'] ?? null, 'q' => $_GET['q'] ?? null]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'recompute_all') {
        $n = 0;
        foreach ($db->query("SELECT user_id FROM freelancer_profiles")->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            recompute_freelancer_metrics((int)$fid);
            $n++;
        }
        set_flash('success', "{$n} freelancer'ın performans puanı ve seviyesi yeniden hesaplandı.");
        redirect($back);
    }

    $uid = (int)($_POST['user_id'] ?? 0);
    $fp = $db->prepare("SELECT fp.*, u.full_name FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.user_id = ?");
    $fp->execute([$uid]);
    $f = $fp->fetch();
    if (!$f) {
        set_flash('error', 'Freelancer bulunamadı.');
        redirect($back);
    }

    if (in_array($action, ['approve', 'suspend'], true)) {
        $new = $action === 'suspend' ? 'suspended' : 'approved';
        $db->prepare("UPDATE freelancer_profiles SET status = ? WHERE user_id = ?")->execute([$new, $uid]);
        if ($new === 'approved') {
            notify_user($uid, 'Freelancer hesabınız onaylandı. Size uygun işler iş havuzunuzda listelenir.', '/platform/pool.php');
        }
        log_activity('platform', "Freelancer {$f['full_name']}: " . ($new === 'approved' ? 'onaylandı' : 'askıya alındı'), 'freelancer', $uid, '/modules/platform/freelancers.php');
        set_flash('success', "{$f['full_name']} " . ($new === 'approved' ? 'onaylandı.' : 'askıya alındı.'));
    }
    if ($action === 'save') {
        $tier   = array_key_exists($_POST['tier'] ?? '', FREELANCER_TIERS) ? $_POST['tier'] : $f['tier'];
        $skills = array_values(array_intersect(array_keys(JOB_CATEGORIES), (array)($_POST['skills'] ?? [])));
        $status = in_array($_POST['status'] ?? '', ['pending', 'approved', 'suspended'], true) ? $_POST['status'] : $f['status'];
        // Elle değiştirilen seviye otomatik hesaplamayla ezilmesin diye sabitlenir
        $locked = isset($_POST['tier_locked']) || $tier !== $f['tier'] ? 1 : 0;
        $db->prepare("UPDATE freelancer_profiles SET tier = ?, tier_locked = ?, skills = ?, status = ?, city = ?, admin_notes = ?, is_available = ? WHERE user_id = ?")
           ->execute([$tier, $locked, implode(',', $skills), $status, trim($_POST['city'] ?? ''), trim($_POST['admin_notes'] ?? ''), isset($_POST['is_available']) ? 1 : 0, $uid]);
        if ($tier !== $f['tier']) {
            $up = tier_rank($tier) > tier_rank($f['tier']);
            notify_user($uid, ($up ? 'Seviyeniz yükseltildi: ' : 'Seviyeniz güncellendi: ') . tier_label($tier) . '. Aynı anda alabileceğiniz iş sayısı: ' . tier_job_limit($tier), '/platform/performance.php');
        }
        if ($status === 'approved' && $f['status'] !== 'approved') {
            notify_user($uid, 'Freelancer hesabınız onaylandı. Size uygun işler iş havuzunuzda listelenir.', '/platform/pool.php');
        }
        if (!$locked && platform_setting('platform_auto_tier') === '1') {
            recompute_freelancer_metrics($uid);
        }
        set_flash('success', "{$f['full_name']} güncellendi." . ($tier !== $f['tier'] ? ' Seviye sabitlendi; otomatik seviye değişmeyecek.' : ''));
    }
    if ($action === 'recompute') {
        $p = recompute_freelancer_metrics($uid);
        set_flash('success', "{$f['full_name']}: puan " . ($p['score'] !== null ? number_format((float)$p['score'], 1, ',', '') : '—') . ', seviye ' . tier_label($p['tier']) . '.');
    }
    if ($action === 'delete') {
        if (!$can_delete) {
            set_flash('error', 'Kalıcı silme yetkiniz yok.');
            redirect($back);
        }
        [$ok, $msg] = platform_delete_account($uid);
        if ($ok) {
            log_activity('platform', "Freelancer hesabı silindi: {$f['full_name']}", 'freelancer', null, '/modules/platform/freelancers.php');
        }
        set_flash($ok ? 'success' : 'error', $msg);
    }
    redirect($back);
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'suspended'], true) ? $_GET['status'] : '';
$tier   = array_key_exists($_GET['tier'] ?? '', FREELANCER_TIERS) ? $_GET['tier'] : '';
$skill  = array_key_exists($_GET['skill'] ?? '', JOB_CATEGORIES) ? $_GET['skill'] : '';
$q      = trim($_GET['q'] ?? '');
$focus  = (int)($_GET['focus'] ?? 0);
$sort   = in_array($_GET['sort'] ?? '', ['score', 'jobs', 'new'], true) ? $_GET['sort'] : 'score';

$sql = "
    SELECT fp.*, u.full_name, u.email, u.phone, u.last_login, u.created_at AS user_created, c.iban,
           (SELECT COALESCE(SUM(pj.freelancer_fee), 0) FROM platform_jobs pj WHERE pj.assigned_user_id = fp.user_id AND pj.status = 'completed') AS total_earned,
           (SELECT COUNT(*) FROM platform_applications pa WHERE pa.user_id = fp.user_id AND pa.status = 'pending') AS pending_offers
    FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id LEFT JOIN contacts c ON c.id = fp.contact_id
    WHERE 1=1
";
$params = [];
if ($status) { $sql .= " AND fp.status = ?"; $params[] = $status; }
if ($tier)   { $sql .= " AND fp.tier = ?"; $params[] = $tier; }
if ($skill)  { $sql .= " AND FIND_IN_SET(?, fp.skills)"; $params[] = $skill; }
if ($focus)  { $sql .= " AND fp.user_id = ?"; $params[] = $focus; }
if ($q !== '') { $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR fp.city LIKE ? OR fp.title LIKE ?)"; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"); }
$sql .= " ORDER BY FIELD(fp.status, 'pending', 'approved', 'suspended'), " . [
    'score' => "fp.score IS NULL, fp.score DESC, FIELD(fp.tier, 'elite', 'gold', 'silver', 'standard')",
    'jobs'  => "fp.completed_jobs DESC",
    'new'   => "u.created_at DESC",
][$sort];
$st = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $r['cap'] = freelancer_capacity($r);
}
unset($r);
$counts = $db->query("SELECT status, COUNT(*) FROM freelancer_profiles GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$tier_counts = $db->query("SELECT tier, COUNT(*) FROM freelancer_profiles WHERE status = 'approved' GROUP BY tier")->fetchAll(PDO::FETCH_KEY_PAIR);
$auto_tier = platform_setting('platform_auto_tier') === '1';
$status_badge = ['pending' => ['Onay bekliyor', 'warning'], 'approved' => ['Onaylı', 'success'], 'suspended' => ['Askıda', 'danger']];
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['status' => $status, 'tier' => $tier, 'skill' => $skill, 'q' => $q, 'sort' => $sort !== 'score' ? $sort : ''], $over), fn($v) => $v !== '' && $v !== null));

$page_title = "Freelancer'lar";
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Freelancer'lar</span></div>
        <h1 class="h1">Freelancer'lar</h1>
        <p class="sub">Seviye; işleri kimin göreceğini ve aynı anda kaç iş alınabileceğini belirler. <?= $auto_tier ? 'Seviyeler performans puanına göre otomatik güncellenir.' : 'Otomatik seviye kapalı.' ?></p>
    </div>
    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="recompute_all"><button class="btn btn-secondary"><i data-lucide="refresh-cw"></i>Puanları yeniden hesapla</button></form>
</div>

<div class="card" style="margin-bottom:20px">
    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
        <?php foreach (FREELANCER_TIERS as $tk => $tv): $rule = FREELANCER_TIER_RULES[$tk] ?? null; ?>
            <a href="<?= $qs(['tier' => $tier === $tk ? '' : $tk]) ?>" class="kpi" style="<?= $tier === $tk ? 'background:var(--surface-2)' : '' ?>">
                <div class="kpi-label"><?= tier_badge($tk) ?><span class="num"><?= tier_job_limit($tk) ?> iş</span></div>
                <div class="kpi-value"><?= (int)($tier_counts[$tk] ?? 0) ?></div>
                <div class="kpi-meta"><?= $rule ? "puan {$rule['score']}+ · {$rule['jobs']}+ iş" : 'başlangıç seviyesi' ?></div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
    <nav class="tabs" style="border:0">
        <?php foreach (['' => 'Tümü', 'pending' => 'Onay bekleyen', 'approved' => 'Onaylı', 'suspended' => 'Askıda'] as $sk => $sv): ?>
            <a href="<?= $qs(['status' => $sk, 'focus' => null]) ?>" class="tab <?= $status === $sk && !$focus ? 'is-active' : '' ?>"><?= $sv ?><span class="count"><?= $sk === '' ? array_sum($counts) : (int)($counts[$sk] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <select class="select" style="height:34px;width:auto" onchange="location.href=this.value">
            <option value="<?= e($qs(['skill' => ''])) ?>">Tüm uzmanlıklar</option>
            <?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= e($qs(['skill' => $ck])) ?>" <?= $skill === $ck ? 'selected' : '' ?>><?= e($cv['label']) ?></option><?php endforeach; ?>
        </select>
        <select class="select" style="height:34px;width:auto" onchange="location.href=this.value">
            <?php foreach (['score' => 'Puana göre', 'jobs' => 'İş sayısına göre', 'new' => 'En yeni'] as $sk => $sl): ?><option value="<?= e($qs(['sort' => $sk === 'score' ? '' : $sk])) ?>" <?= $sort === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?>
        </select>
        <form method="GET" class="searchbox"><?php foreach (['status' => $status, 'tier' => $tier, 'skill' => $skill] as $hk => $hv): if ($hv): ?><input type="hidden" name="<?= $hk ?>" value="<?= e($hv) ?>"><?php endif; endforeach; ?><i data-lucide="search"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Ad, e-posta, şehir" style="width:200px"></form>
    </div>
</div>

<?php if ($focus): ?><p class="small" style="margin-bottom:10px"><a class="link" href="<?= $qs(['focus' => null]) ?>">Tüm listeye dön</a></p><?php endif; ?>

<?php if (!$rows): ?>
    <div class="card"><?= ui_empty('Bu listede freelancer yok', 'Freelancer\'lar giriş sayfasındaki başvuru bağlantısıyla kayıt olur.', 'users') ?></div>
<?php else: ?>
<div class="stack-sm">
<?php foreach ($rows as $f):
    [$sl, $stn] = $status_badge[$f['status']] ?? [$f['status'], 'neutral'];
    $fskills = array_filter(explode(',', (string)$f['skills']));
    $incidents = (int)$f['releases_count'] + (int)$f['removed_count'];
    $suggested = $f['score'] !== null ? suggested_tier((float)$f['score'], (int)$f['completed_jobs']) : null;
?>
    <div class="card" x-data="{ open: <?= $focus === (int)$f['user_id'] ? 'true' : 'false' ?>, del: false }">
        <div class="card-pad-sm" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
            <?= ui_score_ring($f['score'] !== null ? (float)$f['score'] : null, 'puan', 58) ?>
            <div style="flex:1;min-width:220px">
                <p style="font-weight:500;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <?= e($f['full_name']) ?> <?= tier_badge($f['tier']) ?>
                    <?php if ((int)$f['tier_locked'] === 1): ?><span title="Seviye sabitlendi" style="display:inline-flex;color:var(--muted)"><i data-lucide="lock" style="width:13px;height:13px"></i></span><?php endif; ?>
                    <?= ui_badge($sl, $stn, true) ?>
                    <?php if ((int)$f['is_available'] !== 1): ?><?= ui_badge('Müsait değil', 'neutral') ?><?php endif; ?>
                </p>
                <p class="xsmall text-muted" style="margin-top:3px"><?= e($f['title'] ?: '—') ?> · <?= e($f['city'] ?: '—') ?> · <?= e(implode(', ', array_map('job_category_label', $fskills)) ?: 'uzmanlık seçilmedi') ?></p>
                <?php if ($suggested && $suggested !== $f['tier']): ?><p class="xsmall" style="margin-top:3px;color:var(--warning)">Performansa göre önerilen seviye: <?= e(tier_label($suggested)) ?></p><?php endif; ?>
            </div>
            <div class="small" style="display:grid;grid-template-columns:repeat(5,auto);gap:4px 22px;text-align:right">
                <span class="xsmall text-muted">Aktif</span><span class="xsmall text-muted">Tamamlanan</span><span class="xsmall text-muted">Zamanında</span><span class="xsmall text-muted">KK ilk sefer</span><span class="xsmall text-muted">Puan ort.</span>
                <span class="num" style="<?= !$f['cap']['can_take'] ? 'color:var(--warning)' : '' ?>"><?= $f['cap']['active'] ?>/<?= $f['cap']['limit'] ?></span>
                <span class="num"><?= (int)$f['completed_jobs'] ?></span>
                <span class="num"><?= $f['on_time_rate'] !== null ? '%' . number_format((float)$f['on_time_rate'], 0) : '—' ?></span>
                <span class="num"><?= $f['qa_pass_rate'] !== null ? '%' . number_format((float)$f['qa_pass_rate'], 0) : '—' ?></span>
                <span class="num"><?= $f['rating_avg'] !== null || $f['agency_rating_avg'] !== null ? number_format((float)($f['rating_avg'] ?? $f['agency_rating_avg']), 1, ',', '') : '—' ?></span>
            </div>
            <div style="display:flex;gap:6px">
                <?php if ($f['status'] === 'pending' || $f['status'] === 'suspended'): ?>
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>"><button class="btn btn-primary btn-sm"><?= $f['status'] === 'pending' ? 'Onayla' : 'Yeniden aç' ?></button></form>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm" @click="open = !open"><span x-text="open ? 'Kapat' : 'Karne'"></span></button>
            </div>
        </div>

        <div x-show="open" x-cloak class="card-foot" style="background:var(--surface)">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" style="padding:6px 0">
                <div class="stack">
                    <p class="eyebrow">Performans</p>
                    <?= ui_meter('Zamanında teslim', $f['on_time_rate'] !== null ? (float)$f['on_time_rate'] : null) ?>
                    <?= ui_meter('Kalite kontrol ilk sefer', $f['qa_pass_rate'] !== null ? (float)$f['qa_pass_rate'] : null) ?>
                    <?= ui_meter('Ekip puanı', $f['rating_avg'] !== null ? (float)$f['rating_avg'] / 5 * 100 : null) ?>
                    <?= ui_meter('Müşteri puanı', $f['agency_rating_avg'] !== null ? (float)$f['agency_rating_avg'] / 5 * 100 : null) ?>
                    <dl class="dl xsmall" style="grid-template-columns:130px 1fr">
                        <dt>Ort. revizyon</dt><dd class="num"><?= $f['avg_revisions'] !== null ? number_format((float)$f['avg_revisions'], 1, ',', '') : '—' ?></dd>
                        <dt>Bırakılan / alınan iş</dt><dd class="num"><?= (int)$f['releases_count'] ?> / <?= (int)$f['removed_count'] ?></dd>
                        <dt>Geciken teslim</dt><dd class="num"><?= (int)$f['late_count'] ?></dd>
                        <dt>Toplam hakediş</dt><dd class="money"><?= format_money((float)$f['total_earned']) ?></dd>
                        <dt>Bekleyen teklif</dt><dd class="num"><?= (int)$f['pending_offers'] ?></dd>
                    </dl>
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="recompute"><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>"><button class="btn btn-ghost btn-sm"><i data-lucide="refresh-cw"></i>Yeniden hesapla</button></form>
                </div>
                <form method="POST" action="" class="stack lg:col-span-2"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="save"><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>">
                    <p class="eyebrow">Hesap ve seviye</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="field"><label class="label">Seviye</label><select class="select" name="tier"><?php foreach (FREELANCER_TIERS as $tk => $tv): ?><option value="<?= $tk ?>" <?= $f['tier'] === $tk ? 'selected' : '' ?>><?= e($tv['label']) ?> · <?= tier_job_limit($tk) ?> iş</option><?php endforeach; ?></select></div>
                        <div class="field"><label class="label">Durum</label><select class="select" name="status"><?php foreach ($status_badge as $sk => [$sv]): ?><option value="<?= $sk ?>" <?= $f['status'] === $sk ? 'selected' : '' ?>><?= $sv ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label class="label">Şehir</label><input class="input" name="city" value="<?= e($f['city'] ?? '') ?>"></div>
                    </div>
                    <div style="display:flex;gap:18px;flex-wrap:wrap">
                        <label class="check small"><input type="checkbox" name="tier_locked" value="1" <?= (int)$f['tier_locked'] === 1 ? 'checked' : '' ?>>Seviyeyi sabitle (otomatik değişmesin)</label>
                        <label class="check small"><input type="checkbox" name="is_available" value="1" <?= (int)$f['is_available'] === 1 ? 'checked' : '' ?>>Müsait</label>
                    </div>
                    <div class="field"><span class="label">Uzmanlık</span>
                        <div style="display:flex;gap:6px 14px;flex-wrap:wrap">
                            <?php foreach (JOB_CATEGORIES as $ck => $cv): ?><label class="check xsmall"><input type="checkbox" name="skills[]" value="<?= $ck ?>" <?= in_array($ck, $fskills, true) ? 'checked' : '' ?>><?= e($cv['label']) ?></label><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="field"><label class="label">İç not</label><textarea class="textarea" name="admin_notes" rows="2" placeholder="Freelancer görmez"><?= e($f['admin_notes'] ?? '') ?></textarea></div>
                    <p class="xsmall text-muted">
                        <?= e($f['email']) ?> · <?= e($f['phone'] ?? '') ?> · IBAN <?= $f['iban'] ? e($f['iban']) : '<span style="color:var(--warning)">yok</span>' ?>
                        <?php if ($f['portfolio_url'] && is_safe_url($f['portfolio_url'])): ?> · <a class="link" target="_blank" rel="noopener" href="<?= e($f['portfolio_url']) ?>">portfolyo</a><?php endif; ?>
                        · son giriş <?= $f['last_login'] ? time_ago($f['last_login']) : '—' ?>
                    </p>
                    <?php if ($f['equipment'] || $f['bio']): ?><p class="xsmall text-ink-2"><?= e(trim(($f['equipment'] ? 'Ekipman: ' . $f['equipment'] . '. ' : '') . ($f['bio'] ?? ''))) ?></p><?php endif; ?>
                    <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap">
                        <button class="btn btn-primary btn-sm">Kaydet</button>
                        <?php if ($can_delete): ?><button type="button" class="small" style="color:var(--danger)" @click="del = !del">Hesabı kalıcı sil</button><?php endif; ?>
                    </div>
                </form>
            </div>
            <?php if ($can_delete): ?>
            <form x-show="del" x-cloak method="POST" action="" class="alert alert-danger" style="margin-top:12px;align-items:center;justify-content:space-between" onsubmit="return confirm('<?= e($f['full_name']) ?> hesabı kalıcı olarak silinsin mi?');"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>">
                <span class="small">Aktif işleri (<?= $f['cap']['active'] ?>) havuza döner, teklifleri silinir. Hakediş faturası olan cari kartı korunur.</span>
                <button class="btn btn-danger-solid btn-sm">Kalıcı sil</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
