<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - GENEL BAKIŞ (AJANS / FREELANCER)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

require_client_login(['agency', 'freelancer']);
$role    = portal_role();
$profile = require_platform_role($role, false);
$uid     = (int)$_SESSION['client_user_id'];
$cid     = (int)$_SESSION['client_contact_id'];
$first   = explode(' ', trim($_SESSION['client_user']['full_name'] ?? ''))[0] ?? '';

platform_header('Genel bakış', 'index');

// ====================================================================
// ONAY BEKLEYEN HESAP
// ====================================================================
if ($profile['status'] !== 'approved'): ?>
    <div class="card" style="max-width:640px;margin:24px auto 0">
        <div class="card-pad" style="padding:32px">
            <span class="badge tone-warning has-dot">İnceleniyor</span>
            <h1 class="h1" style="margin-top:14px">Başvurunuz alındı</h1>
            <p class="text-muted" style="margin-top:8px">
                <?= $role === 'agency'
                    ? 'Ajans hesabınız platform ekibi tarafından onaylandığında sipariş verebileceksiniz. Bu genellikle bir iş günü içinde tamamlanır.'
                    : 'Profiliniz değerlendiriliyor. Onaylandığınızda uzmanlık alanınıza ve şehrinize uygun işler iş havuzunuzda görünecek.' ?>
            </p>
            <div class="hairline" style="margin:24px 0"></div>
            <ol class="stack-sm small text-ink-2" style="list-style:none;padding:0">
                <li style="display:flex;gap:10px"><span class="badge tone-success badge-square">1</span>Hesap oluşturuldu</li>
                <li style="display:flex;gap:10px"><span class="badge tone-warning badge-square">2</span>Ekip incelemesi</li>
                <li style="display:flex;gap:10px"><span class="badge badge-square">3</span><?= $role === 'agency' ? 'İlk siparişinizi verin' : 'İlk işinizi alın' ?></li>
            </ol>
            <div style="margin-top:24px;display:flex;gap:8px">
                <a href="<?= BASE_URL ?>/platform/profile.php" class="btn btn-primary">Profili tamamla</a>
                <a href="<?= BASE_URL ?>/client/logout.php" class="btn btn-ghost">Çıkış</a>
            </div>
        </div>
    </div>
<?php platform_footer(); exit; endif;

// ====================================================================
// AJANS
// ====================================================================
if ($role === 'agency'):
    $st = $db->prepare("SELECT * FROM platform_jobs WHERE agency_contact_id = ? ORDER BY id DESC");
    $st->execute([$cid]);
    $jobs = $st->fetchAll();
    $active = array_values(array_filter($jobs, fn($j) => !in_array($j['status'], ['completed', 'cancelled'], true)));
    $waiting = array_values(array_filter($jobs, fn($j) => in_array($j['status'], ['quote_sent', 'delivered'], true)));
    $completed = array_values(array_filter($jobs, fn($j) => $j['status'] === 'completed'));
    $year_spend = array_sum(array_map(fn($j) => (float)$j['agency_price'], array_filter($completed, fn($j) => substr((string)$j['completed_at'], 0, 4) === date('Y'))));
    $balance = recalculate_contact_balance($cid);
?>
    <div class="page-head">
        <div>
            <h1 class="h1">Merhaba <?= e($first) ?></h1>
            <p class="sub">Siparişlerinizin güncel durumu.</p>
        </div>
        <a href="<?= BASE_URL ?>/platform/job_new.php" class="btn btn-accent btn-lg"><i data-lucide="plus"></i>Yeni sipariş</a>
    </div>

    <div class="card" style="margin-bottom:24px">
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-label">Devam eden</div><div class="kpi-value"><?= count($active) ?></div><div class="kpi-meta">aktif sipariş</div></div>
            <div class="kpi"><div class="kpi-label">Onayınızı bekleyen</div><div class="kpi-value" style="<?= $waiting ? 'color:var(--accent)' : '' ?>"><?= count($waiting) ?></div><div class="kpi-meta">teslimat veya fiyat</div></div>
            <div class="kpi"><div class="kpi-label">Bu yıl harcama</div><div class="kpi-value"><?= format_money($year_spend) ?></div><div class="kpi-meta"><?= count($completed) ?> tamamlanan iş</div></div>
            <div class="kpi"><div class="kpi-label">Açık bakiye</div><div class="kpi-value"><?= format_money(max(0, $balance)) ?></div><div class="kpi-meta"><a class="link" href="<?= BASE_URL ?>/modules/contacts/statement_print.php" target="_blank">Ekstreyi görüntüle</a></div></div>
        </div>
    </div>

    <?php if ($waiting): ?>
    <section style="margin-bottom:28px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
            <span class="rec-dot"></span><h2 class="h2">Sizden yanıt bekleyenler</h2>
        </div>
        <div class="stack-sm"><?php foreach ($waiting as $j) platform_job_row($j, 'agency'); ?></div>
    </section>
    <?php endif; ?>

    <section>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h2 class="h2">Devam eden siparişler</h2>
            <a href="<?= BASE_URL ?>/platform/jobs.php?f=all" class="small link">Tümü</a>
        </div>
        <?php if (!$active): ?>
            <div class="card"><?= ui_empty('Aktif siparişiniz yok', 'Hizmet kataloğundan seçim yaparak dakikalar içinde sipariş verebilirsiniz.', 'clapperboard', '<a href="' . BASE_URL . '/platform/job_new.php" class="btn btn-primary"><i data-lucide="plus"></i>Sipariş oluştur</a>') ?></div>
        <?php else: ?>
            <div class="stack-sm"><?php foreach ($active as $j) if (!in_array($j['status'], ['quote_sent', 'delivered'], true)) platform_job_row($j, 'agency'); ?></div>
        <?php endif; ?>
    </section>

<?php
// ====================================================================
// FREELANCER
// ====================================================================
else:
    $st = $db->prepare("SELECT * FROM platform_jobs WHERE assigned_user_id = ? AND status NOT IN ('completed', 'cancelled') ORDER BY deadline IS NULL, deadline ASC");
    $st->execute([$uid]);
    $my_jobs = $st->fetchAll();
    $pool = visible_pool_jobs($profile);
    $cap = freelancer_capacity($profile);
    $pend = $db->prepare("SELECT COALESCE(SUM(i.grand_total - i.paid_amount), 0) FROM platform_jobs j JOIN invoices i ON i.id = j.purchase_invoice_id WHERE j.assigned_user_id = ? AND i.payment_status != 'paid'");
    $pend->execute([$uid]);
    $pending_pay = (float)$pend->fetchColumn();
    $next = next_tier_progress($profile);
    $needs_action = array_values(array_filter($my_jobs, fn($j) => in_array($j['status'], ['assigned', 'revision'], true)));
?>
    <div class="page-head">
        <div>
            <h1 class="h1">Merhaba <?= e($first) ?></h1>
            <p class="sub" style="display:flex;align-items:center;gap:8px">
                <?= tier_badge($profile['tier']) ?>
                <span><?= $cap['active'] ?> / <?= $cap['limit'] ?> iş kapasitesi kullanılıyor</span>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/platform/pool.php" class="btn btn-primary btn-lg"><i data-lucide="radar"></i>İş havuzu<?php if ($pool): ?><span class="badge tone-accent" style="margin-left:4px"><?= count($pool) ?></span><?php endif; ?></a>
    </div>

    <?php if ((int)$profile['is_available'] !== 1): ?>
        <div class="alert alert-neutral" style="margin-bottom:20px"><i data-lucide="moon"></i><span>Durumunuz <b>müsait değil</b>. Yeni iş alamazsınız. <a class="link" href="<?= BASE_URL ?>/platform/profile.php">Profilden değiştirin</a>.</span></div>
    <?php elseif (!$cap['can_take']): ?>
        <div class="alert alert-warning" style="margin-bottom:20px"><i data-lucide="gauge"></i><span>Seviyenizin eşzamanlı iş limitine ulaştınız (<?= $cap['limit'] ?>). Teslim ettikçe yeni iş alabilirsiniz; seviyeniz yükseldikçe limit artar.</span></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:24px">
        <div class="kpi-grid">
            <div class="kpi"><div class="kpi-label">Performans puanı</div><div class="kpi-value"><?= $profile['score'] !== null ? number_format((float)$profile['score'], 0) : '—' ?></div><div class="kpi-meta"><a class="link" href="<?= BASE_URL ?>/platform/performance.php">Karneyi gör</a></div></div>
            <div class="kpi"><div class="kpi-label">Size uygun işler</div><div class="kpi-value"><?= count($pool) ?></div><div class="kpi-meta">havuzda açık</div></div>
            <div class="kpi"><div class="kpi-label">Tamamlanan</div><div class="kpi-value"><?= (int)$profile['completed_jobs'] ?></div><div class="kpi-meta"><?= $profile['on_time_rate'] !== null ? '%' . number_format((float)$profile['on_time_rate'], 0) . ' zamanında' : 'henüz veri yok' ?></div></div>
            <div class="kpi"><div class="kpi-label">Bekleyen ödeme</div><div class="kpi-value"><?= format_money($pending_pay) ?></div><div class="kpi-meta"><a class="link" href="<?= BASE_URL ?>/platform/earnings.php">Kazanç</a></div></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 stack-lg">
            <?php if ($needs_action): ?>
            <section>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><span class="rec-dot"></span><h2 class="h2">Sizi bekleyen işler</h2></div>
                <div class="stack-sm"><?php foreach ($needs_action as $j) platform_job_row($j, 'freelancer'); ?></div>
            </section>
            <?php endif; ?>
            <section>
                <h2 class="h2" style="margin-bottom:12px">Üzerimdeki işler</h2>
                <?php $others = array_filter($my_jobs, fn($j) => !in_array($j['status'], ['assigned', 'revision'], true)); ?>
                <?php if (!$my_jobs): ?>
                    <div class="card"><?= ui_empty('Üzerinizde iş yok', 'İş havuzundan size uygun bir iş alabilirsiniz.', 'briefcase', '<a href="' . BASE_URL . '/platform/pool.php" class="btn btn-secondary">Havuza git</a>') ?></div>
                <?php elseif ($others): ?>
                    <div class="stack-sm"><?php foreach ($others as $j) platform_job_row($j, 'freelancer'); ?></div>
                <?php endif; ?>
            </section>
            <section>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <h2 class="h2">Havuzdaki yeni işler</h2>
                    <a href="<?= BASE_URL ?>/platform/pool.php" class="small link">Tümü</a>
                </div>
                <?php if (!$pool): ?>
                    <div class="card"><?= ui_empty('Şu an size uygun açık iş yok', 'Yeni işler yayınlandığında bildirim alırsınız. Uzmanlık ve şehir bilginizi güncel tutun.', 'radar') ?></div>
                <?php else: ?>
                    <div class="stack-sm"><?php foreach (array_slice($pool, 0, 4) as $j) platform_job_row($j, 'freelancer'); ?></div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="stack">
            <div class="card card-pad" style="display:flex;gap:18px;align-items:center">
                <?= ui_score_ring($profile['score'] !== null ? (float)$profile['score'] : null, 'puan', 96) ?>
                <div>
                    <p class="eyebrow">Seviye</p>
                    <p class="h2" style="margin-top:2px"><?= e(tier_label($profile['tier'])) ?></p>
                    <p class="small text-muted" style="margin-top:2px">Aynı anda <?= $cap['limit'] ?> iş</p>
                </div>
            </div>
            <?php if ($next): ?>
            <div class="card card-pad">
                <p class="eyebrow">Sonraki seviye: <?= e(tier_label($next['tier'])) ?></p>
                <div class="stack-sm" style="margin-top:12px">
                    <?= ui_meter('Puan (' . $next['need_score'] . ' gerekli)', $next['need_score'] ? min(100, $next['score'] / $next['need_score'] * 100) : 0) ?>
                    <?= ui_meter('Tamamlanan iş (' . $next['need_jobs'] . ' gerekli)', min(100, $next['jobs'] / max(1, $next['need_jobs']) * 100)) ?>
                </div>
                <p class="xsmall text-muted" style="margin-top:12px">Seviye yükseldikçe daha değerli işleri görür ve aynı anda daha fazla iş alabilirsiniz.</p>
            </div>
            <?php endif; ?>
        </aside>
    </div>
<?php endif;

platform_footer();
