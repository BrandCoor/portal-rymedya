<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GLOBAL ARAMA
 * ====================================================================
 * Projeler, cariler, faturalar, teklifler, görevler ve ekipmanlar içinde
 * tek kutudan arama. Kullanıcı yalnızca yetkili olduğu modülleri görür.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();

$q = trim($_GET['q'] ?? '');
$groups = [];

if (mb_strlen($q) >= 2) {
    $term = '%' . $q . '%';
    $run = function (string $sql, int $n) use ($db, $term) {
        $st = $db->prepare($sql . ' LIMIT 20');
        $st->execute(array_fill(0, $n, $term));
        return $st->fetchAll();
    };

    if (has_permission('projects.view')) {
        $rows = $run("SELECT p.id, p.project_code, p.project_name, p.status, c.company_title FROM projects p LEFT JOIN contacts c ON p.client_id = c.id
                      WHERE p.project_name LIKE ? OR p.project_code LIKE ? OR c.company_title LIKE ? OR p.description LIKE ? ORDER BY p.id DESC", 4);
        $groups['Projeler'] = ['icon' => 'film', 'items' => array_map(fn($r) => [
            'title' => $r['project_code'] . ' · ' . $r['project_name'],
            'sub'   => ($r['company_title'] ?? '-') . ' · ' . (PROJECT_STATUSES[$r['status']]['label'] ?? $r['status']),
            'link'  => "/modules/projects/detail.php?id={$r['id']}",
        ], $rows)];

        $rows = $run("SELECT t.id, t.title, t.project_id, t.status, p.project_name FROM project_tasks t JOIN projects p ON t.project_id = p.id
                      WHERE t.title LIKE ? OR t.description LIKE ? ORDER BY t.id DESC", 2);
        $groups['Görevler'] = ['icon' => 'list-checks', 'items' => array_map(fn($r) => [
            'title' => $r['title'],
            'sub'   => $r['project_name'] . ' · ' . (TASK_STATUSES[$r['status']]['label'] ?? $r['status']),
            'link'  => "/modules/projects/detail.php?id={$r['project_id']}&tab=tasks",
        ], $rows)];
    }

    if (has_permission('contacts.view')) {
        $rows = $run("SELECT id, company_title, authorized_person, phone, email, type, tax_number FROM contacts
                      WHERE company_title LIKE ? OR authorized_person LIKE ? OR phone LIKE ? OR email LIKE ? OR tax_number LIKE ? ORDER BY company_title", 5);
        $groups['Cariler'] = ['icon' => 'users', 'items' => array_map(fn($r) => [
            'title' => $r['company_title'],
            'sub'   => (CONTACT_TYPES[$r['type']] ?? $r['type']) . ' · ' . trim(($r['authorized_person'] ?? '') . ' ' . ($r['phone'] ?? '') . ' ' . ($r['email'] ?? '')),
            'link'  => "/modules/contacts/detail.php?id={$r['id']}",
        ], $rows)];
    }

    if (has_permission('finance.invoices') || has_permission('finance.view')) {
        $rows = $run("SELECT i.id, i.invoice_number, i.invoice_type, i.grand_total, i.payment_status, i.issue_date, i.contact_id, c.company_title FROM invoices i LEFT JOIN contacts c ON i.contact_id = c.id
                      WHERE i.invoice_number LIKE ? OR c.company_title LIKE ? OR i.notes LIKE ? ORDER BY i.issue_date DESC", 3);
        $groups['Faturalar'] = ['icon' => 'receipt', 'items' => array_map(fn($r) => [
            'title' => $r['invoice_number'] . ' · ' . format_money($r['grand_total']),
            'sub'   => ($r['invoice_type'] === 'sales' ? 'Satış' : 'Alış') . ' · ' . ($r['company_title'] ?? '-') . ' · ' . format_date($r['issue_date']) . ' · ' . (['paid' => 'Ödendi', 'partial' => 'Kısmi', 'unpaid' => 'Ödenmedi'][$r['payment_status']] ?? ''),
            'link'  => "/modules/contacts/detail.php?id={$r['contact_id']}&tab=invoices",
        ], $rows)];
    }

    if (can_access_module('proposals.manage')) {
        $rows = $run("SELECT p.id, p.proposal_code, p.title, p.grand_total, p.currency, c.company_title FROM proposals p LEFT JOIN contacts c ON p.client_id = c.id
                      WHERE p.proposal_code LIKE ? OR p.title LIKE ? OR c.company_title LIKE ? ORDER BY p.id DESC", 3);
        $groups['Teklifler'] = ['icon' => 'kanban', 'items' => array_map(fn($r) => [
            'title' => $r['proposal_code'] . ' · ' . $r['title'],
            'sub'   => ($r['company_title'] ?? '-') . ' · ' . format_money($r['grand_total'], $r['currency']),
            'link'  => "/modules/proposals/create.php?id={$r['id']}",
        ], $rows)];
    }

    if (can_access_module('inventory.manage')) {
        $rows = $run("SELECT id, item_name, serial_number, status FROM equipment WHERE item_name LIKE ? OR serial_number LIKE ? ORDER BY item_name", 2);
        $groups['Ekipmanlar'] = ['icon' => 'camera', 'items' => array_map(fn($r) => [
            'title' => $r['item_name'],
            'sub'   => 'Seri No: ' . ($r['serial_number'] ?: '-'),
            'link'  => '/modules/inventory/index.php?search=' . urlencode($r['item_name']),
        ], $rows)];
    }

    $groups = array_filter($groups, fn($g) => !empty($g['items']));
}

$total = array_sum(array_map(fn($g) => count($g['items']), $groups));

$page_title = 'Arama';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <form method="GET" class="mb-6">
        <div class="relative">
            <i data-lucide="search" class="w-5 h-5 text-slate-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
            <input type="search" name="q" value="<?= e($q) ?>" autofocus placeholder="Proje adı, müşteri, telefon, fatura no, teklif no, seri no..."
                   class="w-full pl-12 pr-4 py-3.5 bg-white border border-slate-200 rounded-2xl text-sm shadow-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
        </div>
    </form>

    <?php if ($q === ''): ?>
        <p class="text-center text-sm text-slate-400 py-12">Aramak istediğiniz kelimeyi yazın.</p>
    <?php elseif (mb_strlen($q) < 2): ?>
        <p class="text-center text-sm text-slate-400 py-12">En az 2 karakter giriniz.</p>
    <?php elseif ($total === 0): ?>
        <p class="text-center text-sm text-slate-400 py-12">“<?= e($q) ?>” için sonuç bulunamadı.</p>
    <?php else: ?>
        <p class="text-xs text-slate-500 mb-3">“<?= e($q) ?>” için <?= $total ?> sonuç</p>
        <div class="space-y-4">
            <?php foreach ($groups as $gname => $g): ?>
            <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-700 flex items-center gap-2">
                    <i data-lucide="<?= $g['icon'] ?>" class="w-4 h-4"></i> <?= $gname ?> (<?= count($g['items']) ?>)
                </div>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($g['items'] as $it): ?>
                        <a href="<?= BASE_URL . e($it['link']) ?>" class="block px-5 py-3 hover:bg-slate-50">
                            <p class="text-sm font-bold text-slate-900"><?= e($it['title']) ?></p>
                            <p class="text-xs text-slate-500"><?= e($it['sub']) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
