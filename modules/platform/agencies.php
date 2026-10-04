<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - AJANSLAR (İŞ VERENLER)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid = (int)($_POST['user_id'] ?? 0);
    $ap = $db->prepare("SELECT ap.*, c.company_title FROM agency_profiles ap JOIN contacts c ON c.id = ap.contact_id WHERE ap.user_id = ?");
    $ap->execute([$uid]);
    $a = $ap->fetch();
    $action = $_POST['action'] ?? '';
    if ($a && in_array($action, ['approve', 'suspend'], true)) {
        $new = $action === 'approve' ? 'approved' : 'suspended';
        $db->prepare("UPDATE agency_profiles SET status = ? WHERE user_id = ?")->execute([$new, $uid]);
        if ($new === 'approved') {
            notify_user($uid, '🎉 Ajans hesabınız onaylandı! Artık iş talebi oluşturabilirsiniz.', '/platform/job_new.php');
        }
        log_activity('platform', "Ajans {$a['company_title']}: " . ($new === 'approved' ? 'onaylandı' : 'askıya alındı'), 'agency', $uid, '/modules/platform/agencies.php');
        set_flash('success', "{$a['company_title']} " . ($new === 'approved' ? 'onaylandı.' : 'askıya alındı.'));
    }
    if ($a && $action === 'notes') {
        $db->prepare("UPDATE agency_profiles SET admin_notes = ? WHERE user_id = ?")->execute([trim($_POST['admin_notes'] ?? ''), $uid]);
        set_flash('success', 'Not kaydedildi.');
    }
    redirect(BASE_URL . '/modules/platform/agencies.php');
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'suspended'], true) ? $_GET['status'] : '';
$sql = "
    SELECT ap.*, c.company_title, c.authorized_person, c.phone, c.email, c.city, c.balance, u.last_login,
           (SELECT COUNT(*) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id) AS job_count,
           (SELECT COUNT(*) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id AND j.status NOT IN ('completed', 'cancelled')) AS active_count,
           (SELECT COALESCE(SUM(j.agency_price), 0) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id AND j.status = 'completed') AS revenue,
           (SELECT AVG(j.agency_rating) FROM platform_jobs j WHERE j.agency_contact_id = ap.contact_id AND j.agency_rating IS NOT NULL) AS avg_rating_given
    FROM agency_profiles ap JOIN contacts c ON c.id = ap.contact_id JOIN users u ON u.id = ap.user_id
" . ($status ? " WHERE ap.status = ?" : "") . " ORDER BY FIELD(ap.status, 'pending', 'approved', 'suspended'), revenue DESC";
$st = $db->prepare($sql);
$st->execute($status ? [$status] : []);
$rows = $st->fetchAll();

$page_title = 'Ajanslar';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="mb-5">
    <a href="<?= BASE_URL ?>/modules/platform/index.php" class="text-xs font-semibold text-slate-500">← İş Platformu</a>
    <h1 class="text-2xl font-black text-slate-900">Ajanslar</h1>
    <p class="text-xs text-slate-500">Platform üzerinden iş veren ajanslar. Onaylanmayan ajans iş talebi oluşturamaz.</p>
</div>
<div class="mb-4 flex gap-2 text-xs">
    <?php foreach (['' => 'Tümü', 'pending' => 'Onay Bekleyen', 'approved' => 'Onaylı', 'suspended' => 'Askıda'] as $sk => $sv): ?>
        <a href="?status=<?= $sk ?>" class="px-3 py-2 rounded-xl font-bold <?= $status === $sk ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600' ?>"><?= $sv ?></a>
    <?php endforeach; ?>
</div>
<div class="bg-white border border-slate-200 rounded-3xl overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs">
        <thead><tr class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase">
            <th class="py-3 px-4 text-left">Ajans</th><th class="py-3 px-4 text-left">İletişim</th><th class="py-3 px-4 text-center">İş (Aktif)</th><th class="py-3 px-4 text-right">Ciro</th><th class="py-3 px-4 text-right">Açık Bakiye</th><th class="py-3 px-4 text-left">Durum</th><th class="py-3 px-4"></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (!$rows): ?><tr><td colspan="7" class="py-10 text-center text-slate-400">Kayıt yok.</td></tr><?php endif; ?>
            <?php foreach ($rows as $a): ?>
            <tr class="<?= $a['status'] === 'pending' ? 'bg-amber-50/50' : '' ?>">
                <td class="py-3 px-4"><a href="<?= BASE_URL ?>/modules/contacts/detail.php?id=<?= (int)$a['contact_id'] ?>" class="font-bold text-slate-900 hover:text-brand-600"><?= e($a['company_title']) ?></a>
                    <div class="text-[10px] text-slate-400"><?= e($a['city'] ?? '') ?><?= $a['website'] ? ' · ' . e($a['website']) : '' ?></div>
                    <?php if ($a['admin_notes']): ?><div class="text-[10px] text-amber-700">📝 <?= e($a['admin_notes']) ?></div><?php endif; ?></td>
                <td class="py-3 px-4 text-slate-600"><?= e($a['authorized_person'] ?? '') ?><br><?= e($a['email'] ?? '') ?> · <?= e($a['phone'] ?? '') ?></td>
                <td class="py-3 px-4 text-center font-bold"><?= (int)$a['job_count'] ?> (<?= (int)$a['active_count'] ?>)</td>
                <td class="py-3 px-4 text-right font-semibold"><?= format_money((float)$a['revenue']) ?></td>
                <td class="py-3 px-4 text-right <?= (float)$a['balance'] > 0 ? 'text-rose-600 font-bold' : '' ?>"><?= format_money((float)$a['balance']) ?></td>
                <td class="py-3 px-4"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'suspended' => 'bg-rose-100 text-rose-800'][$a['status']] ?? '' ?>"><?= e(['pending' => 'Onay Bekliyor', 'approved' => 'Onaylı', 'suspended' => 'Askıda'][$a['status']] ?? $a['status']) ?></span></td>
                <td class="py-3 px-4 text-right whitespace-nowrap">
                    <form method="POST" action="" class="inline"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                        <?php if ($a['status'] !== 'approved'): ?><button name="action" value="approve" class="px-3 py-1.5 bg-emerald-600 text-white font-bold rounded-lg">Onayla</button>
                        <?php else: ?><button name="action" value="suspend" onclick="return confirm('Ajans askıya alınsın mı?');" class="px-3 py-1.5 bg-white border border-rose-300 text-rose-700 font-bold rounded-lg">Askıya Al</button><?php endif; ?>
                    </form>
                    <details class="inline-block text-left"><summary class="cursor-pointer px-2 text-slate-400">Not</summary>
                        <form method="POST" action="" class="absolute right-8 mt-1 p-2 bg-white border border-slate-200 rounded-xl shadow-lg z-10 w-64"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>"><input type="hidden" name="action" value="notes">
                            <textarea name="admin_notes" rows="3" class="w-full p-2 border border-slate-200 rounded-lg"><?= e($a['admin_notes']) ?></textarea><button class="mt-1 px-3 py-1 bg-slate-900 text-white rounded-lg font-bold">Kaydet</button></form>
                    </details>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
