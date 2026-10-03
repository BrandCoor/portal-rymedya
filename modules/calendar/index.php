<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - PRODÜKSİYON TAKVİMİ
 * ====================================================================
 * Çekim günleri, proje teslim tarihleri, görev son tarihleri, fatura
 * vadeleri, ekipman kira dönüşleri ve teklif geçerlilik tarihleri tek
 * bir aylık takvimde gösterilir. Kullanıcı yalnızca yetkili olduğu
 * kayıtları görür.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_permission('projects.view');

// Seçili ay (?month=2026-10)
$month_param = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month_param) || !checkdate((int)substr($month_param, 5, 2), 1, (int)substr($month_param, 0, 4))) {
    $month_param = date('Y-m');
}
$first_day  = new DateTimeImmutable($month_param . '-01');
$last_day   = $first_day->modify('last day of this month');
$grid_start = $first_day->modify('-' . ((int)$first_day->format('N') - 1) . ' days');   // Pazartesi
$grid_end   = $last_day->modify('+' . (7 - (int)$last_day->format('N')) . ' days');     // Pazar
$from = $grid_start->format('Y-m-d');
$to   = $grid_end->format('Y-m-d');

$events = [];
$add = function (string $date, string $type, string $title, string $sub, string $link) use (&$events) {
    $events[$date][] = ['type' => $type, 'title' => $title, 'sub' => $sub, 'link' => $link];
};

// 1. Çekim günleri
$st = $db->prepare("
    SELECT s.id, s.title, s.shoot_date, s.start_time, s.location_name, p.id AS project_id, p.project_name
    FROM shoots s JOIN projects p ON s.project_id = p.id
    WHERE s.shoot_date BETWEEN ? AND ? AND p.status != 'cancelled'
    ORDER BY s.shoot_date, s.start_time
");
$st->execute([$from, $to]);
foreach ($st->fetchAll() as $r) {
    $time = !empty($r['start_time']) ? substr($r['start_time'], 0, 5) . ' ' : '';
    $add($r['shoot_date'], 'shoot', $time . $r['title'], $r['project_name'] . (!empty($r['location_name']) ? ' · ' . $r['location_name'] : ''), "/modules/projects/detail.php?id={$r['project_id']}&tab=shoots");
}

// 2. Proje teslim tarihleri
$st = $db->prepare("SELECT id, project_name, deadline, status FROM projects WHERE deadline BETWEEN ? AND ? AND status NOT IN ('cancelled', 'invoiced')");
$st->execute([$from, $to]);
foreach ($st->fetchAll() as $r) {
    $add($r['deadline'], 'deadline', 'Teslim: ' . $r['project_name'], PROJECT_STATUSES[$r['status']]['label'] ?? $r['status'], "/modules/projects/detail.php?id={$r['id']}");
}

// 3. Açık görevler
$st = $db->prepare("
    SELECT t.id, t.title, t.due_date, t.project_id, u.full_name, p.project_name
    FROM project_tasks t JOIN projects p ON t.project_id = p.id LEFT JOIN users u ON t.assigned_user_id = u.id
    WHERE t.due_date BETWEEN ? AND ? AND t.status != 'done'
");
$st->execute([$from, $to]);
foreach ($st->fetchAll() as $r) {
    $add($r['due_date'], 'task', $r['title'], ($r['full_name'] ?? 'Atanmadı') . ' · ' . $r['project_name'], "/modules/projects/detail.php?id={$r['project_id']}&tab=tasks");
}

// 4. Fatura vadeleri (finans yetkisi)
if (has_permission('finance.view') || has_permission('finance.invoices')) {
    $st = $db->prepare("
        SELECT i.invoice_number, i.due_date, i.invoice_type, i.grand_total, i.paid_amount, i.contact_id, c.company_title
        FROM invoices i LEFT JOIN contacts c ON i.contact_id = c.id
        WHERE i.due_date BETWEEN ? AND ? AND i.payment_status != 'paid'
    ");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $rem = (float)$r['grand_total'] - (float)$r['paid_amount'];
        $label = $r['invoice_type'] === 'sales' ? 'Tahsilat: ' : 'Ödeme: ';
        $add($r['due_date'], $r['invoice_type'] === 'sales' ? 'receivable' : 'payable', $label . format_money($rem), $r['invoice_number'] . ' · ' . ($r['company_title'] ?? ''), "/modules/contacts/detail.php?id={$r['contact_id']}&tab=invoices");
    }
}

// 5. Ekipman kira dönüşleri
if (can_access_module('inventory.manage')) {
    try {
        $st = $db->prepare("SELECT e.item_name, e.rental_end_date, c.company_title FROM equipment e LEFT JOIN contacts c ON e.rental_contact_id = c.id WHERE e.status = 'rented_out' AND e.rental_end_date BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $add($r['rental_end_date'], 'rental', 'İade: ' . $r['item_name'], $r['company_title'] ?? '', '/modules/inventory/index.php?status=rented_out');
        }
    } catch (Throwable $e) {
    }
}

// 6. Teklif geçerlilik bitişleri
if (can_access_module('proposals.manage')) {
    try {
        $st = $db->prepare("SELECT p.id, p.proposal_code, p.title, p.valid_until, c.company_title FROM proposals p LEFT JOIN contacts c ON p.client_id = c.id WHERE p.valid_until BETWEEN ? AND ? AND p.status IN ('sent', 'negotiating')");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $add($r['valid_until'], 'proposal', 'Teklif bitiyor: ' . $r['proposal_code'], ($r['company_title'] ?? '') . ' · ' . $r['title'], "/modules/proposals/create.php?id={$r['id']}");
        }
    } catch (Throwable $e) {
    }
}

$types = [
    'shoot'      => ['label' => 'Çekim',            'color' => 'bg-brand-600 text-white'],
    'deadline'   => ['label' => 'Proje Teslimi',    'color' => 'bg-rose-600 text-white'],
    'task'       => ['label' => 'Görev',            'color' => 'bg-emerald-100 text-emerald-800 border border-emerald-300'],
    'receivable' => ['label' => 'Tahsilat Vadesi',  'color' => 'bg-amber-100 text-amber-900 border border-amber-300'],
    'payable'    => ['label' => 'Ödeme Vadesi',     'color' => 'bg-orange-100 text-orange-900 border border-orange-300'],
    'rental'     => ['label' => 'Ekipman İadesi',   'color' => 'bg-purple-100 text-purple-800 border border-purple-300'],
    'proposal'   => ['label' => 'Teklif Bitişi',    'color' => 'bg-indigo-100 text-indigo-800 border border-indigo-300'],
];
$present_types = [];
foreach ($events as $list) {
    foreach ($list as $ev) {
        $present_types[$ev['type']] = true;
    }
}

$prev_month = $first_day->modify('-1 month')->format('Y-m');
$next_month = $first_day->modify('+1 month')->format('Y-m');
$today = date('Y-m-d');

$page_title = 'Prodüksiyon Takvimi';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ hidden: {}, view: window.innerWidth < 768 ? 'list' : 'grid' }">
    <div class="mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Prodüksiyon Takvimi</h1>
            <p class="text-xs text-slate-500 mt-0.5">Çekimler, teslimler, görevler ve ödeme vadeleri tek ekranda.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="?month=<?= $prev_month ?>" class="p-2 bg-white border border-slate-200 rounded-xl hover:bg-slate-50"><i data-lucide="chevron-left" class="w-4 h-4"></i></a>
            <span class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-black text-slate-900 min-w-[150px] text-center"><?= turkish_month((int)$first_day->format('n')) . ' ' . $first_day->format('Y') ?></span>
            <a href="?month=<?= $next_month ?>" class="p-2 bg-white border border-slate-200 rounded-xl hover:bg-slate-50"><i data-lucide="chevron-right" class="w-4 h-4"></i></a>
            <a href="?month=<?= date('Y-m') ?>" class="px-3 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl">Bugün</a>
            <div class="inline-flex bg-white border border-slate-200 rounded-xl p-1 text-xs font-bold ml-1">
                <button @click="view = 'grid'" :class="view === 'grid' ? 'bg-slate-900 text-white' : 'text-slate-500'" class="px-2.5 py-1 rounded-lg">Ay</button>
                <button @click="view = 'list'" :class="view === 'list' ? 'bg-slate-900 text-white' : 'text-slate-500'" class="px-2.5 py-1 rounded-lg">Liste</button>
            </div>
        </div>
    </div>

    <!-- Lejant / Filtre -->
    <div class="mb-4 flex flex-wrap gap-2 text-[11px]">
        <?php foreach ($types as $tk => $tv): if (!isset($present_types[$tk])) continue; ?>
            <button type="button" @click="hidden['<?= $tk ?>'] = !hidden['<?= $tk ?>']" :class="hidden['<?= $tk ?>'] ? 'opacity-40 line-through' : ''" class="px-2.5 py-1 rounded-lg font-bold <?= $tv['color'] ?>"><?= $tv['label'] ?></button>
        <?php endforeach; ?>
    </div>

    <!-- AY GÖRÜNÜMÜ -->
    <div x-show="view === 'grid'" class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
        <div class="grid grid-cols-7 bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase text-center">
            <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $dn): ?><div class="py-2"><?= $dn ?></div><?php endforeach; ?>
        </div>
        <div class="grid grid-cols-7">
            <?php for ($d = $grid_start; $d <= $grid_end; $d = $d->modify('+1 day')):
                $ds = $d->format('Y-m-d');
                $in_month = $d->format('m') === $first_day->format('m');
                $day_events = $events[$ds] ?? [];
            ?>
            <div class="min-h-[110px] border-b border-r border-slate-100 p-1.5 <?= $in_month ? 'bg-white' : 'bg-slate-50/70' ?> <?= $ds === $today ? 'ring-2 ring-inset ring-brand-500' : '' ?>">
                <div class="text-[11px] font-bold mb-1 <?= $ds === $today ? 'text-brand-600' : ($in_month ? 'text-slate-700' : 'text-slate-300') ?>"><?= (int)$d->format('j') ?></div>
                <div class="space-y-1">
                    <?php foreach ($day_events as $ev): $tv = $types[$ev['type']]; ?>
                        <a href="<?= BASE_URL . e($ev['link']) ?>" x-show="!hidden['<?= $ev['type'] ?>']" title="<?= e($ev['title'] . ' — ' . $ev['sub']) ?>"
                           class="block px-1.5 py-0.5 rounded-md text-[10px] font-semibold truncate <?= $tv['color'] ?>"><?= e($ev['title']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- LİSTE (AJANDA) GÖRÜNÜMÜ -->
    <div x-show="view === 'list'" x-cloak class="bg-white border border-slate-200 rounded-3xl shadow-sm divide-y divide-slate-100">
        <?php
        $has_any = false;
        for ($d = $first_day; $d <= $last_day; $d = $d->modify('+1 day')):
            $ds = $d->format('Y-m-d');
            if (empty($events[$ds])) continue;
            $has_any = true;
        ?>
        <div class="p-4 flex gap-4">
            <div class="w-14 flex-shrink-0 text-center">
                <div class="text-xl font-black <?= $ds === $today ? 'text-brand-600' : 'text-slate-900' ?>"><?= $d->format('j') ?></div>
                <div class="text-[10px] font-bold text-slate-400 uppercase"><?= ['', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'][(int)$d->format('N')] ?></div>
            </div>
            <div class="flex-1 space-y-2">
                <?php foreach ($events[$ds] as $ev): $tv = $types[$ev['type']]; ?>
                <a href="<?= BASE_URL . e($ev['link']) ?>" x-show="!hidden['<?= $ev['type'] ?>']" class="flex items-start gap-2 group">
                    <span class="mt-0.5 px-2 py-0.5 rounded-md text-[10px] font-bold flex-shrink-0 <?= $tv['color'] ?>"><?= $tv['label'] ?></span>
                    <span class="text-xs">
                        <strong class="text-slate-900 group-hover:text-brand-600"><?= e($ev['title']) ?></strong>
                        <span class="text-slate-500 block"><?= e($ev['sub']) ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endfor; ?>
        <?php if (!$has_any): ?>
            <p class="py-12 text-center text-sm text-slate-400">Bu ay için planlanmış kayıt yok.</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
