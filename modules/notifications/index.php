<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - BİLDİRİMLER & AKTİVİTE GÜNLÜĞÜ
 * ====================================================================
 * "Bana Gelenler": müşteri hareketleri ve bana atanan işler.
 * "Tüm Aktivite": sistemdeki tüm önemli işlemler (yalnızca yöneticiler).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();

$view     = ($_GET['view'] ?? 'mine') === 'all' && has_permission('settings.manage') ? 'all' : 'mine';
$per_page = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$user_id  = (int)$user['id'];

if ($view === 'all') {
    $where  = '1=1';
    $params = [];
} else {
    [$where, $params] = notification_scope_sql($user_id);
}

$total = 0;
$items = [];
try {
    $cnt = $db->prepare("SELECT COUNT(*) FROM activity_log a WHERE {$where}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $st = $db->prepare("SELECT a.* FROM activity_log a WHERE {$where} ORDER BY a.id DESC LIMIT {$per_page} OFFSET {$offset}");
    $st->execute($params);
    $items = $st->fetchAll();
} catch (Throwable $e) {
    $items = [];
}

// Sayfa açıldığında okunmamış bildirimler okundu sayılır
$seen = $db->prepare("SELECT last_seen_id FROM user_notification_state WHERE user_id = ?");
$seen->execute([$user_id]);
$last_seen = (int)$seen->fetchColumn();
mark_notifications_read($user_id);

$actor_icons = ['client' => 'user-round', 'staff' => 'briefcase', 'system' => 'cpu'];
$total_pages = max(1, (int)ceil($total / $per_page));

$page_title = 'Bildirimler & Aktivite';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Bildirimler & Aktivite</h1>
            <p class="text-xs text-slate-500 mt-0.5">Müşteri onayları, revizyon talepleri, görev atamaları ve tahsilatlar.</p>
        </div>
        <?php if (has_permission('settings.manage')): ?>
        <div class="inline-flex bg-white border border-slate-200 rounded-xl p-1 text-xs font-bold">
            <a href="?view=mine" class="px-3 py-1.5 rounded-lg <?= $view === 'mine' ? 'bg-brand-600 text-white' : 'text-slate-600' ?>">Bana Gelenler</a>
            <a href="?view=all" class="px-3 py-1.5 rounded-lg <?= $view === 'all' ? 'bg-brand-600 text-white' : 'text-slate-600' ?>">Tüm Aktivite</a>
        </div>
        <?php endif; ?>
    </div>

    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm divide-y divide-slate-100">
        <?php if (empty($items)): ?>
            <div class="py-16 text-center text-slate-400">
                <i data-lucide="bell-off" class="w-10 h-10 mx-auto mb-2 opacity-40"></i>
                <p class="text-sm">Gösterilecek bildirim yok.</p>
            </div>
        <?php else: foreach ($items as $it): $unread = $view === 'mine' && (int)$it['id'] > $last_seen; ?>
            <div class="p-4 flex items-start gap-3 <?= $unread ? 'bg-brand-50/50' : '' ?>">
                <div class="w-9 h-9 rounded-xl flex-shrink-0 flex items-center justify-center <?= $it['actor_type'] === 'client' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600' ?>">
                    <i data-lucide="<?= $actor_icons[$it['actor_type']] ?? 'circle' ?>" class="w-4 h-4"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-slate-800"><?= e($it['message']) ?></p>
                    <p class="text-[11px] text-slate-400 mt-0.5">
                        <?= e($it['actor_name'] ?? '') ?><?= $it['actor_type'] === 'client' ? ' (Müşteri)' : '' ?> · <?= format_date($it['created_at'], true) ?>
                    </p>
                </div>
                <?php if (!empty($it['link'])): ?>
                    <a href="<?= BASE_URL . e($it['link']) ?>" class="text-xs font-bold text-brand-600 hover:underline flex-shrink-0">Aç →</a>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="mt-4 flex justify-center gap-2 text-xs">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?view=<?= $view ?>&page=<?= $i ?>" class="px-3 py-1.5 rounded-lg border <?= $i === $page ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-slate-200 text-slate-600' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
