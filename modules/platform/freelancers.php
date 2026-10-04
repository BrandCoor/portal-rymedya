<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - FREELANCER HAVUZU
 * ====================================================================
 * Başvuru onayı, seviye (tier) belirleme, askıya alma, uzmanlık düzenleme.
 * Seviye; havuzdaki işlerin kime görüneceğini belirleyen ana pazarlama aracıdır.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid = (int)($_POST['user_id'] ?? 0);
    $fp = $db->prepare("SELECT fp.*, u.full_name FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.user_id = ?");
    $fp->execute([$uid]);
    $f = $fp->fetch();
    if ($f) {
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['approve', 'suspend', 'reactivate'], true)) {
            $new = $action === 'suspend' ? 'suspended' : 'approved';
            $db->prepare("UPDATE freelancer_profiles SET status = ? WHERE user_id = ?")->execute([$new, $uid]);
            if ($new === 'approved') {
                notify_user($uid, '🎉 Freelancer hesabınız onaylandı! Size uygun işler artık iş havuzunuzda.', '/platform/pool.php');
            }
            log_activity('platform', "Freelancer {$f['full_name']}: " . ($new === 'approved' ? 'onaylandı' : 'askıya alındı'), 'freelancer', $uid, '/modules/platform/freelancers.php');
            set_flash('success', "{$f['full_name']} " . ($new === 'approved' ? 'onaylandı.' : 'askıya alındı.'));
        }
        if ($action === 'save') {
            $tier = array_key_exists($_POST['tier'] ?? '', FREELANCER_TIERS) ? $_POST['tier'] : $f['tier'];
            $skills = array_values(array_intersect(array_keys(JOB_CATEGORIES), (array)($_POST['skills'] ?? [])));
            $status = in_array($_POST['status'] ?? '', ['pending', 'approved', 'suspended'], true) ? $_POST['status'] : $f['status'];
            $db->prepare("UPDATE freelancer_profiles SET tier = ?, skills = ?, status = ?, city = ?, admin_notes = ?, is_available = ? WHERE user_id = ?")
               ->execute([$tier, implode(',', $skills), $status, trim($_POST['city'] ?? ''), trim($_POST['admin_notes'] ?? ''), isset($_POST['is_available']) ? 1 : 0, $uid]);
            if ($tier !== $f['tier']) {
                $up = tier_rank($tier) > tier_rank($f['tier']);
                notify_user($uid, ($up ? '⭐ Tebrikler! Seviyeniz yükseltildi: ' : 'Seviyeniz güncellendi: ') . FREELANCER_TIERS[$tier]['label'], '/platform/profile.php');
            }
            if ($status === 'approved' && $f['status'] !== 'approved') {
                notify_user($uid, '🎉 Freelancer hesabınız onaylandı! Size uygun işler artık iş havuzunuzda.', '/platform/pool.php');
            }
            set_flash('success', 'Freelancer bilgileri güncellendi.');
        }
    }
    redirect(BASE_URL . '/modules/platform/freelancers.php?' . http_build_query(array_filter(['status' => $_GET['status'] ?? null])));
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'suspended'], true) ? $_GET['status'] : '';
$tier   = array_key_exists($_GET['tier'] ?? '', FREELANCER_TIERS) ? $_GET['tier'] : '';
$skill  = array_key_exists($_GET['skill'] ?? '', JOB_CATEGORIES) ? $_GET['skill'] : '';
$q      = trim($_GET['q'] ?? '');

$sql = "
    SELECT fp.*, u.full_name, u.email, u.phone, u.last_login, c.iban,
           (SELECT COUNT(*) FROM platform_jobs pj WHERE pj.assigned_user_id = fp.user_id AND pj.status IN ('" . implode("','", JOB_ACTIVE_STATUSES) . "')) AS active_jobs,
           (SELECT COALESCE(SUM(pj.freelancer_fee), 0) FROM platform_jobs pj WHERE pj.assigned_user_id = fp.user_id AND pj.status = 'completed') AS total_earned
    FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id LEFT JOIN contacts c ON c.id = fp.contact_id
    WHERE 1=1
";
$params = [];
if ($status) { $sql .= " AND fp.status = ?"; $params[] = $status; }
if ($tier)   { $sql .= " AND fp.tier = ?"; $params[] = $tier; }
if ($skill)  { $sql .= " AND FIND_IN_SET(?, fp.skills)"; $params[] = $skill; }
if ($q !== '') { $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR fp.city LIKE ? OR fp.title LIKE ?)"; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"); }
$sql .= " ORDER BY FIELD(fp.status, 'pending', 'approved', 'suspended'), FIELD(fp.tier, 'elite', 'gold', 'silver', 'standard'), fp.rating_avg DESC";
$st = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
$counts = $db->query("SELECT status, COUNT(*) FROM freelancer_profiles GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$tier_counts = $db->query("SELECT tier, COUNT(*) FROM freelancer_profiles WHERE status = 'approved' GROUP BY tier")->fetchAll(PDO::FETCH_KEY_PAIR);

$page_title = 'Freelancer Havuzu';
require_once __DIR__ . '/../../includes/header.php';
$fi = 'w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs';
?>
<div x-data="{ edit: null }">
<div class="mb-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
    <div>
        <a href="<?= BASE_URL ?>/modules/platform/index.php" class="text-xs font-semibold text-slate-500">← İş Platformu</a>
        <h1 class="text-2xl font-black text-slate-900">Freelancer Havuzu</h1>
        <p class="text-xs text-slate-500">Seviyeler işlerin kime görüneceğini belirler: Standart → Silver → Gold → Elite.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs">
        <?php foreach (FREELANCER_TIERS as $tk => $tv): ?>
            <span class="px-2.5 py-1.5 rounded-xl border font-bold <?= $tv['color'] ?>"><?= $tv['label'] ?>: <?= (int)($tier_counts[$tk] ?? 0) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<form method="GET" class="mb-4 flex flex-wrap items-center gap-2 text-xs bg-white p-3 rounded-2xl border border-slate-200">
    <?php foreach (['' => 'Tümü', 'pending' => 'Onay Bekleyen', 'approved' => 'Onaylı', 'suspended' => 'Askıda'] as $sk => $sv): ?>
        <a href="?status=<?= $sk ?>" class="px-3 py-2 rounded-xl font-bold <?= $status === $sk ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600' ?>"><?= $sv ?><?= $sk ? ' (' . (int)($counts[$sk] ?? 0) . ')' : '' ?></a>
    <?php endforeach; ?>
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <select name="tier" onchange="this.form.submit()" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl"><option value="">Tüm seviyeler</option><?php foreach (FREELANCER_TIERS as $tk => $tv): ?><option value="<?= $tk ?>" <?= $tier === $tk ? 'selected' : '' ?>><?= $tv['label'] ?></option><?php endforeach; ?></select>
    <select name="skill" onchange="this.form.submit()" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl"><option value="">Tüm uzmanlıklar</option><?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>" <?= $skill === $ck ? 'selected' : '' ?>><?= e($cv['label']) ?></option><?php endforeach; ?></select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="İsim, şehir, unvan..." class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl">
</form>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
    <?php if (!$rows): ?><p class="p-10 text-center bg-white border-2 border-dashed border-slate-200 rounded-2xl text-sm text-slate-500 lg:col-span-2">Kayıt yok.</p><?php endif; ?>
    <?php foreach ($rows as $f):
        $t = FREELANCER_TIERS[$f['tier']] ?? FREELANCER_TIERS['standard'];
        $sk = array_filter(explode(',', (string)$f['skills']));
        $badge = ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'suspended' => 'bg-rose-100 text-rose-800'][$f['status']] ?? '';
    ?>
    <div class="bg-white border <?= $f['status'] === 'pending' ? 'border-amber-300' : 'border-slate-200' ?> rounded-2xl p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900"><?= e($f['full_name']) ?> <span class="ml-1 px-2 py-0.5 rounded-full border text-[10px] <?= $t['color'] ?>"><?= $t['label'] ?></span> <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $badge ?>"><?= e(['pending' => 'Onay Bekliyor', 'approved' => 'Onaylı', 'suspended' => 'Askıda'][$f['status']] ?? $f['status']) ?></span></p>
                <p class="text-xs text-slate-500"><?= e($f['title'] ?: '-') ?> · 📍 <?= e($f['city'] ?: '-') ?> · <?= (int)$f['is_available'] === 1 ? '🟢 Müsait' : '⚪ Müsait değil' ?></p>
                <p class="text-xs text-slate-500"><?= e($f['email']) ?> · <?= e($f['phone'] ?? '') ?></p>
                <div class="flex flex-wrap gap-1 mt-1.5"><?php foreach ($sk as $s): ?><span class="px-1.5 py-0.5 bg-slate-100 rounded text-[10px] text-slate-600"><?= e(job_category_label($s)) ?></span><?php endforeach; ?></div>
                <p class="text-[11px] text-slate-500 mt-1.5"><?= render_stars($f['rating_avg'] !== null ? (float)$f['rating_avg'] : null) ?> · <?= (int)$f['completed_jobs'] ?> iş · aktif <?= (int)$f['active_jobs'] ?> · kazanç <?= format_money((float)$f['total_earned']) ?>
                    <?php if ($f['day_rate']): ?> · günlük <?= format_money($f['day_rate']) ?><?php endif; ?></p>
                <?php if ($f['portfolio_url']): ?><a href="<?= e($f['portfolio_url']) ?>" target="_blank" rel="noopener" class="text-[11px] text-brand-600 underline">Portfolyo / Showreel</a><?php endif; ?>
                <?php if ($f['admin_notes']): ?><p class="text-[11px] text-amber-700 mt-1">📝 <?= e($f['admin_notes']) ?></p><?php endif; ?>
            </div>
            <div class="flex flex-col gap-1.5 flex-shrink-0">
                <?php if ($f['status'] !== 'approved'): ?>
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>"><input type="hidden" name="action" value="approve"><button class="w-full px-3 py-1.5 bg-emerald-600 text-white text-xs font-bold rounded-lg">Onayla</button></form>
                <?php else: ?>
                    <form method="POST" action="" onsubmit="return confirm('Freelancer askıya alınsın mı? Platforma giriş yapamaz.');"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>"><input type="hidden" name="action" value="suspend"><button class="w-full px-3 py-1.5 bg-white border border-rose-300 text-rose-700 text-xs font-bold rounded-lg">Askıya Al</button></form>
                <?php endif; ?>
                <button type="button" @click="edit = <?= (int)$f['user_id'] ?>" class="px-3 py-1.5 bg-slate-100 text-slate-700 text-xs font-bold rounded-lg">Düzenle</button>
            </div>
        </div>

        <form x-show="edit === <?= (int)$f['user_id'] ?>" x-cloak method="POST" action="" class="mt-3 pt-3 border-t border-slate-100 space-y-2">
            <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$f['user_id'] ?>"><input type="hidden" name="action" value="save">
            <div class="grid grid-cols-3 gap-2">
                <select name="tier" class="<?= $fi ?>"><?php foreach (FREELANCER_TIERS as $tk => $tv): ?><option value="<?= $tk ?>" <?= $f['tier'] === $tk ? 'selected' : '' ?>><?= $tv['label'] ?></option><?php endforeach; ?></select>
                <select name="status" class="<?= $fi ?>"><?php foreach (['pending' => 'Onay Bekliyor', 'approved' => 'Onaylı', 'suspended' => 'Askıda'] as $sk2 => $sv2): ?><option value="<?= $sk2 ?>" <?= $f['status'] === $sk2 ? 'selected' : '' ?>><?= $sv2 ?></option><?php endforeach; ?></select>
                <input type="text" name="city" value="<?= e($f['city']) ?>" placeholder="Şehir" class="<?= $fi ?>">
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-1">
                <?php foreach (JOB_CATEGORIES as $ck => $cv): ?><label class="flex items-center gap-1 text-[11px]"><input type="checkbox" name="skills[]" value="<?= $ck ?>" <?= in_array($ck, $sk, true) ? 'checked' : '' ?> class="rounded"><?= e($cv['label']) ?></label><?php endforeach; ?>
            </div>
            <label class="flex items-center gap-1 text-[11px]"><input type="checkbox" name="is_available" value="1" <?= (int)$f['is_available'] === 1 ? 'checked' : '' ?> class="rounded"> Müsait</label>
            <textarea name="admin_notes" rows="2" placeholder="İç not (freelancer görmez)" class="<?= $fi ?>"><?= e($f['admin_notes']) ?></textarea>
            <div class="flex justify-end gap-2"><button type="button" @click="edit = null" class="px-3 py-1.5 text-xs text-slate-500">Kapat</button><button class="px-4 py-1.5 bg-slate-900 text-white text-xs font-bold rounded-lg">Kaydet</button></div>
        </form>
    </div>
    <?php endforeach; ?>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
