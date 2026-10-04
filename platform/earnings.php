<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - KAZANÇLARIM (FREELANCER)
 * ====================================================================
 * Tamamlanan her iş için oluşan hakediş (alış faturası) ve ödeme durumu.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile = require_platform_role('freelancer');
$uid = (int)$_SESSION['client_user_id'];

$st = $db->prepare("
    SELECT j.id, j.job_code, j.title, j.completed_at, j.freelancer_fee, j.currency, j.freelancer_rating,
           i.grand_total, i.paid_amount, i.payment_status
    FROM platform_jobs j
    LEFT JOIN invoices i ON i.id = j.purchase_invoice_id
    WHERE j.assigned_user_id = ? AND j.status = 'completed'
    ORDER BY j.completed_at DESC
");
$st->execute([$uid]);
$rows = $st->fetchAll();

$total   = array_sum(array_map(fn($r) => (float)($r['grand_total'] ?? $r['freelancer_fee']), $rows));
$paid    = array_sum(array_map(fn($r) => (float)($r['paid_amount'] ?? 0), $rows));
$pending = $total - $paid;

$in_progress = $db->prepare("SELECT COALESCE(SUM(freelancer_fee), 0) FROM platform_jobs WHERE assigned_user_id = ? AND status IN ('" . implode("','", JOB_ACTIVE_STATUSES) . "')");
$in_progress->execute([$uid]);

$c = $db->prepare("SELECT iban FROM contacts WHERE id = ?");
$c->execute([(int)$_SESSION['client_contact_id']]);
$iban = $c->fetchColumn();

platform_header('Kazançlarım', 'earnings');
?>
<h1 class="text-2xl font-black text-slate-900 mb-5">Kazançlarım</h1>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <?php foreach ([
        ['Toplam Hakediş', $total, 'text-slate-900'],
        ['Ödenen', $paid, 'text-emerald-600'],
        ['Ödeme Bekleyen', $pending, 'text-amber-600'],
        ['Devam Eden İşlerden', (float)$in_progress->fetchColumn(), 'text-cyan-600'],
    ] as [$l, $v, $cls]): ?>
    <div class="bg-white p-4 rounded-2xl border border-slate-200">
        <p class="text-[11px] font-bold text-slate-400 uppercase"><?= $l ?></p>
        <p class="text-xl font-black mt-1 <?= $cls ?>"><?= format_money($v) ?></p>
    </div>
    <?php endforeach; ?>
</div>

<?php if (empty($iban)): ?>
    <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-800">Ödemelerinizin yapılabilmesi için <a href="<?= BASE_URL ?>/platform/profile.php" class="font-bold underline">profilinize IBAN</a> ekleyin.</div>
<?php endif; ?>

<div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
    <table class="w-full text-xs">
        <thead><tr class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase">
            <th class="py-3 px-4 text-left">İş</th><th class="py-3 px-4 text-left">Tamamlanma</th><th class="py-3 px-4 text-right">Hakediş</th><th class="py-3 px-4 text-right">Ödenen</th><th class="py-3 px-4 text-center">Durum</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="py-10 text-center text-slate-400">Henüz tamamlanan işiniz yok.</td></tr>
            <?php else: foreach ($rows as $r):
                $ps = ['paid' => ['Ödendi', 'bg-emerald-100 text-emerald-800'], 'partial' => ['Kısmi Ödendi', 'bg-amber-100 text-amber-800'], 'unpaid' => ['Ödeme Bekliyor', 'bg-slate-100 text-slate-700']][$r['payment_status'] ?? 'unpaid'] ?? ['-', ''];
            ?>
            <tr>
                <td class="py-3 px-4"><a href="<?= BASE_URL ?>/platform/job.php?id=<?= (int)$r['id'] ?>" class="font-bold text-slate-900 hover:text-emerald-600"><span class="font-mono text-[10px] text-slate-400"><?= e($r['job_code']) ?></span> <?= e($r['title']) ?></a>
                    <?php if ($r['freelancer_rating']): ?><div class="text-[10px]"><?= render_stars((float)$r['freelancer_rating']) ?></div><?php endif; ?></td>
                <td class="py-3 px-4 text-slate-600"><?= format_date($r['completed_at']) ?></td>
                <td class="py-3 px-4 text-right font-bold"><?= format_money((float)($r['grand_total'] ?? $r['freelancer_fee']), $r['currency']) ?></td>
                <td class="py-3 px-4 text-right text-emerald-700"><?= format_money((float)($r['paid_amount'] ?? 0), $r['currency']) ?></td>
                <td class="py-3 px-4 text-center"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $ps[1] ?>"><?= $ps[0] ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php platform_footer();
