<?php
/**
 * ====================================================================
 * E-POSTA MERKEZİ - ABONELER
 * ====================================================================
 * Cari kartlar, portal hesapları ve personel otomatik eşitlenir;
 * manuel ekleme ve CSV içe/dışa aktarma desteklenir.
 * Abonelikten çıkan kişiler eşitlemede yeniden abone yapılmaz.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('mail.manage');
$self = BASE_URL . '/modules/mail/subscribers.php';

// CSV dışa aktarma
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aboneler-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['eposta', 'ad', 'firma', 'tur', 'etiketler', 'izin', 'durum', 'eklenme'], ';');
    foreach ($db->query("SELECT * FROM mail_subscribers ORDER BY id") as $r) {
        fputcsv($out, [$r['email'], $r['name'], $r['company'], MAIL_SUBSCRIBER_KINDS[$r['kind']] ?? $r['kind'], $r['tags'], $r['consent'] ? 'evet' : 'hayır', $r['status'] === 'subscribed' ? 'abone' : 'çıktı', $r['created_at']], ';');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $ins = $db->prepare("INSERT INTO mail_subscribers (email, name, company, kind, tags, consent, status, token, source, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 'subscribed', ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE name = COALESCE(NULLIF(VALUES(name), ''), name), company = COALESCE(NULLIF(VALUES(company), ''), company),
                            tags = TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(tags, ''), NULLIF(VALUES(tags), ''))), consent = GREATEST(consent, VALUES(consent))");
    $clean_tags = fn($t) => implode(',', array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 40), explode(',', (string)$t)))));

    if ($action === 'sync') {
        $n = mail_sync_subscribers();
        set_flash('success', "{$n} kayıt eşitlendi. Abonelikten çıkmış kişiler listeye geri alınmadı.");
    }
    if ($action === 'add') {
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Geçerli bir e-posta adresi girin.');
        } else {
            $ins->execute([$email, trim($_POST['name'] ?? ''), trim($_POST['company'] ?? ''), 'manual', $clean_tags($_POST['tags'] ?? ''), isset($_POST['consent']) ? 1 : 0, mail_subscriber_token(), 'manual']);
            set_flash('success', "{$email} listeye eklendi.");
        }
    }
    if ($action === 'import' && !empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        $first = fgets($fh);
        $delim = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',';
        rewind($fh);
        $added = 0; $skipped = 0; $row = 0;
        $tags = $clean_tags($_POST['tags'] ?? '');
        $consent = isset($_POST['consent']) ? 1 : 0;
        while (($cols = fgetcsv($fh, 0, $delim)) !== false && $row < 20000) {
            $row++;
            $email = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)($cols[0] ?? ''))));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
            $ins->execute([$email, trim($cols[1] ?? ''), trim($cols[2] ?? ''), 'manual', $clean_tags(trim(($cols[3] ?? '') . ',' . $tags, ',')), $consent, mail_subscriber_token(), 'import']);
            $added++;
        }
        fclose($fh);
        set_flash('success', "{$added} adres içe aktarıldı" . ($skipped ? ", {$skipped} satır geçersiz olduğu için atlandı" : '') . '.');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'toggle' && $id) {
        $db->prepare("UPDATE mail_subscribers SET status = IF(status = 'subscribed', 'unsubscribed', 'subscribed'), unsubscribed_at = IF(status = 'unsubscribed', NOW(), NULL) WHERE id = ?")->execute([$id]);
        set_flash('success', 'Abonelik durumu değiştirildi.');
    }
    if ($action === 'update' && $id) {
        $db->prepare("UPDATE mail_subscribers SET name = ?, company = ?, tags = ?, consent = ? WHERE id = ?")
           ->execute([trim($_POST['name'] ?? ''), trim($_POST['company'] ?? ''), $clean_tags($_POST['tags'] ?? ''), isset($_POST['consent']) ? 1 : 0, $id]);
        set_flash('success', 'Abone güncellendi.');
    }
    if ($action === 'delete' && $id) {
        $db->prepare("DELETE FROM mail_subscribers WHERE id = ?")->execute([$id]);
        set_flash('success', 'Abone silindi.');
    }
    redirect($self . '?' . http_build_query(array_filter(['kind' => $_GET['kind'] ?? null, 'status' => $_GET['status'] ?? null, 'q' => $_GET['q'] ?? null])));
}

// İlk açılışta liste boşsa otomatik eşitle
if ((int)$db->query("SELECT COUNT(*) FROM mail_subscribers")->fetchColumn() === 0) {
    mail_sync_subscribers();
}

$kind   = array_key_exists($_GET['kind'] ?? '', MAIL_SUBSCRIBER_KINDS) ? $_GET['kind'] : '';
$status = in_array($_GET['status'] ?? '', ['subscribed', 'unsubscribed'], true) ? $_GET['status'] : '';
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$per    = 50;
$where = ['1=1']; $params = [];
if ($kind)   { $where[] = 's.kind = ?'; $params[] = $kind; }
if ($status) { $where[] = 's.status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = '(s.email LIKE ? OR s.name LIKE ? OR s.company LIKE ? OR s.tags LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"); }
$w = implode(' AND ', $where);
$cnt = $db->prepare("SELECT COUNT(*) FROM mail_subscribers s WHERE {$w}");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$st = $db->prepare("SELECT s.*, fp.tier FROM mail_subscribers s LEFT JOIN freelancer_profiles fp ON fp.user_id = s.user_id WHERE {$w} ORDER BY s.status = 'subscribed' DESC, s.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per));
$st->execute($params);
$rows = $st->fetchAll();
$stats = $db->query("SELECT kind, SUM(status = 'subscribed') AS active, COUNT(*) AS total FROM mail_subscribers GROUP BY kind")->fetchAll(PDO::FETCH_UNIQUE);
$all_active = array_sum(array_map(fn($r) => (int)$r['active'], $stats));
$unsub = (int)$db->query("SELECT COUNT(*) FROM mail_subscribers WHERE status = 'unsubscribed'")->fetchColumn();
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['kind' => $kind, 'status' => $status, 'q' => $q], $o), fn($v) => $v !== '' && $v !== null));

$page_title = 'Aboneler';
require_once __DIR__ . '/../../includes/header.php';
?>
<div x-data="{ add: false, imp: false, edit: null }">
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/mail/index.php">E-posta</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Aboneler</span></div>
        <h1 class="h1">Aboneler</h1>
        <p class="sub">Toplu e-postaların gönderileceği adresler. Müşteri, ajans, freelancer ve personel kayıtları otomatik eşitlenir.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn btn-secondary"><i data-lucide="refresh-cw"></i>Eşitle</button></form>
        <button class="btn btn-secondary" @click="imp = true"><i data-lucide="upload"></i>CSV içe aktar</button>
        <a class="btn btn-secondary" href="?export=csv"><i data-lucide="download"></i>Dışa aktar</a>
        <button class="btn btn-primary" @click="add = true"><i data-lucide="user-plus"></i>Abone ekle</button>
    </div>
</div>

<div class="card" style="margin-bottom:20px">
    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr))">
        <a href="<?= $qs(['kind' => '', 'status' => 'subscribed']) ?>" class="kpi"><div class="kpi-label">Aktif abone</div><div class="kpi-value"><?= $all_active ?></div><div class="kpi-meta"><?= $unsub ?> kişi çıktı</div></a>
        <?php foreach (MAIL_SUBSCRIBER_KINDS as $k => $l): ?>
            <a href="<?= $qs(['kind' => $kind === $k ? '' : $k]) ?>" class="kpi" style="<?= $kind === $k ? 'background:var(--surface-2)' : '' ?>"><div class="kpi-label"><?= e($l) ?></div><div class="kpi-value"><?= (int)($stats[$k]['active'] ?? 0) ?></div><div class="kpi-meta"><?= (int)($stats[$k]['total'] ?? 0) ?> kayıt</div></a>
        <?php endforeach; ?>
    </div>
</div>

<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
    <div class="seg">
        <a href="<?= $qs(['status' => '']) ?>" class="<?= $status === '' ? 'is-active' : '' ?>">Tümü</a>
        <a href="<?= $qs(['status' => 'subscribed']) ?>" class="<?= $status === 'subscribed' ? 'is-active' : '' ?>">Abone</a>
        <a href="<?= $qs(['status' => 'unsubscribed']) ?>" class="<?= $status === 'unsubscribed' ? 'is-active' : '' ?>">Çıkanlar</a>
    </div>
    <form method="GET" class="searchbox"><?php if ($kind): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?><?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?><i data-lucide="search"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="E-posta, ad, firma, etiket" style="width:260px"></form>
</div>

<div class="card">
    <?php if (!$rows): ?>
        <?= ui_empty('Kayıt bulunamadı', 'Eşitle butonu cari kartlardaki ve hesaplardaki e-posta adreslerini listeye ekler.', 'users') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Abone</th><th>Tür</th><th>Etiketler</th><th>İzin</th><th>Durum</th><th>Kaynak</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr style="<?= $r['status'] !== 'subscribed' ? 'opacity:.6' : '' ?>">
                    <td><div style="font-weight:500"><?= e($r['email']) ?></div><div class="xsmall text-muted"><?= e(trim(($r['name'] ?? '') . ($r['company'] && $r['company'] !== $r['name'] ? ' · ' . $r['company'] : ''))) ?: '—' ?></div></td>
                    <td class="small"><?= e(MAIL_SUBSCRIBER_KINDS[$r['kind']] ?? $r['kind']) ?><?php if ($r['kind'] === 'freelancer' && $r['tier']): ?> <?= tier_badge($r['tier']) ?><?php endif; ?></td>
                    <td class="xsmall"><?php foreach (array_filter(explode(',', (string)$r['tags'])) as $t): ?><span class="badge" style="margin:1px"><?= e($t) ?></span><?php endforeach; ?></td>
                    <td><?= $r['consent'] ? ui_badge('Ticari izin', 'success') : '<span class="xsmall text-faint">—</span>' ?></td>
                    <td><?= $r['status'] === 'subscribed' ? ui_badge('Abone', 'success', true) : ui_badge('Çıktı', 'neutral', true) ?><?php if ($r['unsubscribed_at']): ?><div class="xsmall text-faint"><?= format_date($r['unsubscribed_at']) ?></div><?php endif; ?></td>
                    <td class="xsmall text-muted"><?= e(['sync' => 'Eşitleme', 'manual' => 'Manuel', 'import' => 'CSV', 'register' => 'Kayıt formu', 'profile' => 'Profil'][$r['source']] ?? ($r['source'] ?? '—')) ?></td>
                    <td class="r" style="white-space:nowrap">
                        <button type="button" class="btn btn-ghost btn-sm" @click="edit = <?= e(json_encode(['id' => (int)$r['id'], 'email' => $r['email'], 'name' => $r['name'], 'company' => $r['company'], 'tags' => $r['tags'], 'consent' => (int)$r['consent']])) ?>">Düzenle</button>
                        <form method="POST" action="" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm"><?= $r['status'] === 'subscribed' ? 'Listeden çıkar' : 'Yeniden ekle' ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > $per): ?>
    <div class="card-foot" style="display:flex;justify-content:space-between;align-items:center">
        <span class="xsmall text-muted"><?= $total ?> kayıt · sayfa <?= $page ?> / <?= (int)ceil($total / $per) ?></span>
        <div style="display:flex;gap:6px">
            <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= $qs(['p' => $page - 1]) ?>">Önceki</a><?php endif; ?>
            <?php if ($page * $per < $total): ?><a class="btn btn-secondary btn-sm" href="<?= $qs(['p' => $page + 1]) ?>">Sonraki</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<p class="xsmall text-muted" style="margin-top:12px">Ticari elektronik ileti (kampanya, tanıtım) göndermek için alıcının onayı gerekir ve gönderici olarak İYS'ye kayıt yükümlülüğü olabilir. Bilgilendirme amaçlı e-postalar (iş, fatura, duyuru) bu kapsamda değildir. Kampanya gönderirken "yalnızca ticari izni olanlar" seçeneğini kullanın.</p>

<!-- ABONE EKLE -->
<div x-show="add" x-cloak class="modal-backdrop" @keydown.escape.window="add = false">
    <form method="POST" action="" class="modal" @click.outside="add = false"><?= csrf_field() ?><input type="hidden" name="action" value="add">
        <div class="modal-head"><p class="h3">Abone ekle</p><button type="button" class="icon-btn" @click="add = false"><i data-lucide="x"></i></button></div>
        <div class="modal-body stack">
            <div class="field"><label class="label">E-posta <span class="req">*</span></label><input class="input" type="email" name="email" required></div>
            <div class="grid grid-cols-2 gap-3"><div class="field"><label class="label">Ad soyad</label><input class="input" name="name"></div><div class="field"><label class="label">Firma</label><input class="input" name="company"></div></div>
            <div class="field"><label class="label">Etiketler</label><input class="input" name="tags" placeholder="fuar-2026, istanbul"><span class="hint">Virgülle ayırın; kampanyada etikete göre gönderebilirsiniz.</span></div>
            <label class="check small"><input type="checkbox" name="consent" value="1">Bu kişiden ticari ileti onayı alındı</label>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" @click="add = false">Vazgeç</button><button class="btn btn-primary">Ekle</button></div>
    </form>
</div>

<!-- İÇE AKTAR -->
<div x-show="imp" x-cloak class="modal-backdrop" @keydown.escape.window="imp = false">
    <form method="POST" action="" enctype="multipart/form-data" class="modal" @click.outside="imp = false"><?= csrf_field() ?><input type="hidden" name="action" value="import">
        <div class="modal-head"><p class="h3">CSV içe aktar</p><button type="button" class="icon-btn" @click="imp = false"><i data-lucide="x"></i></button></div>
        <div class="modal-body stack">
            <p class="small text-muted">Sütun sırası: <span class="mono">eposta; ad; firma; etiketler</span>. Yalnızca ilk sütun zorunludur. Excel'den "CSV (noktalı virgülle ayrılmış)" olarak kaydedebilirsiniz. Var olan adresler güncellenir, tekrar eklenmez.</p>
            <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
            <div class="field"><label class="label">Tüm satırlara etiket ekle</label><input class="input" name="tags" placeholder="ör. fuar-2026"></div>
            <label class="check small"><input type="checkbox" name="consent" value="1">Bu listedeki kişilerden ticari ileti onayı alındı</label>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" @click="imp = false">Vazgeç</button><button class="btn btn-primary">İçe aktar</button></div>
    </form>
</div>

<!-- DÜZENLE -->
<div x-show="edit" x-cloak class="modal-backdrop" @keydown.escape.window="edit = null">
    <template x-if="edit">
    <div class="modal" @click.outside="edit = null">
        <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" :value="edit.id">
            <div class="modal-head"><div><p class="h3">Aboneyi düzenle</p><p class="small text-muted" x-text="edit.email"></p></div><button type="button" class="icon-btn" @click="edit = null"><i data-lucide="x"></i></button></div>
            <div class="modal-body stack">
                <div class="grid grid-cols-2 gap-3"><div class="field"><label class="label">Ad soyad</label><input class="input" name="name" :value="edit.name"></div><div class="field"><label class="label">Firma</label><input class="input" name="company" :value="edit.company"></div></div>
                <div class="field"><label class="label">Etiketler</label><input class="input" name="tags" :value="edit.tags"></div>
                <label class="check small"><input type="checkbox" name="consent" value="1" :checked="edit.consent == 1">Ticari ileti onayı var</label>
            </div>
            <div class="modal-foot" style="justify-content:space-between">
                <button type="submit" form="del-sub" class="btn btn-ghost btn-sm" style="color:var(--danger)" onclick="return confirm('Abone kalıcı olarak silinsin mi? Eşitlemede tekrar eklenebilir; göndermemek için listeden çıkarmayı tercih edin.');">Sil</button>
                <div style="display:flex;gap:8px"><button type="button" class="btn btn-ghost" @click="edit = null">Vazgeç</button><button class="btn btn-primary">Kaydet</button></div>
            </div>
        </form>
        <form id="del-sub" method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" :value="edit.id"></form>
    </div>
    </template>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
