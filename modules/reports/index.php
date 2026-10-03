<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - YÖNETİM RAPORLARI
 * ====================================================================
 * 1. Proje Kârlılığı   : gelir (fatura / bütçe) - set & ekip maliyetleri
 * 2. Alacak Yaşlandırma: vadesine göre ödenmemiş satış faturaları
 * 3. Aylık Gelir-Gider : faturalanan, tahsil edilen, ödenen, maaş
 * 4. Müşteri Ciroları  : müşteri bazında ciro, tahsilat, açık bakiye
 * Her rapor Excel uyumlu CSV olarak indirilebilir (?export=csv).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('reports.view');

$tabs = [
    'profitability' => ['label' => 'Proje Kârlılığı',     'icon' => 'trending-up'],
    'aging'         => ['label' => 'Alacak Yaşlandırma',  'icon' => 'hourglass'],
    'monthly'       => ['label' => 'Aylık Gelir-Gider',   'icon' => 'calendar-range'],
    'clients'       => ['label' => 'Müşteri Ciroları',    'icon' => 'users'],
];
$tab  = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'profitability';
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

// Her rapor: columns [key => [başlık, tür]] + rows + totals
$columns = [];
$rows    = [];
$totals  = [];
$note    = '';

if ($tab === 'profitability') {
    $note = 'Gelir: projeye kesilen satış faturalarının KDV hariç toplamı; fatura yoksa bütçe + yansıtılacak giderler (tahmini). Maliyet: çekim günlerine girilen tüm set/ekip giderleri (KDV hariç).';
    $columns = [
        'project'  => ['Proje', 'text'],
        'client'   => ['Müşteri', 'text'],
        'status'   => ['Durum', 'text'],
        'budget'   => ['Bütçe', 'money'],
        'revenue'  => ['Gelir', 'money'],
        'cost'     => ['Maliyet', 'money'],
        'profit'   => ['Kâr', 'money'],
        'margin'   => ['Marj %', 'percent'],
        'basis'    => ['Gelir Tipi', 'text'],
    ];
    $st = $db->prepare("
        SELECT p.id, p.project_code, p.project_name, p.status, p.agreed_budget, c.company_title,
               (SELECT COALESCE(SUM(i.subtotal), 0) FROM invoices i WHERE i.project_id = p.id AND i.invoice_type = 'sales') AS invoiced,
               (SELECT COALESCE(SUM(scg.agreed_fee), 0) FROM shoot_crew_gear scg JOIN shoots s ON scg.shoot_id = s.id WHERE s.project_id = p.id) AS cost,
               (SELECT COALESCE(SUM(scg.agreed_fee), 0) FROM shoot_crew_gear scg JOIN shoots s ON scg.shoot_id = s.id WHERE s.project_id = p.id AND scg.is_rebillable = 1) AS rebillable
        FROM projects p
        LEFT JOIN contacts c ON p.client_id = c.id
        WHERE p.status != 'cancelled' AND (YEAR(COALESCE(p.start_date, p.created_at)) = ? OR YEAR(p.created_at) = ?)
        ORDER BY p.id DESC
    ");
    $st->execute([$year, $year]);
    foreach ($st->fetchAll() as $r) {
        $is_actual = (float)$r['invoiced'] > 0;
        $revenue   = $is_actual ? (float)$r['invoiced'] : (float)$r['agreed_budget'] + (float)$r['rebillable'];
        $profit    = $revenue - (float)$r['cost'];
        $rows[] = [
            'project' => $r['project_code'] . ' · ' . $r['project_name'],
            'client'  => $r['company_title'] ?? '-',
            'status'  => PROJECT_STATUSES[$r['status']]['label'] ?? $r['status'],
            'budget'  => (float)$r['agreed_budget'],
            'revenue' => $revenue,
            'cost'    => (float)$r['cost'],
            'profit'  => $profit,
            'margin'  => $revenue > 0 ? $profit / $revenue * 100 : 0,
            'basis'   => $is_actual ? 'Faturalı' : 'Tahmini',
            '_link'   => "/modules/projects/detail.php?id={$r['id']}",
        ];
    }
    foreach (['budget', 'revenue', 'cost', 'profit'] as $k) {
        $totals[$k] = array_sum(array_column($rows, $k));
    }
    $totals['margin'] = $totals['revenue'] > 0 ? $totals['profit'] / $totals['revenue'] * 100 : 0;
}

if ($tab === 'aging') {
    $note = 'Vade tarihi girilmemiş faturalarda vade, fatura tarihinden 30 gün sonra kabul edilir. Tutarlar kalan (tahsil edilmemiş) bakiyedir.';
    $columns = [
        'client'  => ['Müşteri', 'text'],
        'count'   => ['Fatura', 'int'],
        'current' => ['Vadesi Gelmemiş', 'money'],
        'd30'     => ['1-30 Gün', 'money'],
        'd60'     => ['31-60 Gün', 'money'],
        'd90'     => ['61-90 Gün', 'money'],
        'd90p'    => ['90+ Gün', 'money'],
        'total'   => ['Toplam Alacak', 'money'],
    ];
    $st = $db->query("
        SELECT i.contact_id, c.company_title, i.grand_total - i.paid_amount AS remaining,
               DATEDIFF(CURRENT_DATE(), COALESCE(i.due_date, DATE_ADD(i.issue_date, INTERVAL 30 DAY))) AS days_late
        FROM invoices i LEFT JOIN contacts c ON i.contact_id = c.id
        WHERE i.invoice_type = 'sales' AND i.payment_status != 'paid' AND i.grand_total - i.paid_amount > 0.009
    ");
    $by_client = [];
    foreach ($st->fetchAll() as $r) {
        $cid = (int)$r['contact_id'];
        $by_client[$cid] ??= ['client' => $r['company_title'] ?? '-', 'count' => 0, 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0, '_link' => "/modules/contacts/detail.php?id={$cid}&tab=invoices"];
        $d = (int)$r['days_late'];
        $bucket = $d <= 0 ? 'current' : ($d <= 30 ? 'd30' : ($d <= 60 ? 'd60' : ($d <= 90 ? 'd90' : 'd90p')));
        $by_client[$cid][$bucket] += (float)$r['remaining'];
        $by_client[$cid]['total'] += (float)$r['remaining'];
        $by_client[$cid]['count']++;
    }
    $rows = array_values($by_client);
    usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
    foreach (['count', 'current', 'd30', 'd60', 'd90', 'd90p', 'total'] as $k) {
        $totals[$k] = array_sum(array_column($rows, $k));
    }
}

if ($tab === 'monthly') {
    $note = 'Faturalanan tutarlar KDV hariçtir. Tahsilat / Ödeme sütunları kasa ve bankalara gerçekten giren/çıkan paradır (virmanlar hariç).';
    $columns = [
        'month'     => ['Ay', 'text'],
        'sales'     => ['Satış Faturası', 'money'],
        'purchases' => ['Alış Faturası', 'money'],
        'cash_in'   => ['Kasa Girişi', 'money'],
        'cash_out'  => ['Kasa Çıkışı', 'money'],
        'payroll'   => ['Maaş Ödemeleri', 'money'],
        'net_cash'  => ['Net Nakit', 'money'],
    ];
    $q = function (string $sql) use ($db, $year) {
        $st = $db->prepare($sql);
        $st->execute([$year]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $sales     = $q("SELECT MONTH(issue_date), SUM(subtotal) FROM invoices WHERE invoice_type = 'sales' AND YEAR(issue_date) = ? GROUP BY MONTH(issue_date)");
    $purchases = $q("SELECT MONTH(issue_date), SUM(subtotal) FROM invoices WHERE invoice_type = 'purchase' AND YEAR(issue_date) = ? GROUP BY MONTH(issue_date)");
    $cash_in   = $q("SELECT MONTH(transaction_date), SUM(amount) FROM transactions WHERE account_id IS NOT NULL AND type = 'income' AND category NOT LIKE 'Virman%' AND YEAR(transaction_date) = ? GROUP BY MONTH(transaction_date)");
    $cash_out  = $q("SELECT MONTH(transaction_date), SUM(amount) FROM transactions WHERE account_id IS NOT NULL AND type = 'expense' AND category NOT LIKE 'Virman%' AND YEAR(transaction_date) = ? GROUP BY MONTH(transaction_date)");
    $payroll   = $q("SELECT MONTH(payment_date), SUM(net_paid) FROM payrolls WHERE YEAR(payment_date) = ? GROUP BY MONTH(payment_date)");
    for ($m = 1; $m <= 12; $m++) {
        $rows[] = [
            'month'     => turkish_month($m),
            'sales'     => (float)($sales[$m] ?? 0),
            'purchases' => (float)($purchases[$m] ?? 0),
            'cash_in'   => (float)($cash_in[$m] ?? 0),
            'cash_out'  => (float)($cash_out[$m] ?? 0),
            'payroll'   => (float)($payroll[$m] ?? 0),
            'net_cash'  => (float)($cash_in[$m] ?? 0) - (float)($cash_out[$m] ?? 0),
        ];
    }
    foreach (['sales', 'purchases', 'cash_in', 'cash_out', 'payroll', 'net_cash'] as $k) {
        $totals[$k] = array_sum(array_column($rows, $k));
    }
}

if ($tab === 'clients') {
    $note = 'Ciro: seçili yılda müşteriye kesilen satış faturalarının KDV hariç toplamı. Açık bakiye: tüm zamanlar için güncel cari bakiye.';
    $columns = [
        'client'    => ['Müşteri', 'text'],
        'projects'  => ['Proje', 'int'],
        'revenue'   => ['Ciro (KDV Hariç)', 'money'],
        'invoiced'  => ['Faturalanan (KDV Dahil)', 'money'],
        'collected' => ['Tahsil Edilen', 'money'],
        'balance'   => ['Açık Bakiye', 'money'],
        'share'     => ['Ciro Payı %', 'percent'],
    ];
    $st = $db->prepare("
        SELECT c.id, c.company_title, c.balance,
               (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id AND YEAR(p.created_at) = ?) AS projects,
               COALESCE(SUM(i.subtotal), 0) AS revenue, COALESCE(SUM(i.grand_total), 0) AS invoiced, COALESCE(SUM(i.paid_amount), 0) AS collected
        FROM contacts c
        JOIN invoices i ON i.contact_id = c.id AND i.invoice_type = 'sales' AND YEAR(i.issue_date) = ?
        GROUP BY c.id, c.company_title, c.balance
        ORDER BY revenue DESC
    ");
    $st->execute([$year, $year]);
    $data = $st->fetchAll();
    $sum_rev = array_sum(array_column($data, 'revenue'));
    foreach ($data as $r) {
        $rows[] = [
            'client'    => $r['company_title'],
            'projects'  => (int)$r['projects'],
            'revenue'   => (float)$r['revenue'],
            'invoiced'  => (float)$r['invoiced'],
            'collected' => (float)$r['collected'],
            'balance'   => (float)$r['balance'],
            'share'     => $sum_rev > 0 ? (float)$r['revenue'] / $sum_rev * 100 : 0,
            '_link'     => "/modules/contacts/detail.php?id={$r['id']}",
        ];
    }
    foreach (['projects', 'revenue', 'invoiced', 'collected', 'balance'] as $k) {
        $totals[$k] = array_sum(array_column($rows, $k));
    }
    $totals['share'] = $rows ? 100 : 0;
}

// ====================================================================
// CSV DIŞA AKTARMA (Excel Türkçe uyumlu: UTF-8 BOM + noktalı virgül)
// ====================================================================
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'rapor_' . $tab . '_' . $year . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(fn($c) => $c[0], $columns), ';');
    $fmt = function ($v, $type) {
        if ($type === 'money' || $type === 'percent') {
            return number_format((float)$v, 2, ',', '');
        }
        return (string)$v;
    };
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($k, $c) => $fmt($r[$k] ?? '', $c[1]), array_keys($columns), $columns), ';');
    }
    if ($totals) {
        $line = [];
        foreach ($columns as $k => $c) {
            $line[] = isset($totals[$k]) ? $fmt($totals[$k], $c[1]) : ($k === array_key_first($columns) ? 'TOPLAM' : '');
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

$cell = function ($v, string $type): string {
    return match ($type) {
        'money'   => format_money((float)$v),
        'percent' => '%' . number_format((float)$v, 1, ',', '.'),
        'int'     => (string)(int)$v,
        default   => e((string)$v),
    };
};

$page_title = 'Yönetim Raporları';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Yönetim Raporları</h1>
        <p class="text-xs text-slate-500 mt-0.5">Hangi proje kazandırıyor, kim ödemiyor, nakit nasıl akıyor?</p>
    </div>
    <form method="GET" class="flex items-center gap-2">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <?php if ($tab !== 'aging'): ?>
        <select name="year" onchange="this.form.submit()" class="py-2 px-3 bg-white border border-slate-200 rounded-xl text-xs font-bold">
            <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 4; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <?php endif; ?>
        <a href="?tab=<?= e($tab) ?>&year=<?= $year ?>&export=csv" class="inline-flex items-center gap-1.5 py-2 px-3.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl">
            <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> Excel / CSV
        </a>
    </form>
</div>

<div class="mb-4 flex flex-wrap gap-2">
    <?php foreach ($tabs as $tk => $tv): ?>
        <a href="?tab=<?= $tk ?>&year=<?= $year ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold border <?= $tab === $tk ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
            <i data-lucide="<?= $tv['icon'] ?>" class="w-4 h-4"></i> <?= $tv['label'] ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'monthly' && $rows): ?>
<div class="bg-white border border-slate-200 rounded-3xl p-5 shadow-sm mb-4">
    <canvas id="monthlyChart" height="90"></canvas>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($rows, 'month'), JSON_UNESCAPED_UNICODE) ?>,
            datasets: [
                { label: 'Kasa Girişi', data: <?= json_encode(array_column($rows, 'cash_in')) ?>, backgroundColor: 'rgba(16,185,129,0.8)', borderRadius: 6 },
                { label: 'Kasa Çıkışı', data: <?= json_encode(array_column($rows, 'cash_out')) ?>, backgroundColor: 'rgba(244,63,94,0.8)', borderRadius: 6 }
            ]
        },
        options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
    });
});
</script>
<?php endif; ?>

<?php if ($tab === 'aging' && $totals): ?>
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4">
    <?php foreach (['current' => 'bg-emerald-50 text-emerald-800', 'd30' => 'bg-amber-50 text-amber-800', 'd60' => 'bg-orange-50 text-orange-800', 'd90' => 'bg-rose-50 text-rose-700', 'd90p' => 'bg-rose-100 text-rose-900'] as $k => $cls): ?>
        <div class="p-4 rounded-2xl border border-slate-200 <?= $cls ?>">
            <p class="text-[10px] font-bold uppercase"><?= $columns[$k][0] ?></p>
            <p class="text-lg font-black mt-1"><?= format_money($totals[$k]) ?></p>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                    <?php foreach ($columns as $k => $c): ?>
                        <th class="py-3 px-4 <?= $c[1] === 'text' ? '' : 'text-right' ?>"><?= $c[0] ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= count($columns) ?>" class="py-12 text-center text-slate-400">Bu dönem için veri bulunamadı.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr class="hover:bg-slate-50">
                        <?php $first = true; foreach ($columns as $k => $c):
                            $neg = in_array($c[1], ['money', 'percent'], true) && (float)($r[$k] ?? 0) < 0;
                        ?>
                            <td class="py-3 px-4 <?= $c[1] === 'text' ? '' : 'text-right font-semibold' ?> <?= $neg ? 'text-rose-600' : 'text-slate-800' ?>">
                                <?php if ($first && !empty($r['_link'])): ?>
                                    <a href="<?= BASE_URL . e($r['_link']) ?>" class="font-bold text-brand-700 hover:underline"><?= $cell($r[$k] ?? '', $c[1]) ?></a>
                                <?php else: ?>
                                    <?= $cell($r[$k] ?? '', $c[1]) ?>
                                <?php endif; ?>
                            </td>
                        <?php $first = false; endforeach; ?>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if ($rows && $totals): ?>
            <tfoot>
                <tr class="bg-slate-900 text-white font-black">
                    <?php $first = true; foreach ($columns as $k => $c): ?>
                        <td class="py-3 px-4 <?= $c[1] === 'text' ? '' : 'text-right' ?>"><?= $first ? 'TOPLAM' : (isset($totals[$k]) ? $cell($totals[$k], $c[1]) : '') ?></td>
                    <?php $first = false; endforeach; ?>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php if ($note): ?>
    <p class="mt-3 text-[11px] text-slate-400"><i data-lucide="info" class="w-3 h-3 inline"></i> <?= e($note) ?></p>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
