<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - GÖREV PANOSU (TÜM PROJELER)
 * ====================================================================
 * Kurgucu, yönetmen ve yapımcıların tüm projelerdeki işlerini tek ekranda
 * görmesini sağlar. Varsayılan görünüm: bana atanan açık görevler.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_permission('projects.view');

$scope    = $_GET['scope'] ?? 'mine';           // mine | all
$status   = $_GET['status'] ?? 'open';          // open | todo | in_progress | done | all
$assignee = (int)($_GET['assignee'] ?? 0);

$sql = "
    SELECT t.*, u.full_name AS assignee_name, p.project_name, p.project_code, c.company_title AS client_name
    FROM project_tasks t
    JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assigned_user_id = u.id
    LEFT JOIN contacts c ON p.client_id = c.id
    WHERE 1=1
";
$params = [];

if ($scope === 'mine') {
    $sql .= " AND t.assigned_user_id = ?";
    $params[] = (int)$user['id'];
} elseif ($assignee > 0) {
    $sql .= " AND t.assigned_user_id = ?";
    $params[] = $assignee;
}

if ($status === 'open') {
    $sql .= " AND t.status != 'done'";
} elseif ($status === 'overdue') {
    $sql .= " AND t.status != 'done' AND t.due_date < CURRENT_DATE()";
} elseif (array_key_exists($status, TASK_STATUSES)) {
    $sql .= " AND t.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY FIELD(t.status, 'in_progress', 'todo', 'done'), (t.due_date IS NULL), t.due_date ASC, FIELD(t.priority, 'high', 'normal', 'low'), t.id DESC LIMIT 300";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$users_list = $db->query("
    SELECT u.id, u.full_name FROM users u LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.status = 'active' AND (u.role_id = 1 OR (u.contact_id IS NULL AND COALESCE(r.role_slug, '') != 'client'))
    ORDER BY u.full_name
")->fetchAll();

$my_open    = (int)$db->query("SELECT COUNT(*) FROM project_tasks WHERE assigned_user_id = " . (int)$user['id'] . " AND status != 'done'")->fetchColumn();
$my_overdue = (int)$db->query("SELECT COUNT(*) FROM project_tasks WHERE assigned_user_id = " . (int)$user['id'] . " AND status != 'done' AND due_date < CURRENT_DATE()")->fetchColumn();
$all_open   = (int)$db->query("SELECT COUNT(*) FROM project_tasks WHERE status != 'done'")->fetchColumn();

$page_title = 'Görevler';
require_once __DIR__ . '/../../includes/header.php';

$qs = function (array $over) use ($scope, $status, $assignee) {
    return '?' . http_build_query(array_merge(['scope' => $scope, 'status' => $status, 'assignee' => $assignee ?: null], $over));
};
?>

<div class="mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Görev Panosu</h1>
        <p class="text-xs text-slate-500 mt-0.5">Tüm projelerdeki işler. Görev eklemek için ilgili projenin "Görevler" sekmesini kullanın.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs">
        <span class="px-3 py-2 bg-white border border-slate-200 rounded-xl font-bold text-slate-700">Bana Açık: <?= $my_open ?></span>
        <span class="px-3 py-2 bg-white border <?= $my_overdue ? 'border-rose-300 text-rose-700' : 'border-slate-200 text-slate-700' ?> rounded-xl font-bold">Geciken: <?= $my_overdue ?></span>
        <span class="px-3 py-2 bg-white border border-slate-200 rounded-xl font-bold text-slate-700">Ekipte Açık: <?= $all_open ?></span>
    </div>
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-2 bg-white p-3 rounded-2xl border border-slate-200 shadow-sm text-xs">
    <div class="inline-flex bg-slate-100 rounded-xl p-1 font-bold">
        <a href="<?= $qs(['scope' => 'mine']) ?>" class="px-3 py-1.5 rounded-lg <?= $scope === 'mine' ? 'bg-white shadow text-brand-700' : 'text-slate-500' ?>">Bana Atananlar</a>
        <a href="<?= $qs(['scope' => 'all']) ?>" class="px-3 py-1.5 rounded-lg <?= $scope === 'all' ? 'bg-white shadow text-brand-700' : 'text-slate-500' ?>">Tüm Ekip</a>
    </div>
    <input type="hidden" name="scope" value="<?= e($scope) ?>">
    <div>
        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Durum</label>
        <select name="status" onchange="this.form.submit()" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl">
            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Açık Görevler</option>
            <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Gecikenler</option>
            <?php foreach (TASK_STATUSES as $k => $v): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
            <?php endforeach; ?>
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Tümü</option>
        </select>
    </div>
    <?php if ($scope === 'all'): ?>
    <div>
        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Sorumlu</label>
        <select name="assignee" onchange="this.form.submit()" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl">
            <option value="0">Herkes</option>
            <?php foreach ($users_list as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $assignee === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</form>

<div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
    <?php if (empty($tasks)): ?>
        <div class="py-16 text-center text-slate-400">
            <i data-lucide="party-popper" class="w-10 h-10 mx-auto mb-2 opacity-40"></i>
            <p class="text-sm">Bu filtrede görev yok.</p>
        </div>
    <?php else: ?>
    <div class="divide-y divide-slate-100">
        <?php foreach ($tasks as $t):
            $ts = TASK_STATUSES[$t['status']] ?? TASK_STATUSES['todo'];
            $tp = TASK_PRIORITIES[$t['priority']] ?? TASK_PRIORITIES['normal'];
            $overdue = $t['status'] !== 'done' && !empty($t['due_date']) && $t['due_date'] < date('Y-m-d');
            $can_toggle = has_permission('projects.edit') || (int)$t['assigned_user_id'] === (int)$user['id'];
            $next = $t['status'] === 'todo' ? 'in_progress' : ($t['status'] === 'in_progress' ? 'done' : 'todo');
            $next_label = ['in_progress' => 'Başla', 'done' => 'Tamamla', 'todo' => 'Yeniden Aç'][$next];
        ?>
        <div class="p-4 flex flex-col md:flex-row md:items-center justify-between gap-3 <?= $overdue ? 'bg-rose-50/40' : '' ?>">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-bold <?= $tp['color'] ?> text-xs">●</span>
                    <p class="text-sm font-bold <?= $t['status'] === 'done' ? 'line-through text-slate-400' : 'text-slate-900' ?>"><?= e($t['title']) ?></p>
                    <span class="px-2 py-0.5 rounded-full border text-[10px] font-bold <?= $ts['color'] ?>"><?= $ts['label'] ?></span>
                </div>
                <p class="text-xs text-slate-500 mt-1">
                    <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= (int)$t['project_id'] ?>&tab=tasks" class="font-semibold text-brand-600 hover:underline"><?= e($t['project_code']) ?> · <?= e($t['project_name']) ?></a>
                    <?= !empty($t['client_name']) ? ' · ' . e($t['client_name']) : '' ?>
                </p>
                <p class="text-[11px] mt-1 <?= $overdue ? 'text-rose-600 font-bold' : 'text-slate-400' ?>">
                    <?= e($t['assignee_name'] ?? 'Atanmadı') ?>
                    <?= !empty($t['due_date']) ? ' · ' . format_date($t['due_date']) . ($overdue ? ' (gecikti)' : '') : '' ?>
                </p>
            </div>
            <?php if ($can_toggle): ?>
            <form method="POST" action="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= (int)$t['project_id'] ?>" class="flex-shrink-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_task">
                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="status" value="<?= $next ?>">
                <input type="hidden" name="return_to" value="tasks">
                <button type="submit" class="px-3.5 py-2 rounded-xl text-xs font-bold <?= $next === 'done' ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-slate-100 hover:bg-slate-200 text-slate-700' ?>"><?= $next_label ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
