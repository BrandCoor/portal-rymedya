<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - BİLDİRİMLER (AJANS & FREELANCER)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

require_client_login(['agency', 'freelancer']);
$uid = (int)$_SESSION['client_user_id'];

$st = $db->prepare("SELECT * FROM activity_log WHERE target_user_id = ? ORDER BY id DESC LIMIT 100");
$st->execute([$uid]);
$items = $st->fetchAll();
$seen = $db->prepare("SELECT last_seen_id FROM user_notification_state WHERE user_id = ?");
$seen->execute([$uid]);
$last = (int)$seen->fetchColumn();
mark_notifications_read($uid);

platform_header('Bildirimler', '');
?>
<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-black text-slate-900 mb-5">Bildirimler</h1>
    <div class="bg-white border border-slate-200 rounded-2xl divide-y divide-slate-100">
        <?php if (!$items): ?>
            <p class="py-12 text-center text-sm text-slate-400">Bildiriminiz yok.</p>
        <?php else: foreach ($items as $n): ?>
            <a href="<?= BASE_URL . e($n['link'] ?: '/platform/index.php') ?>" class="block p-4 hover:bg-slate-50 <?= (int)$n['id'] > $last ? 'bg-sky-50/60' : '' ?>">
                <p class="text-sm text-slate-800"><?= e($n['message']) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5"><?= format_date($n['created_at'], true) ?></p>
            </a>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php platform_footer();
