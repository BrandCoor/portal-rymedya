<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - AJANSLAR (İŞ VERENLER)
 * ====================================================================
 * Onay / askıya alma, iç not, kalıcı silme (platform.delete)
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');
$can_delete = can_access_module('platform.delete');
$self = BASE_URL . '/modules/platform/agencies.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid = (int)($_POST['user_id'] ?? 0);
    $ap = $db->prepare("SELECT ap.*, c.company_title FROM agency_profiles ap JOIN contacts c ON c.id = ap.contact_id WHERE ap.user_id = ?");
    $ap->execute([$uid]);
    $a = $ap->fetch();
    $action = $_POST['action'] ?? '';
    if (!$a) {
        set_flash('error', 'Ajans bulunamadı.');
        redirect($self);
    }
    if (in_array($action, ['approve', 'suspend'], true)) {
        $new = $action === 'approve' ? 'approved' : 'suspended';
        $db->prepare("UPDATE agency_profiles SET status = ? WHERE user_id = ?")->execute([$new, $uid]);
        if ($new === 'approved') {
            notify_user($uid, 'Ajans hesabınız onaylandı. Hizmet kataloğundan iş girebilirsiniz.', '/platform/job_new.php');
        }
        log_activity('platform', "Ajans {$a['company_title']}: " . ($new === 'approved' ? 'onaylandı' : 'askıya alındı'), 'agency', $uid, '/modules/platform/agencies.php');
        set_flash('success', "{$a['company_title']} " . ($new === 'approved' ? 'onaylandı.' : 'askıya alındı.'));
    }
    if ($action === 'notes') {
        $db->prepare("UPDATE agency_profiles SET admin_notes = ? WHERE user_id = ?")->execute([trim($_POST['admin_notes'] ?? ''), $uid]);
        set_flash('success', 'Not kaydedildi.');
    }
    if ($action === 'delete') {
        if (!$can_delete) {
            set_flash('error', 'Kalıcı silme yetkiniz yok.');
            redirect($self);
        }
        [$ok, $msg] = platform_delete_account($uid, !empty($_POST['cascade_jobs']));
        if ($ok) {
            log_activity('platform', "Ajans hesabı silindi: {$a['company_title']}", 'agency', null, '/modules/platform/agencies.php');
        }
        set_flash($ok ? 'success' : 'error', $msg);
    }
    redirect($self . (!empty($_POST['back_status']) ? '?status=' . urlencode($_POST['back_status']) : ''));
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'suspended'], true) ? $_GET['status'] : '';
$sql = "
    SELECT ap.*, c.company_title, c.authorized_person, c.phone, c.email, c.city, c.balance, u.last_login, u.created_at AS user_created,
           (SELECT COUNT(*) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id) AS job_count,
           (SELECT COUNT(*) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id AND j.status NOT IN ('completed', 'cancelled')) AS active_count,
           (SELECT COALESCE(SUM(j.agency_price), 0) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id AND j.status = 'completed') AS revenue,
           (SELECT AVG(j.agency_rating) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id AND j.agency_rating IS NOT NULL) AS avg_rating_given,
           (SELECT MAX(j.created_at) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id) AS last_order
    FROM agency_profiles ap JOIN contacts c ON c.id = ap.contact_id JOIN users u ON u.id = ap.user_id
" . ($status ? " WHERE ap.status = ?" : "") . " ORDER BY FIELD(ap.status, 'pending', 'approved', 'suspended'), revenue DESC";
$st = $db->prepare($sql);
$st->execute($status ? [$status] : []);
$rows = $st->fetchAll();
$tot = $db->query("SELECT status, COUNT(*) FROM agency_profiles GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$status_badge = ['pending' => ['Onay bekliyor', 'warning'], 'approved' => ['Onaylı', 'success'], 'suspended' => ['Askıda', 'danger']];

$page_title = 'Ajanslar';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Ajanslar</span></div>
        <h1 class="h1">Ajanslar</h1>
        <p class="sub">Platformdan iş giren ajanslar. Onaylanmayan ajans iş giremez.</p>
    </div>
</div>

<nav class="tabs" style="margin-bottom:16px">
    <?php foreach (['' => 'Tümü', 'pending' => 'Onay bekleyen', 'approved' => 'Onaylı', 'suspended' => 'Askıda'] as $sk => $sv): ?>
        <a href="?status=<?= $sk ?>" class="tab <?= $status === $sk ? 'is-active' : '' ?>"><?= $sv ?><span class="count"><?= $sk === '' ? array_sum($tot) : (int)($tot[$sk] ?? 0) ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
    <div class="card"><?= ui_empty('Bu listede ajans yok', 'Ajanslar giriş sayfasındaki "Ajans kaydı" bağlantısıyla başvurur.', 'building-2') ?></div>
<?php else: ?>
<div class="stack-sm">
    <?php foreach ($rows as $a): [$sl, $stn] = $status_badge[$a['status']] ?? [$a['status'], 'neutral']; ?>
    <div class="card" x-data="{ more: false, del: false }">
        <div class="card-pad-sm" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <?= ui_avatar($a['company_title']) ?>
            <div style="flex:1;min-width:220px">
                <p style="font-weight:500;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <a class="hover:underline" href="<?= BASE_URL ?>/modules/contacts/detail.php?id=<?= (int)$a['contact_id'] ?>"><?= e($a['company_title']) ?></a><?= ui_badge($sl, $stn, true) ?>
                </p>
                <p class="xsmall text-muted" style="margin-top:2px"><?= e($a['authorized_person'] ?? '') ?> · <?= e($a['email'] ?? '') ?> · <?= e($a['phone'] ?? '') ?><?= $a['city'] ? ' · ' . e($a['city']) : '' ?></p>
            </div>
            <div style="display:flex;gap:22px;text-align:right" class="small">
                <div><p class="xsmall text-muted">İş</p><p class="num"><?= (int)$a['job_count'] ?><?= (int)$a['active_count'] ? ' <span class="text-muted">(' . (int)$a['active_count'] . ' aktif)</span>' : '' ?></p></div>
                <div><p class="xsmall text-muted">Ciro</p><p class="money"><?= format_money((float)$a['revenue']) ?></p></div>
                <div><p class="xsmall text-muted">Bakiye</p><p class="num" style="<?= (float)$a['balance'] > 0 ? 'color:var(--warning)' : '' ?>"><?= format_money((float)$a['balance']) ?></p></div>
                <div><p class="xsmall text-muted">Verdiği puan</p><p class="num"><?= $a['avg_rating_given'] !== null ? number_format((float)$a['avg_rating_given'], 1, ',', '') : '—' ?></p></div>
            </div>
            <div style="display:flex;gap:6px">
                <?php if ($a['status'] !== 'approved'): ?>
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>"><input type="hidden" name="back_status" value="<?= e($status) ?>"><button class="btn btn-primary btn-sm">Onayla</button></form>
                <?php else: ?>
                    <form method="POST" action="" onsubmit="return confirm('Ajans askıya alınsın mı? Oturumu kapatılır ve iş giremez.');"><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>"><input type="hidden" name="back_status" value="<?= e($status) ?>"><button class="btn btn-secondary btn-sm">Askıya al</button></form>
                <?php endif; ?>
                <button type="button" class="icon-btn" @click="more = !more" aria-label="Detay"><i data-lucide="more-horizontal"></i></button>
            </div>
        </div>
        <div x-show="more" x-cloak class="card-foot stack">
            <p class="xsmall text-muted">Kayıt <?= format_date($a['user_created']) ?> · son giriş <?= $a['last_login'] ? time_ago($a['last_login']) : '—' ?> · son iş <?= $a['last_order'] ? format_date($a['last_order']) : '—' ?><?= $a['website'] ? ' · ' . e($a['website']) : '' ?></p>
            <form method="POST" action="" style="display:flex;gap:8px;align-items:flex-end"><?= csrf_field() ?>
                <input type="hidden" name="action" value="notes"><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                <textarea class="textarea" name="admin_notes" rows="2" placeholder="İç not (ajans görmez)" style="flex:1"><?= e($a['admin_notes'] ?? '') ?></textarea>
                <button class="btn btn-secondary btn-sm">Kaydet</button>
            </form>
            <?php if ($can_delete): ?>
            <div>
                <button type="button" class="small" style="color:var(--danger)" @click="del = !del">Hesabı kalıcı sil</button>
                <form x-show="del" x-cloak method="POST" action="" class="stack-sm" style="margin-top:8px" onsubmit="return confirm('<?= e($a['company_title']) ?> hesabı kalıcı olarak silinsin mi?');"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                    <?php if ((int)$a['job_count'] > 0): ?>
                        <label class="check xsmall"><input type="checkbox" name="cascade_jobs" value="1">Ajansın <?= (int)$a['job_count'] ?> işini de sil (faturalar korunur)</label>
                    <?php endif; ?>
                    <p class="xsmall text-muted">Faturası veya cari hareketi olan cari kartı muhasebe geçmişi için korunur; yalnızca giriş hesabı silinir.</p>
                    <div><button class="btn btn-danger-solid btn-sm">Kalıcı sil</button></div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
