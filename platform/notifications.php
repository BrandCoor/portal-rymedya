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
<div style="max-width:760px;margin:0 auto">
    <div class="page-head"><div><h1 class="h1">Bildirimler</h1><p class="sub">Siparişler, atamalar ve teslimatlarla ilgili son 100 bildirim.</p></div></div>
    <div class="card">
        <?php if (!$items): ?>
            <?= ui_empty('Bildiriminiz yok', 'Siparişlerinizde bir gelişme olduğunda burada görünecek.', 'bell') ?>
        <?php else: ?>
        <div class="divide">
            <?php $day = null; foreach ($items as $n): $d = substr($n['created_at'], 0, 10); ?>
                <?php if ($d !== $day): $day = $d; ?>
                    <p class="eyebrow" style="padding:14px 20px 6px;background:var(--surface-2)"><?= $d === date('Y-m-d') ? 'Bugün' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'Dün' : format_date($d)) ?></p>
                <?php endif; ?>
                <a href="<?= BASE_URL . e($n['link'] ?: '/platform/index.php') ?>" style="display:flex;gap:12px;padding:13px 20px;align-items:flex-start" class="hover:bg-slate-50">
                    <span style="width:7px;height:7px;border-radius:50%;margin-top:7px;flex-shrink:0;background:<?= (int)$n['id'] > $last ? 'var(--accent)' : 'transparent' ?>"></span>
                    <span style="flex:1;min-width:0">
                        <span class="small text-ink" style="display:block;line-height:1.5;<?= (int)$n['id'] > $last ? 'font-weight:500' : '' ?>"><?= e($n['message']) ?></span>
                        <span class="xsmall text-faint"><?= date('H:i', strtotime($n['created_at'])) ?></span>
                    </span>
                    <i data-lucide="chevron-right" style="width:15px;height:15px;color:var(--faint);margin-top:3px"></i>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php platform_footer();
