<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞLERİM (AJANS) / İŞLERİM (FREELANCER)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

require_client_login(['agency', 'freelancer']);
$role    = portal_role();
$profile = require_platform_role($role);
$uid     = (int)$_SESSION['client_user_id'];
$cid     = (int)$_SESSION['client_contact_id'];
$q       = trim($_GET['q'] ?? '');

$tabs = $role === 'agency'
    ? ['action' => 'Onayınızı bekleyen', 'active' => 'Devam eden', 'completed' => 'Tamamlanan', 'cancelled' => 'İptal', 'all' => 'Tümü']
    : ['active' => 'Aktif', 'offers' => 'Tekliflerim', 'completed' => 'Tamamlanan', 'all' => 'Tümü'];
$tab = array_key_exists($_GET['f'] ?? '', $tabs) ? $_GET['f'] : 'active';

// Sekme sayıları için tüm kayıtlar bir kez çekilir
if ($role === 'agency') {
    $st = $db->prepare("SELECT * FROM platform_jobs WHERE agency_contact_id = ? ORDER BY id DESC");
    $st->execute([$cid]);
} else {
    $st = $db->prepare("SELECT * FROM platform_jobs WHERE assigned_user_id = ? AND assigned_type = 'freelancer' ORDER BY id DESC");
    $st->execute([$uid]);
}
$all = $st->fetchAll();
if ($q !== '') {
    $needle = mb_strtolower($q);
    $all = array_values(array_filter($all, fn($j) => str_contains(mb_strtolower($j['title'] . ' ' . $j['job_code']), $needle)));
}

$buckets = [
    'action'    => fn($j) => in_array($j['status'], ['quote_sent', 'delivered'], true),
    'active'    => fn($j) => !in_array($j['status'], ['completed', 'cancelled'], true),
    'completed' => fn($j) => $j['status'] === 'completed',
    'cancelled' => fn($j) => $j['status'] === 'cancelled',
    'all'       => fn($j) => true,
];
$counts = [];
foreach ($tabs as $k => $_) {
    $counts[$k] = isset($buckets[$k]) ? count(array_filter($all, $buckets[$k])) : 0;
}

$applications = [];
if ($role === 'freelancer') {
    $st = $db->prepare("SELECT a.*, j.job_code, j.title, j.status AS job_status, j.freelancer_fee, j.currency, j.deadline, j.category
                        FROM platform_applications a JOIN platform_jobs j ON a.job_id = j.id
                        WHERE a.user_id = ? ORDER BY FIELD(a.status, 'pending', 'accepted', 'rejected', 'withdrawn'), a.id DESC");
    $st->execute([$uid]);
    $applications = $st->fetchAll();
    $counts['offers'] = count(array_filter($applications, fn($a) => $a['status'] === 'pending'));
}
$jobs = $tab === 'offers' ? [] : array_values(array_filter($all, $buckets[$tab]));

$app_status = [
    'pending'   => ['Değerlendiriliyor', 'info'],
    'accepted'  => ['Kabul edildi', 'success'],
    'rejected'  => ['Kabul edilmedi', 'neutral'],
    'withdrawn' => ['Geri çekildi', 'neutral'],
];

$title = $role === 'agency' ? 'İşler' : 'İşlerim';
platform_header($title, 'jobs');
?>
<div class="page-head">
    <div>
        <h1 class="h1"><?= $title ?></h1>
        <p class="sub"><?= $role === 'agency' ? 'Girdiğiniz tüm işler ve durumları.' : 'Size atanan işler ve verdiğiniz teklifler.' ?></p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
        <form method="GET" action="" class="searchbox hidden sm:block" style="width:240px">
            <input type="hidden" name="f" value="<?= e($tab) ?>">
            <i data-lucide="search"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Başlık veya kod" style="width:100%">
        </form>
        <?php if ($role === 'agency'): ?><a href="<?= BASE_URL ?>/platform/job_new.php" class="btn btn-accent"><i data-lucide="plus"></i>Yeni iş</a><?php endif; ?>
    </div>
</div>

<nav class="tabs" style="margin-bottom:20px">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="?f=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="tab <?= $tab === $k ? 'is-active' : '' ?>"><?= e($label) ?><?php if ($counts[$k] > 0 || $tab === $k): ?><span class="count"><?= $counts[$k] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'offers'): ?>
    <?php if (!$applications): ?>
        <div class="card"><?= ui_empty('Henüz teklif vermediniz', 'Teklif usulündeki işlere iş havuzundan teklif verebilirsiniz.', 'send', '<a class="btn btn-secondary" href="' . BASE_URL . '/platform/pool.php">İş havuzuna git</a>') ?></div>
    <?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>İş</th><th>Teklifiniz</th><th>Müsaitlik</th><th>Durum</th><th>Gerekçe / not</th></tr></thead>
                <tbody>
                <?php foreach ($applications as $a): [$al, $at] = $app_status[$a['status']] ?? [$a['status'], 'neutral']; ?>
                    <tr class="row-link" onclick="location.href='<?= BASE_URL ?>/platform/job.php?id=<?= (int)$a['job_id'] ?>'">
                        <td>
                            <span class="code-tag"><?= e($a['job_code']) ?></span>
                            <div style="font-weight:500;margin-top:2px"><?= e($a['title']) ?></div>
                            <div class="xsmall text-muted"><?= e(job_category_label($a['category'])) ?> · teslim <?= format_date($a['deadline']) ?></div>
                        </td>
                        <td class="money"><?= format_money((float)($a['proposed_fee'] ?? $a['freelancer_fee']), $a['currency']) ?></td>
                        <td class="small"><?= $a['available_from'] ? format_date($a['available_from']) : '—' ?></td>
                        <td><?= ui_badge($al, $at, true) ?><div class="xsmall text-faint" style="margin-top:4px"><?= time_ago($a['reviewed_at'] ?: $a['created_at']) ?></div></td>
                        <td class="small" style="max-width:280px">
                            <?php if ($a['status'] === 'rejected' && $a['reject_reason']): ?><span class="text-ink-2"><?= e($a['reject_reason']) ?></span>
                            <?php else: ?><span class="text-muted truncate-2"><?= e($a['note'] ?? '') ?></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php elseif (!$jobs): ?>
    <div class="card">
        <?php if ($role === 'agency'): ?>
            <?= ui_empty($q !== '' ? 'Aramanızla eşleşen iş yok' : 'Bu listede iş yok', $tab === 'action' ? 'Onayınızı bekleyen teklif veya teslimat bulunmuyor.' : 'Hizmet kataloğundan birkaç adımda iş girebilirsiniz.', 'briefcase', '<a class="btn btn-accent" href="' . BASE_URL . '/platform/job_new.php">Yeni iş</a>') ?>
        <?php else: ?>
            <?= ui_empty('Bu listede iş yok', 'Size uygun işler iş havuzunda listelenir.', 'radar', '<a class="btn btn-secondary" href="' . BASE_URL . '/platform/pool.php">İş havuzu</a>') ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="stack-sm">
        <?php foreach ($jobs as $j) platform_job_row($j, $role); ?>
    </div>
<?php endif; ?>
<?php platform_footer();
