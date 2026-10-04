<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - İŞ DETAYI (KONTROL MERKEZİ)
 * ====================================================================
 * Fiyatlama · Görünürlük politikası · Atama (ekip / freelancer / başvuru)
 * Kalite kontrol · Teslim · Kapanış · Freelancer ödemesi · Mesajlar
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');

$job_id = (int)($_GET['id'] ?? 0);
$job = get_job($job_id);
if (!$job) {
    set_flash('error', 'İş bulunamadı.');
    redirect(BASE_URL . '/modules/platform/index.php');
}
$self = BASE_URL . "/modules/platform/job.php?id={$job_id}";
$staff_name = 'RY Medya · ' . ($user['full_name'] ?? 'Ekip');
$editable_policy = in_array($job['status'], ['submitted', 'quote_sent', 'open'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $done = function (string $msg, string $type = 'success') use ($self) {
        set_flash($type, $msg);
        redirect($self);
    };

    // ---------- İş bilgileri ----------
    if ($action === 'save_details') {
        $title = trim($_POST['title'] ?? '');
        $deadline = valid_date($_POST['deadline'] ?? '');
        if ($title === '' || !$deadline) {
            $done('Başlık ve teslim tarihi zorunludur.', 'error');
        }
        $db->prepare("UPDATE platform_jobs SET title = ?, category = ?, description = ?, deliverables = ?, reference_links = ?, location_city = ?, location_detail = ?, is_remote = ?, start_date = ?, deadline = ?, agency_contact_id = ? WHERE id = ?")
           ->execute([
               $title, array_key_exists($_POST['category'] ?? '', JOB_CATEGORIES) ? $_POST['category'] : $job['category'],
               trim($_POST['description'] ?? ''), trim($_POST['deliverables'] ?? ''), trim($_POST['reference_links'] ?? ''),
               trim($_POST['location_city'] ?? '') ?: null, trim($_POST['location_detail'] ?? ''), isset($_POST['is_remote']) ? 1 : 0,
               valid_date($_POST['start_date'] ?? ''), $deadline, !empty($_POST['agency_contact_id']) ? (int)$_POST['agency_contact_id'] : null, $job_id
           ]);
        $done('İş bilgileri güncellendi.');
    }

    // ---------- Fiyatlama ----------
    if ($action === 'save_pricing') {
        $price = parse_money($_POST['agency_price'] ?? '');
        $fee   = parse_money($_POST['freelancer_fee'] ?? '');
        $db->prepare("UPDATE platform_jobs SET agency_price = ?, freelancer_fee = ? WHERE id = ?")->execute([$price > 0 ? $price : null, $fee > 0 ? $fee : null, $job_id]);
        $next = $_POST['next'] ?? '';
        if ($next === 'quote' && in_array($job['status'], ['submitted', 'quote_sent'], true)) {
            if ($price <= 0) {
                $done('Teklif göndermek için ajans fiyatı giriniz.', 'error');
            }
            $db->prepare("UPDATE platform_jobs SET status = 'quote_sent' WHERE id = ?")->execute([$job_id]);
            notify_contact_users($job['agency_contact_id'], "💰 {$job['job_code']} için fiyat teklifimiz hazır: " . format_money($price, $job['currency']) . " + KDV. Onayınızı bekliyoruz.", "/platform/job.php?id={$job_id}", $job_id);
            $done('Fiyat teklifi ajansa gönderildi.');
        }
        if ($next === 'publish' && in_array($job['status'], ['submitted', 'quote_sent'], true)) {
            job_publish($job_id);
            $done($job['visibility'] === 'internal' ? 'İş onaylandı (ekibe özel). Şimdi ekibinize alabilir veya doğrudan atayabilirsiniz.' : 'İş onaylandı ve görünürlük kurallarına göre havuza açıldı.');
        }
        $done('Fiyatlar kaydedildi.');
    }

    // ---------- Görünürlük / dağıtım politikası ----------
    if ($action === 'save_policy' && $editable_policy) {
        $visibility = array_key_exists($_POST['visibility'] ?? '', JOB_VISIBILITY) ? $_POST['visibility'] : 'pool';
        $db->prepare("UPDATE platform_jobs SET visibility = ?, dispatch_mode = ?, min_tier = ?, priority_tier = ?, priority_hours = ?, skill_match_only = ?, city_match_only = ? WHERE id = ?")
           ->execute([
               $visibility,
               array_key_exists($_POST['dispatch_mode'] ?? '', JOB_DISPATCH) ? $_POST['dispatch_mode'] : 'first_come',
               array_key_exists($_POST['min_tier'] ?? '', FREELANCER_TIERS) ? $_POST['min_tier'] : 'standard',
               array_key_exists($_POST['priority_tier'] ?? '', FREELANCER_TIERS) ? $_POST['priority_tier'] : null,
               max(0, min(720, (int)($_POST['priority_hours'] ?? 0))),
               isset($_POST['skill_match_only']) ? 1 : 0, isset($_POST['city_match_only']) ? 1 : 0, $job_id
           ]);
        $db->prepare("DELETE FROM platform_job_visible_to WHERE job_id = ?")->execute([$job_id]);
        if ($visibility === 'selected') {
            $ins = $db->prepare("INSERT IGNORE INTO platform_job_visible_to (job_id, user_id) VALUES (?, ?)");
            foreach ((array)($_POST['selected_users'] ?? []) as $sel) {
                $ins->execute([$job_id, (int)$sel]);
                if ($job['status'] === 'open') {
                    notify_user((int)$sel, "Size özel yeni iş: {$job['job_code']} · {$job['title']}", "/platform/job.php?id={$job_id}", $job_id);
                }
            }
        }
        $done('Görünürlük ve dağıtım kuralları kaydedildi.');
    }

    // ---------- Atama ----------
    if ($action === 'take_internal') {
        $pid = job_take_internal($job_id, (int)$user['id']);
        if ($pid === null) {
            $done('Bu durumdaki iş ekibe alınamaz.', 'error');
        }
        $done('İşi ekibiniz üstlendi' . ($pid ? ' ve ERP\'de proje açıldı.' : '.'));
    }
    if ($action === 'assign_direct' && in_array($job['status'], ['open', 'submitted', 'quote_sent'], true)) {
        $fid = (int)($_POST['user_id'] ?? 0);
        $fp = $db->prepare("SELECT * FROM freelancer_profiles WHERE user_id = ? AND status = 'approved'");
        $fp->execute([$fid]);
        if (!$fp->fetch()) {
            $done('Onaylı bir freelancer seçiniz.', 'error');
        }
        $fee = parse_money($_POST['fee'] ?? '');
        if ($job['status'] !== 'open') {
            $db->prepare("UPDATE platform_jobs SET status = 'open', published_at = COALESCE(published_at, NOW()) WHERE id = ?")->execute([$job_id]);
        }
        job_assign_freelancer($job_id, $fid, false, $fee > 0 ? $fee : null);
        $done('Freelancer işe atandı ve bilgilendirildi.');
    }
    if ($action === 'accept_application' && $job['status'] === 'open') {
        $ap = $db->prepare("SELECT * FROM platform_applications WHERE id = ? AND job_id = ? AND status = 'pending'");
        $ap->execute([(int)($_POST['application_id'] ?? 0), $job_id]);
        $app = $ap->fetch();
        if (!$app) {
            $done('Başvuru bulunamadı.', 'error');
        }
        $use_fee = isset($_POST['use_proposed_fee']) && $app['proposed_fee'] !== null ? (float)$app['proposed_fee'] : null;
        job_assign_freelancer($job_id, (int)$app['user_id'], false, $use_fee);
        // Seçilmeyenlere bilgi
        $others = $db->prepare("SELECT user_id FROM platform_applications WHERE job_id = ? AND status = 'rejected'");
        $others->execute([$job_id]);
        foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $o) {
            notify_user((int)$o, "{$job['job_code']} için başka bir freelancer seçildi. Başvurunuz için teşekkürler.", "/platform/job.php?id={$job_id}", $job_id);
        }
        $done('Başvuru kabul edildi, iş atandı.');
    }
    if ($action === 'unassign' && in_array($job['status'], ['assigned', 'in_progress', 'revision'], true) && $job['assigned_type'] === 'freelancer') {
        $old = (int)$job['assigned_user_id'];
        job_unassign($job_id);
        notify_user($old, "{$job['job_code']} işindeki atamanız platform ekibi tarafından kaldırıldı.", "/platform/jobs.php", $job_id);
        $done('Atama kaldırıldı, iş tekrar havuzda.');
    }

    // ---------- Kalite kontrol & teslim ----------
    if (in_array($action, ['qa_approve', 'qa_reject'], true) && $job['status'] === 'qa_review') {
        $fb = trim($_POST['feedback'] ?? '');
        if ($action === 'qa_reject' && $fb === '') {
            $done('Düzeltme notu yazınız.', 'error');
        }
        job_qa_decision($job, (int)($_POST['delivery_id'] ?? 0), $action === 'qa_approve', $fb);
        $done($action === 'qa_approve' ? 'Teslimat onaylandı ve ajansa iletildi.' : 'Teslimat düzeltme için freelancer\'a geri gönderildi.');
    }
    if ($action === 'staff_deliver' && in_array($job['status'], ['in_progress', 'revision', 'assigned'], true)) {
        $url = trim($_POST['url'] ?? '');
        if (!is_safe_url($url)) {
            $done('Geçerli bir teslim bağlantısı giriniz.', 'error');
        }
        job_submit_delivery($job, $url, trim($_POST['note'] ?? ''), 'staff', (int)$user['id']);
        $done('Teslimat ajansa gönderildi.');
    }
    if ($action === 'force_complete' && in_array($job['status'], ['delivered', 'qa_review'], true)) {
        job_complete(get_job($job_id));
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işi tamamlandı olarak kapatıldı.", "/platform/job.php?id={$job_id}", $job_id);
        $done('İş tamamlandı olarak kapatıldı; faturalar oluşturuldu.');
    }
    if ($action === 'cancel' && !in_array($job['status'], ['completed', 'cancelled'], true)) {
        $reason = trim($_POST['reason'] ?? '') ?: 'Platform tarafından iptal edildi';
        $db->prepare("UPDATE platform_jobs SET status = 'cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $job_id]);
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} iptal edildi: {$reason}", "/platform/job.php?id={$job_id}", $job_id);
        if (!empty($job['assigned_user_id'])) {
            notify_user((int)$job['assigned_user_id'], "{$job['job_code']} iptal edildi: {$reason}", "/platform/jobs.php", $job_id);
        }
        $done('İş iptal edildi.');
    }

    // ---------- Kapanış sonrası ----------
    if ($action === 'rate_freelancer' && $job['status'] === 'completed' && $job['assigned_type'] === 'freelancer') {
        rate_freelancer($job, (int)($_POST['rating'] ?? 0));
        $done('Freelancer puanlandı.');
    }
    if ($action === 'pay_freelancer' && !empty($job['purchase_invoice_id'])) {
        $inv = $db->query("SELECT grand_total, paid_amount FROM invoices WHERE id = " . (int)$job['purchase_invoice_id'])->fetch();
        $amount = parse_money($_POST['amount'] ?? '') ?: ((float)$inv['grand_total'] - (float)$inv['paid_amount']);
        $err = record_invoice_payment((int)$job['purchase_invoice_id'], (int)($_POST['account_id'] ?? 0), $amount, valid_date($_POST['pay_date'] ?? '', date('Y-m-d')), (int)$user['id']);
        if ($err) {
            $done($err, 'error');
        }
        notify_user((int)$job['assigned_user_id'], "💸 {$job['job_code']} hakedişiniz ödendi: " . format_money($amount), "/platform/earnings.php", $job_id);
        $done('Freelancer ödemesi kaydedildi.');
    }

    // ---------- Mesajlar ----------
    if ($action === 'message') {
        $ch  = ($_POST['channel'] ?? '') === 'freelancer' ? 'freelancer' : 'agency';
        $msg = trim($_POST['message'] ?? '');
        if ($msg !== '') {
            add_job_message($job_id, $ch, 'staff', (int)$user['id'], 'RY Medya Ekibi', $msg);
            if ($ch === 'agency') {
                notify_contact_users($job['agency_contact_id'], "💬 {$job['job_code']} için ekipten mesaj: \"" . mb_substr($msg, 0, 120) . "\"", "/platform/job.php?id={$job_id}#mesajlar", $job_id);
            } elseif (!empty($job['assigned_user_id'])) {
                notify_user((int)$job['assigned_user_id'], "💬 {$job['job_code']} için ekipten mesaj: \"" . mb_substr($msg, 0, 120) . "\"", "/platform/job.php?id={$job_id}#mesajlar", $job_id);
            }
        }
        redirect($self . '#mesajlar');
    }
    redirect($self);
}

// ====================================================================
// GÖRÜNTÜLEME VERİLERİ
// ====================================================================
$job = get_job($job_id);
$freelancers = $db->query("
    SELECT fp.*, u.full_name, u.email, u.phone,
           (SELECT COUNT(*) FROM platform_jobs pj WHERE pj.assigned_user_id = fp.user_id AND pj.status IN ('" . implode("','", JOB_ACTIVE_STATUSES) . "')) AS active_jobs
    FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id
    WHERE fp.status = 'approved' AND u.status = 'active'
    ORDER BY FIELD(fp.tier, 'elite', 'gold', 'silver', 'standard'), fp.rating_avg DESC
")->fetchAll();

// "Bu işi kimler görüyor?" önizlemesi (pazarlama politikasının etkisi)
$preview_job = $job;
$preview_job['status'] = 'open';
$preview_job['published_at'] = $job['published_at'] ?: date('Y-m-d H:i:s');
$visibility_preview = [];
foreach ($freelancers as $f) {
    $reason = job_visibility_reason($preview_job, $f);
    $visibility_preview[] = ['f' => $f, 'reason' => $reason];
}
$visible_count = count(array_filter($visibility_preview, fn($v) => $v['reason'] === null));

$selected_ids = $db->query("SELECT user_id FROM platform_job_visible_to WHERE job_id = {$job_id}")->fetchAll(PDO::FETCH_COLUMN);
$applications = $db->query("
    SELECT a.*, u.full_name, fp.tier, fp.rating_avg, fp.completed_jobs, fp.portfolio_url
    FROM platform_applications a JOIN users u ON u.id = a.user_id LEFT JOIN freelancer_profiles fp ON fp.user_id = a.user_id
    WHERE a.job_id = {$job_id} ORDER BY FIELD(a.status, 'pending', 'accepted', 'rejected', 'withdrawn'), a.id
")->fetchAll();
$deliveries = job_deliveries($job_id);
$msg_agency = job_messages($job_id, 'agency');
$msg_free   = job_messages($job_id, 'freelancer');
$agencies   = $db->query("SELECT id, company_title FROM contacts WHERE type = 'agency' ORDER BY company_title")->fetchAll();
$accounts   = $db->query("SELECT id, account_name, balance, currency FROM accounts WHERE status = 'active'")->fetchAll();
$purchase   = $job['purchase_invoice_id'] ? $db->query("SELECT * FROM invoices WHERE id = " . (int)$job['purchase_invoice_id'])->fetch() : null;
// İş geçmişi: aynı olayın birden çok kişiye giden bildirim kopyaları tekilleştirilir
$history = [];
foreach ($db->query("SELECT * FROM activity_log WHERE entity_type = 'job' AND entity_id = {$job_id} ORDER BY id DESC LIMIT 80")->fetchAll() as $h) {
    $history[$h['message'] . '|' . $h['created_at']] ??= $h;
}
$history = array_slice(array_values($history), 0, 30);

$margin = ($job['agency_price'] !== null && $job['freelancer_fee'] !== null) ? (float)$job['agency_price'] - (float)$job['freelancer_fee'] : null;
$default_margin = (float)platform_setting('platform_default_margin');
$suggest_fee = $job['agency_price'] !== null ? round((float)$job['agency_price'] * (1 - $default_margin / 100), 2) : null;

$page_title = $job['job_code'] . ' · Platform İşi';
require_once __DIR__ . '/../../includes/header.php';
$fi = 'w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs';
?>
<div x-data="{ tab: 'agency', vis: '<?= e($job['visibility']) ?>' }">

<div class="mb-5">
    <a href="<?= BASE_URL ?>/modules/platform/index.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800">← İş Platformu</a>
    <div class="mt-2 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-xs font-bold text-slate-500"><?= e($job['job_code']) ?></span>
                <?= job_status_badge($job['status']) ?>
                <span class="text-[10px] font-bold bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full"><?= e(job_category_label($job['category'])) ?></span>
                <?php if ($job['internal_project_id']): ?>
                    <a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= (int)$job['internal_project_id'] ?>" class="text-[10px] font-bold bg-brand-50 text-brand-700 px-2 py-0.5 rounded-full border border-brand-200">ERP Projesi →</a>
                <?php endif; ?>
            </div>
            <h1 class="text-2xl font-black text-slate-900 mt-1"><?= e($job['title']) ?></h1>
            <p class="text-xs text-slate-500 mt-0.5">
                🏢 <?= $job['agency_contact_id'] ? '<a class="font-semibold hover:underline" href="' . BASE_URL . '/modules/contacts/detail.php?id=' . (int)$job['agency_contact_id'] . '">' . e($job['agency_name']) . '</a>' : 'İç iş' ?>
                · Teslim: <strong><?= format_date($job['deadline']) ?></strong>
                · Atanan: <strong><?= $job['assigned_type'] === 'internal' ? 'Ekibimiz' : e($job['assignee_name'] ?? '—') ?></strong>
                <?php if ((int)$job['revision_count'] > 0): ?> · Revizyon: <strong><?= (int)$job['revision_count'] ?></strong><?php endif; ?>
            </p>
        </div>
        <div class="grid grid-cols-3 gap-2 text-center">
            <div class="bg-white border border-slate-200 rounded-xl px-3 py-2"><p class="text-[10px] font-bold text-slate-400 uppercase">Ajans</p><p class="text-sm font-black"><?= $job['agency_price'] !== null ? format_money($job['agency_price'], $job['currency']) : '-' ?></p></div>
            <div class="bg-white border border-slate-200 rounded-xl px-3 py-2"><p class="text-[10px] font-bold text-slate-400 uppercase">Freelancer</p><p class="text-sm font-black"><?= $job['freelancer_fee'] !== null ? format_money($job['freelancer_fee'], $job['currency']) : '-' ?></p></div>
            <div class="bg-white border border-slate-200 rounded-xl px-3 py-2"><p class="text-[10px] font-bold text-slate-400 uppercase">Marj</p><p class="text-sm font-black <?= $margin !== null && $margin < 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $margin !== null ? format_money($margin, $job['currency']) : '-' ?></p></div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
<div class="xl:col-span-2 space-y-5">

    <!-- ============ SIRADAKİ ADIM ============ -->
    <div class="bg-white border-2 border-brand-200 rounded-3xl p-5">
        <h2 class="text-sm font-black text-slate-900 mb-3 flex items-center gap-2"><i data-lucide="zap" class="w-4 h-4 text-brand-600"></i> Sıradaki Adım</h2>

        <?php if (in_array($job['status'], ['submitted', 'quote_sent'], true)): ?>
            <form method="POST" action="" class="space-y-3">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_pricing">
                <p class="text-xs text-slate-600"><?= $job['status'] === 'quote_sent' ? 'Ajansın fiyat onayı bekleniyor. Fiyatı güncelleyip tekrar gönderebilir veya onay beklemeden yayınlayabilirsiniz.' : 'Talebi inceleyin, ajans fiyatını ve freelancer ücretini belirleyin.' ?>
                    <?php if ($job['budget'] !== null): ?><br>Ajansın belirttiği bütçe: <strong><?= format_money($job['budget'], $job['currency']) ?></strong><?php endif; ?></p>
                <div class="grid grid-cols-2 gap-3" x-data="{ price: <?= js_val((float)($job['agency_price'] ?? $job['budget'] ?? 0)) ?>, fee: <?= js_val((float)($job['freelancer_fee'] ?? 0)) ?> }">
                    <div><label class="block text-xs font-bold text-slate-600 mb-1">Ajans Fiyatı (KDV hariç)</label><input type="number" step="0.01" name="agency_price" x-model="price" class="<?= $fi ?>"></div>
                    <div><label class="block text-xs font-bold text-slate-600 mb-1">Freelancer Ücreti <button type="button" @click="fee = (price * <?= 1 - $default_margin / 100 ?>).toFixed(2)" class="text-[10px] text-brand-600 underline">%<?= $default_margin ?> marjla öner</button></label><input type="number" step="0.01" name="freelancer_fee" x-model="fee" class="<?= $fi ?>"></div>
                    <p class="col-span-2 text-xs text-slate-500">Platform marjı: <strong class="text-emerald-700" x-text="((price || 0) - (fee || 0)).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺'"></strong>
                        <span x-show="price > 0" x-text="' (%' + (((price - fee) / price) * 100).toFixed(1) + ')'"></span></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button name="next" value="quote" class="px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold rounded-xl">💰 Fiyat Teklifini Ajansa Gönder</button>
                    <button name="next" value="publish" class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl">✓ Onayla & Yayınla (fiyat onayı beklemeden)</button>
                    <button name="next" value="" class="px-4 py-2.5 bg-white border border-slate-300 text-xs font-bold rounded-xl">Sadece Kaydet</button>
                </div>
            </form>

        <?php elseif ($job['status'] === 'open'): ?>
            <p class="text-xs text-slate-600 mb-3">
                İş <?= $job['visibility'] === 'internal' ? '<strong>ekibe özel</strong> (freelancer\'lar görmüyor)' : '<strong>' . $visible_count . ' freelancer\'a görünür</strong> (' . e(JOB_DISPATCH[$job['dispatch_mode']]) . ')' ?>.
                Kendiniz üstlenebilir, doğrudan atayabilir<?= $job['dispatch_mode'] === 'application' ? ' veya başvurulardan seçebilir' : '' ?>siniz.
            </p>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="" onsubmit="return confirm('İşi ekibiniz üstlensin mi? ERP\'de proje açılacak.');"><?= csrf_field() ?><input type="hidden" name="action" value="take_internal">
                    <button class="px-4 py-2.5 bg-slate-900 hover:bg-slate-700 text-white text-xs font-bold rounded-xl">🏢 İşi Ekibim Üstlensin</button></form>
            </div>
            <form method="POST" action="" class="mt-3 flex flex-wrap items-end gap-2 p-3 bg-slate-50 rounded-2xl border border-slate-200"><?= csrf_field() ?><input type="hidden" name="action" value="assign_direct">
                <div class="flex-1 min-w-[220px]"><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Doğrudan Freelancer Ata</label>
                    <select name="user_id" required class="<?= $fi ?> bg-white">
                        <option value="">Seçin...</option>
                        <?php foreach ($freelancers as $f): ?>
                            <option value="<?= (int)$f['user_id'] ?>"><?= e($f['full_name']) ?> · <?= FREELANCER_TIERS[$f['tier']]['label'] ?? $f['tier'] ?> · <?= e($f['city'] ?? '-') ?> · aktif <?= (int)$f['active_jobs'] ?><?= (int)$f['is_available'] !== 1 ? ' · MÜSAİT DEĞİL' : '' ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Ücret (boş = mevcut)</label><input type="number" step="0.01" name="fee" class="<?= $fi ?> bg-white w-36"></div>
                <button class="px-4 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl">Ata</button>
            </form>

        <?php elseif (in_array($job['status'], ['assigned', 'in_progress', 'revision'], true)): ?>
            <?php if ($job['assigned_type'] === 'internal'): ?>
                <p class="text-xs text-slate-600 mb-3">Ekibiniz çalışıyor. Bitince teslim bağlantısını ajansa gönderin.</p>
                <form method="POST" action="" class="space-y-2"><?= csrf_field() ?><input type="hidden" name="action" value="staff_deliver">
                    <input type="url" name="url" required placeholder="Teslim bağlantısı (https://...)" class="<?= $fi ?>">
                    <textarea name="note" rows="2" placeholder="Teslim notu" class="<?= $fi ?>"></textarea>
                    <button class="px-4 py-2.5 bg-emerald-600 text-white text-xs font-bold rounded-xl">📦 Ajansa Teslim Et</button></form>
            <?php else: ?>
                <p class="text-xs text-slate-600"><strong><?= e($job['assignee_name']) ?></strong> <?= $job['status'] === 'assigned' ? 'işi aldı, başlamasını bekliyor.' : ($job['status'] === 'revision' ? 'revizyon üzerinde çalışıyor.' : 'çalışıyor.') ?></p>
                <form method="POST" action="" class="mt-3" onsubmit="return confirm('Atama kaldırılıp iş havuza geri alınsın mı?');"><?= csrf_field() ?><input type="hidden" name="action" value="unassign">
                    <button class="px-4 py-2 bg-white border border-slate-300 text-xs font-bold rounded-xl">↩ Atamayı Kaldır, Havuza Geri Al</button></form>
            <?php endif; ?>

        <?php elseif ($job['status'] === 'qa_review'): ?>
            <?php $pending_d = array_values(array_filter($deliveries, fn($d) => $d['status'] === 'qa')); $pd = $pending_d[0] ?? null; ?>
            <?php if ($pd): ?>
            <div class="p-3 bg-purple-50 border border-purple-200 rounded-2xl mb-3">
                <a href="<?= e($pd['url']) ?>" target="_blank" rel="noopener" class="text-sm font-bold text-purple-800 underline break-all">📎 Teslimatı Aç</a>
                <?php if ($pd['note']): ?><p class="text-xs text-slate-700 mt-1 whitespace-pre-line"><?= e($pd['note']) ?></p><?php endif; ?>
            </div>
            <form method="POST" action="" class="space-y-2" x-data="{ reject: false }"><?= csrf_field() ?><input type="hidden" name="delivery_id" value="<?= (int)$pd['id'] ?>">
                <textarea name="feedback" rows="2" placeholder="Not (reddederken zorunlu)" class="<?= $fi ?>"></textarea>
                <div class="flex gap-2">
                    <button name="action" value="qa_approve" class="px-4 py-2.5 bg-emerald-600 text-white text-xs font-bold rounded-xl">✓ Kaliteyi Onayla, Ajansa Gönder</button>
                    <button name="action" value="qa_reject" class="px-4 py-2.5 bg-white border border-rose-300 text-rose-700 text-xs font-bold rounded-xl">✕ Düzeltme İste</button>
                </div></form>
            <?php endif; ?>

        <?php elseif ($job['status'] === 'delivered'): ?>
            <p class="text-xs text-slate-600 mb-3">Teslimat ajansta, onay bekleniyor. Ajans telefonla onay verdiyse işi siz kapatabilirsiniz.</p>
            <form method="POST" action="" onsubmit="return confirm('İş tamamlandı olarak kapatılsın mı? Faturalar oluşturulacak.');"><?= csrf_field() ?><input type="hidden" name="action" value="force_complete">
                <button class="px-4 py-2.5 bg-emerald-600 text-white text-xs font-bold rounded-xl">🏁 Tamamlandı Olarak Kapat</button></form>

        <?php elseif ($job['status'] === 'completed'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="text-xs space-y-1">
                    <p>Ajans puanı: <?= $job['agency_rating'] ? render_stars((float)$job['agency_rating']) : '<span class="text-slate-400">verilmedi</span>' ?></p>
                    <?php if ($job['agency_review']): ?><p class="text-slate-600 italic">“<?= e($job['agency_review']) ?>”</p><?php endif; ?>
                    <?php if ($job['sales_invoice_id']): ?><p><a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= (int)$job['sales_invoice_id'] ?>" target="_blank" class="font-bold text-brand-600 underline">Ajans satış faturası</a></p><?php endif; ?>
                    <?php if ($job['assigned_type'] === 'freelancer'): ?>
                    <form method="POST" action="" class="flex items-center gap-2 pt-2"><?= csrf_field() ?><input type="hidden" name="action" value="rate_freelancer">
                        <span class="font-bold">Freelancer puanı:</span>
                        <select name="rating" class="py-1 px-2 border border-slate-200 rounded-lg"><?php for ($r = 5; $r >= 1; $r--): ?><option value="<?= $r ?>" <?= (int)$job['freelancer_rating'] === $r ? 'selected' : '' ?>><?= str_repeat('★', $r) ?></option><?php endfor; ?></select>
                        <button class="px-3 py-1 bg-slate-900 text-white rounded-lg font-bold">Kaydet</button></form>
                    <?php endif; ?>
                </div>
                <?php if ($purchase): $remaining = (float)$purchase['grand_total'] - (float)$purchase['paid_amount']; ?>
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                    <p class="font-bold text-slate-800">Freelancer Hakedişi: <?= format_money($purchase['grand_total']) ?></p>
                    <p class="text-slate-500">Ödenen: <?= format_money($purchase['paid_amount']) ?> · Kalan: <strong><?= format_money($remaining) ?></strong></p>
                    <?php if ($remaining > 0.009): ?>
                    <form method="POST" action="" class="mt-2 space-y-2"><?= csrf_field() ?><input type="hidden" name="action" value="pay_freelancer">
                        <select name="account_id" required class="<?= $fi ?> bg-white"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['account_name']) ?> (<?= format_money($a['balance'], $a['currency']) ?>)</option><?php endforeach; ?></select>
                        <div class="flex gap-2"><input type="number" step="0.01" name="amount" value="<?= number_format($remaining, 2, '.', '') ?>" class="<?= $fi ?> bg-white"><input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" class="<?= $fi ?> bg-white"></div>
                        <button class="w-full py-2 bg-emerald-600 text-white font-bold rounded-xl">💸 Ödemeyi Kaydet</button></form>
                    <?php else: ?><p class="mt-2 font-bold text-emerald-700">✓ Ödendi</p><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php elseif ($job['status'] === 'cancelled'): ?>
            <p class="text-sm text-rose-700">İptal edildi: <?= e($job['cancel_reason'] ?? '') ?></p>
        <?php endif; ?>

        <?php if (!in_array($job['status'], ['completed', 'cancelled'], true)): ?>
        <details class="mt-4 text-xs"><summary class="cursor-pointer text-slate-400 hover:text-rose-600">İşi iptal et</summary>
            <form method="POST" action="" class="mt-2 flex gap-2" onsubmit="return confirm('İş iptal edilsin mi? Ajans ve freelancer bilgilendirilecek.');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <input type="text" name="reason" placeholder="İptal nedeni" class="flex-1 py-2 px-3 border border-slate-200 rounded-xl"><button class="px-4 py-2 bg-rose-600 text-white font-bold rounded-xl">İptal Et</button></form>
        </details>
        <?php endif; ?>
    </div>

    <!-- ============ BAŞVURULAR ============ -->
    <?php if ($applications): ?>
    <div class="bg-white border border-slate-200 rounded-3xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-3">Başvurular (<?= count($applications) ?>)</h2>
        <div class="space-y-2">
            <?php foreach ($applications as $a): ?>
            <div class="p-3 rounded-2xl border <?= $a['status'] === 'accepted' ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-slate-50' ?> flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="text-xs">
                    <p class="font-bold text-slate-900"><?= e($a['full_name']) ?> <span class="ml-1 px-1.5 py-0.5 rounded border text-[10px] <?= FREELANCER_TIERS[$a['tier']]['color'] ?? '' ?>"><?= FREELANCER_TIERS[$a['tier']]['label'] ?? $a['tier'] ?></span></p>
                    <p class="text-slate-500"><?= render_stars($a['rating_avg'] !== null ? (float)$a['rating_avg'] : null) ?> · <?= (int)$a['completed_jobs'] ?> iş<?= $a['proposed_fee'] !== null ? ' · Önerilen: <strong>' . format_money($a['proposed_fee']) . '</strong>' : '' ?>
                        <?php if ($a['portfolio_url']): ?> · <a href="<?= e($a['portfolio_url']) ?>" target="_blank" rel="noopener" class="text-brand-600 underline">Portfolyo</a><?php endif; ?></p>
                    <?php if ($a['note']): ?><p class="text-slate-700 mt-1">“<?= e($a['note']) ?>”</p><?php endif; ?>
                </div>
                <?php if ($a['status'] === 'pending' && $job['status'] === 'open'): ?>
                <form method="POST" action="" class="flex items-center gap-2 flex-shrink-0"><?= csrf_field() ?><input type="hidden" name="action" value="accept_application"><input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                    <?php if ($a['proposed_fee'] !== null): ?><label class="text-[10px] flex items-center gap-1"><input type="checkbox" name="use_proposed_fee" value="1" class="rounded"> önerdiği ücretle</label><?php endif; ?>
                    <button class="px-3 py-2 bg-brand-600 text-white text-xs font-bold rounded-xl">Seç & Ata</button></form>
                <?php else: ?>
                    <span class="text-[10px] font-bold text-slate-500 uppercase"><?= e(['accepted' => 'Seçildi', 'rejected' => 'Seçilmedi', 'withdrawn' => 'Geri çekti', 'pending' => 'Bekliyor'][$a['status']] ?? $a['status']) ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============ GÖRÜNÜRLÜK POLİTİKASI ============ -->
    <div class="bg-white border border-slate-200 rounded-3xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-1 flex items-center gap-2"><i data-lucide="eye" class="w-4 h-4"></i> Görünürlük & Dağıtım Politikası</h2>
        <p class="text-[11px] text-slate-400 mb-3">Bu işi hangi freelancer'ların göreceğini ve nasıl alacağını belirleyin.<?= $editable_policy ? '' : ' (İş atandıktan sonra değiştirilemez.)' ?></p>
        <form method="POST" action="" class="space-y-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_policy">
            <fieldset <?= $editable_policy ? '' : 'disabled' ?> class="space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                <?php foreach (JOB_VISIBILITY as $vk => $vl): ?>
                <label class="flex items-start gap-2 p-3 rounded-xl border cursor-pointer text-xs" :class="vis === '<?= $vk ?>' ? 'border-brand-500 bg-brand-50' : 'border-slate-200'">
                    <input type="radio" name="visibility" value="<?= $vk ?>" x-model="vis" class="mt-0.5"> <span class="font-semibold"><?= e($vl) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div x-show="vis === 'pool'" class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3 bg-slate-50 rounded-2xl border border-slate-200">
                <div><label class="block text-xs font-bold text-slate-600 mb-1">Asgari Seviye</label>
                    <select name="min_tier" class="<?= $fi ?> bg-white"><?php foreach (FREELANCER_TIERS as $tk => $tv): ?><option value="<?= $tk ?>" <?= $job['min_tier'] === $tk ? 'selected' : '' ?>><?= $tv['label'] ?> ve üzeri</option><?php endforeach; ?></select></div>
                <div><label class="block text-xs font-bold text-slate-600 mb-1">Öncelikli Erişim</label>
                    <div class="flex gap-1">
                        <select name="priority_tier" class="<?= $fi ?> bg-white"><option value="">Yok</option><?php foreach (FREELANCER_TIERS as $tk => $tv): ?><option value="<?= $tk ?>" <?= $job['priority_tier'] === $tk ? 'selected' : '' ?>><?= $tv['label'] ?>+ önce görsün</option><?php endforeach; ?></select>
                        <input type="number" name="priority_hours" min="0" max="720" value="<?= (int)$job['priority_hours'] ?>" class="<?= $fi ?> bg-white w-20" title="saat"><span class="text-xs self-center text-slate-500">saat</span>
                    </div></div>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="skill_match_only" value="1" <?= (int)$job['skill_match_only'] === 1 ? 'checked' : '' ?> class="rounded"> Sadece bu iş türünde uzman olanlar görsün</label>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="city_match_only" value="1" <?= (int)$job['city_match_only'] === 1 ? 'checked' : '' ?> class="rounded"> Sadece aynı şehirdekiler görsün (uzaktan işlerde geçersiz)</label>
            </div>
            <div x-show="vis === 'selected'" class="p-3 bg-slate-50 rounded-2xl border border-slate-200 max-h-56 overflow-y-auto grid grid-cols-1 md:grid-cols-2 gap-1">
                <?php foreach ($freelancers as $f): ?>
                <label class="flex items-center gap-2 text-xs p-1.5 rounded-lg hover:bg-white"><input type="checkbox" name="selected_users[]" value="<?= (int)$f['user_id'] ?>" <?= in_array($f['user_id'], $selected_ids) ? 'checked' : '' ?> class="rounded">
                    <?= e($f['full_name']) ?> <span class="text-slate-400">· <?= FREELANCER_TIERS[$f['tier']]['label'] ?? '' ?> · <?= e($f['city'] ?? '') ?></span></label>
                <?php endforeach; ?>
            </div>
            <div x-show="vis !== 'internal'"><label class="block text-xs font-bold text-slate-600 mb-1">Alma Şekli</label>
                <select name="dispatch_mode" class="<?= $fi ?>"><?php foreach (JOB_DISPATCH as $dk => $dv): ?><option value="<?= $dk ?>" <?= $job['dispatch_mode'] === $dk ? 'selected' : '' ?>><?= e($dv) ?></option><?php endforeach; ?></select></div>
            <?php if ($editable_policy): ?><button class="px-4 py-2 bg-slate-900 text-white text-xs font-bold rounded-xl">Politikayı Kaydet</button><?php endif; ?>
            </fieldset>
        </form>

        <details class="mt-4">
            <summary class="cursor-pointer text-xs font-bold text-brand-600">👁 Bu işi kimler görüyor? (<?= $job['visibility'] === 'internal' ? 0 : $visible_count ?> / <?= count($freelancers) ?> onaylı freelancer)</summary>
            <div class="mt-2 max-h-72 overflow-y-auto divide-y divide-slate-100 text-xs border border-slate-100 rounded-xl">
                <?php foreach ($visibility_preview as $vp): $f = $vp['f']; ?>
                <div class="flex items-center justify-between px-3 py-2">
                    <span><?= e($f['full_name']) ?> <span class="text-slate-400">· <?= FREELANCER_TIERS[$f['tier']]['label'] ?? '' ?> · <?= e($f['city'] ?? '-') ?></span></span>
                    <?= $vp['reason'] === null ? '<span class="font-bold text-emerald-600">✓ Görüyor</span>' : '<span class="text-slate-400">✕ ' . e($vp['reason']) . '</span>' ?>
                </div>
                <?php endforeach; ?>
                <?php if (!$freelancers): ?><p class="p-3 text-slate-400">Onaylı freelancer yok.</p><?php endif; ?>
            </div>
        </details>
    </div>

    <!-- ============ İŞ BİLGİLERİ ============ -->
    <details class="bg-white border border-slate-200 rounded-3xl p-5" <?= in_array($job['status'], ['submitted'], true) ? 'open' : '' ?>>
        <summary class="cursor-pointer text-sm font-bold text-slate-900">İş Bilgileri & Brief</summary>
        <form method="POST" action="" class="mt-4 space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="save_details">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="md:col-span-2"><label class="block text-xs font-bold text-slate-600 mb-1">Başlık</label><input type="text" name="title" value="<?= e($job['title']) ?>" class="<?= $fi ?>"></div>
                <div><label class="block text-xs font-bold text-slate-600 mb-1">Ajans</label><select name="agency_contact_id" class="<?= $fi ?>"><option value="">— İç iş —</option><?php foreach ($agencies as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$job['agency_contact_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['company_title']) ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-xs font-bold text-slate-600 mb-1">İş Türü</label><select name="category" class="<?= $fi ?>"><?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>" <?= $job['category'] === $ck ? 'selected' : '' ?>><?= e($cv['label']) ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-xs font-bold text-slate-600 mb-1">Başlangıç</label><input type="date" name="start_date" value="<?= e($job['start_date']) ?>" class="<?= $fi ?>"></div>
                <div><label class="block text-xs font-bold text-slate-600 mb-1">Teslim</label><input type="date" name="deadline" value="<?= e($job['deadline']) ?>" class="<?= $fi ?>"></div>
                <div><label class="block text-xs font-bold text-slate-600 mb-1">Şehir</label><input type="text" name="location_city" value="<?= e($job['location_city']) ?>" class="<?= $fi ?>"></div>
                <div><label class="block text-xs font-bold text-slate-600 mb-1">Lokasyon Detayı</label><input type="text" name="location_detail" value="<?= e($job['location_detail']) ?>" class="<?= $fi ?>"></div>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_remote" value="1" <?= (int)$job['is_remote'] === 1 ? 'checked' : '' ?> class="rounded"> Uzaktan yapılabilir</label>
            </div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Brief</label><textarea name="description" rows="4" class="<?= $fi ?>"><?= e($job['description']) ?></textarea></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Teslimatlar</label><textarea name="deliverables" rows="3" class="<?= $fi ?>"><?= e($job['deliverables']) ?></textarea></div>
            <div><label class="block text-xs font-bold text-slate-600 mb-1">Referanslar</label><textarea name="reference_links" rows="2" class="<?= $fi ?>"><?= e($job['reference_links']) ?></textarea></div>
            <button class="px-4 py-2 bg-slate-900 text-white text-xs font-bold rounded-xl">Kaydet</button>
        </form>
    </details>

    <?php if ($deliveries): ?>
    <div class="bg-white border border-slate-200 rounded-3xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-3">Teslimat Geçmişi</h2>
        <div class="space-y-2 text-xs">
            <?php foreach ($deliveries as $d): ?>
            <div class="p-3 bg-slate-50 rounded-xl border border-slate-200">
                <div class="flex justify-between gap-2"><a href="<?= e($d['url']) ?>" target="_blank" rel="noopener" class="font-bold text-brand-600 underline break-all">📎 <?= e($d['url']) ?></a>
                    <span class="font-bold uppercase text-[10px] text-slate-500"><?= e(['qa' => 'Kalite kontrolde', 'sent' => 'Ajansta', 'approved' => 'Onaylandı', 'revision' => 'Ajans revizyon istedi', 'qa_rejected' => 'KK reddetti'][$d['status']] ?? $d['status']) ?></span></div>
                <?php if ($d['note']): ?><p class="mt-1 text-slate-600"><?= e($d['note']) ?></p><?php endif; ?>
                <?php if ($d['feedback']): ?><p class="mt-1 text-orange-700"><strong>Geri bildirim:</strong> <?= e($d['feedback']) ?></p><?php endif; ?>
                <p class="text-[10px] text-slate-400 mt-1"><?= $d['submitted_by_type'] === 'staff' ? 'Ekip' : 'Freelancer' ?> · <?= format_date($d['created_at'], true) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ============ SAĞ: MESAJLAR & GEÇMİŞ ============ -->
<div class="space-y-5">
    <div id="mesajlar" class="bg-white border border-slate-200 rounded-3xl p-5">
        <div class="flex gap-1 mb-3 text-xs font-bold">
            <button @click="tab = 'agency'" :class="tab === 'agency' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600'" class="flex-1 py-2 rounded-xl">Ajans (<?= count($msg_agency) ?>)</button>
            <button @click="tab = 'freelancer'" :class="tab === 'freelancer' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600'" class="flex-1 py-2 rounded-xl">Freelancer (<?= count($msg_free) ?>)</button>
        </div>
        <p class="text-[10px] text-slate-400 mb-3">Ajans ve freelancer birbirinin mesajlarını görmez; ikisi de yalnızca "RY Medya Ekibi" ile yazışır.</p>
        <?php foreach (['agency' => $msg_agency, 'freelancer' => $msg_free] as $ch => $msgs): ?>
        <div x-show="tab === '<?= $ch ?>'" <?= $ch === 'freelancer' ? 'x-cloak' : '' ?>>
            <div class="space-y-2 max-h-80 overflow-y-auto mb-3">
                <?php if (!$msgs): ?><p class="text-xs text-slate-400 text-center py-4">Mesaj yok.</p><?php endif; ?>
                <?php foreach ($msgs as $m): $mine = $m['sender_type'] === 'staff'; ?>
                <div class="flex <?= $mine ? 'justify-end' : 'justify-start' ?>">
                    <div class="max-w-[85%] px-3 py-2 rounded-2xl text-xs <?= $mine ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-800' ?>">
                        <p class="text-[10px] font-bold opacity-70"><?= e($m['sender_name']) ?> · <?= time_ago($m['created_at']) ?></p>
                        <p class="whitespace-pre-line"><?= e($m['message']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($ch === 'agency' && !$job['agency_contact_id']): ?>
                <p class="text-[11px] text-slate-400">İç işte ajans kanalı yok.</p>
            <?php elseif ($ch === 'freelancer' && $job['assigned_type'] !== 'freelancer'): ?>
                <p class="text-[11px] text-slate-400">İşe freelancer atandığında yazışabilirsiniz.</p>
            <?php else: ?>
            <form method="POST" action="" class="flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="message"><input type="hidden" name="channel" value="<?= $ch ?>">
                <textarea name="message" rows="2" required placeholder="<?= $ch === 'agency' ? 'Ajansa yazın...' : 'Freelancer\'a yazın...' ?>" class="flex-1 p-2 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                <button class="px-3 bg-slate-900 text-white rounded-xl"><i data-lucide="send" class="w-4 h-4"></i></button></form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white border border-slate-200 rounded-3xl p-5">
        <h2 class="text-sm font-bold text-slate-900 mb-3">İş Geçmişi</h2>
        <ol class="relative border-l-2 border-slate-200 ml-2 space-y-3">
            <?php foreach ($history as $h): ?>
            <li class="ml-4"><span class="absolute -left-[7px] w-3 h-3 rounded-full <?= $h['actor_type'] === 'client' ? 'bg-indigo-500' : 'bg-slate-400' ?>"></span>
                <p class="text-xs text-slate-800"><?= e($h['message']) ?></p>
                <p class="text-[10px] text-slate-400"><?= e($h['actor_name'] ?? '') ?> · <?= format_date($h['created_at'], true) ?></p></li>
            <?php endforeach; ?>
            <?php if (!$history): ?><li class="ml-4 text-xs text-slate-400">Kayıt yok.</li><?php endif; ?>
        </ol>
    </div>
</div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
