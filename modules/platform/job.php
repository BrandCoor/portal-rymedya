<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - İŞ DETAYI (KONTROL MERKEZİ)
 * ====================================================================
 * Sıradaki adım paneli · Fiyat (özel talepler) · Görünürlük politikası
 * Teklifler: kabul / gerekçeli ret / kişiye özel yazışma
 * Atama (ekip / freelancer, seviye kapasitesi) · Kalite kontrol · Teslim
 * Kapanış, puanlama, hakediş ödemesi · Değişiklik geçmişi · Kalıcı silme
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
$user       = current_user();
$staff_id   = (int)$user['id'];
$self       = BASE_URL . "/modules/platform/job.php?id={$job_id}";
$can_delete = can_access_module('platform.delete');
$policy_editable = in_array($job['status'], ['submitted', 'quote_sent', 'open'], true);

/** Freelancer profili + kapasite */
function staff_freelancer(int $uid): ?array {
    global $db;
    $st = $db->prepare("SELECT fp.*, u.full_name, u.email FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.user_id = ?");
    $st->execute([$uid]);
    $p = $st->fetch();
    if (!$p) return null;
    $p['cap'] = freelancer_capacity($p);
    return $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $done = function (string $msg, string $type = 'success', string $anchor = '') use ($self) {
        set_flash($type, $msg);
        redirect($self . $anchor);
    };

    // ---------- İş bilgileri ----------
    if ($action === 'save_details') {
        $title    = trim($_POST['title'] ?? '');
        $deadline = valid_date($_POST['deadline'] ?? '');
        if ($title === '' || !$deadline) {
            $done('Başlık ve teslim tarihi zorunlu.', 'error');
        }
        $new = [
            'title' => $title,
            'category' => array_key_exists($_POST['category'] ?? '', JOB_CATEGORIES) ? $_POST['category'] : $job['category'],
            'description' => trim($_POST['description'] ?? ''),
            'deliverables' => trim($_POST['deliverables'] ?? ''),
            'reference_links' => trim($_POST['reference_links'] ?? ''),
            'agency_notes' => trim($_POST['agency_notes'] ?? ''),
            'location_city' => trim($_POST['location_city'] ?? '') ?: null,
            'location_detail' => trim($_POST['location_detail'] ?? ''),
            'is_remote' => isset($_POST['is_remote']) ? 1 : 0,
            'start_date' => valid_date($_POST['start_date'] ?? ''),
            'deadline' => $deadline,
            'agency_contact_id' => !empty($_POST['agency_contact_id']) ? (int)$_POST['agency_contact_id'] : null,
        ];
        $labels = ['title' => 'Başlık', 'category' => 'İş türü', 'description' => 'Brief', 'deliverables' => 'Teslimatlar', 'reference_links' => 'Referanslar',
                   'agency_notes' => 'Ek notlar', 'location_city' => 'Şehir', 'location_detail' => 'Lokasyon', 'is_remote' => 'Uzaktan', 'start_date' => 'Başlangıç tarihi',
                   'deadline' => 'Teslim tarihi', 'agency_contact_id' => 'Ajans'];
        $changed = [];
        foreach ($new as $col => $v) {
            if ((string)($job[$col] ?? '') !== (string)($v ?? '')) {
                $changed[] = $col;
                $fmt = fn($x) => in_array($col, ['start_date', 'deadline'], true) ? format_date($x) : ($col === 'is_remote' ? ((int)$x ? 'Evet' : 'Hayır') : $x);
                log_job_change($job_id, 'staff', $staff_id, $labels[$col], $col === 'agency_contact_id' ? $job['agency_name'] : $fmt($job[$col]), $col === 'agency_contact_id' ? ('#' . $v) : $fmt($v));
            }
        }
        if (!$changed) {
            $done('Değişiklik yapılmadı.', 'info');
        }
        $db->prepare("UPDATE platform_jobs SET " . implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($new))) . " WHERE id = ?")->execute([...array_values($new), $job_id]);
        if (!empty($job['assigned_user_id']) && array_intersect($changed, ['description', 'deliverables', 'reference_links', 'agency_notes', 'start_date', 'deadline', 'location_city', 'location_detail'])) {
            notify_user((int)$job['assigned_user_id'], "{$job['job_code']} brief/plan güncellendi.", "/platform/job.php?id={$job_id}", $job_id);
        }
        $done('İş bilgileri güncellendi.');
    }

    // ---------- Fiyat ----------
    if ($action === 'save_pricing') {
        $price = parse_money($_POST['agency_price'] ?? '');
        $fee   = parse_money($_POST['freelancer_fee'] ?? '');
        if ($price > 0 && $fee > $price) {
            $done('Freelancer ücreti ajans fiyatından yüksek olamaz.', 'error');
        }
        if (abs($price - (float)$job['agency_price']) > 0.009) {
            log_job_change($job_id, 'staff', $staff_id, 'Ajans fiyatı', $job['agency_price'] !== null ? format_money((float)$job['agency_price']) : '—', $price > 0 ? format_money($price) : '—');
        }
        if (abs($fee - (float)$job['freelancer_fee']) > 0.009) {
            log_job_change($job_id, 'staff', $staff_id, 'Freelancer ücreti', $job['freelancer_fee'] !== null ? format_money((float)$job['freelancer_fee']) : '—', $fee > 0 ? format_money($fee) : '—');
            if (!empty($job['assigned_user_id'])) {
                notify_user((int)$job['assigned_user_id'], "{$job['job_code']} hakedişiniz güncellendi: " . format_money($fee), "/platform/job.php?id={$job_id}", $job_id);
            }
        }
        $db->prepare("UPDATE platform_jobs SET agency_price = ?, freelancer_fee = ? WHERE id = ?")->execute([$price > 0 ? $price : null, $fee > 0 ? $fee : null, $job_id]);
        $next = $_POST['next'] ?? '';
        if ($next === 'quote' && in_array($job['status'], ['submitted', 'quote_sent'], true)) {
            if ($price <= 0) {
                $done('Teklif için ajans fiyatı girin.', 'error');
            }
            $db->prepare("UPDATE platform_jobs SET status = 'quote_sent' WHERE id = ?")->execute([$job_id]);
            log_job_change($job_id, 'staff', $staff_id, 'Fiyat teklifi', '—', 'Ajansa gönderildi');
            notify_contact_users($job['agency_contact_id'], "{$job['job_code']} için fiyat teklifimiz hazır: " . format_money($price, $job['currency']) . " + KDV.", "/platform/job.php?id={$job_id}", $job_id);
            $done('Fiyat teklifi ajansa gönderildi.');
        }
        if ($next === 'publish' && in_array($job['status'], ['submitted', 'quote_sent'], true)) {
            job_publish($job_id);
            log_job_change($job_id, 'staff', $staff_id, 'Durum', job_status_label($job['status']), 'Yayına alındı');
            $done($job['visibility'] === 'internal' ? 'İş onaylandı (ekibe özel).' : 'İş onaylandı ve görünürlük kurallarına göre havuza açıldı.');
        }
        $done('Fiyatlar kaydedildi.');
    }
    if ($action === 'publish' && $job['status'] === 'submitted') {
        if ($job['agency_price'] === null || $job['freelancer_fee'] === null) {
            $done('Yayına almadan önce ajans fiyatı ve freelancer ücreti belirlenmeli.', 'error');
        }
        job_publish($job_id);
        log_job_change($job_id, 'staff', $staff_id, 'Durum', 'Onay bekliyor', 'Yayına alındı');
        $done('Sipariş onaylandı ve yayına alındı.');
    }

    // ---------- Görünürlük / dağıtım politikası ----------
    if ($action === 'save_policy' && $policy_editable) {
        $visibility = array_key_exists($_POST['visibility'] ?? '', JOB_VISIBILITY) ? $_POST['visibility'] : 'pool';
        $policy = [
            'visibility' => $visibility,
            'dispatch_mode' => array_key_exists($_POST['dispatch_mode'] ?? '', JOB_DISPATCH) ? $_POST['dispatch_mode'] : 'first_come',
            'min_tier' => array_key_exists($_POST['min_tier'] ?? '', FREELANCER_TIERS) ? $_POST['min_tier'] : 'standard',
            'priority_tier' => array_key_exists($_POST['priority_tier'] ?? '', FREELANCER_TIERS) ? $_POST['priority_tier'] : null,
            'priority_hours' => max(0, min(720, (int)($_POST['priority_hours'] ?? 0))),
            'skill_match_only' => isset($_POST['skill_match_only']) ? 1 : 0,
            'city_match_only' => isset($_POST['city_match_only']) ? 1 : 0,
        ];
        $db->prepare("UPDATE platform_jobs SET " . implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($policy))) . " WHERE id = ?")->execute([...array_values($policy), $job_id]);
        $before = $db->prepare("SELECT user_id FROM platform_job_visible_to WHERE job_id = ?");
        $before->execute([$job_id]);
        $before = array_map('intval', $before->fetchAll(PDO::FETCH_COLUMN));
        $db->prepare("DELETE FROM platform_job_visible_to WHERE job_id = ?")->execute([$job_id]);
        if ($visibility === 'selected') {
            $ins = $db->prepare("INSERT IGNORE INTO platform_job_visible_to (job_id, user_id) VALUES (?, ?)");
            foreach (array_unique(array_map('intval', (array)($_POST['selected_users'] ?? []))) as $sel) {
                $ins->execute([$job_id, $sel]);
                if ($job['status'] === 'open' && !in_array($sel, $before, true)) {
                    notify_user($sel, "Size özel iş: {$job['job_code']} · {$job['title']}", "/platform/job.php?id={$job_id}", $job_id);
                }
            }
        }
        $summary = JOB_VISIBILITY[$policy['visibility']] . ' · ' . JOB_DISPATCH[$policy['dispatch_mode']] . ' · ' . tier_label($policy['min_tier']) . '+';
        log_job_change($job_id, 'staff', $staff_id, 'Görünürlük politikası', null, $summary);
        $done('Görünürlük ve dağıtım kuralları kaydedildi.', 'success', '#politika');
    }

    // ---------- Atama ----------
    if ($action === 'take_internal') {
        $pid = job_take_internal($job_id, $staff_id);
        if ($pid === null) {
            $done('Bu durumdaki iş ekibe alınamaz.', 'error');
        }
        log_job_change($job_id, 'staff', $staff_id, 'Atama', '—', site_setting('platform_team_name'));
        $done('İşi ekibiniz üstlendi' . ($pid ? '; ERP\'de proje açıldı.' : '.'));
    }
    if ($action === 'assign_direct' && in_array($job['status'], ['open', 'submitted', 'quote_sent'], true)) {
        $f = staff_freelancer((int)($_POST['user_id'] ?? 0));
        if (!$f || $f['status'] !== 'approved') {
            $done('Onaylı bir freelancer seçin.', 'error');
        }
        if (!$f['cap']['can_take'] && empty($_POST['override_limit'])) {
            $done("{$f['full_name']} aktif iş limitinde ({$f['cap']['active']}/{$f['cap']['limit']}). Yine de atamak için \"limiti aş\" seçeneğini işaretleyin.", 'error');
        }
        $fee = parse_money($_POST['fee'] ?? '');
        if ($job['status'] !== 'open') {
            $db->prepare("UPDATE platform_jobs SET status = 'open', published_at = COALESCE(published_at, NOW()) WHERE id = ?")->execute([$job_id]);
        }
        job_assign_freelancer($job_id, (int)$f['user_id'], false, $fee > 0 ? $fee : null);
        log_job_change($job_id, 'staff', $staff_id, 'Atama', '—', $f['full_name'] . (!$f['cap']['can_take'] ? ' (limit aşıldı)' : ''));
        $done("İş {$f['full_name']} kişisine atandı.");
    }
    if ($action === 'accept_application' && $job['status'] === 'open') {
        $ap = $db->prepare("SELECT * FROM platform_applications WHERE id = ? AND job_id = ? AND status = 'pending'");
        $ap->execute([(int)($_POST['application_id'] ?? 0), $job_id]);
        $app = $ap->fetch();
        if (!$app) {
            $done('Teklif bulunamadı veya artık beklemede değil.', 'error', '#teklifler');
        }
        $f = staff_freelancer((int)$app['user_id']);
        if (!$f || $f['status'] !== 'approved') {
            $done('Freelancer hesabı aktif değil.', 'error', '#teklifler');
        }
        if (!$f['cap']['can_take'] && empty($_POST['override_limit'])) {
            $done("{$f['full_name']} şu an aktif iş limitinde ({$f['cap']['active']}/{$f['cap']['limit']}).", 'error', '#teklifler');
        }
        $use_fee = !empty($_POST['use_proposed_fee']) && $app['proposed_fee'] !== null ? (float)$app['proposed_fee'] : null;
        $others = $db->prepare("SELECT user_id FROM platform_applications WHERE job_id = ? AND status = 'pending' AND id != ?");
        $others->execute([$job_id, $app['id']]);
        $others = $others->fetchAll(PDO::FETCH_COLUMN);
        job_assign_freelancer($job_id, (int)$app['user_id'], false, $use_fee);
        log_job_change($job_id, 'staff', $staff_id, 'Atama', '—', $f['full_name'] . ' (teklif kabul edildi)');
        foreach ($others as $o) {
            notify_user((int)$o, "{$job['job_code']} için başka bir teklif seçildi. İlginiz için teşekkürler.", "/platform/job.php?id={$job_id}", $job_id);
        }
        $done('Teklif kabul edildi, iş atandı.', 'success', '#teklifler');
    }
    if ($action === 'reject_application') {
        $reason = trim($_POST['reason'] ?? '');
        if (mb_strlen($reason) < 3) {
            $done('Ret gerekçesi yazın; freelancer\'a iletilir.', 'error', '#teklifler');
        }
        $ap = $db->prepare("SELECT a.*, u.full_name FROM platform_applications a JOIN users u ON u.id = a.user_id WHERE a.id = ? AND a.job_id = ? AND a.status = 'pending'");
        $ap->execute([(int)($_POST['application_id'] ?? 0), $job_id]);
        $app = $ap->fetch();
        if (!$app) {
            $done('Teklif bulunamadı.', 'error', '#teklifler');
        }
        $db->prepare("UPDATE platform_applications SET status = 'rejected', reject_reason = ?, reviewed_at = NOW() WHERE id = ?")->execute([$reason, $app['id']]);
        notify_user((int)$app['user_id'], "{$job['job_code']} teklifiniz kabul edilmedi: " . mb_substr($reason, 0, 140), "/platform/job.php?id={$job_id}", $job_id);
        log_job_change($job_id, 'staff', $staff_id, 'Atama', $app['full_name'] . ' teklifi', 'Reddedildi: ' . $reason);
        $done("{$app['full_name']} teklifi reddedildi ve gerekçe iletildi.", 'success', '#teklifler');
    }
    if ($action === 'unassign' && in_array($job['status'], ['assigned', 'in_progress', 'revision'], true) && $job['assigned_type'] === 'freelancer') {
        $reason = trim($_POST['reason'] ?? '');
        $old = (int)$job['assigned_user_id'];
        $count = !empty($_POST['count_against']);
        // Freelancer kaynaklı olmayan geri almalar güvenilirlik puanına işlenmez
        job_unassign($job_id, $count ? 'staff' : 'neutral');
        recompute_freelancer_metrics($old);
        log_job_change($job_id, 'staff', $staff_id, 'Atama', $job['assignee_name'], 'Kaldırıldı' . ($reason !== '' ? ": {$reason}" : ''));
        notify_user($old, "{$job['job_code']} işindeki atamanız platform ekibi tarafından kaldırıldı." . ($reason !== '' ? " Not: {$reason}" : ''), "/platform/jobs.php", $job_id);
        $done('Atama kaldırıldı, iş tekrar havuzda.');
    }

    // ---------- Kalite kontrol & teslim ----------
    if (in_array($action, ['qa_approve', 'qa_reject'], true) && $job['status'] === 'qa_review') {
        $fb = trim($_POST['feedback'] ?? '');
        if ($action === 'qa_reject' && $fb === '') {
            $done('Düzeltme notu yazın.', 'error');
        }
        job_qa_decision($job, (int)($_POST['delivery_id'] ?? 0), $action === 'qa_approve', $fb);
        $done($action === 'qa_approve' ? 'Teslimat onaylandı ve ajansa iletildi.' : 'Teslimat düzeltme için freelancer\'a döndü.');
    }
    if ($action === 'staff_deliver' && in_array($job['status'], ['in_progress', 'revision', 'assigned'], true)) {
        $url = trim($_POST['url'] ?? '');
        if (!is_safe_url($url)) {
            $done('Geçerli bir teslim bağlantısı girin.', 'error');
        }
        job_submit_delivery($job, $url, trim($_POST['note'] ?? ''), 'staff', $staff_id);
        $done('Teslimat ajansa gönderildi.');
    }
    if ($action === 'force_complete' && in_array($job['status'], ['delivered', 'qa_review'], true)) {
        job_complete(get_job($job_id));
        log_job_change($job_id, 'staff', $staff_id, 'Durum', job_status_label($job['status']), 'Ekip tarafından tamamlandı');
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} tamamlandı olarak kapatıldı.", "/platform/job.php?id={$job_id}", $job_id);
        $done('İş tamamlandı; faturalar oluşturuldu.');
    }
    if ($action === 'cancel' && !in_array($job['status'], ['completed', 'cancelled'], true)) {
        $reason = trim($_POST['reason'] ?? '') ?: 'Platform tarafından iptal edildi';
        $db->prepare("UPDATE platform_jobs SET status = 'cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $job_id]);
        $db->prepare("UPDATE platform_applications SET status = 'rejected', reviewed_at = NOW(), reject_reason = COALESCE(reject_reason, 'İş iptal edildi.') WHERE job_id = ? AND status = 'pending'")->execute([$job_id]);
        log_job_change($job_id, 'staff', $staff_id, 'Durum', job_status_label($job['status']), 'İptal: ' . $reason);
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} iptal edildi: {$reason}", "/platform/job.php?id={$job_id}", $job_id);
        if (!empty($job['assigned_user_id'])) {
            notify_user((int)$job['assigned_user_id'], "{$job['job_code']} iptal edildi: {$reason}", "/platform/jobs.php", $job_id);
        }
        $done('İş iptal edildi.');
    }

    // ---------- Kapanış sonrası ----------
    if ($action === 'rate_freelancer' && $job['status'] === 'completed' && $job['assigned_type'] === 'freelancer') {
        rate_freelancer($job, (int)($_POST['rating'] ?? 0));
        $done('Freelancer değerlendirildi; performans puanı güncellendi.');
    }
    if ($action === 'pay_freelancer' && !empty($job['purchase_invoice_id'])) {
        $inv = $db->prepare("SELECT grand_total, paid_amount FROM invoices WHERE id = ?");
        $inv->execute([(int)$job['purchase_invoice_id']]);
        $inv = $inv->fetch();
        $amount = parse_money($_POST['amount'] ?? '') ?: ((float)$inv['grand_total'] - (float)$inv['paid_amount']);
        $err = record_invoice_payment((int)$job['purchase_invoice_id'], (int)($_POST['account_id'] ?? 0), $amount, valid_date($_POST['pay_date'] ?? '', date('Y-m-d')), $staff_id);
        if ($err) {
            $done($err, 'error');
        }
        notify_user((int)$job['assigned_user_id'], "{$job['job_code']} hakedişiniz ödendi: " . format_money($amount), "/platform/earnings.php", $job_id);
        $done('Freelancer ödemesi kaydedildi.');
    }

    // ---------- Yazışma ----------
    if ($action === 'message') {
        $msg = trim($_POST['message'] ?? '');
        $to  = $_POST['thread'] ?? 'agency';
        if ($msg !== '') {
            if ($to === 'agency') {
                add_job_message($job_id, 'agency', 'staff', $staff_id, site_setting('platform_team_name'), $msg);
                notify_contact_users($job['agency_contact_id'], "{$job['job_code']} için ekipten mesaj: \"" . mb_substr($msg, 0, 120) . "\"", "/platform/job.php?id={$job_id}#mesajlar", $job_id);
            } else {
                $f = staff_freelancer((int)$to);
                if (!$f) {
                    $done('Freelancer bulunamadı.', 'error', '#mesajlar');
                }
                add_job_message($job_id, 'freelancer', 'staff', $staff_id, site_setting('platform_team_name'), $msg, (int)$f['user_id']);
                notify_user((int)$f['user_id'], "{$job['job_code']} için ekipten mesaj: \"" . mb_substr($msg, 0, 120) . "\"", "/platform/job.php?id={$job_id}#mesajlar", $job_id);
            }
        }
        redirect($self . '&thread=' . urlencode((string)$to) . '#mesajlar');
    }

    // ---------- Kalıcı silme ----------
    if ($action === 'delete_job') {
        if (!$can_delete) {
            $done('Kalıcı silme yetkiniz yok.', 'error');
        }
        if (trim($_POST['confirm_code'] ?? '') !== $job['job_code']) {
            $done('Onay için iş kodunu aynen yazın.', 'error', '#tehlikeli');
        }
        platform_delete_job($job_id, !empty($_POST['with_invoices']));
        log_activity('platform', "İş kalıcı olarak silindi: {$job['job_code']} · {$job['title']}", 'job', null, '/modules/platform/index.php');
        set_flash('success', "{$job['job_code']} kalıcı olarak silindi.");
        redirect(BASE_URL . '/modules/platform/index.php');
    }
    redirect($self);
}

// ====================================================================
// GÖRÜNÜM VERİLERİ
// ====================================================================
$job   = get_job($job_id);
$items = job_items($job_id);

$freelancers = $db->query("
    SELECT fp.*, u.full_name, u.email
    FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id
    WHERE fp.status = 'approved' AND u.status = 'active'
    ORDER BY FIELD(fp.tier, 'elite', 'gold', 'silver', 'standard'), fp.score DESC, u.full_name
")->fetchAll();
foreach ($freelancers as &$f) {
    $f['cap'] = freelancer_capacity($f);
}
unset($f);

// "Bu işi kimler görüyor?" önizlemesi
$preview_job = array_merge($job, ['status' => 'open', 'published_at' => $job['published_at'] ?: date('Y-m-d H:i:s')]);
$preview = [];
foreach ($freelancers as $f) {
    $preview[] = ['f' => $f, 'reason' => job_visibility_reason($preview_job, $f)];
}
$visible_count = count(array_filter($preview, fn($v) => $v['reason'] === null));
$visible_free  = count(array_filter($preview, fn($v) => $v['reason'] === null && $v['f']['cap']['can_take']));

$sel = $db->prepare("SELECT user_id FROM platform_job_visible_to WHERE job_id = ?");
$sel->execute([$job_id]);
$selected_ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));

$ap = $db->prepare("
    SELECT a.*, u.full_name, fp.tier, fp.score, fp.rating_avg, fp.completed_jobs, fp.on_time_rate, fp.portfolio_url, fp.is_available, fp.user_id AS fp_uid
    FROM platform_applications a JOIN users u ON u.id = a.user_id LEFT JOIN freelancer_profiles fp ON fp.user_id = a.user_id
    WHERE a.job_id = ? ORDER BY FIELD(a.status, 'pending', 'accepted', 'rejected', 'withdrawn'), a.id
");
$ap->execute([$job_id]);
$applications = $ap->fetchAll();
foreach ($applications as &$a) {
    $a['cap'] = $a['fp_uid'] ? freelancer_capacity(['user_id' => $a['user_id'], 'tier' => $a['tier'], 'is_available' => $a['is_available']]) : ['active' => 0, 'limit' => 0, 'can_take' => false];
}
unset($a);
$pending_apps = count(array_filter($applications, fn($a) => $a['status'] === 'pending'));

$deliveries = job_deliveries($job_id);

// Yazışma başlıkları: ajans + freelancer başına kişisel başlıklar
$threads = ['agency' => ['name' => $job['agency_name'] ?: 'Ajans', 'sub' => 'Ajans', 'count' => count(job_messages($job_id, 'agency'))]];
foreach (job_freelancer_threads($job_id) as $t) {
    $threads[(string)$t['thread_user_id']] = ['name' => $t['full_name'], 'sub' => 'Freelancer', 'count' => (int)$t['cnt']];
}
foreach ($applications as $a) {
    $threads[(string)$a['user_id']] ??= ['name' => $a['full_name'], 'sub' => 'Teklif veren', 'count' => 0];
}
if ($job['assigned_type'] === 'freelancer' && $job['assigned_user_id']) {
    $threads[(string)$job['assigned_user_id']] ??= ['name' => $job['assignee_name'], 'sub' => 'Atanan', 'count' => 0];
    $threads[(string)$job['assigned_user_id']]['sub'] = 'Atanan';
}
$thread = (string)($_GET['thread'] ?? 'agency');
if ($thread !== 'agency' && !ctype_digit($thread)) {
    $thread = 'agency';
}
if ($thread !== 'agency' && !isset($threads[$thread])) {
    $tf = staff_freelancer((int)$thread);
    if ($tf) {
        $threads[$thread] = ['name' => $tf['full_name'], 'sub' => 'Yeni yazışma', 'count' => 0];
    } else {
        $thread = 'agency';
    }
}
$messages = $thread === 'agency' ? job_messages($job_id, 'agency') : job_messages($job_id, 'freelancer', (int)$thread);

$changes  = job_changes($job_id, 60);
$agencies = $db->query("SELECT id, company_title FROM contacts WHERE type = 'agency' ORDER BY company_title")->fetchAll();
$accounts = $db->query("SELECT id, account_name, balance, currency FROM accounts WHERE status = 'active'")->fetchAll();
$purchase = null;
if ($job['purchase_invoice_id']) {
    $pi = $db->prepare("SELECT * FROM invoices WHERE id = ?");
    $pi->execute([(int)$job['purchase_invoice_id']]);
    $purchase = $pi->fetch();
}

$margin = ($job['agency_price'] !== null && $job['freelancer_fee'] !== null) ? (float)$job['agency_price'] - (float)$job['freelancer_fee'] : null;
$margin_pct = $margin !== null && (float)$job['agency_price'] > 0 ? $margin / (float)$job['agency_price'] * 100 : null;
$default_margin = (float)platform_setting('platform_default_margin');
$location = (int)$job['is_remote'] === 1 ? 'Uzaktan' : trim(($job['location_city'] ?? '') . ((string)$job['location_detail'] !== '' ? ' · ' . $job['location_detail'] : ''));
$lead = in_array($job['status'], ['submitted', 'quote_sent', 'open'], true) ? assess_lead_time($job['start_date'], $job['deadline'], $items) : null;

$steps = ['submitted' => 'Sipariş'];
if ($job['pricing_source'] === 'custom') $steps['quote_sent'] = 'Teklif';
$steps += ['open' => 'Atama', 'in_progress' => 'Üretim', 'qa_review' => 'Kalite kontrol', 'delivered' => 'Ajans onayı', 'completed' => 'Tamamlandı'];
$step_map = ['assigned' => 'in_progress', 'revision' => 'in_progress'];
$cur_key = $step_map[$job['status']] ?? $job['status'];
$step_keys = array_keys($steps);
$cur_idx = array_search($cur_key, $step_keys, true);

$page_title = $job['job_code'] . ' · İş merkezi';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div style="min-width:0">
        <div class="crumb">
            <a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span class="code-tag"><?= e($job['job_code']) ?></span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?= job_status_badge($job['status']) ?>
            <?php if ((int)$job['is_rush'] === 1): ?><?= ui_badge('Acil', 'accent') ?><?php endif; ?>
            <?= ui_badge($job['pricing_source'] === 'catalog' ? 'Katalog siparişi' : 'Özel talep', 'neutral') ?>
            <?php if ($lead && $lead['level'] === 'block'): ?><?= ui_badge('Termin geçti / yetersiz', 'danger', true) ?><?php endif; ?>
        </div>
        <h1 class="h1" style="margin-top:10px"><?= e($job['title']) ?></h1>
        <p class="small text-muted" style="margin-top:6px;display:flex;gap:16px;flex-wrap:wrap">
            <span style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="building-2" style="width:14px;height:14px"></i>
                <?php if ($job['agency_contact_id']): ?><a class="link" href="<?= BASE_URL ?>/modules/contacts/detail.php?id=<?= (int)$job['agency_contact_id'] ?>"><?= e($job['agency_name'] ?? '—') ?></a><?php else: ?>Ajanssız iş<?php endif; ?>
            </span>
            <span style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="<?= job_category_icon($job['category']) ?>" style="width:14px;height:14px"></i><?= e(job_category_label($job['category'])) ?></span>
            <span style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="map-pin" style="width:14px;height:14px"></i><?= e($location ?: '—') ?></span>
            <span style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="calendar" style="width:14px;height:14px"></i><?= $job['start_date'] ? format_date($job['start_date']) . ' → ' : '' ?><?= format_date($job['deadline']) ?></span>
        </p>
    </div>
    <div class="card" style="display:flex;min-width:0">
        <div class="kpi" style="padding:12px 16px;border-right:1px solid var(--line-2)"><div class="kpi-label">Ajans fiyatı</div><div class="kpi-value" style="font-size:18px"><?= $job['agency_price'] !== null ? format_money((float)$job['agency_price'], $job['currency']) : '—' ?></div></div>
        <div class="kpi" style="padding:12px 16px;border-right:1px solid var(--line-2)"><div class="kpi-label">Freelancer</div><div class="kpi-value" style="font-size:18px"><?= $job['freelancer_fee'] !== null ? format_money((float)$job['freelancer_fee'], $job['currency']) : '—' ?></div></div>
        <div class="kpi" style="padding:12px 16px"><div class="kpi-label">Marj</div><div class="kpi-value" style="font-size:18px;color:<?= $margin !== null && $margin < 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= $margin !== null ? format_money($margin) : '—' ?></div><?php if ($margin_pct !== null): ?><div class="kpi-meta">%<?= number_format($margin_pct, 0) ?></div><?php endif; ?></div>
    </div>
</div>

<?php if ($job['status'] !== 'cancelled'): ?>
<div class="card card-pad-sm" style="margin-bottom:20px">
    <div class="steps">
        <?php foreach ($step_keys as $i => $k):
            $cls = $cur_idx === false ? '' : ($i < $cur_idx || $job['status'] === 'completed' ? 'is-done' : ($i === $cur_idx ? 'is-current' : '')); ?>
            <div class="step <?= $cls ?>"><span class="step-bar"></span><span class="step-label"><?= e($steps[$k]) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
<div class="xl:col-span-2 stack-lg" style="min-width:0">

    <!-- ===================== SIRADAKİ ADIM ===================== -->
    <section class="card card-emphasis">
        <div class="card-head"><div><p class="eyebrow">Sıradaki adım</p></div><?= job_status_badge($job['status']) ?></div>
        <div class="card-pad stack">
        <?php if ($lead && $lead['level'] !== 'ok' && $job['status'] !== 'cancelled'): ?>
            <div class="alert <?= $lead['level'] === 'block' ? 'alert-danger' : 'alert-warning' ?>"><i data-lucide="calendar-clock"></i><div>
                <?= $lead['level'] === 'block' ? 'Başlangıca kalan süre minimum terminin altında. Tarihi ajansla netleştirin veya ekibe alın.' : 'Yakın tarihli iş: atamayı öncelikli yapın.' ?>
                <span class="xsmall">(<?= $lead['hours'] !== null ? number_format(max(0, $lead['hours']), 0) . ' saat kaldı' : '' ?>)</span>
            </div></div>
        <?php endif; ?>

        <?php if (in_array($job['status'], ['submitted', 'quote_sent'], true)): ?>
            <?php if ($job['pricing_source'] === 'catalog' && $job['status'] === 'submitted'): ?>
                <p class="small text-ink-2">Katalog siparişi; tutar katalogdan hesaplandı. Onayladığınızda görünürlük kurallarına göre havuza açılır.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="publish"><button class="btn btn-primary"><i data-lucide="send"></i>Onayla ve yayınla</button></form>
                    <form method="POST" action="" onsubmit="return confirm('İş ekibinize alınsın ve ERP\'de proje açılsın mı?');"><?= csrf_field() ?><input type="hidden" name="action" value="take_internal"><button class="btn btn-secondary"><i data-lucide="users"></i>Ekibimize al</button></form>
                </div>
            <?php else: ?>
                <p class="small text-ink-2"><?= $job['status'] === 'quote_sent' ? 'Teklif ajansın onayında. Gerekirse fiyatı güncelleyip yeniden gönderebilirsiniz.' : 'Özel talep. Fiyatı belirleyip ajansa teklif gönderin veya doğrudan yayınlayın.' ?>
                    <?php if ($job['budget'] !== null): ?><span class="text-muted">Ajansın bütçe beklentisi: <strong><?= format_money((float)$job['budget'], $job['currency']) ?></strong></span><?php endif; ?></p>
                <form method="POST" action="" class="stack" x-data="{ price: <?= json_encode((float)($job['agency_price'] ?? 0)) ?>, fee: <?= json_encode((float)($job['freelancer_fee'] ?? 0)) ?>, m: <?= json_encode($default_margin) ?> }"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_pricing">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="field"><label class="label">Ajans fiyatı (KDV hariç)</label><input class="input" type="number" step="0.01" min="0" name="agency_price" x-model.number="price"></div>
                        <div class="field"><label class="label">Freelancer ücreti</label><input class="input" type="number" step="0.01" min="0" name="freelancer_fee" x-model.number="fee">
                            <button type="button" class="hint link" style="text-align:left" @click="fee = Math.round(price * (100 - m)) / 100" x-show="price > 0">%<?= rtrim(rtrim(number_format($default_margin, 1, ',', ''), '0'), ',') ?> marjla öner</button></div>
                        <div class="field"><span class="label">Marj</span><div class="input" style="display:flex;align-items:center;background:var(--surface-2)"><span class="num" x-text="price > 0 ? ((price - fee).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺ · %' + Math.round((price - fee) / price * 100)) : '—'"></span></div></div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="btn btn-primary" name="next" value="quote"><i data-lucide="send"></i><?= $job['status'] === 'quote_sent' ? 'Teklifi yeniden gönder' : 'Teklifi ajansa gönder' ?></button>
                        <button class="btn btn-secondary" name="next" value="publish" onclick="return confirm('Ajans onayı beklemeden yayına alınsın mı?');">Onay beklemeden yayınla</button>
                        <button class="btn btn-ghost" name="next" value="">Yalnızca kaydet</button>
                    </div>
                </form>
            <?php endif; ?>

        <?php elseif ($job['status'] === 'open'): ?>
            <p class="small text-ink-2">
                <?= $job['visibility'] === 'internal' ? 'Ekibe özel iş; freelancer\'lar görmüyor.' : "İş havuzda: <strong>{$visible_count}</strong> freelancer görüyor, <strong>{$visible_free}</strong> kişinin kapasitesi uygun." ?>
                <?= $job['dispatch_mode'] === 'application' ? ($pending_apps ? " <strong>{$pending_apps}</strong> teklif değerlendirme bekliyor." : ' Henüz teklif yok.') : ' İlk alan alır modunda.' ?>
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <form method="POST" action="" class="panel stack-sm" style="padding:14px" x-data="{ uid: '' }"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_direct">
                    <p class="small" style="font-weight:500">Doğrudan ata</p>
                    <select name="user_id" class="select" required x-model="uid">
                        <option value="">Freelancer seçin</option>
                        <?php foreach ($preview as $p): $f = $p['f']; ?>
                            <option value="<?= (int)$f['user_id'] ?>"><?= e($f['full_name']) ?> · <?= e(tier_label($f['tier'])) ?> · <?= $f['cap']['active'] ?>/<?= $f['cap']['limit'] ?><?= $p['reason'] ? ' · ' . e($p['reason']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="input-group"><input type="text" inputmode="decimal" name="fee" class="input" placeholder="Ücret (boş: <?= e(number_format((float)$job['freelancer_fee'], 0, ',', '.')) ?>)"><span class="addon">TL</span></div>
                    <label class="check xsmall"><input type="checkbox" name="override_limit" value="1">Aktif iş limiti doluysa yine de ata</label>
                    <button class="btn btn-primary btn-sm" :disabled="!uid">Ata</button>
                </form>
                <div class="panel stack-sm" style="padding:14px">
                    <p class="small" style="font-weight:500">Ekibimize al</p>
                    <p class="xsmall text-muted">İş <?= e(site_setting('platform_team_name')) ?> üzerine geçer<?= $job['agency_contact_id'] ? ', ERP\'de proje açılır' : '' ?>. Bekleyen teklifler gerekçesiyle kapatılır.</p>
                    <form method="POST" action="" onsubmit="return confirm('İş ekibinize alınsın mı?');"><?= csrf_field() ?><input type="hidden" name="action" value="take_internal"><button class="btn btn-secondary btn-sm"><i data-lucide="users"></i>Ekibimize al</button></form>
                </div>
            </div>

        <?php elseif (in_array($job['status'], ['assigned', 'in_progress', 'revision'], true)): ?>
            <?php if ($job['assigned_type'] === 'freelancer'): $af = staff_freelancer((int)$job['assigned_user_id']); ?>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <?= ui_avatar($job['assignee_name']) ?>
                    <div style="flex:1;min-width:0">
                        <p style="font-weight:500"><a class="link" href="<?= BASE_URL ?>/modules/platform/freelancers.php?focus=<?= (int)$job['assigned_user_id'] ?>"><?= e($job['assignee_name']) ?></a> <?= tier_badge($job['assignee_tier']) ?></p>
                        <p class="xsmall text-muted"><?= $job['assigned_at'] ? 'Atandı ' . format_date($job['assigned_at'], true) : '' ?><?= $af && $af['score'] !== null ? ' · puan ' . number_format((float)$af['score'], 0) : '' ?><?= $af ? ' · aktif ' . $af['cap']['active'] . '/' . $af['cap']['limit'] : '' ?></p>
                    </div>
                    <a href="?id=<?= $job_id ?>&thread=<?= (int)$job['assigned_user_id'] ?>#mesajlar" class="btn btn-secondary btn-sm"><i data-lucide="message-square"></i>Yaz</a>
                </div>
                <details class="small">
                    <summary style="cursor:pointer;color:var(--muted)">Atamayı kaldır</summary>
                    <form method="POST" action="" class="stack-sm" style="margin-top:10px"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="unassign">
                        <input class="input" type="text" name="reason" placeholder="Not (freelancer'a iletilir)">
                        <label class="check xsmall"><input type="checkbox" name="count_against" value="1" checked>Güvenilirlik puanına işlensin (freelancer kaynaklı)</label>
                        <div><button class="btn btn-danger btn-sm" onclick="return confirm('Atama kaldırılsın mı?');">Atamayı kaldır</button></div>
                    </form>
                </details>
            <?php else: ?>
                <p class="small text-ink-2">İş ekibinizde.<?php if ($job['internal_project_id']): ?> <a class="link" href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= (int)$job['internal_project_id'] ?>">ERP projesine git</a><?php endif; ?></p>
                <form method="POST" action="" class="stack-sm"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="staff_deliver">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <input class="input" type="url" name="url" required placeholder="Teslim bağlantısı (https://)">
                        <input class="input" type="text" name="note" placeholder="Teslim notu">
                    </div>
                    <div><button class="btn btn-primary btn-sm"><i data-lucide="upload"></i>Ajansa teslim et</button></div>
                </form>
            <?php endif; ?>
            <?php if ($job['status'] === 'revision'): $fb = job_deliveries($job_id, ['revision', 'qa_rejected']); if ($fb): ?>
                <div class="alert alert-warning"><i data-lucide="rotate-ccw"></i><div><strong><?= $fb[0]['status'] === 'qa_rejected' ? 'Kalite kontrol notu' : 'Ajans revizyonu' ?>:</strong> <?= e($fb[0]['feedback'] ?? '') ?></div></div>
            <?php endif; endif; ?>

        <?php elseif ($job['status'] === 'qa_review'): $qa = job_deliveries($job_id, ['qa']); $d = $qa[0] ?? null; ?>
            <?php if ($d): ?>
                <div class="panel" style="padding:14px">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
                        <a href="<?= e($d['url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm"><i data-lucide="external-link"></i>Teslimatı aç</a>
                        <span class="xsmall text-muted"><?= e($job['assignee_name'] ?? '') ?> · <?= format_date($d['created_at'], true) ?></span>
                    </div>
                    <?php if ($d['note']): ?><p class="small prose-text" style="margin-top:10px"><?= e($d['note']) ?></p><?php endif; ?>
                </div>
                <form method="POST" action="" class="stack-sm" x-data="{ reject: false }"><?= csrf_field() ?>
                    <input type="hidden" name="delivery_id" value="<?= (int)$d['id'] ?>">
                    <textarea class="textarea" name="feedback" rows="2" placeholder="Not (onayda isteğe bağlı, düzeltmede zorunlu)"></textarea>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="btn btn-primary" name="action" value="qa_approve"><i data-lucide="check"></i>Onayla, ajansa ilet</button>
                        <button class="btn btn-secondary" name="action" value="qa_reject">Düzeltme iste</button>
                    </div>
                    <p class="xsmall text-muted">Kalite kontrolden ilk seferde geçme, freelancer puanının %20'sidir.</p>
                </form>
            <?php endif; ?>
            <form method="POST" action="" onsubmit="return confirm('Kalite kontrol ve ajans onayı atlanarak iş kapatılsın mı?');"><?= csrf_field() ?><input type="hidden" name="action" value="force_complete"><button class="btn btn-ghost btn-sm">Doğrudan tamamla</button></form>

        <?php elseif ($job['status'] === 'delivered'): ?>
            <p class="small text-ink-2">Teslimat ajansın onayında<?= $job['delivered_at'] ? ' (' . time_ago($job['delivered_at']) . ')' : '' ?>. Ajans onayladığında faturalar otomatik oluşur.</p>
            <form method="POST" action="" onsubmit="return confirm('Ajans onayı beklenmeden iş tamamlansın mı?');"><?= csrf_field() ?><input type="hidden" name="action" value="force_complete"><button class="btn btn-secondary btn-sm">Ajans adına tamamla</button></form>

        <?php elseif ($job['status'] === 'completed'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="panel stack-sm" style="padding:14px">
                    <p class="small" style="font-weight:500">Değerlendirmeler</p>
                    <p class="small">Ajans: <?= render_stars($job['agency_rating'] !== null ? (float)$job['agency_rating'] : null) ?></p>
                    <?php if ($job['agency_review']): ?><p class="xsmall text-muted">"<?= e($job['agency_review']) ?>"</p><?php endif; ?>
                    <?php if ($job['assigned_type'] === 'freelancer'): ?>
                    <form method="POST" action="" style="display:flex;gap:8px;align-items:center"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="rate_freelancer">
                        <span class="small">Ekip puanı</span>
                        <select name="rating" class="select" style="width:auto;height:30px">
                            <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= (int)$job['freelancer_rating'] === $i ? 'selected' : '' ?>><?= $i ?> / 5</option><?php endfor; ?>
                        </select>
                        <button class="btn btn-secondary btn-sm">Kaydet</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="panel stack-sm" style="padding:14px">
                    <p class="small" style="font-weight:500">Faturalar</p>
                    <?php if ($job['sales_invoice_id']): ?><a class="small link" target="_blank" href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= (int)$job['sales_invoice_id'] ?>">Satış faturası</a><?php else: ?><p class="xsmall text-muted">Satış faturası yok.</p><?php endif; ?>
                    <?php if ($purchase): $remain = (float)$purchase['grand_total'] - (float)$purchase['paid_amount']; ?>
                        <p class="small">Hakediş: <span class="money"><?= format_money((float)$purchase['grand_total']) ?></span> · ödenen <?= format_money((float)$purchase['paid_amount']) ?></p>
                        <?php if ($remain > 0.009): ?>
                        <form method="POST" action="" class="stack-sm"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="pay_freelancer">
                            <div style="display:flex;gap:6px">
                                <select name="account_id" class="select" required><?php foreach ($accounts as $acc): ?><option value="<?= (int)$acc['id'] ?>"><?= e($acc['account_name']) ?></option><?php endforeach; ?></select>
                                <input type="number" step="0.01" name="amount" class="input" value="<?= number_format($remain, 2, '.', '') ?>" style="width:130px">
                            </div>
                            <div><button class="btn btn-primary btn-sm">Ödemeyi kaydet</button></div>
                        </form>
                        <?php else: ?><?= ui_badge('Ödendi', 'success', true) ?><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($job['status'] === 'cancelled'): ?>
            <div class="alert alert-danger"><i data-lucide="circle-x"></i><div>İptal edildi. <?= e($job['cancel_reason'] ?? '') ?></div></div>
        <?php endif; ?>
        </div>
    </section>

    <!-- ===================== TEKLİFLER ===================== -->
    <?php if ($applications || ($job['status'] === 'open' && $job['dispatch_mode'] === 'application')): ?>
    <section class="card" id="teklifler">
        <div class="card-head"><div><p class="card-title">Teklifler</p><p class="card-sub">Ret gerekçesi freelancer'a bildirim olarak iletilir.</p></div><span class="xsmall text-muted"><?= $pending_apps ?> bekleyen</span></div>
        <?php if (!$applications): ?>
            <?= ui_empty('Henüz teklif yok', 'Uygun freelancer\'lara sağdaki yazışma alanından doğrudan yazabilirsiniz.', 'inbox') ?>
        <?php else: ?>
        <div class="divide">
            <?php foreach ($applications as $a): $st_map = ['pending' => ['Bekliyor', 'info'], 'accepted' => ['Kabul edildi', 'success'], 'rejected' => ['Reddedildi', 'neutral'], 'withdrawn' => ['Geri çekildi', 'neutral']]; [$sl, $stn] = $st_map[$a['status']] ?? [$a['status'], 'neutral']; ?>
            <div class="card-pad-sm" x-data="{ rej: false }">
                <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
                    <?= ui_avatar($a['full_name']) ?>
                    <div style="flex:1;min-width:200px">
                        <p style="font-weight:500;display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?= e($a['full_name']) ?> <?= tier_badge($a['tier']) ?> <?= ui_badge($sl, $stn, true) ?></p>
                        <p class="xsmall text-muted" style="margin-top:3px">
                            Puan <?= $a['score'] !== null ? number_format((float)$a['score'], 0) : '—' ?> · <?= (int)$a['completed_jobs'] ?> iş · zamanında <?= $a['on_time_rate'] !== null ? '%' . number_format((float)$a['on_time_rate'], 0) : '—' ?>
                            · <span style="<?= !$a['cap']['can_take'] ? 'color:var(--danger)' : '' ?>">aktif <?= $a['cap']['active'] ?>/<?= $a['cap']['limit'] ?></span>
                            <?php if ($a['portfolio_url'] && is_safe_url($a['portfolio_url'])): ?> · <a class="link" target="_blank" rel="noopener" href="<?= e($a['portfolio_url']) ?>">portfolyo</a><?php endif; ?>
                        </p>
                        <?php if ($a['note']): ?><p class="small prose-text" style="margin-top:8px"><?= e($a['note']) ?></p><?php endif; ?>
                        <?php if ($a['status'] === 'rejected' && $a['reject_reason']): ?><p class="xsmall" style="margin-top:6px;color:var(--muted)">Gerekçe: <?= e($a['reject_reason']) ?></p><?php endif; ?>
                    </div>
                    <div style="text-align:right">
                        <p class="money"><?= format_money((float)($a['proposed_fee'] ?? $job['freelancer_fee']), $job['currency']) ?></p>
                        <p class="xsmall text-muted"><?= $a['proposed_fee'] !== null ? 'önerilen' : 'belirlenen ücret' ?><?= $a['available_from'] ? ' · ' . format_date($a['available_from']) . '\'den itibaren' : '' ?></p>
                    </div>
                </div>
                <?php if ($a['status'] === 'pending' && $job['status'] === 'open'): ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;margin-left:44px">
                    <form method="POST" action="" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('Bu teklif kabul edilip iş atansın mı?');"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="accept_application"><input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                        <?php if ($a['proposed_fee'] !== null): ?><label class="check xsmall"><input type="checkbox" name="use_proposed_fee" value="1" checked>Önerilen ücretle</label><?php endif; ?>
                        <?php if (!$a['cap']['can_take']): ?><label class="check xsmall" style="color:var(--danger)"><input type="checkbox" name="override_limit" value="1">Limiti aş</label><?php endif; ?>
                        <button class="btn btn-primary btn-sm"><i data-lucide="check"></i>Kabul et</button>
                    </form>
                    <button type="button" class="btn btn-secondary btn-sm" @click="rej = !rej">Reddet</button>
                    <a href="?id=<?= $job_id ?>&thread=<?= (int)$a['user_id'] ?>#mesajlar" class="btn btn-ghost btn-sm"><i data-lucide="message-square"></i>Yaz</a>
                </div>
                <form x-show="rej" x-cloak method="POST" action="" class="stack-sm" style="margin:10px 0 0 44px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject_application"><input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>">
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php foreach (['Ücret bütçenin üzerinde.', 'Tarihler uygun değil.', 'Portfolyo bu iş için yeterli değil.', 'Daha deneyimli bir ekip üyesi seçildi.'] as $preset): ?>
                            <button type="button" class="btn btn-ghost btn-sm" style="border:1px solid var(--line)" @click="$refs.r.value = '<?= e($preset) ?>'"><?= e($preset) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <textarea x-ref="r" name="reason" rows="2" required class="textarea" placeholder="Gerekçe (freelancer'a iletilir)"></textarea>
                    <div><button class="btn btn-danger btn-sm">Reddet ve bildir</button></div>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ===================== KALEMLER ===================== -->
    <?php if ($items): ?>
    <section class="card">
        <div class="card-head"><p class="card-title">Sipariş kalemleri</p></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Hizmet</th><th class="r">Miktar</th><th class="r">Ajans birim</th><th class="r">Freelancer birim</th><th class="r">Ajans tutar</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['name']) ?><?php if (!empty($it['min_tier']) && $it['min_tier'] !== 'standard'): ?> <?= tier_badge($it['min_tier']) ?><?php endif; ?></td>
                        <td class="r num"><?= qty_label((float)$it['quantity']) ?> <?= e($it['unit']) ?></td>
                        <td class="r num"><?= format_money((float)$it['agency_unit_price']) ?></td>
                        <td class="r num text-muted"><?= format_money((float)$it['freelancer_unit_fee']) ?></td>
                        <td class="r money"><?= format_money((float)$it['agency_unit_price'] * (float)$it['quantity']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ((float)$job['rush_fee'] > 0): ?><tr><td colspan="4" class="text-muted">Acil iş farkı</td><td class="r money"><?= format_money((float)$job['rush_fee']) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($job['pricing_source'] === 'catalog' && !in_array($job['status'], ['completed', 'cancelled'], true)): ?>
        <details class="card-foot">
            <summary class="small" style="cursor:pointer">Fiyatı elle düzelt</summary>
            <form method="POST" action="" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:flex-end"><?= csrf_field() ?>
                <input type="hidden" name="action" value="save_pricing">
                <div class="field"><label class="label">Ajans fiyatı</label><input class="input" type="number" step="0.01" name="agency_price" value="<?= e((string)$job['agency_price']) ?>" style="width:150px"></div>
                <div class="field"><label class="label">Freelancer ücreti</label><input class="input" type="number" step="0.01" name="freelancer_fee" value="<?= e((string)$job['freelancer_fee']) ?>" style="width:150px"></div>
                <button class="btn btn-secondary">Kaydet</button>
            </form>
        </details>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ===================== BRIEF / DETAY ===================== -->
    <section class="card" x-data="{ edit: false }">
        <div class="card-head"><p class="card-title">Brief</p><button type="button" class="btn btn-ghost btn-sm" @click="edit = !edit"><i data-lucide="pencil"></i><span x-text="edit ? 'Vazgeç' : 'Düzenle'"></span></button></div>
        <div class="card-pad stack" x-show="!edit">
            <p class="prose-text"><?= e($job['description'] ?? '') ?></p>
            <?php if ($job['deliverables']): ?><div><p class="eyebrow" style="margin-bottom:6px">Teslimatlar</p><p class="prose-text"><?= e($job['deliverables']) ?></p></div><?php endif; ?>
            <?php if ($job['agency_notes']): ?><div class="panel" style="padding:12px 14px"><p class="eyebrow" style="margin-bottom:6px">Ajans ek notları</p><p class="prose-text small"><?= e($job['agency_notes']) ?></p></div><?php endif; ?>
            <?php $refs = array_filter(preg_split('/\s+/', trim((string)$job['reference_links']))); if ($refs): ?>
                <div><p class="eyebrow" style="margin-bottom:6px">Referanslar</p><?php foreach ($refs as $r): ?><?php if (is_safe_url($r)): ?><a class="small link" style="display:block;word-break:break-all" target="_blank" rel="noopener" href="<?= e($r) ?>"><?= e($r) ?></a><?php else: ?><span class="small"><?= e($r) ?></span> <?php endif; ?><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
        <form method="POST" action="" class="card-pad stack" x-show="edit" x-cloak><?= csrf_field() ?>
            <input type="hidden" name="action" value="save_details">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="field sm:col-span-2"><label class="label">Başlık</label><input class="input" name="title" value="<?= e($job['title']) ?>" required></div>
                <div class="field"><label class="label">Ajans</label><select class="select" name="agency_contact_id"><option value="">— Ajanssız —</option><?php foreach ($agencies as $ag): ?><option value="<?= (int)$ag['id'] ?>" <?= (int)$job['agency_contact_id'] === (int)$ag['id'] ? 'selected' : '' ?>><?= e($ag['company_title']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">İş türü</label><select class="select" name="category"><?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>" <?= $job['category'] === $ck ? 'selected' : '' ?>><?= e($cv['label']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">Başlangıç</label><input class="input" type="date" name="start_date" value="<?= e($job['start_date'] ?? '') ?>"></div>
                <div class="field"><label class="label">Teslim</label><input class="input" type="date" name="deadline" value="<?= e($job['deadline'] ?? '') ?>" required></div>
                <div class="field"><label class="label">Şehir</label><input class="input" name="location_city" value="<?= e($job['location_city'] ?? '') ?>"></div>
                <div class="field"><label class="label">Lokasyon</label><input class="input" name="location_detail" value="<?= e($job['location_detail'] ?? '') ?>"></div>
                <label class="check sm:col-span-2"><input type="checkbox" name="is_remote" value="1" <?= (int)$job['is_remote'] === 1 ? 'checked' : '' ?>>Uzaktan yapılabilir</label>
                <div class="field sm:col-span-2"><label class="label">Brief</label><textarea class="textarea" name="description" rows="5"><?= e($job['description'] ?? '') ?></textarea></div>
                <div class="field sm:col-span-2"><label class="label">Teslimatlar</label><textarea class="textarea" name="deliverables" rows="3"><?= e($job['deliverables'] ?? '') ?></textarea></div>
                <div class="field"><label class="label">Referanslar</label><textarea class="textarea" name="reference_links" rows="3"><?= e($job['reference_links'] ?? '') ?></textarea></div>
                <div class="field"><label class="label">Ek notlar</label><textarea class="textarea" name="agency_notes" rows="3"><?= e($job['agency_notes'] ?? '') ?></textarea></div>
            </div>
            <div><button class="btn btn-primary">Kaydet</button></div>
        </form>
    </section>

    <!-- ===================== TESLİMATLAR ===================== -->
    <?php if ($deliveries): ?>
    <section class="card">
        <div class="card-head"><p class="card-title">Teslimatlar</p><span class="xsmall text-muted"><?= count($deliveries) ?> sürüm</span></div>
        <div class="divide">
            <?php foreach ($deliveries as $i => $d): ?>
            <div class="card-pad-sm" style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                    <a href="<?= e($d['url']) ?>" target="_blank" rel="noopener" class="small link" style="font-weight:500">Sürüm <?= count($deliveries) - $i ?></a>
                    <span class="xsmall text-muted"> · <?= $d['submitted_by_type'] === 'staff' ? 'Ekip' : 'Freelancer' ?> · <?= format_date($d['created_at'], true) ?></span>
                    <?php if ($d['note']): ?><p class="small prose-text" style="margin-top:4px"><?= e($d['note']) ?></p><?php endif; ?>
                    <?php if ($d['feedback']): ?><p class="small" style="margin-top:4px;color:var(--warning)">Geri bildirim: <?= e($d['feedback']) ?></p><?php endif; ?>
                </div>
                <?= delivery_status_badge($d['status']) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===================== GEÇMİŞ ===================== -->
    <section class="card">
        <div class="card-head"><p class="card-title">Değişiklik geçmişi</p></div>
        <div class="card-pad"><?= render_job_changes($changes, 'staff') ?></div>
    </section>
</div>

<!-- ===================== SAĞ SÜTUN ===================== -->
<aside class="stack-lg" style="min-width:0">

    <!-- YAZIŞMA -->
    <section class="card" id="mesajlar">
        <div class="card-head"><div><p class="card-title">Yazışma</p><p class="card-sub">Her freelancer ile ayrı başlık; ajans freelancer yazışmalarını görmez.</p></div></div>
        <div style="padding:10px 12px;border-bottom:1px solid var(--line-2);display:flex;gap:6px;flex-wrap:wrap">
            <?php foreach ($threads as $tk => $t): ?>
                <a href="?id=<?= $job_id ?>&thread=<?= e((string)$tk) ?>#mesajlar" class="btn btn-sm <?= (string)$tk === $thread ? 'btn-primary' : 'btn-ghost' ?>" style="<?= (string)$tk === $thread ? '' : 'border:1px solid var(--line)' ?>">
                    <?= $tk === 'agency' ? '<i data-lucide="building-2"></i>' : '' ?><?= e(mb_strimwidth($t['name'], 0, 22, '…')) ?><?= $t['count'] ? ' <span class="num" style="opacity:.6">' . $t['count'] . '</span>' : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="card-pad">
            <p class="xsmall text-muted" style="margin-bottom:8px"><?= e($threads[$thread]['name']) ?> · <?= e($threads[$thread]['sub']) ?></p>
            <div class="thread" style="max-height:380px;overflow-y:auto;padding:2px 2px 10px">
                <?php if (!$messages): ?><p class="small text-muted" style="text-align:center;padding:18px 0">Henüz mesaj yok.</p>
                <?php else: foreach ($messages as $m): $mine = $m['sender_type'] === 'staff'; ?>
                    <div class="bubble <?= $mine ? 'is-me' : 'is-them' ?>"><div class="bubble-meta"><?= e($m['sender_name']) ?> · <?= time_ago($m['created_at']) ?></div><?= e($m['message']) ?></div>
                <?php endforeach; endif; ?>
            </div>
            <form method="POST" action="" style="display:flex;gap:8px;align-items:flex-end;margin-top:8px"><?= csrf_field() ?>
                <input type="hidden" name="action" value="message"><input type="hidden" name="thread" value="<?= e($thread) ?>">
                <textarea name="message" rows="2" required class="textarea" style="flex:1" placeholder="<?= $thread === 'agency' ? 'Ajansa yazın' : e($threads[$thread]['name']) . ' kişisine yazın' ?>"></textarea>
                <button class="btn btn-primary btn-icon" aria-label="Gönder"><i data-lucide="arrow-up"></i></button>
            </form>
            <?php if (!in_array($job['status'], ['completed', 'cancelled'], true)): ?>
            <form method="GET" action="" style="display:flex;gap:6px;margin-top:12px">
                <input type="hidden" name="id" value="<?= $job_id ?>">
                <select name="thread" class="select" style="height:32px;font-size:12.5px">
                    <option value="">Freelancer'a yaz…</option>
                    <?php foreach ($preview as $p): if (isset($threads[(string)$p['f']['user_id']])) continue; ?>
                        <option value="<?= (int)$p['f']['user_id'] ?>"><?= e($p['f']['full_name']) ?> · <?= e(tier_label($p['f']['tier'])) ?><?= $p['reason'] === null ? ' · görüyor' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary btn-sm">Aç</button>
            </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- GÖRÜNÜRLÜK POLİTİKASI -->
    <section class="card" id="politika" x-data="{ vis: '<?= e($job['visibility']) ?>', prev: false }">
        <div class="card-head"><div><p class="card-title">Görünürlük ve dağıtım</p><p class="card-sub"><?= $visible_count ?> / <?= count($freelancers) ?> freelancer görüyor</p></div></div>
        <?php if ($policy_editable): ?>
        <form method="POST" action="" class="card-pad stack"><?= csrf_field() ?>
            <input type="hidden" name="action" value="save_policy">
            <div class="field"><label class="label">Kim görsün</label>
                <select class="select" name="visibility" x-model="vis"><?php foreach (JOB_VISIBILITY as $vk => $vl): ?><option value="<?= $vk ?>"><?= e($vl) ?></option><?php endforeach; ?></select></div>
            <div x-show="vis === 'selected'" class="field"><label class="label">Seçili freelancer'lar</label>
                <div class="panel" style="max-height:180px;overflow-y:auto;padding:8px 10px">
                    <?php foreach ($freelancers as $f): ?>
                        <label class="check xsmall" style="padding:3px 0"><input type="checkbox" name="selected_users[]" value="<?= (int)$f['user_id'] ?>" <?= in_array((int)$f['user_id'], $selected_ids, true) ? 'checked' : '' ?>><?= e($f['full_name']) ?> <span class="text-muted">· <?= e(tier_label($f['tier'])) ?> · <?= e($f['city'] ?? '') ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div x-show="vis !== 'internal'" class="stack">
                <div class="field"><label class="label">Dağıtım</label>
                    <select class="select" name="dispatch_mode"><?php foreach (JOB_DISPATCH as $dk => $dl): ?><option value="<?= $dk ?>" <?= $job['dispatch_mode'] === $dk ? 'selected' : '' ?>><?= e($dl) ?></option><?php endforeach; ?></select></div>
                <div x-show="vis === 'pool'" class="stack">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="field"><label class="label">En düşük seviye</label><select class="select" name="min_tier"><?php foreach (FREELANCER_TIERS as $tk => $tv): ?><option value="<?= $tk ?>" <?= $job['min_tier'] === $tk ? 'selected' : '' ?>><?= e($tv['label']) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label class="label">Öncelikli seviye</label><select class="select" name="priority_tier"><option value="">Yok</option><?php foreach (FREELANCER_TIERS as $tk => $tv): if ($tk === 'standard') continue; ?><option value="<?= $tk ?>" <?= $job['priority_tier'] === $tk ? 'selected' : '' ?>><?= e($tv['label']) ?>+</option><?php endforeach; ?></select></div>
                    </div>
                    <div class="field"><label class="label">Öncelik süresi</label><div class="input-group"><input class="input" type="number" min="0" max="720" name="priority_hours" value="<?= (int)$job['priority_hours'] ?>"><span class="addon">saat</span></div><span class="hint">Bu süre boyunca yalnızca öncelikli seviye ve üstü görür.</span></div>
                    <label class="check small"><input type="checkbox" name="skill_match_only" value="1" <?= (int)$job['skill_match_only'] === 1 ? 'checked' : '' ?>>Yalnızca bu alanda uzman olanlar</label>
                    <label class="check small"><input type="checkbox" name="city_match_only" value="1" <?= (int)$job['city_match_only'] === 1 ? 'checked' : '' ?>>Yalnızca aynı şehirdekiler (yerinde işler)</label>
                </div>
            </div>
            <div><button class="btn btn-primary btn-sm">Kuralları kaydet</button></div>
        </form>
        <?php else: ?>
            <div class="card-pad"><p class="small text-muted"><?= e(JOB_VISIBILITY[$job['visibility']] ?? $job['visibility']) ?> · <?= e(JOB_DISPATCH[$job['dispatch_mode']] ?? '') ?> · <?= e(tier_label($job['min_tier'])) ?>+</p></div>
        <?php endif; ?>
        <div class="card-foot">
            <button type="button" class="small link" @click="prev = !prev"><span x-text="prev ? 'Önizlemeyi gizle' : 'Kimler görüyor?'"></span></button>
            <div x-show="prev" x-cloak class="stack-sm" style="margin-top:10px;max-height:260px;overflow-y:auto">
                <?php foreach ($preview as $p): ?>
                    <div style="display:flex;justify-content:space-between;gap:8px" class="xsmall">
                        <span><?= e($p['f']['full_name']) ?> <span class="text-faint"><?= e(tier_label($p['f']['tier'])) ?> · <?= $p['f']['cap']['active'] ?>/<?= $p['f']['cap']['limit'] ?></span></span>
                        <?= $p['reason'] === null ? '<span style="color:var(--success)">görüyor</span>' : '<span class="text-muted">' . e($p['reason']) . '</span>' ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$preview): ?><p class="xsmall text-muted">Onaylı freelancer yok.</p><?php endif; ?>
            </div>
        </div>
    </section>

    <!-- TEHLİKELİ İŞLEMLER -->
    <section class="card" id="tehlikeli">
        <div class="card-head"><p class="card-title">İşlemler</p></div>
        <div class="card-pad stack">
            <?php if (!in_array($job['status'], ['completed', 'cancelled'], true)): ?>
            <form method="POST" action="" class="stack-sm" onsubmit="return confirm('İş iptal edilsin mi? Ajans ve atanan kişi bilgilendirilir.');"><?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input class="input" name="reason" placeholder="İptal nedeni">
                <div><button class="btn btn-secondary btn-sm">İşi iptal et</button></div>
            </form>
            <?php endif; ?>
            <?php if ($can_delete): ?>
            <details>
                <summary class="small" style="cursor:pointer;color:var(--danger)">Kalıcı olarak sil</summary>
                <form method="POST" action="" class="stack-sm" style="margin-top:10px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_job">
                    <p class="xsmall text-muted">İş, kalemler, teklifler, yazışmalar, teslimatlar ve geçmiş silinir. Bu işlem geri alınamaz.</p>
                    <?php if ($job['sales_invoice_id'] || $job['purchase_invoice_id']): ?>
                        <label class="check xsmall"><input type="checkbox" name="with_invoices" value="1">Bağlı faturaları ve ödemelerini de sil</label>
                    <?php endif; ?>
                    <input class="input" name="confirm_code" placeholder="Onay için <?= e($job['job_code']) ?> yazın" autocomplete="off">
                    <div><button class="btn btn-danger-solid btn-sm">Kalıcı olarak sil</button></div>
                </form>
            </details>
            <?php else: ?>
                <p class="xsmall text-faint">Kalıcı silme yetkisi rol ayarlarından verilir (platform.delete).</p>
            <?php endif; ?>
        </div>
    </section>
</aside>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
