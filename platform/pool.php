<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞ HAVUZU (FREELANCER)
 * ====================================================================
 * Yalnızca platform politikasına göre bu freelancer'a açılan işler listelenir
 * (seviye şartı, öncelik süresi, uzmanlık, şehir, seçili liste).
 * Seviye nedeniyle görülemeyen işlerin yalnızca sayısı gösterilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile   = require_platform_role('freelancer');
$uid       = (int)$profile['user_id'];
$pool_open = platform_setting('platform_pool_enabled') === '1';
$cap       = freelancer_capacity($profile);
$jobs      = $pool_open ? visible_pool_jobs($profile) : [];

// Seviye / öncelik nedeniyle kapalı işler (motivasyon için yalnızca sayı)
$locked = ['tier' => 0, 'priority' => 0];
if ($pool_open) {
    foreach ($db->query("SELECT * FROM platform_jobs WHERE status = 'open' AND visibility = 'pool'")->fetchAll() as $j) {
        $r = job_visibility_reason($j, $profile);
        // Yalnızca seviye yüzünden kapalı olanlar sayılır (uzmanlık / şehir uyumlu)
        if ($r === null || job_visibility_reason($j, ['tier' => 'elite'] + $profile) !== null) continue;
        if (str_starts_with($r, 'Seviye')) $locked['tier']++;
        if (str_starts_with($r, 'Öncelik')) $locked['priority']++;
    }
}

// Filtreler
$f_cat    = array_key_exists($_GET['category'] ?? '', JOB_CATEGORIES) ? $_GET['category'] : '';
$f_mode   = in_array($_GET['mode'] ?? '', ['first_come', 'application'], true) ? $_GET['mode'] : '';
$f_remote = !empty($_GET['remote']);
$f_rush   = !empty($_GET['rush']);
$sort     = in_array($_GET['sort'] ?? '', ['new', 'start', 'fee'], true) ? $_GET['sort'] : 'new';

$cats_available = array_count_values(array_column($jobs, 'category'));
$filtered = array_values(array_filter($jobs, function ($j) use ($f_cat, $f_mode, $f_remote, $f_rush) {
    if ($f_cat !== '' && $j['category'] !== $f_cat) return false;
    if ($f_mode !== '' && $j['dispatch_mode'] !== $f_mode) return false;
    if ($f_remote && (int)$j['is_remote'] !== 1) return false;
    if ($f_rush && (int)$j['is_rush'] !== 1) return false;
    return true;
}));
usort($filtered, function ($a, $b) use ($sort) {
    if ($sort === 'fee') return (float)$b['freelancer_fee'] <=> (float)$a['freelancer_fee'];
    if ($sort === 'start') return strcmp($a['start_date'] ?: $a['deadline'] ?: '9999', $b['start_date'] ?: $b['deadline'] ?: '9999');
    return [(int)$b['is_rush'], $b['published_at']] <=> [(int)$a['is_rush'], $a['published_at']];
});

$my_apps = [];
$st = $db->prepare("SELECT job_id, status FROM platform_applications WHERE user_id = ?");
$st->execute([$uid]);
foreach ($st->fetchAll() as $a) $my_apps[(int)$a['job_id']] = $a['status'];

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['category' => $f_cat, 'mode' => $f_mode, 'remote' => $f_remote ? 1 : '', 'rush' => $f_rush ? 1 : '', 'sort' => $sort !== 'new' ? $sort : ''], $over), fn($v) => $v !== '' && $v !== null));

platform_header('İş havuzu', 'pool');
?>
<div class="page-head">
    <div>
        <h1 class="h1">İş havuzu</h1>
        <p class="sub">Seviyenize, uzmanlığınıza ve şehrinize göre size açılan işler.</p>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
        <div style="text-align:right">
            <p class="xsmall text-muted">Aktif iş kapasitesi</p>
            <p class="num" style="font-weight:600"><?= $cap['active'] ?> / <?= $cap['limit'] ?></p>
        </div>
        <div style="width:120px" class="progress <?= $cap['active'] >= $cap['limit'] ? 'tone-warning' : '' ?>"><span style="width:<?= min(100, $cap['limit'] ? $cap['active'] / $cap['limit'] * 100 : 100) ?>%"></span></div>
        <?= tier_badge($profile['tier']) ?>
    </div>
</div>

<?php if (!$pool_open): ?>
    <div class="card"><?= ui_empty('İş havuzu geçici olarak kapalı', 'Ekip size doğrudan iş atadığında bildirim alacaksınız.', 'pause-circle') ?></div>
<?php platform_footer(); exit; endif; ?>

<?php if (!$cap['can_take']): ?>
    <div class="alert alert-warning" style="margin-bottom:16px"><i data-lucide="gauge"></i><div>
        <?php if ((int)$profile['is_available'] !== 1): ?>
            Durumunuz <strong>müsait değil</strong>; işleri görebilir ama alamazsınız. <a class="link" href="<?= BASE_URL ?>/platform/profile.php">Profilden değiştir</a>
        <?php else: ?>
            <strong>Aktif iş limitiniz dolu.</strong> <?= e(tier_label($profile['tier'])) ?> seviyesinde aynı anda <?= $cap['limit'] ?> iş yürütebilirsiniz. Bir işi teslim ettiğinizde yeni iş alabilirsiniz.
        <?php endif; ?>
    </div></div>
<?php endif; ?>

<?php if ($locked['tier'] || $locked['priority']): $next = next_tier_progress($profile); ?>
    <div class="panel" style="padding:12px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <i data-lucide="lock" style="width:16px;height:16px;color:var(--muted)"></i>
        <p class="small text-ink-2" style="flex:1;min-width:220px">
            <?php if ($locked['tier']): ?><strong><?= $locked['tier'] ?> iş</strong> daha yüksek seviye gerektiriyor<?php endif; ?>
            <?php if ($locked['tier'] && $locked['priority']): ?>, <?php endif; ?>
            <?php if ($locked['priority']): ?><strong><?= $locked['priority'] ?> iş</strong> şu an üst seviyelere öncelikli<?php endif; ?>.
            <?php if ($next): ?><span class="text-muted"><?= e(tier_label($next['tier'])) ?> için: <?= e(tier_rule_summary($next['tier'])) ?>.</span><?php endif; ?>
        </p>
        <a href="<?= BASE_URL ?>/platform/performance.php" class="btn btn-secondary btn-sm">Performansım</a>
    </div>
<?php endif; ?>

<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
    <div class="seg">
        <a href="<?= $qs(['category' => '']) ?>" class="<?= $f_cat === '' ? 'is-active' : '' ?>">Tümü <span class="text-muted num"><?= count($jobs) ?></span></a>
        <?php foreach ($cats_available as $ck => $n): ?>
            <a href="<?= $qs(['category' => $ck]) ?>" class="<?= $f_cat === $ck ? 'is-active' : '' ?>"><?= e(job_category_label($ck)) ?> <span class="text-muted num"><?= $n ?></span></a>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:6px;margin-left:auto;flex-wrap:wrap">
        <a href="<?= $qs(['mode' => $f_mode === 'first_come' ? '' : 'first_come']) ?>" class="btn btn-sm <?= $f_mode === 'first_come' ? 'btn-primary' : 'btn-secondary' ?>">Hemen alınabilir</a>
        <a href="<?= $qs(['mode' => $f_mode === 'application' ? '' : 'application']) ?>" class="btn btn-sm <?= $f_mode === 'application' ? 'btn-primary' : 'btn-secondary' ?>">Teklif usulü</a>
        <a href="<?= $qs(['remote' => $f_remote ? '' : 1]) ?>" class="btn btn-sm <?= $f_remote ? 'btn-primary' : 'btn-secondary' ?>">Uzaktan</a>
        <a href="<?= $qs(['rush' => $f_rush ? '' : 1]) ?>" class="btn btn-sm <?= $f_rush ? 'btn-primary' : 'btn-secondary' ?>">Acil</a>
        <select class="select" style="height:30px;font-size:12.5px;width:auto" onchange="location.href=this.value">
            <?php foreach (['new' => 'En yeni', 'start' => 'Tarihi en yakın', 'fee' => 'Hakediş yüksek'] as $sk => $sl): ?>
                <option value="<?= e($qs(['sort' => $sk === 'new' ? '' : $sk])) ?>" <?= $sort === $sk ? 'selected' : '' ?>><?= $sl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if (!$filtered): ?>
    <div class="card"><?= ui_empty($jobs ? 'Filtrelerle eşleşen iş yok' : 'Şu an size uygun açık iş yok', $jobs ? 'Filtreleri kaldırarak tüm işleri görebilirsiniz.' : 'Uzmanlık alanlarınızı ve şehrinizi güncel tutun; uygun iş geldiğinde bildirim alırsınız.', 'radar', $jobs ? '<a class="btn btn-secondary" href="?">Filtreleri temizle</a>' : '<a class="btn btn-secondary" href="' . BASE_URL . '/platform/profile.php">Profili düzenle</a>') ?></div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <?php foreach ($filtered as $j):
        $app = $my_apps[(int)$j['id']] ?? null;
        $ref = $j['start_date'] ?: $j['deadline'];
        $days = $ref ? (int)floor((strtotime($ref) - strtotime(date('Y-m-d'))) / 86400) : null;
        $priority_left = (!empty($j['priority_tier']) && (int)$j['priority_hours'] > 0 && $j['published_at'])
            ? strtotime($j['published_at']) + (int)$j['priority_hours'] * 3600 - time() : 0;
    ?>
    <a href="<?= BASE_URL ?>/platform/job.php?id=<?= (int)$j['id'] ?>" class="card card-hover" style="display:flex;flex-direction:column">
        <div class="card-pad" style="flex:1">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php if ((int)$j['is_rush'] === 1): ?><?= ui_badge('Acil', 'accent') ?><?php endif; ?>
                    <?= ui_badge($j['dispatch_mode'] === 'first_come' ? 'Hemen alınabilir' : 'Teklif usulü', $j['dispatch_mode'] === 'first_come' ? 'success' : 'neutral') ?>
                    <?php if ($j['visibility'] === 'selected'): ?><?= ui_badge('Size özel', 'violet') ?><?php endif; ?>
                    <?php if ($priority_left > 0): ?><?= ui_badge('Öncelikli erişim · ' . max(1, (int)ceil($priority_left / 3600)) . ' sa', 'warning') ?><?php endif; ?>
                    <?php if ($app === 'pending'): ?><?= ui_badge('Teklif verdiniz', 'info', true) ?><?php endif; ?>
                </div>
                <span class="code-tag"><?= e($j['job_code']) ?></span>
            </div>
            <p style="font-weight:600;font-size:15px;margin-top:12px;line-height:1.35"><?= e($j['title']) ?></p>
            <p class="small text-muted truncate-2" style="margin-top:6px"><?= e(mb_strimwidth((string)$j['description'], 0, 220, '…')) ?></p>
        </div>
        <div class="card-foot" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <span class="xsmall text-muted" style="display:inline-flex;gap:5px;align-items:center"><i data-lucide="<?= job_category_icon($j['category']) ?>" style="width:13px;height:13px"></i><?= e(job_category_label($j['category'])) ?></span>
            <span class="xsmall text-muted" style="display:inline-flex;gap:5px;align-items:center"><i data-lucide="map-pin" style="width:13px;height:13px"></i><?= (int)$j['is_remote'] === 1 ? 'Uzaktan' : e($j['location_city'] ?: '—') ?></span>
            <?php if ($ref): ?>
                <span class="xsmall" style="display:inline-flex;gap:5px;align-items:center;color:<?= $days !== null && $days <= 3 ? 'var(--warning)' : 'var(--muted)' ?>"><i data-lucide="calendar" style="width:13px;height:13px"></i><?= format_date($ref) ?><?= $days !== null ? ' · ' . ($days <= 0 ? 'bugün' : "{$days} gün") : '' ?></span>
            <?php endif; ?>
            <span class="money" style="margin-left:auto;font-size:15px"><?= format_money((float)$j['freelancer_fee'], $j['currency']) ?></span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php platform_footer();
