<?php
/**
 * ====================================================================
 * E-POSTA MERKEZİ - GÖNDERİM KAYITLARI (KUYRUK)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('mail.manage');
$self = BASE_URL . '/modules/mail/log.php';

// Tek e-postanın içeriği (önizleme)
if (isset($_GET['view'])) {
    $st = $db->prepare("SELECT body_html FROM mail_queue WHERE id = ?");
    $st->execute([(int)$_GET['view']]);
    header('Content-Security-Policy: script-src \'none\'');
    echo $st->fetchColumn() ?: 'Bulunamadı';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'process') {
        $r = mail_process_queue(max(1, (int)site_setting('mail_batch_size')));
        set_flash(isset($r['error']) ? 'error' : 'success', $r['error'] ?? "{$r['sent']} e-posta gönderildi" . ($r['failed'] ? ", {$r['failed']} hatalı" : '') . ". Kuyrukta {$r['remaining']} kaldı.");
    }
    if ($action === 'retry') {
        $db->prepare("UPDATE mail_queue SET status = 'queued', attempts = 0 WHERE id = ? AND status IN ('failed', 'skipped')")->execute([(int)$_POST['id']]);
        set_flash('success', 'Yeniden kuyruğa alındı.');
    }
    if ($action === 'retry_all') {
        $n = $db->exec("UPDATE mail_queue SET status = 'queued', attempts = 0 WHERE status = 'failed'");
        set_flash('success', "{$n} hatalı e-posta yeniden kuyruğa alındı.");
    }
    if ($action === 'purge') {
        $n = $db->exec("DELETE FROM mail_queue WHERE status IN ('sent', 'skipped') AND created_at < NOW() - INTERVAL 90 DAY");
        set_flash('success', "90 günden eski {$n} kayıt silindi.");
    }
    redirect($self . '?' . http_build_query(array_filter(['status' => $_GET['status'] ?? null, 'kind' => $_GET['kind'] ?? null, 'campaign' => $_GET['campaign'] ?? null, 'q' => $_GET['q'] ?? null])));
}

$status   = array_key_exists($_GET['status'] ?? '', MAIL_STATUSES) ? $_GET['status'] : '';
$kind     = array_key_exists($_GET['kind'] ?? '', MAIL_KINDS) ? $_GET['kind'] : '';
$campaign = (int)($_GET['campaign'] ?? 0);
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['p'] ?? 1));
$per      = 50;
$where = ['1=1']; $params = [];
if ($status)   { $where[] = 'status = ?'; $params[] = $status; }
if ($kind)     { $where[] = 'kind = ?'; $params[] = $kind; }
if ($campaign) { $where[] = 'campaign_id = ?'; $params[] = $campaign; }
if ($q !== '') { $where[] = '(to_email LIKE ? OR subject LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%"); }
$w = implode(' AND ', $where);
$c = $db->prepare("SELECT COUNT(*) FROM mail_queue WHERE {$w}");
$c->execute($params);
$total = (int)$c->fetchColumn();
$st = $db->prepare("SELECT id, kind, campaign_id, to_email, to_name, subject, status, attempts, last_error, created_at, sent_at FROM mail_queue WHERE {$w} ORDER BY id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per));
$st->execute($params);
$rows = $st->fetchAll();
$counts = $db->query("SELECT status, COUNT(*) FROM mail_queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['status' => $status, 'kind' => $kind, 'campaign' => $campaign ?: '', 'q' => $q], $o), fn($v) => $v !== '' && $v !== null));

$page_title = 'Gönderim kayıtları';
require_once __DIR__ . '/../../includes/header.php';
?>
<div x-data="{ view: null }">
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/mail/index.php">E-posta</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Gönderim kayıtları</span></div>
        <h1 class="h1">Gönderim kayıtları</h1>
        <p class="sub">Gönderilen, bekleyen ve hatalı tüm e-postalar.<?= $campaign ? ' Kampanya #' . $campaign . ' filtresi uygulanıyor.' : '' ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="process"><button class="btn btn-secondary"><i data-lucide="play"></i>Kuyruğu şimdi işle</button></form>
        <?php if (!empty($counts['failed'])): ?><form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="retry_all"><button class="btn btn-secondary">Hatalıları yeniden dene</button></form><?php endif; ?>
        <form method="POST" action="" onsubmit="return confirm('90 günden eski gönderilmiş kayıtlar silinsin mi?');"><?= csrf_field() ?><input type="hidden" name="action" value="purge"><button class="btn btn-ghost">Eski kayıtları temizle</button></form>
    </div>
</div>

<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
    <nav class="tabs" style="border:0">
        <a href="<?= $qs(['status' => '']) ?>" class="tab <?= $status === '' ? 'is-active' : '' ?>">Tümü<span class="count"><?= array_sum($counts) ?></span></a>
        <?php foreach (MAIL_STATUSES as $sk => [$sl]): ?><a href="<?= $qs(['status' => $sk]) ?>" class="tab <?= $status === $sk ? 'is-active' : '' ?>"><?= $sl ?><span class="count"><?= (int)($counts[$sk] ?? 0) ?></span></a><?php endforeach; ?>
    </nav>
    <div style="display:flex;gap:8px">
        <select class="select" style="height:34px;width:auto" onchange="location.href=this.value">
            <option value="<?= e($qs(['kind' => ''])) ?>">Tüm türler</option>
            <?php foreach (MAIL_KINDS as $k => $l): ?><option value="<?= e($qs(['kind' => $k])) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
        <form method="GET" class="searchbox"><?php foreach (['status' => $status, 'kind' => $kind, 'campaign' => $campaign ?: ''] as $hk => $hv): if ($hv !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= e((string)$hv) ?>"><?php endif; endforeach; ?><i data-lucide="search"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Alıcı veya konu" style="width:220px"></form>
    </div>
</div>

<div class="card">
    <?php if (!$rows): ?>
        <?= ui_empty('Kayıt yok', 'E-posta gönderildikçe burada listelenir.', 'inbox') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Alıcı</th><th>Konu</th><th>Tür</th><th>Durum</th><th>Tarih</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): [$sl, $stn] = MAIL_STATUSES[$r['status']] ?? [$r['status'], 'neutral']; ?>
                <tr>
                    <td><div style="font-weight:500"><?= e($r['to_email']) ?></div><?php if ($r['to_name']): ?><div class="xsmall text-muted"><?= e($r['to_name']) ?></div><?php endif; ?></td>
                    <td class="small" style="max-width:360px"><span class="truncate-2"><?= e($r['subject']) ?></span><?php if ($r['last_error']): ?><div class="xsmall" style="color:var(--danger);margin-top:2px"><?= e($r['last_error']) ?></div><?php endif; ?></td>
                    <td class="xsmall"><?= e(MAIL_KINDS[$r['kind']] ?? $r['kind']) ?><?php if ($r['campaign_id']): ?> · <a class="link" href="<?= BASE_URL ?>/modules/mail/campaign.php?id=<?= (int)$r['campaign_id'] ?>">#<?= (int)$r['campaign_id'] ?></a><?php endif; ?></td>
                    <td><?= ui_badge($sl, $stn, true) ?><?php if ($r['attempts'] > 1): ?><div class="xsmall text-faint"><?= (int)$r['attempts'] ?> deneme</div><?php endif; ?></td>
                    <td class="xsmall text-muted" style="white-space:nowrap"><?= format_date($r['sent_at'] ?: $r['created_at'], true) ?></td>
                    <td class="r" style="white-space:nowrap">
                        <button type="button" class="btn btn-ghost btn-sm" @click="view = <?= (int)$r['id'] ?>">Görüntüle</button>
                        <?php if (in_array($r['status'], ['failed', 'skipped'], true)): ?><form method="POST" action="" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm">Yeniden dene</button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $per): ?>
    <div class="card-foot" style="display:flex;justify-content:space-between;align-items:center">
        <span class="xsmall text-muted"><?= $total ?> kayıt</span>
        <div style="display:flex;gap:6px">
            <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= $qs(['p' => $page - 1]) ?>">Önceki</a><?php endif; ?>
            <?php if ($page * $per < $total): ?><a class="btn btn-secondary btn-sm" href="<?= $qs(['p' => $page + 1]) ?>">Sonraki</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<p class="xsmall text-muted" style="margin-top:12px">Zamanlanmış görev (önerilir): cPanel → Cron Jobs → her 5 dakikada bir <span class="mono">php <?= e(realpath(__DIR__ . '/../../cron/mail-queue.php')) ?></span></p>

<div x-show="view" x-cloak class="modal-backdrop" @keydown.escape.window="view = null">
    <div class="modal modal-lg" @click.outside="view = null" style="overflow:hidden">
        <div class="modal-head" style="padding-bottom:12px"><p class="h3">E-posta içeriği</p><button type="button" class="icon-btn" @click="view = null"><i data-lucide="x"></i></button></div>
        <template x-if="view"><iframe :src="'?view=' + view" sandbox="" style="width:100%;height:70vh;border:0;background:#F4F3F0"></iframe></template>
    </div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
