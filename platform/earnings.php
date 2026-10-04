<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - KAZANÇ (FREELANCER)
 * ====================================================================
 * Onaylanan her aşama için oluşan hakediş (alış faturası) ve ödeme durumu.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile = require_platform_role('freelancer');
$uid = (int)$_SESSION['client_user_id'];

// Aşama bazlı hakediş: her onaylanan aşama ayrı satır
$st = $db->prepare("
    SELECT j.id, j.job_code, j.title, j.status AS job_status, j.currency, j.freelancer_rating,
           m.title AS ms_title, m.is_extra, m.no_work, m.fee AS freelancer_fee, m.approved_at AS completed_at,
           (SELECT COUNT(*) FROM platform_milestones x WHERE x.job_id = j.id AND x.status IN ('" . implode("','", MILESTONE_LIVE) . "')) AS ms_count,
           i.invoice_number, i.grand_total, i.paid_amount, i.payment_status
    FROM platform_milestones m
    JOIN platform_jobs j ON j.id = m.job_id
    LEFT JOIN invoices i ON i.id = m.purchase_invoice_id
    WHERE m.freelancer_user_id = ? AND m.status = 'approved'
    ORDER BY m.approved_at DESC, m.id DESC
");
$st->execute([$uid]);
$rows = $st->fetchAll();
$job_count = count(array_unique(array_column($rows, 'id')));

$total   = array_sum(array_map(fn($r) => (float)($r['grand_total'] ?? $r['freelancer_fee']), $rows));
$paid    = array_sum(array_map(fn($r) => (float)($r['paid_amount'] ?? 0), $rows));
$pending = $total - $paid;

$in_progress = $db->prepare("SELECT COALESCE(SUM(m.fee), 0) FROM platform_milestones m JOIN platform_jobs j ON j.id = m.job_id WHERE j.assigned_user_id = ? AND j.status IN ('" . implode("','", JOB_ACTIVE_STATUSES) . "') AND m.status IN ('open', 'in_review', 'revision')");
$in_progress->execute([$uid]);

// Aylık hakediş (son 6 ay)
$monthly = [];
for ($m = 5; $m >= 0; $m--) {
    $monthly[date('Y-m', strtotime("first day of -{$m} month"))] = 0.0;
}
foreach ($rows as $r) {
    $k = substr((string)$r['completed_at'], 0, 7);
    if (isset($monthly[$k])) $monthly[$k] += (float)($r['grand_total'] ?? $r['freelancer_fee']);
}
$month_max = max(1, max($monthly));
$in_progress_total = (float)$in_progress->fetchColumn();

$c = $db->prepare("SELECT iban FROM contacts WHERE id = ?");
$c->execute([(int)$_SESSION['client_contact_id']]);
$iban = $c->fetchColumn();

platform_header('Kazanç', 'earnings');
$tr_months = ['01' => 'Oca', '02' => 'Şub', '03' => 'Mar', '04' => 'Nis', '05' => 'May', '06' => 'Haz', '07' => 'Tem', '08' => 'Ağu', '09' => 'Eyl', '10' => 'Eki', '11' => 'Kas', '12' => 'Ara'];
$pay_status = ['paid' => ['Ödendi', 'success'], 'partial' => ['Kısmi ödendi', 'warning'], 'unpaid' => ['Ödeme bekliyor', 'neutral']];
?>
<div class="page-head">
    <div>
        <h1 class="h1">Kazanç</h1>
        <p class="sub">Ajansın onayladığı her aşama için hakediş kaydı oluşur; ödemeler IBAN'ınıza yapılır.</p>
    </div>
</div>

<?php if (empty($iban)): ?>
    <div class="alert alert-warning" style="margin-bottom:20px"><i data-lucide="landmark"></i><div>Ödemelerinizin yapılabilmesi için <a href="<?= BASE_URL ?>/platform/profile.php" class="link">profilinize IBAN</a> ekleyin.</div></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6" style="margin-bottom:24px">
    <div class="card lg:col-span-2">
        <div class="kpi-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
            <div class="kpi"><div class="kpi-label">Toplam hakediş</div><div class="kpi-value"><?= format_money($total) ?></div><div class="kpi-meta"><?= count($rows) ?> onaylı aşama · <?= $job_count ?> iş</div></div>
            <div class="kpi"><div class="kpi-label">Ödenen</div><div class="kpi-value" style="color:var(--success)"><?= format_money($paid) ?></div><div class="kpi-meta">hesabınıza aktarılan</div></div>
            <div class="kpi"><div class="kpi-label">Ödeme bekleyen</div><div class="kpi-value"><?= format_money(max(0, $pending)) ?></div><div class="kpi-meta">onaylanmış hakediş</div></div>
            <div class="kpi"><div class="kpi-label">Devam eden aşamalardan</div><div class="kpi-value text-muted"><?= format_money($in_progress_total) ?></div><div class="kpi-meta">aşama onaylandıkça kayda geçer</div></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><p class="card-title">Son 6 ay</p></div>
        <div class="card-pad" style="display:flex;align-items:flex-end;gap:10px;height:170px">
            <?php foreach ($monthly as $ym => $v): ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end" title="<?= e(format_money($v)) ?>">
                    <div style="width:100%;max-width:28px;border-radius:4px 4px 0 0;background:<?= $ym === date('Y-m') ? 'var(--accent)' : 'var(--ink)' ?>;height:<?= max(2, round($v / $month_max * 100)) ?>%;opacity:<?= $v > 0 ? 1 : .15 ?>"></div>
                    <span class="xsmall text-muted"><?= $tr_months[substr($ym, 5, 2)] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<section class="card">
    <div class="card-head"><p class="card-title">Hakedişler</p></div>
    <?php if (!$rows): ?>
        <?= ui_empty('Henüz onaylanan aşamanız yok', 'Teslim ettiğiniz aşama onaylandığında hakedişiniz burada görünür.', 'wallet', '<a class="btn btn-secondary" href="' . BASE_URL . '/platform/pool.php">İş havuzu</a>') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>İş / aşama</th><th>Onay</th><th class="r">Hakediş</th><th class="r">Ödenen</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): [$pl, $pt] = $pay_status[$r['payment_status'] ?? 'unpaid'] ?? ['—', 'neutral']; ?>
                <tr class="row-link" onclick="location.href='<?= BASE_URL ?>/platform/job.php?id=<?= (int)$r['id'] ?>'">
                    <td><span class="code-tag"><?= e($r['job_code']) ?></span><div style="font-weight:500"><?= e($r['title']) ?></div><div class="xsmall text-muted"><?= (int)$r['ms_count'] > 1 || (int)$r['is_extra'] === 1 ? e($r['ms_title']) . ((int)$r['no_work'] === 1 ? ' · prim' : ((int)$r['is_extra'] === 1 ? ' · ek kalem' : '')) . ' · ' : '' ?><?= $r['invoice_number'] ? e($r['invoice_number']) : 'kayıt hazırlanıyor' ?></div><?php if ($r['freelancer_rating'] && $r['job_status'] === 'completed'): ?><div style="margin-top:2px"><?= render_stars((float)$r['freelancer_rating']) ?></div><?php endif; ?></td>
                    <td class="small"><?= format_date($r['completed_at']) ?></td>
                    <td class="r money"><?= format_money((float)($r['grand_total'] ?? $r['freelancer_fee']), $r['currency']) ?></td>
                    <td class="r num" style="color:var(--success)"><?= format_money((float)($r['paid_amount'] ?? 0), $r['currency']) ?></td>
                    <td><?= ui_badge($pl, $pt, true) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php platform_footer();
