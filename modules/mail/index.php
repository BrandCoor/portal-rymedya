<?php
/**
 * ====================================================================
 * E-POSTA MERKEZİ - GENEL BAKIŞ & KAMPANYALAR
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('mail.manage');

$camps = $db->query("SELECT c.*, u.full_name FROM mail_campaigns c LEFT JOIN users u ON u.id = c.created_by ORDER BY c.id DESC LIMIT 100")->fetchAll();
$subs  = (int)$db->query("SELECT COUNT(*) FROM mail_subscribers WHERE status = 'subscribed'")->fetchColumn();
$month = $db->query("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status IN ('queued', 'sending')) AS queued FROM mail_queue WHERE created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')")->fetch();
$queued_all = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status IN ('queued', 'sending')")->fetchColumn();
$notices = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE kind = 'notice' AND status = 'sent' AND sent_at >= CURRENT_DATE() - INTERVAL 7 DAY")->fetchColumn();
$camp_status = ['draft' => ['Taslak', 'neutral'], 'sending' => ['Gönderiliyor', 'warning'], 'sent' => ['Gönderildi', 'success'], 'cancelled' => ['Durduruldu', 'danger']];

$page_title = 'E-posta';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h1">E-posta</h1>
        <p class="sub">İşe bağlı bildirim e-postaları, toplu gönderimler ve abone listesi.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/modules/mail/subscribers.php" class="btn btn-secondary"><i data-lucide="users"></i>Aboneler</a>
        <a href="<?= BASE_URL ?>/modules/mail/log.php" class="btn btn-secondary"><i data-lucide="list"></i>Gönderim kayıtları</a>
        <a href="<?= BASE_URL ?>/modules/mail/campaign.php" class="btn btn-primary"><i data-lucide="plus"></i>Yeni toplu e-posta</a>
    </div>
</div>

<?php if (!mail_enabled()): ?>
    <div class="alert alert-warning" style="margin-bottom:16px"><i data-lucide="mail-warning"></i><div>
        <strong>E-posta gönderimi kapalı.</strong> Bildirim ve toplu e-postaların gitmesi için <a class="link" href="<?= BASE_URL ?>/modules/settings/index.php?tab=mail">Ayarlar → E-posta</a> bölümünden gönderim yöntemini (SMTP veya sunucu) ve gönderen adresini girip açın; ardından test e-postası gönderin.
    </div></div>
<?php else: ?>
    <div class="alert alert-success" style="margin-bottom:16px"><i data-lucide="mail-check"></i><div>
        E-posta gönderimi açık · <?= site_setting('mail_transport') === 'smtp' ? 'SMTP: ' . e(site_setting('smtp_host')) : 'sunucu mail()' ?> · gönderen <?= e(mail_from_email()) ?>.
        <?= site_setting('mail_notify_portal') === '1' ? 'Ajans, freelancer ve müşteri bildirimleri açık.' : 'Portal bildirimleri kapalı.' ?>
        <a class="link" href="<?= BASE_URL ?>/modules/settings/index.php?tab=mail">Ayarlar</a>
    </div></div>
<?php endif; ?>

<div class="card" style="margin-bottom:24px">
    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
        <div class="kpi"><div class="kpi-label">Aktif abone</div><div class="kpi-value"><?= $subs ?></div><div class="kpi-meta"><a class="link" href="<?= BASE_URL ?>/modules/mail/subscribers.php">Listeyi yönet</a></div></div>
        <div class="kpi"><div class="kpi-label">Bu ay gönderilen</div><div class="kpi-value"><?= (int)$month['sent'] ?></div><div class="kpi-meta"><?= (int)$month['failed'] ? "<span style='color:var(--danger)'>" . (int)$month['failed'] . ' hatalı</span>' : 'hata yok' ?></div></div>
        <div class="kpi"><div class="kpi-label">Kuyrukta bekleyen</div><div class="kpi-value"><?= $queued_all ?></div><div class="kpi-meta"><a class="link" href="<?= BASE_URL ?>/modules/mail/log.php?status=queued">Kuyruğu gör</a></div></div>
        <div class="kpi"><div class="kpi-label">Son 7 gün bildirim</div><div class="kpi-value"><?= $notices ?></div><div class="kpi-meta">işe bağlı e-posta</div></div>
    </div>
</div>

<section class="card">
    <div class="card-head"><p class="card-title">Toplu e-postalar</p></div>
    <?php if (!$camps): ?>
        <?= ui_empty('Henüz toplu e-posta yok', 'Müşterilerinize, ajanslara veya freelancer\'lara duyuru, kampanya ya da yeni iş fırsatı gönderin.', 'send', '<a class="btn btn-primary" href="' . BASE_URL . '/modules/mail/campaign.php">Yeni toplu e-posta</a>') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Kampanya</th><th>Hedef</th><th>Durum</th><th class="r">Gönderilen</th><th>Tarih</th></tr></thead>
            <tbody>
            <?php foreach ($camps as $c): [$sl, $stn] = $camp_status[$c['status']] ?? [$c['status'], 'neutral']; $aud = json_decode((string)$c['audience'], true) ?: []; ?>
                <tr class="row-link" onclick="location.href='<?= BASE_URL ?>/modules/mail/campaign.php?id=<?= (int)$c['id'] ?>'">
                    <td><div style="font-weight:500"><?= e($c['name']) ?></div><div class="xsmall text-muted"><?= e($c['subject']) ?></div></td>
                    <td class="xsmall text-muted"><?= e(implode(', ', array_map(fn($k) => MAIL_SUBSCRIBER_KINDS[$k] ?? $k, (array)($aud['kinds'] ?? [])))) ?: '—' ?><?= !empty($aud['tag']) ? ' · #' . e($aud['tag']) : '' ?></td>
                    <td><?= ui_badge($sl, $stn, true) ?></td>
                    <td class="r num"><?= $c['status'] === 'draft' ? '—' : (int)$c['sent'] . ' / ' . (int)$c['total'] ?><?= (int)$c['failed'] ? ' <span style="color:var(--danger)">(' . (int)$c['failed'] . ')</span>' : '' ?></td>
                    <td class="small"><?= format_date($c['queued_at'] ?: $c['created_at']) ?><div class="xsmall text-muted"><?= e($c['full_name'] ?? '') ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:24px">
    <div class="card-head"><p class="card-title">Otomatik gönderilen e-postalar</p></div>
    <div class="card-pad small text-ink-2 stack-sm">
        <p><strong>Ajans:</strong> sipariş yayına alındı, ekip atandı, üretime başlandı, teslim edildi, fiyat teklifi hazır, iş tamamlandı, ekipten mesaj, hesap onayı.</p>
        <p><strong>Freelancer:</strong> iş atandı, teklif kabul edilmedi (gerekçesiyle), kalite kontrol sonucu, revizyon talebi, hakediş ve ödeme, seviye değişikliği, size özel iş, ekipten mesaj, hesap onayı.</p>
        <p><strong>Müşteri:</strong> yeni kurgu versiyonu onayınıza sunuldu, teslim dosyası paylaşıldı, fatura düzenlendi, yeni fiyat teklifi.</p>
        <p><strong>Personel:</strong> görev ve kurgu ataması, müşteri revizyon talebi, tamamlanan görev; ekip gelen kutusu tanımlıysa tüm platform bildirimlerinin kopyası.</p>
        <p class="xsmall text-muted">Kişiler profil sayfalarından iş bildirimlerini ve duyuru e-postalarını ayrı ayrı kapatabilir.</p>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
