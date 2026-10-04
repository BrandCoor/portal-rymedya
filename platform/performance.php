<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - PERFORMANS KARNESİ (FREELANCER)
 * ====================================================================
 * Puan, seviye, eşzamanlı iş limiti ve bir sonraki seviye için gerekenler.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile = require_platform_role('freelancer');
$uid     = (int)$profile['user_id'];

if (empty($profile['metrics_updated_at']) || strtotime($profile['metrics_updated_at']) < time() - 6 * 3600) {
    $profile = recompute_freelancer_metrics($uid) ?: $profile;
}
$cap  = freelancer_capacity($profile);
$next = next_tier_progress($profile);
$score = $profile['score'] !== null ? (float)$profile['score'] : null;

// Bileşenler (recompute_freelancer_metrics ile aynı ağırlıklar)
$ratings = [];
foreach (['rating_avg', 'agency_rating_avg'] as $k) {
    if ($profile[$k] !== null) $ratings[] = (float)$profile[$k];
}
$rating_pct = $ratings ? array_sum($ratings) / count($ratings) / 5 * 100 : null;
$rev_pct    = $profile['avg_revisions'] !== null ? (1 - min((float)$profile['avg_revisions'] / 3, 1)) * 100 : null;
$incidents  = (int)$profile['releases_count'] + (int)$profile['removed_count'];
$done       = (int)$profile['completed_jobs'];
$rel_pct    = $done + $incidents > 0 ? max(0, 1 - ($incidents / max(1, $done + $incidents)) * 2) * 100 : null;
$components = [
    ['Değerlendirme puanı', 35, $rating_pct, 'Ekip ve müşteri puanlarının ortalaması'],
    ['Zamanında teslim', 25, $profile['on_time_rate'] !== null ? (float)$profile['on_time_rate'] : null, 'İlk teslimin teslim tarihine kadar yapılması'],
    ['Kalite kontrol', 20, $profile['qa_pass_rate'] !== null ? (float)$profile['qa_pass_rate'] : null, 'Kalite kontrolden ilk seferde geçme'],
    ['Revizyon yükü', 10, $rev_pct, 'İş başına ortalama ' . ($profile['avg_revisions'] !== null ? number_format((float)$profile['avg_revisions'], 1, ',', '') : '—') . ' revizyon'],
    ['Güvenilirlik', 10, $rel_pct, $incidents ? "{$incidents} kez iş bırakıldı veya geri alındı" : 'Bırakılan iş yok'],
];

$st = $db->prepare("SELECT id, job_code, title, deadline, first_delivered_at, completed_at, revision_count, freelancer_rating, agency_rating, freelancer_fee, currency
                    FROM platform_jobs WHERE assigned_user_id = ? AND assigned_type = 'freelancer' AND status = 'completed' ORDER BY completed_at DESC LIMIT 12");
$st->execute([$uid]);
$recent = $st->fetchAll();

platform_header('Performans', 'performance');
?>
<div class="page-head">
    <div>
        <h1 class="h1">Performans karnesi</h1>
        <p class="sub">Puanınız tamamlanan işlerinizden hesaplanır; seviyeniz ve aynı anda alabileceğiniz iş sayısı buna göre belirlenir.</p>
    </div>
    <?php if ($profile['metrics_updated_at']): ?><span class="xsmall text-faint">Son güncelleme <?= time_ago($profile['metrics_updated_at']) ?></span><?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6" style="margin-bottom:24px">
    <section class="card">
        <div class="card-pad" style="display:flex;flex-direction:column;align-items:center;text-align:center;padding:28px 20px">
            <?= ui_score_ring($score, $score === null ? 'henüz yok' : '/ 100', 132) ?>
            <div style="margin-top:16px;display:flex;gap:8px;align-items:center"><?= tier_badge($profile['tier']) ?><?php if ((int)$profile['tier_locked'] === 1): ?><span class="xsmall text-muted">ekip tarafından sabitlendi</span><?php endif; ?></div>
            <p class="small text-muted" style="margin-top:10px"><?= $done ?> tamamlanan iş<?= (int)$profile['late_count'] ? ' · ' . (int)$profile['late_count'] . ' gecikme' : '' ?></p>
        </div>
        <div class="card-foot">
            <div style="display:flex;justify-content:space-between" class="small"><span class="text-muted">Aktif iş kapasitesi</span><span class="num" style="font-weight:600"><?= $cap['active'] ?> / <?= $cap['limit'] ?></span></div>
            <div class="progress <?= $cap['active'] >= $cap['limit'] ? 'tone-warning' : '' ?>" style="margin-top:8px"><span style="width:<?= min(100, $cap['limit'] ? $cap['active'] / $cap['limit'] * 100 : 100) ?>%"></span></div>
        </div>
    </section>

    <section class="card lg:col-span-2">
        <div class="card-head"><div><p class="card-title">Puanın bileşenleri</p><p class="card-sub">Ağırlıklar sabittir; her tamamlanan iş ve değerlendirme sonrası yeniden hesaplanır.</p></div></div>
        <div class="card-pad stack">
            <?php foreach ($components as [$label, $w, $pct, $hint]): ?>
                <div>
                    <?= ui_meter($label . ' · %' . $w, $pct) ?>
                    <p class="xsmall text-faint" style="margin-top:4px"><?= e($hint) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if ($score === null): ?>
                <div class="alert alert-neutral"><i data-lucide="info"></i><div>İlk işinizi tamamladığınızda puanınız oluşur. O zamana kadar <?= e(tier_label($profile['tier'])) ?> seviyesindesiniz.</div></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="card lg:col-span-2">
        <div class="card-head"><p class="card-title">Seviyeler</p><?php if ($next): ?><span class="xsmall text-muted">Sıradaki: <?= e(tier_label($next['tier'])) ?></span><?php endif; ?></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Seviye</th><th>Koşullar</th><th class="r">Eşzamanlı iş</th><th></th></tr></thead>
                <tbody>
                <?php foreach (FREELANCER_TIERS as $tk => $tv):
                    $is_cur = $profile['tier'] === $tk; ?>
                    <tr style="<?= $is_cur ? 'background:var(--surface-2)' : '' ?>">
                        <td><?= tier_badge($tk) ?></td>
                        <td class="xsmall text-ink-2" style="max-width:360px"><?= e(tier_rule_summary($tk)) ?></td>
                        <td class="r num" style="font-weight:600"><?= tier_job_limit($tk) ?></td>
                        <td class="r"><?= $is_cur ? '<span class="xsmall" style="font-weight:500">Siz buradasınız</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-foot xsmall text-muted">Üst seviyeler daha yüksek bütçeli işleri ve öncelikli erişim süresindeki işleri görür.</div>
    </section>

    <section class="card">
        <div class="card-head"><p class="card-title">Sonraki seviye</p></div>
        <div class="card-pad stack">
            <?php if (!$next): ?>
                <p class="small text-ink-2">En üst seviyedesiniz. Puanınızı koruyarak önceliklerinizi sürdürün.</p>
            <?php else: ?>
                <p class="small text-ink-2"><strong><?= e(tier_label($next['tier'])) ?></strong> seviyesinde aynı anda <?= tier_job_limit($next['tier']) ?> iş alabilirsiniz.</p>
                <?php foreach ($next['checks'] as $c): ?>
                <div>
                    <div class="meter-row small" style="display:flex;justify-content:space-between;gap:8px">
                        <span class="text-muted" style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="<?= $c['pass'] ? 'circle-check' : 'circle' ?>" style="width:14px;height:14px;color:<?= $c['pass'] ? 'var(--success)' : 'var(--faint)' ?>"></i><?= e($c['label']) ?></span>
                        <span class="num"><?= e(tier_value_text($c)) ?> <span class="text-faint">/ <?= e($c['op']) ?> <?= rtrim(rtrim(number_format($c['need'], 1, ',', ''), '0'), ',') ?><?= $c['unit'] === '%' ? '%' : ($c['unit'] === '★' ? '★' : '') ?></span></span>
                    </div>
                    <div class="progress <?= $c['pass'] ? 'tone-success' : '' ?>" style="margin-top:6px"><span style="width:<?= tier_check_progress($c) ?>%"></span></div>
                </div>
                <?php endforeach; ?>
                <?php if (platform_setting('platform_auto_tier') !== '1' || (int)$profile['tier_locked'] === 1): ?>
                    <p class="xsmall text-muted">Seviye geçişleri ekip tarafından yapılır.</p>
                <?php else: ?>
                    <p class="xsmall text-muted">Şartları sağladığınızda seviyeniz otomatik yükselir.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card" style="margin-top:24px">
    <div class="card-head"><p class="card-title">Son tamamlanan işler</p></div>
    <?php if (!$recent): ?>
        <?= ui_empty('Henüz tamamlanan iş yok', 'İş havuzundan uygun bir iş alarak başlayın.', 'briefcase', '<a class="btn btn-secondary" href="' . BASE_URL . '/platform/pool.php">İş havuzu</a>') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>İş</th><th>Teslim</th><th class="r">Revizyon</th><th>Ekip puanı</th><th>Müşteri puanı</th><th class="r">Hakediş</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r):
                $on_time = $r['deadline'] && $r['first_delivered_at'] ? strtotime($r['first_delivered_at']) <= strtotime($r['deadline'] . ' 23:59:59') : null; ?>
                <tr class="row-link" onclick="location.href='<?= BASE_URL ?>/platform/job.php?id=<?= (int)$r['id'] ?>'">
                    <td><span class="code-tag"><?= e($r['job_code']) ?></span><div style="font-weight:500"><?= e($r['title']) ?></div></td>
                    <td><?= $on_time === null ? '<span class="text-muted">—</span>' : ($on_time ? ui_badge('Zamanında', 'success', true) : ui_badge('Gecikmeli', 'danger', true)) ?></td>
                    <td class="r num"><?= (int)$r['revision_count'] ?></td>
                    <td><?= render_stars($r['freelancer_rating'] !== null ? (float)$r['freelancer_rating'] : null) ?></td>
                    <td><?= render_stars($r['agency_rating'] !== null ? (float)$r['agency_rating'] : null) ?></td>
                    <td class="r money"><?= format_money((float)$r['freelancer_fee'], $r['currency']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php platform_footer();
