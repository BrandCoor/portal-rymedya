<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞ / İŞ DETAYI (AJANS & FREELANCER)
 * ====================================================================
 * Ajans     : özel teklif onayı, düzenleme, iptal, teslim onayı + puan,
 *             revizyon, ekiple yazışma, değişiklik geçmişi
 * Freelancer: işi al (seviye kapasitesi), teklif ver / geri çek,
 *             ret gerekçesini gör, ekiple kişisel yazışma, başla,
 *             bırak, teslim et
 * Ajans freelancer'ı, freelancer ajans fiyatını hiçbir zaman görmez.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

require_client_login(['agency', 'freelancer']);
$role    = portal_role();
$profile = require_platform_role($role);
$uid     = (int)$_SESSION['client_user_id'];
$cid     = (int)$_SESSION['client_contact_id'];
$me_name = $_SESSION['client_user']['full_name'] ?? '';
$company = $_SESSION['client_user']['company_name'] ?? $me_name;

$job_id = (int)($_GET['id'] ?? 0);
$job    = get_job($job_id);

// ====================================================================
// ERİŞİM
// ====================================================================
$my_application = null;
$has_thread = false;
if ($job && $role === 'freelancer') {
    $ap = $db->prepare("SELECT * FROM platform_applications WHERE job_id = ? AND user_id = ?");
    $ap->execute([$job_id, $uid]);
    $my_application = $ap->fetch() ?: null;
    $th = $db->prepare("SELECT COUNT(*) FROM platform_messages WHERE job_id = ? AND channel = 'freelancer' AND thread_user_id = ?");
    $th->execute([$job_id, $uid]);
    $has_thread = (int)$th->fetchColumn() > 0;
}
$allowed = $job && (
    ($role === 'agency' && (int)$job['agency_contact_id'] === $cid) ||
    ($role === 'freelancer' && (freelancer_can_see_job($job, $profile) || $my_application || $has_thread))
);
if (!$allowed) {
    set_flash('error', 'İş bulunamadı veya görüntüleme yetkiniz yok.');
    redirect(BASE_URL . '/platform/index.php');
}

$self_url = BASE_URL . "/platform/job.php?id={$job_id}";
$back = function (string $type, string $msg, string $anchor = '') use ($self_url) {
    set_flash($type, $msg);
    redirect($self_url . $anchor);
};
$production = ['assigned', 'in_progress', 'qa_review', 'revision', 'delivered'];

// İş kaydı CSV
if (isset($_GET['export'])) {
    export_job_events($job, $role);
}

// ====================================================================
// İŞLEMLER
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $is_assignee = $role === 'freelancer' && (int)$job['assigned_user_id'] === $uid;

    // ---------------- ORTAK: SORUN BİLDİR ----------------
    if ($action === 'issue' && ($role === 'agency' || $is_assignee || $my_application)) {
        $reason = array_key_exists($_POST['reason'] ?? '', ISSUE_REASONS) ? $_POST['reason'] : 'other';
        $details = trim($_POST['details'] ?? '');
        if (mb_strlen($details) < 10) {
            $back('error', 'Sorunu birkaç cümleyle açıklayın.', '#sorun');
        }
        $ok = job_issue_open($job, $role, $uid, $reason, $details);
        $back($ok ? 'success' : 'error', $ok ? 'Bildiriminiz ekibimize iletildi. Çözüldüğünde haber vereceğiz.' : 'Bu iş için zaten açık bir bildiriminiz var.');
    }

    // ---------------- AJANS ----------------
    if ($role === 'agency') {
        if ($action === 'message') {
            $msg = trim($_POST['message'] ?? '');
            if ($msg !== '') {
                add_job_message($job_id, 'agency', 'agency', $uid, $company, $msg);
                notify_staff("{$job['job_code']} için ajans mesajı: \"" . mb_substr($msg, 0, 120) . "\"", $job_id);
            }
            redirect($self_url . '#mesajlar');
        }
        if ($action === 'approve_quote' && $job['status'] === 'quote_sent') {
            job_event($job_id, 'quote', 'Fiyat teklifi onaylandı', ['amount' => (float)$job['agency_price'], 'visibility' => 'agency']);
            job_publish($job_id);
            notify_staff("{$job['job_code']} fiyatı ajans tarafından onaylandı (" . format_money((float)$job['agency_price'], $job['currency']) . ").", $job_id);
            $back('success', 'Teklifi onayladınız. Ekip ataması başladı.');
        }
        if ($action === 'reject_quote' && $job['status'] === 'quote_sent') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                $back('error', 'Teklifi neden uygun bulmadığınızı kısaca yazın.');
            }
            $db->prepare("UPDATE platform_jobs SET status = 'submitted' WHERE id = ?")->execute([$job_id]);
            add_job_message($job_id, 'agency', 'agency', $uid, $company, "Teklif hakkında geri bildirim: {$reason}");
            job_event($job_id, 'quote', 'Fiyat teklifi geri çevrildi', ['new' => $reason, 'amount' => (float)$job['agency_price'], 'visibility' => 'agency']);
            notify_staff("{$job['job_code']} fiyat teklifi ajans tarafından geri çevrildi: \"" . mb_substr($reason, 0, 120) . "\"", $job_id);
            $back('success', 'Geri bildiriminiz iletildi. Ekibimiz teklifi güncelleyecek.');
        }
        if ($action === 'cancel' && in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)) {
            $reason = trim($_POST['reason'] ?? '') ?: 'Ajans tarafından iptal edildi';
            $db->prepare("UPDATE platform_jobs SET status = 'cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $job_id]);
            $db->prepare("UPDATE platform_applications SET status = 'rejected', reviewed_at = NOW(), reject_reason = 'İş müşteri tarafından iptal edildi.' WHERE job_id = ? AND status = 'pending'")->execute([$job_id]);
            job_event($job_id, 'cancelled', 'İş iptal edildi', ['new' => $reason]);
            notify_staff("{$job['job_code']} ajans tarafından iptal edildi: {$reason}", $job_id);
            $back('success', 'İş iptal edildi.');
        }
        if ($action === 'approve_delivery' && $job['status'] === 'delivered') {
            $sent = job_deliveries($job_id, ['sent']);
            $m = $sent && $sent[0]['milestone_id'] ? get_milestone((int)$sent[0]['milestone_id']) : milestone_next($job_id);
            $rating = (int)($_POST['rating'] ?? 0);
            if (!$m) {
                job_complete($job, $rating >= 1 && $rating <= 5 ? $rating : null, trim($_POST['review'] ?? ''));
                $back('success', 'Teslimatı onayladınız, iş tamamlandı.');
            }
            $closed = milestone_approve($job, $m, 'agency', $rating >= 1 && $rating <= 5 ? $rating : null, trim($_POST['review'] ?? ''));
            notify_staff("{$job['job_code']} · \"{$m['title']}\" ajans tarafından onaylandı" . ($closed ? ' ve iş kapandı.' : '.') . ($closed && $rating ? " Puan: {$rating}/5" : ''), $job_id);
            $back('success', $closed ? 'Son aşamayı onayladınız, iş tamamlandı.' : "\"{$m['title']}\" onaylandı. Sıradaki aşamada çalışılıyor.");
        }
        if ($action === 'request_revision' && $job['status'] === 'delivered') {
            $feedback = trim($_POST['feedback'] ?? '');
            if (mb_strlen($feedback) < 5) {
                $back('error', 'Revizyon notlarınızı yazın.');
            }
            $paid = revision_is_paid($job);
            $rfee = job_revision_fee($job);
            if ($paid && $rfee <= 0) {
                $back('error', 'Ücretsiz revizyon hakkınız doldu. Ek değişiklik için ekibimizle yazışın.');
            }
            if ($paid && empty($_POST['paid_ack'])) {
                $back('error', 'Ücretsiz revizyon hakkınız doldu. Devam etmek için ' . format_money($rfee) . ' + KDV revizyon ücretini onaylayın.');
            }
            job_request_revision($job, $feedback);
            notify_staff("{$job['job_code']} için ajans " . ($paid ? 'ÜCRETLİ (' . format_money($rfee) . ') ' : '') . "revizyon istedi: \"" . mb_substr($feedback, 0, 140) . "\"", $job_id);
            $back('success', $paid ? 'Ücretli revizyon talebiniz iletildi; ' . format_money($rfee) . ' + KDV iş tutarına eklendi.' : 'Revizyon talebiniz iletildi.');
        }
        if ($action === 'add_extra' && in_array($job['status'], $production, true)) {
            $items = build_order_items((array)($_POST['qty'] ?? []));
            if (!$items) {
                $back('error', 'Eklemek istediğiniz hizmeti ve miktarını seçin.', '#asamalar');
            }
            extra_add_from_catalog($job, $items, trim($_POST['note'] ?? ''));
            $back('success', 'Ek kalem işe eklendi ve tutar güncellendi. Ekip planlamayı yapıyor.', '#asamalar');
        }
        if (in_array($action, ['extra_approve', 'extra_reject'], true)) {
            $m = get_milestone((int)($_POST['milestone_id'] ?? 0));
            if ($m && (int)$m['job_id'] === $job_id && $m['status'] === 'proposed') {
                extra_agency_decision($job, $m, $action === 'extra_approve', trim($_POST['note'] ?? ''));
                $back('success', $action === 'extra_approve' ? 'Ek kalemi onayladınız; tutar işe eklendi.' : 'Ek kalem önerisini reddettiniz.', '#asamalar');
            }
        }
    }

    // ---------------- FREELANCER ----------------
    if ($role === 'freelancer') {
        $cap = freelancer_capacity($profile);

        // Ekiple kişisel yazışma: işi görebilen, teklif veren veya atanan herkes
        if ($action === 'message') {
            $msg = trim($_POST['message'] ?? '');
            if ($msg !== '') {
                add_job_message($job_id, 'freelancer', 'freelancer', $uid, $me_name, $msg, $uid);
                notify_staff("{$job['job_code']} · {$me_name}: \"" . mb_substr($msg, 0, 120) . "\"", $job_id);
            }
            redirect($self_url . '#mesajlar');
        }

        if ($action === 'take' && $job['status'] === 'open' && $job['dispatch_mode'] === 'first_come') {
            if ((int)$profile['is_available'] !== 1) {
                $back('error', 'Durumunuz "müsait değil". Profilinizden değiştirip tekrar deneyin.');
            }
            if (!$cap['can_take']) {
                $back('error', tier_label($profile['tier']) . " seviyesinde aynı anda en fazla {$cap['limit']} aktif iş alabilirsiniz. Mevcut bir işi teslim ettiğinizde yeni iş alabilirsiniz.");
            }
            if (job_visibility_reason($job, $profile) !== null) {
                $back('error', 'Bu işi alma yetkiniz yok.');
            }
            if (job_assign_freelancer($job_id, $uid, true, null, 'self')) {
                notify_staff("{$job['job_code']} işini {$me_name} aldı.", $job_id);
                $back('success', 'İş size atandı. Brief\'i inceleyip hazır olduğunuzda "İşe başla" deyin.');
            }
            set_flash('error', 'Bu iş az önce başka biri tarafından alındı.');
            redirect(BASE_URL . '/platform/pool.php');
        }

        $offer_edit = $action === 'apply' && $my_application && $my_application['status'] === 'pending';
        if ($action === 'apply' && $job['status'] === 'open' && $job['dispatch_mode'] === 'application'
            && (!$my_application || in_array($my_application['status'], ['withdrawn', 'pending'], true))) {
            if (job_visibility_reason($job, $profile) !== null) {
                $back('error', 'Bu işe teklif verme yetkiniz yok.');
            }
            if (!$cap['can_take'] && !$offer_edit) {
                $back('error', "Aktif iş limitiniz dolu ({$cap['active']}/{$cap['limit']}). Kapasiteniz açıldığında teklif verebilirsiniz.");
            }
            $fee  = parse_money($_POST['proposed_fee'] ?? '');
            $from = valid_date($_POST['available_from'] ?? '');
            $days = max(0, min(365, (int)($_POST['delivery_days'] ?? 0)));
            $note = trim($_POST['note'] ?? '');
            if (mb_strlen($note) < 10) {
                $back('error', 'Teklifinize kısa bir açıklama ekleyin (en az bir cümle).', '#teklif');
            }
            if ($from && $job['deadline'] && $from > $job['deadline']) {
                $back('error', 'Müsaitlik tarihiniz teslim tarihinden sonra olamaz.', '#teklif');
            }
            if ($my_application) {
                $db->prepare("UPDATE platform_applications SET proposed_fee = ?, available_from = ?, delivery_days = ?, note = ?, status = 'pending', reject_reason = NULL, reviewed_at = NULL, updated_at = NOW()" . ($offer_edit ? '' : ', created_at = NOW()') . " WHERE id = ?")
                   ->execute([$fee > 0 ? $fee : null, $from, $days ?: null, $note, $my_application['id']]);
            } else {
                $db->prepare("INSERT INTO platform_applications (job_id, user_id, proposed_fee, available_from, delivery_days, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())")
                   ->execute([$job_id, $uid, $fee > 0 ? $fee : null, $from, $days ?: null, $note]);
            }
            job_event($job_id, $offer_edit ? 'offer_updated' : 'offer', ($offer_edit ? 'Teklif güncellendi: ' : 'Teklif verildi: ') . $me_name,
                ['amount' => $fee > 0 ? $fee : (float)$job['freelancer_fee'], 'new' => trim(($days ? "{$days} günde teslim" : '') . ($from ? ' · ' . format_date($from) . ' itibarıyla' : ''), ' ·'), 'visibility' => 'freelancer']);
            notify_staff("{$job['job_code']} için " . ($offer_edit ? 'güncellenen' : 'yeni') . " teklif: {$me_name}" . ($fee > 0 ? ' · ' . format_money($fee) : '') . ($days ? " · {$days} gün" : ''), $job_id);
            $back('success', $offer_edit ? 'Teklifiniz güncellendi.' : 'Teklifiniz iletildi. Değerlendirme sonucunu bildirim olarak alacaksınız.');
        }

        if ($action === 'withdraw_application' && $my_application && $my_application['status'] === 'pending') {
            $db->prepare("UPDATE platform_applications SET status = 'withdrawn' WHERE id = ?")->execute([$my_application['id']]);
            job_event($job_id, 'offer_withdrawn', 'Teklif geri çekildi: ' . $me_name, ['visibility' => 'freelancer']);
            notify_staff("{$job['job_code']} teklifi {$me_name} tarafından geri çekildi.", $job_id);
            $back('success', 'Teklifiniz geri çekildi.');
        }

        if ($is_assignee) {
            if ($action === 'start' && $job['status'] === 'assigned') {
                $db->prepare("UPDATE platform_jobs SET status = 'in_progress' WHERE id = ?")->execute([$job_id]);
                job_event($job_id, 'started', $job['assigned_via'] !== 'self' ? 'Atama kabul edildi, üretim başladı' : 'Üretim başladı', ['visibility' => 'all']);
                notify_staff("{$job['job_code']} üretime başladı ({$me_name}).", $job_id);
                notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işiniz üretime başladı.", "/platform/job.php?id={$job_id}", $job_id);
                $back('success', 'İyi çalışmalar. Her aşamayı bitirdiğinizde teslim bağlantısını gönderin.');
            }
            if ($action === 'decline_award' && $job['status'] === 'assigned' && $job['assigned_via'] !== 'self') {
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') {
                    $back('error', 'Reddetme nedeninizi kısaca yazın.');
                }
                job_unassign($job_id, 'declined', $reason);
                notify_staff("{$job['job_code']} atamasını {$me_name} kabul etmedi: \"" . mb_substr($reason, 0, 120) . "\". İş havuza döndü.", $job_id);
                set_flash('success', 'Atamayı reddettiniz. Bu durum puanınızı etkilemez.');
                redirect(BASE_URL . '/platform/jobs.php');
            }
            if ($action === 'release' && in_array($job['status'], ['assigned', 'in_progress'], true)) {
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') {
                    $back('error', 'İşi bırakma nedeninizi yazın.');
                }
                job_unassign($job_id, 'freelancer', $reason);
                recompute_freelancer_metrics($uid);
                notify_staff("{$job['job_code']} işini {$me_name} bıraktı: \"" . mb_substr($reason, 0, 120) . "\". İş havuza döndü.", $job_id);
                set_flash('success', 'İşi bıraktınız. Bu durum güvenilirlik puanınıza yansır.');
                redirect(BASE_URL . '/platform/jobs.php');
            }
            if ($action === 'deliver' && in_array($job['status'], ['in_progress', 'revision'], true)) {
                $url = trim($_POST['url'] ?? '');
                if (!is_safe_url($url)) {
                    $back('error', 'Geçerli bir teslim bağlantısı girin (https://...).', '#teslim');
                }
                $mid = (int)($_POST['milestone_id'] ?? 0) ?: null;
                if (!job_submit_delivery($job, $url, trim($_POST['note'] ?? ''), 'freelancer', $uid, $mid)) {
                    $back('error', 'Teslim bağlantısı gerektiren açık aşama yok. Yerinde işleri "Yapıldı" ile bildirin.');
                }
                notify_staff("{$job['job_code']} teslim edildi ({$me_name})" . (platform_setting('platform_qa_required') === '1' ? ', kalite kontrol bekliyor.' : ', ajansa iletildi.'), $job_id);
                $back('success', 'Teslimatınız gönderildi.');
            }
            if ($action === 'mark_done' && in_array($job['status'], ['in_progress', 'revision'], true)) {
                $m = get_milestone((int)($_POST['milestone_id'] ?? 0));
                if (!$m) {
                    $back('error', 'Aşama bulunamadı.');
                }
                $r = milestone_mark_done($job, $m, 'freelancer', $uid, trim($_POST['note'] ?? ''));
                $msg = ['approved' => "\"{$m['title']}\" tamamlandı; hakedişiniz kayda geçti.", 'staff' => "\"{$m['title']}\" bildirildi; ekip onayından sonra hakedişiniz kayda geçer.", 'agency' => "\"{$m['title']}\" bildirildi; müşteri onayından sonra hakedişiniz kayda geçer."];
                $back(isset($msg[$r]) ? 'success' : 'error', $msg[$r] ?? $r);
            }
            if (in_array($action, ['extra_accept', 'extra_decline'], true)) {
                $m = get_milestone((int)($_POST['milestone_id'] ?? 0));
                if ($m && (int)$m['job_id'] === $job_id && $m['status'] === 'pending_freelancer') {
                    extra_freelancer_decision($job, $m, $action === 'extra_accept', trim($_POST['note'] ?? ''));
                    $back('success', $action === 'extra_accept' ? 'Ek kalemi kabul ettiniz; hakedişinize eklendi.' : 'Ek kalemi kabul etmediniz; ekip bilgilendirildi.', '#asamalar');
                }
            }
        }
    }
    redirect($self_url);
}

// ====================================================================
// GÖRÜNÜM VERİLERİ
// ====================================================================
$job         = get_job($job_id);
$is_assignee = $role === 'freelancer' && (int)$job['assigned_user_id'] === $uid;
$items       = array_values(array_filter(job_items($job_id), fn($i) => ($i['status'] ?? 'active') === 'active'));
$free_revs   = job_free_revisions($job);
$rev_fee     = job_revision_fee($job);
$rev_paid    = revision_is_paid($job);
$milestones  = job_milestones($job_id);
if ($role === 'agency') {
    // Ekibin iç ek işleri ve primleri ajansa gösterilmez
    $milestones = array_values(array_filter($milestones, fn($m) => !milestone_internal($m)));
}
$ms_totals   = milestone_totals($milestones);
$my_issue    = null;
foreach (job_issues($job_id) as $is) {
    if ((int)$is['opened_by_user_id'] === $uid) { $my_issue = $is; break; }
}

if ($role === 'agency') {
    $messages   = job_messages($job_id, 'agency');
    // Ekibin iç ek işlerine ait teslimler ajansa gösterilmez
    $internal_ids = array_map(fn($m) => (int)$m['id'], array_filter(job_milestones($job_id), 'milestone_internal'));
    $deliveries = array_values(array_filter(job_deliveries($job_id, ['sent', 'approved', 'revision']), fn($d) => !in_array((int)$d['milestone_id'], $internal_ids, true)));
    $scope      = agency_edit_scope($job);
    $price      = $job['agency_price'] ?? $job['budget'];
    $price_cap  = $job['agency_price'] !== null ? 'KDV hariç' : 'bütçe beklentiniz';
    $events     = job_events($job_id, 'agency');
    $catalog    = in_array($job['status'], $production, true) ? catalog_services() : [];
} else {
    $messages   = job_messages($job_id, 'freelancer', $uid);
    $deliveries = array_values(array_filter(job_deliveries($job_id), fn($d) => (int)$d['submitted_by_user_id'] === $uid));
    $cap        = freelancer_capacity($profile);
    $price      = $job['freelancer_fee'];
    $price_cap  = 'hakediş';
    // Freelancer yalnızca kendisine atandıktan sonraki kayıtları ve kendi tekliflerini görür
    $events = $is_assignee || ($job['status'] === 'completed' && (int)$job['assigned_user_id'] === $uid)
        ? array_values(array_filter(job_events($job_id, 'freelancer'), fn($e) => strtotime($e['created_at']) >= strtotime((string)$job['assigned_at']) || (int)$e['user_id'] === $uid))
        : array_values(array_filter(job_events($job_id, 'freelancer'), fn($e) => (int)$e['user_id'] === $uid));
    if ($my_application) {
        $ap = $db->prepare("SELECT * FROM platform_applications WHERE id = ?");
        $ap->execute([$my_application['id']]);
        $my_application = $ap->fetch();
    }
    $bid_stats = $db->prepare("SELECT COUNT(*) AS n, AVG(COALESCE(a.proposed_fee, j.freelancer_fee)) AS avg_fee FROM platform_applications a JOIN platform_jobs j ON j.id = a.job_id WHERE a.job_id = ? AND a.status = 'pending'");
    $bid_stats->execute([$job_id]);
    $bid_stats = $bid_stats->fetch();
}
$sent_delivery = job_deliveries($job_id, ['sent']);
$review_ms = $sent_delivery && $sent_delivery[0]['milestone_id'] ? get_milestone((int)$sent_delivery[0]['milestone_id']) : null;
$open_left = count(array_filter($milestones, fn($m) => in_array($m['status'], ['open', 'in_review', 'revision'], true)));
$is_final_review = $review_ms ? $open_left <= 1 : true;
$deliverable_ms = array_values(array_filter($milestones, fn($m) => in_array($m['status'], ['open', 'revision'], true) && (int)$m['no_work'] === 0 && (int)$m['needs_delivery'] === 1));
// Yerinde (fiziki) işler: teslim bağlantısı yok, "yapıldı" olarak işaretlenir
$physical_ms = array_values(array_filter($milestones, fn($m) => in_array($m['status'], ['open', 'revision'], true) && (int)$m['no_work'] === 0 && (int)$m['needs_delivery'] === 0));
$review_physical = $sent_delivery && (string)$sent_delivery[0]['url'] === '';

// Akış adımları (yalnızca yerinde işlerden oluşan işte teslim/onay adımları gösterilmez)
$live_ms = array_filter($milestones, fn($m) => in_array($m['status'], MILESTONE_LIVE, true) && (int)$m['no_work'] === 0);
$only_onsite = $live_ms && !array_filter($live_ms, fn($m) => (int)$m['needs_delivery'] === 1);
$onsite_mode = platform_setting('platform_onsite_confirm');
if ($role === 'agency') {
    $steps = ['submitted' => 'İş'];
    if ($job['pricing_source'] === 'custom') {
        $steps['quote_sent'] = 'Teklif';
    }
    $steps += ['open' => 'Ekip ataması', 'in_progress' => $only_onsite ? 'Çekim / yerinde iş' : 'Üretim'];
    if (!$only_onsite || $onsite_mode === 'agency') $steps['delivered'] = $only_onsite ? 'Onayınız' : 'Teslim';
    $steps['completed'] = 'Tamamlandı';
    $map = ['assigned' => 'in_progress', 'qa_review' => 'in_progress', 'revision' => 'in_progress'];
} else {
    $steps = ['assigned' => 'Atandı', 'in_progress' => $only_onsite ? 'Çekim / yerinde iş' : 'Üretim'];
    if (!$only_onsite || $onsite_mode === 'staff') $steps['qa_review'] = $only_onsite ? 'Ekip onayı' : 'Kalite kontrol';
    if (!$only_onsite || $onsite_mode === 'agency') $steps['delivered'] = 'Müşteri onayı';
    $steps['completed'] = 'Tamamlandı';
    $map = ['revision' => 'in_progress', 'open' => null];
}
$cur_key   = array_key_exists($job['status'], $map) ? $map[$job['status']] : $job['status'];
$step_keys = array_keys($steps);
$cur_idx   = $cur_key !== null ? array_search($cur_key, $step_keys, true) : false;
$show_steps = $job['status'] !== 'cancelled' && ($role === 'agency' || $is_assignee);

$show_agency_name = platform_setting('platform_show_agency_name') === '1';
$location = (int)$job['is_remote'] === 1 ? 'Uzaktan' : trim(($job['location_city'] ?? '') . ((string)($job['location_detail'] ?? '') !== '' ? ' · ' . $job['location_detail'] : ''));
$days_left = $job['deadline'] ? (int)floor((strtotime($job['deadline']) - strtotime(date('Y-m-d'))) / 86400) : null;
$is_closed = in_array($job['status'], ['completed', 'cancelled'], true);

platform_header($job['job_code'] . ' · ' . $job['title'], $role === 'freelancer' && !$is_assignee && $job['status'] === 'open' ? 'pool' : 'jobs');
?>
<div class="page-head">
    <div style="min-width:0">
        <div class="crumb">
            <a href="<?= BASE_URL ?>/platform/<?= $role === 'freelancer' && !$is_assignee ? 'pool.php' : 'jobs.php' ?>"><?= $role === 'agency' ? 'İşler' : ($is_assignee ? 'İşlerim' : 'İş havuzu') ?></a>
            <i data-lucide="chevron-right" style="width:13px;height:13px"></i><span class="code-tag"><?= e($job['job_code']) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <?= job_status_badge($job['status'], $role) ?>
            <?php if ((int)$job['is_rush'] === 1): ?><?= ui_badge('Acil iş', 'accent') ?><?php endif; ?>
            <?php if ($role === 'freelancer' && !$is_assignee && $job['status'] === 'open'): ?>
                <?= ui_badge($job['dispatch_mode'] === 'first_come' ? 'İlk alan alır' : 'Teklif usulü', 'neutral') ?>
                <?php if ($job['min_tier'] !== 'standard'): ?><?= tier_badge($job['min_tier']) ?><?php endif; ?>
            <?php endif; ?>
        </div>
        <h1 class="h1" style="margin-top:10px"><?= e($job['title']) ?></h1>
        <p class="small text-muted" style="margin-top:6px;display:flex;gap:16px;flex-wrap:wrap">
            <span style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="<?= job_category_icon($job['category']) ?>" style="width:14px;height:14px"></i><?= e(job_category_label($job['category'])) ?></span>
            <span style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="map-pin" style="width:14px;height:14px"></i><?= e($location ?: '—') ?></span>
            <?php if ($role === 'freelancer' && $show_agency_name && !empty($job['agency_name'])): ?>
                <span style="display:inline-flex;gap:6px;align-items:center"><i data-lucide="building-2" style="width:14px;height:14px"></i><?= e($job['agency_name']) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;align-items:flex-end;gap:16px">
        <?php if ($role === 'agency' && $scope !== 'locked'): ?>
            <a href="<?= BASE_URL ?>/platform/job_edit.php?id=<?= $job_id ?>" class="btn btn-secondary"><i data-lucide="pencil"></i><?= $scope === 'full' ? 'Düzenle' : 'Not / tarih güncelle' ?></a>
        <?php endif; ?>
        <?php if ($price !== null): ?>
        <div style="text-align:right">
            <p class="eyebrow"><?= e($price_cap) ?></p>
            <p class="money" style="font-size:26px;font-weight:600;letter-spacing:-.02em;line-height:1.15;margin-top:2px"><?= format_money((float)$price, $job['currency']) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($show_steps): ?>
<div class="card card-pad-sm" style="margin-bottom:20px">
    <div class="steps">
        <?php foreach ($step_keys as $i => $k):
            $cls = $cur_idx === false ? '' : ($i < $cur_idx || $job['status'] === 'completed' ? 'is-done' : ($i === $cur_idx ? 'is-current' : '')); ?>
            <div class="step <?= $cls ?>"><span class="step-bar"></span><span class="step-label"><?= e($steps[$k]) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
<div class="lg:col-span-2 stack-lg" style="min-width:0">

<?php /* ================================================================
       AJANS: SIRADAKİ ADIM
       ================================================================ */ if ($role === 'agency'): ?>

    <?php if ($job['status'] === 'submitted'): ?>
        <div class="alert alert-info"><i data-lucide="hourglass"></i><div>
            <strong><?= $job['pricing_source'] === 'custom' ? 'Talebiniz fiyatlandırılıyor.' : 'İşiniz onay bekliyor.' ?></strong>
            <?= $job['pricing_source'] === 'custom' ? 'Ekibimiz brief\'i inceleyip size özel fiyatı bu sayfada paylaşacak. Bu süreçte detayları düzenleyebilirsiniz.' : 'Ekip onayının ardından üretim planlaması başlar.' ?>
        </div></div>

    <?php elseif ($job['status'] === 'quote_sent'): ?>
        <section class="card card-emphasis" x-data="{ reject: false }">
            <div class="card-pad">
                <p class="eyebrow">Fiyat teklifimiz</p>
                <div style="display:flex;align-items:baseline;gap:10px;margin-top:6px;flex-wrap:wrap">
                    <span class="money" style="font-size:30px;font-weight:600;letter-spacing:-.025em"><?= format_money((float)$job['agency_price'], $job['currency']) ?></span>
                    <span class="small text-muted">+ KDV</span>
                    <?php if ($job['budget'] !== null): ?><span class="small text-muted">· bütçe beklentiniz <?= format_money((float)$job['budget'], $job['currency']) ?></span><?php endif; ?>
                </div>
                <p class="small text-muted" style="margin-top:8px">Onayladığınızda iş üretim planına alınır. Teklifi uygun bulmazsanız beklentinizi yazın, ekibimiz güncellesin.</p>
                <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="approve_quote">
                        <button class="btn btn-primary"><i data-lucide="check"></i>Teklifi onayla</button></form>
                    <button type="button" class="btn btn-secondary" @click="reject = !reject">Uygun değil</button>
                </div>
                <form x-show="reject" x-cloak method="POST" action="" class="stack-sm" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="reject_quote">
                    <textarea name="reason" rows="3" required class="textarea" placeholder="Bütçe beklentiniz veya kapsamda yapılabilecek değişiklik"></textarea>
                    <button class="btn btn-primary btn-sm">Geri bildirimi gönder</button>
                </form>
            </div>
        </section>

    <?php elseif ($job['status'] === 'delivered'): ?>
        <section class="card card-emphasis" x-data="{ mode: null, rating: 0, hover: 0 }">
            <div class="card-pad">
                <p class="eyebrow">Onayınız bekleniyor<?= count($milestones) > 1 && $review_ms ? ' · Aşama ' . (int)$review_ms['seq'] . ' / ' . $ms_totals['count'] : '' ?></p>
                <h2 class="h2" style="margin-top:6px"><?= $review_ms && count($milestones) > 1 ? e($review_ms['title']) . ($review_physical ? ' tamamlandı' : ' teslim edildi') : ($review_physical ? 'Yerinde iş tamamlandı' : 'İşiniz teslim edildi') ?></h2>
                <p class="small text-muted" style="margin-top:6px"><?= $review_physical ? 'Ekip işin sahada yapıldığını bildirdi. Doğruysa onaylayın; bir sorun varsa "Sorun bildir" ile iletin.' : ($is_final_review ? 'Teslim bağlantısını inceleyin. Onayladığınızda iş kapanır ve faturanız düzenlenir.' : 'Bu aşamayı onayladığınızda ekip sıradaki aşamaya geçer.') ?>
                    <?php if ((int)platform_setting('platform_auto_approve_days') > 0): ?><span class="xsmall">Teslimden itibaren <?= (int)platform_setting('platform_auto_approve_days') ?> gün içinde yanıt verilmezse otomatik onaylanır.</span><?php endif; ?></p>
                <?php if ($sent_delivery): ?>
                    <?php if (!$review_physical): ?><a href="<?= e($sent_delivery[0]['url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="margin-top:14px"><i data-lucide="external-link"></i>Teslimatı aç</a><?php endif; ?>
                    <?php if (!empty($sent_delivery[0]['note'])): ?><p class="small prose-text" style="margin-top:10px"><?= e($sent_delivery[0]['note']) ?></p><?php endif; ?>
                <?php endif; ?>
                <div class="hairline" style="margin:18px 0"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary" @click="mode = 'approve'"><i data-lucide="check"></i><?= $review_physical ? ($is_final_review ? 'Yapıldı, işi kapat' : 'Yapıldığını onayla') : ($is_final_review ? 'Onayla ve kapat' : 'Aşamayı onayla') ?></button>
                    <?php if (!$review_physical): ?><button type="button" class="btn btn-secondary" @click="mode = 'revision'">Revizyon iste <span class="text-muted num" style="margin-left:4px"><?= (int)$job['revision_count'] ?>/<?= $free_revs ?></span></button><?php endif; ?>
                </div>
                <?php if ($rev_paid && !$review_physical): ?>
                    <p class="xsmall text-muted" style="margin-top:10px"><?= $rev_fee > 0 ? 'Ücretsiz revizyon hakkınız doldu; ek revizyon ' . format_money($rev_fee) . ' + KDV olarak iş tutarına eklenir.' : 'Ücretsiz revizyon hakkınız doldu; ek değişiklik için ekibimizle yazışın.' ?></p>
                <?php endif; ?>

                <form x-show="mode === 'approve'" x-cloak method="POST" action="" class="stack" style="margin-top:18px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve_delivery">
                    <input type="hidden" name="rating" :value="rating">
                    <?php if ($is_final_review): ?>
                    <div class="field">
                        <span class="label">İşi değerlendirin</span>
                        <div style="display:flex;gap:2px" @mouseleave="hover = 0">
                            <template x-for="n in 5" :key="n">
                                <button type="button" @click="rating = n" @mouseenter="hover = n" style="font-size:24px;line-height:1;padding:2px"
                                        :style="{ color: n <= (hover || rating) ? '#C98A0F' : '#DAD8D2' }" :aria-label="n + ' puan'">&#9733;</button>
                            </template>
                        </div>
                        <span class="hint">Puanınız ekibin performans değerlendirmesine işlenir.</span>
                    </div>
                    <textarea name="review" rows="2" class="textarea" placeholder="Kısa bir yorum (isteğe bağlı)"></textarea>
                    <?php else: ?>
                    <p class="small text-ink-2">"<?= e($review_ms['title'] ?? '') ?>" onaylanacak. Kalan <?= $open_left - 1 ?> aşama tamamlandığında iş kapanır.</p>
                    <?php endif; ?>
                    <div><button class="btn btn-primary">Onayı gönder</button></div>
                </form>
                <form x-show="mode === 'revision'" x-cloak method="POST" action="" class="stack-sm" style="margin-top:18px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_revision">
                    <?php if ($rev_paid && $rev_fee <= 0): ?>
                    <div class="alert alert-warning"><i data-lucide="info"></i><div>Ücretsiz revizyon hakkınız (<?= $free_revs ?>) doldu. Ek değişiklik için sağdaki yazışma alanından ekibimize yazın.</div></div>
                    <?php else: ?>
                    <textarea name="feedback" rows="4" required class="textarea" placeholder="Değişmesini istediğiniz noktalar. Zaman kodu ile yazmanız süreci hızlandırır (ör. 00:12 logo daha büyük)."></textarea>
                    <?php if ($rev_paid): ?>
                    <label class="check small panel" style="padding:10px 12px"><input type="checkbox" name="paid_ack" value="1" required><span><strong>Ücretli revizyon:</strong> <?= $free_revs ?> ücretsiz revizyon hakkınız kullanıldı. Bu revizyon için <strong><?= format_money($rev_fee) ?> + KDV</strong> iş tutarına eklenecek; onaylıyorum.</span></label>
                    <?php endif; ?>
                    <div><button class="btn btn-primary"><?= $rev_paid ? 'Ücretli revizyonu gönder' : 'Revizyonu gönder' ?></button></div>
                    <?php endif; ?>
                </form>
            </div>
        </section>

    <?php elseif ($job['status'] === 'completed'): ?>
        <div class="alert alert-success"><i data-lucide="circle-check"></i><div>
            <strong>İş tamamlandı<?= $job['completed_at'] ? ' · ' . format_date($job['completed_at']) : '' ?>.</strong>
            <?php if ($job['agency_rating']): ?>Değerlendirmeniz: <?= render_stars((float)$job['agency_rating']) ?><?php endif; ?>
            <?php if (!empty($job['sales_invoice_id'])): ?>
                <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= (int)$job['sales_invoice_id'] ?>" target="_blank" class="link" style="margin-left:6px">Faturayı görüntüle</a>
            <?php endif; ?>
        </div></div>

    <?php elseif ($job['status'] === 'cancelled'): ?>
        <div class="alert alert-danger"><i data-lucide="circle-x"></i><div><strong>İş iptal edildi.</strong> <?= e($job['cancel_reason'] ?? '') ?></div></div>

    <?php else: ?>
        <div class="alert alert-neutral"><i data-lucide="activity"></i><div>
            <strong><?= e(job_status_label($job['status'], 'agency')) ?>.</strong>
            <?= $job['status'] === 'open'
                ? 'İşiniz uygun ekip üyeleriyle eşleştiriliyor. Atama yapıldığında bildirim alacaksınız.'
                : 'İşiniz ' . e(site_setting('platform_team_name')) . ' tarafından yürütülüyor. Teslim edildiğinde bildirim alacaksınız.' ?>
            <?php if ($scope === 'limited'): ?><br><span class="xsmall">Üretim sürecinde referans ve not ekleyebilir, teslim tarihini ileri alabilirsiniz.</span><?php endif; ?>
        </div></div>
    <?php endif; ?>

<?php /* ================================================================
       FREELANCER: SIRADAKİ ADIM
       ================================================================ */ else: ?>

    <?php if ($job['status'] === 'open' && !$is_assignee): ?>
        <?php if (!$cap['can_take']): ?>
            <div class="alert alert-warning"><i data-lucide="gauge"></i><div>
                <?php if ((int)$profile['is_available'] !== 1): ?>
                    Durumunuz <strong>müsait değil</strong>. Yeni iş alabilmek için <a class="link" href="<?= BASE_URL ?>/platform/profile.php">profilinizden</a> değiştirin.
                <?php else: ?>
                    <strong>Aktif iş limitiniz dolu (<?= $cap['active'] ?>/<?= $cap['limit'] ?>).</strong>
                    <?= e(tier_label($profile['tier'])) ?> seviyesinde aynı anda <?= $cap['limit'] ?> iş yürütebilirsiniz. Limit, performans puanınız yükseldikçe artar.
                    <a class="link" href="<?= BASE_URL ?>/platform/performance.php">Performansım</a>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>

        <?php if ($job['dispatch_mode'] === 'first_come'): ?>
            <section class="card card-emphasis"><div class="card-pad">
                <p class="eyebrow">İlk alan alır</p>
                <h2 class="h2" style="margin-top:6px">Bu iş hemen alınabilir</h2>
                <p class="small text-muted" style="margin-top:6px">İşi aldığınızda size atanır ve aktif iş sayınıza eklenir. Tarihlere uyabileceğinizden emin olun; bırakılan işler güvenilirlik puanınızı düşürür.</p>
                <form method="POST" action="" style="margin-top:16px" onsubmit="return confirm('Bu işi almak istediğinize emin misiniz?');"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="take">
                    <button class="btn btn-accent btn-lg" <?= $cap['can_take'] ? '' : 'disabled' ?>><i data-lucide="hand"></i>İşi al · <?= format_money((float)$job['freelancer_fee'], $job['currency']) ?></button>
                </form>
            </div></section>

        <?php else: /* teklif usulü */ ?>
            <?php if ($my_application && $my_application['status'] === 'pending'): ?>
                <section class="card"><div class="card-pad">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
                        <div>
                            <p class="eyebrow">Teklifiniz</p>
                            <h2 class="h2" style="margin-top:6px">Değerlendiriliyor</h2>
                            <p class="small text-muted" style="margin-top:4px"><?= format_date($my_application['created_at'], true) ?> tarihinde gönderildi.</p>
                        </div>
                        <?= ui_badge('Beklemede', 'info', true) ?>
                    </div>
                    <dl class="dl" style="margin-top:16px">
                        <dt>Ücret öneriniz</dt><dd class="num"><?= $my_application['proposed_fee'] !== null ? format_money((float)$my_application['proposed_fee'], $job['currency']) : 'Belirtilen hakediş (' . format_money((float)$job['freelancer_fee'], $job['currency']) . ')' ?></dd>
                        <dt>Teslim süresi</dt><dd><?= $my_application['delivery_days'] ? (int)$my_application['delivery_days'] . ' gün' : '—' ?></dd>
                        <dt>Müsaitlik</dt><dd><?= $my_application['available_from'] ? format_date($my_application['available_from']) . ' itibarıyla' : '—' ?></dd>
                        <dt>Not</dt><dd class="prose-text"><?= e($my_application['note'] ?? '') ?></dd>
                    </dl>
                    <p class="xsmall text-muted" style="margin-top:12px"><?= (int)$bid_stats['n'] ?> teklif · ortalama <?= format_money((float)$bid_stats['avg_fee'], $job['currency']) ?><?= $my_application['updated_at'] ? ' · son güncelleme ' . time_ago($my_application['updated_at']) : '' ?></p>
                    <div x-data="{ edit: false }" style="margin-top:14px">
                        <div style="display:flex;gap:8px">
                            <button type="button" class="btn btn-secondary btn-sm" @click="edit = !edit"><i data-lucide="pencil"></i>Teklifi güncelle</button>
                            <form method="POST" action="" onsubmit="return confirm('Teklif geri çekilsin mi?');"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="withdraw_application">
                                <button class="btn btn-ghost btn-sm">Teklifi geri çek</button>
                            </form>
                        </div>
                        <form x-show="edit" x-cloak method="POST" action="" class="stack" style="margin-top:14px"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="apply">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div class="field"><label class="label">Ücret</label><div class="input-group"><input type="text" inputmode="decimal" name="proposed_fee" class="input" value="<?= e($my_application['proposed_fee'] !== null ? number_format((float)$my_application['proposed_fee'], 2, ',', '') : '') ?>"><span class="addon">TL</span></div></div>
                                <div class="field"><label class="label">Teslim süresi</label><div class="input-group"><input type="number" min="1" max="365" name="delivery_days" class="input" value="<?= e((string)($my_application['delivery_days'] ?? '')) ?>"><span class="addon">gün</span></div></div>
                                <div class="field"><label class="label">Müsaitlik</label><input type="date" name="available_from" class="input" value="<?= e($my_application['available_from'] ?? '') ?>"></div>
                            </div>
                            <textarea name="note" rows="3" required class="textarea"><?= e($my_application['note'] ?? '') ?></textarea>
                            <div><button class="btn btn-primary btn-sm">Güncellemeyi gönder</button></div>
                        </form>
                    </div>
                </div></section>
            <?php elseif ($my_application && $my_application['status'] === 'rejected'): ?>
                <div class="alert alert-neutral"><i data-lucide="circle-slash"></i><div>
                    <strong>Teklifiniz kabul edilmedi.</strong>
                    <?php if (!empty($my_application['reject_reason'])): ?><div style="margin-top:4px">Gerekçe: <?= e($my_application['reject_reason']) ?></div><?php endif; ?>
                    <div class="xsmall text-muted" style="margin-top:4px">Sorunuz varsa aşağıdan ekiple yazışabilirsiniz.</div>
                </div></div>
            <?php else: ?>
                <section class="card card-emphasis" id="teklif"><div class="card-pad">
                    <p class="eyebrow">Teklif usulü</p>
                    <h2 class="h2" style="margin-top:6px"><?= $my_application ? 'Yeniden teklif verin' : 'Bu iş için teklif verin' ?></h2>
                    <p class="small text-muted" style="margin-top:6px">Ekip gelen teklifleri değerlendirip atama yapar. Sonuç ve gerekçe size bildirilir.<?php if ((int)$bid_stats['n'] > 0): ?> Şu ana kadar <strong><?= (int)$bid_stats['n'] ?></strong> teklif, ortalama <strong><?= format_money((float)$bid_stats['avg_fee'], $job['currency']) ?></strong>.<?php endif; ?></p>
                    <form method="POST" action="" class="stack" style="margin-top:16px"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="apply">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="field">
                                <label class="label">Ücret öneriniz</label>
                                <div class="input-group"><input type="text" inputmode="decimal" name="proposed_fee" class="input" placeholder="<?= e(number_format((float)$job['freelancer_fee'], 0, ',', '.')) ?>"><span class="addon">TL</span></div>
                                <span class="hint">Boş bırakırsanız belirtilen hakediş geçerli olur.</span>
                            </div>
                            <div class="field">
                                <label class="label">Teslim süresi</label>
                                <div class="input-group"><input type="number" min="1" max="365" name="delivery_days" class="input" placeholder="<?= $job['deadline'] ? max(1, (int)floor((strtotime($job['deadline']) - time()) / 86400)) : 7 ?>"><span class="addon">gün</span></div>
                            </div>
                            <div class="field">
                                <label class="label">Müsait olduğunuz tarih</label>
                                <input type="date" name="available_from" class="input" min="<?= date('Y-m-d') ?>" value="<?= e($job['start_date'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field">
                            <label class="label">Açıklama <span class="req">*</span></label>
                            <textarea name="note" rows="4" required class="textarea" placeholder="Benzer işleriniz, kullanacağınız ekipman, planınız"></textarea>
                        </div>
                        <div><button class="btn btn-primary" <?= $cap['can_take'] ? '' : 'disabled' ?>><i data-lucide="send"></i>Teklifi gönder</button></div>
                    </form>
                </div></section>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($is_assignee && $job['status'] === 'assigned'): ?>
        <section class="card card-emphasis" x-data="{ release: false }"><div class="card-pad">
            <?php $offered = $job['assigned_via'] !== 'self'; ?>
            <p class="eyebrow"><?= $offered ? 'Atama teklifi' : 'İş size atandı' ?></p>
            <h2 class="h2" style="margin-top:6px"><?= $offered ? 'Bu işi kabul ediyor musunuz?' : 'Brief\'i inceleyip başlayın' ?></h2>
            <p class="small text-muted" style="margin-top:6px"><?= $offered
                ? 'Ekip bu işi size atadı. Brief, aşamalar ve hakedişi inceleyin; kabul ettiğinizde üretim başlar. Uygun değilseniz puanınız etkilenmeden reddedebilirsiniz.'
                : 'Sorularınızı sağdaki yazışma alanından ekibe iletin. Başladığınızda müşteriye bilgi verilir.' ?></p>
            <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="start">
                    <button class="btn btn-primary"><i data-lucide="play"></i><?= $offered ? 'Kabul et ve başla' : 'İşe başla' ?></button></form>
                <button type="button" class="btn btn-ghost" @click="release = !release"><?= $offered ? 'Reddet' : 'İşi bırak' ?></button>
            </div>
            <form x-show="release" x-cloak method="POST" action="" class="stack-sm" style="margin-top:14px"><?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $offered ? 'decline_award' : 'release' ?>">
                <?php if (!$offered): ?><div class="alert alert-warning"><i data-lucide="triangle-alert"></i><div>Bırakılan işler güvenilirlik puanınızı düşürür ve iş havuza geri döner.</div></div><?php endif; ?>
                <textarea name="reason" rows="2" required class="textarea" placeholder="<?= $offered ? 'Neden kabul edemiyorsunuz? (tarih, ekipman, ücret…)' : 'Bırakma nedeniniz' ?>"></textarea>
                <button class="btn btn-danger btn-sm"><?= $offered ? 'Atamayı reddet' : 'İşi bırak' ?></button>
            </form>
        </div></section>

    <?php elseif ($is_assignee && in_array($job['status'], ['in_progress', 'revision'], true)): ?>
        <?php $fb = job_deliveries($job_id, ['revision', 'qa_rejected']); ?>
        <?php if ($job['status'] === 'revision' && $fb): ?>
            <div class="alert alert-warning"><i data-lucide="rotate-ccw"></i><div>
                <strong><?= $fb[0]['status'] === 'qa_rejected' ? 'Kalite kontrol düzeltme istedi' : 'Müşteri revizyon istedi' ?></strong>
                <div class="prose-text" style="margin-top:4px;color:inherit"><?= e($fb[0]['feedback'] ?? '') ?></div>
            </div></div>
        <?php endif; ?>
        <?php foreach ($physical_ms as $pm): $wday = milestone_work_day($job, $pm); $ready = !$wday || $wday <= date('Y-m-d'); ?>
        <section class="card card-emphasis" x-data="{ open: false }"><div class="card-pad">
            <p class="eyebrow">Yerinde iş<?= $ms_totals['count'] > 1 ? ' · Aşama ' . (int)$pm['seq'] . ' / ' . $ms_totals['count'] : '' ?></p>
            <h2 class="h2" style="margin-top:6px"><?= e($pm['title']) ?></h2>
            <p class="small text-muted" style="margin-top:6px"><?= $wday ? 'Çekim / iş günü: <strong>' . format_date($wday) . '</strong>' . ($job['location_city'] ? ' · ' . e($job['location_city']) . ($job['location_detail'] ? ', ' . e($job['location_detail']) : '') : '') . '. ' : '' ?>Teslim bağlantısı gerekmez; iş bittiğinde "Yapıldı" deyin.</p>
            <?php if ($ready): ?>
                <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="mark_done"><input type="hidden" name="milestone_id" value="<?= (int)$pm['id'] ?>"><button class="btn btn-primary"><i data-lucide="check"></i>Yapıldı</button></form>
                    <button type="button" class="btn btn-ghost" @click="open = !open">Not ekleyerek bildir</button>
                </div>
                <form x-show="open" x-cloak method="POST" action="" class="stack-sm" style="margin-top:12px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_done"><input type="hidden" name="milestone_id" value="<?= (int)$pm['id'] ?>">
                    <textarea name="note" rows="2" class="textarea" placeholder="ör. Çekim 18:00'de bitti, kartlar ekibe teslim edildi"></textarea>
                    <div><button class="btn btn-primary btn-sm">Yapıldı olarak bildir</button></div>
                </form>
            <?php else: ?>
                <p class="xsmall text-muted" style="margin-top:12px"><i data-lucide="calendar-clock" style="width:13px;height:13px;vertical-align:-2px"></i> İş gününden itibaren "Yapıldı" olarak işaretleyebilirsiniz.</p>
            <?php endif; ?>
        </div></section>
        <?php endforeach; ?>
        <?php if ($deliverable_ms || !$physical_ms): ?>
        <section class="card card-emphasis" id="teslim" x-data="{ release: false }"><div class="card-pad">
            <?php $nx = $deliverable_ms[0] ?? null; ?>
            <p class="eyebrow"><?= $job['status'] === 'revision' ? 'Revize teslim' : 'Teslim' ?><?= $nx && $ms_totals['count'] > 1 ? ' · Aşama ' . (int)$nx['seq'] . ' / ' . $ms_totals['count'] : '' ?></p>
            <h2 class="h2" style="margin-top:6px"><?= $nx && $ms_totals['count'] > 1 ? e($nx['title']) : 'Teslim bağlantısını gönderin' ?></h2>
            <?php if ($days_left !== null): ?>
                <p class="small" style="margin-top:6px;color:<?= $days_left < 0 ? 'var(--danger)' : ($days_left <= 1 ? 'var(--warning)' : 'var(--muted)') ?>">
                    <?= $days_left < 0 ? abs($days_left) . ' gün gecikti' : ($days_left === 0 ? 'Teslim günü bugün' : "Teslime {$days_left} gün var") ?> · zamanında teslim performans puanınızın %25'idir.
                </p>
            <?php endif; ?>
            <form method="POST" action="" class="stack" style="margin-top:16px"><?= csrf_field() ?>
                <input type="hidden" name="action" value="deliver">
                <?php if (count($deliverable_ms) > 1): ?>
                <div class="field">
                    <label class="label">Teslim edilen aşama</label>
                    <select name="milestone_id" class="select">
                        <?php foreach ($deliverable_ms as $dm): ?><option value="<?= (int)$dm['id'] ?>"><?= (int)$dm['seq'] ?>. <?= e($dm['title']) ?><?= $dm['status'] === 'revision' ? ' (revizyon)' : '' ?> · <?= format_money((float)$dm['fee']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php elseif ($deliverable_ms): ?><input type="hidden" name="milestone_id" value="<?= (int)$deliverable_ms[0]['id'] ?>"><?php endif; ?>
                <div class="field">
                    <label class="label">Teslim bağlantısı <span class="req">*</span></label>
                    <input type="url" name="url" required class="input" placeholder="https:// (Drive, WeTransfer, Vimeo, Frame.io)">
                </div>
                <div class="field">
                    <label class="label">Teslim notu</label>
                    <textarea name="note" rows="3" class="textarea" placeholder="Dosya listesi, şifre, versiyon, dikkat edilmesi gerekenler"></textarea>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn btn-primary"><i data-lucide="upload"></i>Teslimatı gönder</button>
                    <?php if ($job['status'] === 'in_progress'): ?><button type="button" class="btn btn-ghost" @click="release = !release">İşi bırak</button><?php endif; ?>
                </div>
            </form>
            <?php if ($job['status'] === 'in_progress'): ?>
            <form x-show="release" x-cloak method="POST" action="" class="stack-sm" style="margin-top:14px"><?= csrf_field() ?>
                <input type="hidden" name="action" value="release">
                <div class="alert alert-warning"><i data-lucide="triangle-alert"></i><div>Üretim başladıktan sonra bırakılan işler güvenilirlik puanınızı düşürür.</div></div>
                <textarea name="reason" rows="2" required class="textarea" placeholder="Bırakma nedeniniz"></textarea>
                <button class="btn btn-danger btn-sm">İşi bırak</button>
            </form>
            <?php endif; ?>
        </div></section>
        <?php endif; ?>

    <?php elseif ($is_assignee && in_array($job['status'], ['qa_review', 'delivered'], true)): ?>
        <div class="alert alert-info"><i data-lucide="eye"></i><div>
            <strong><?= e(job_status_label($job['status'], 'freelancer')) ?>.</strong>
            <?= $job['status'] === 'qa_review' ? 'Ekibimiz bildiriminizi kontrol ediyor.' : 'Müşteri onayı bekleniyor.' ?>
        </div></div>

    <?php elseif ($is_assignee && $job['status'] === 'completed'): ?>
        <div class="alert alert-success"><i data-lucide="circle-check"></i><div>
            <strong>İş tamamlandı.</strong> Hakedişiniz <a class="link" href="<?= BASE_URL ?>/platform/earnings.php">kazançlarınıza</a> eklendi.
            <?php if ($job['freelancer_rating']): ?><div style="margin-top:4px">Ekip değerlendirmesi: <?= render_stars((float)$job['freelancer_rating']) ?></div><?php endif; ?>
            <?php if ($job['agency_rating']): ?><div style="margin-top:2px">Müşteri değerlendirmesi: <?= render_stars((float)$job['agency_rating']) ?></div><?php endif; ?>
        </div></div>

    <?php elseif ($my_application && $my_application['status'] === 'rejected'): ?>
        <div class="alert alert-neutral"><i data-lucide="circle-slash"></i><div>
            <strong>Teklifiniz kabul edilmedi.</strong>
            <?php if (!empty($my_application['reject_reason'])): ?><div style="margin-top:4px">Gerekçe: <?= e($my_application['reject_reason']) ?></div><?php endif; ?>
        </div></div>
    <?php elseif (!$is_assignee && !$is_closed): ?>
        <div class="alert alert-neutral"><i data-lucide="info"></i><div>Bu iş artık havuzda değil.</div></div>
    <?php endif; ?>
<?php endif; ?>

    <!-- AŞAMALAR -->
    <?php if ($milestones && ($role === 'agency' || $is_assignee || $job['status'] === 'open')): ?>
    <section class="card" id="asamalar" x-data="{ extra: false }">
        <div class="card-head">
            <div><p class="card-title">Aşamalar</p><p class="card-sub"><?= $ms_totals['approved'] ?> / <?= $ms_totals['count'] ?> aşama onaylandı<?= $role === 'freelancer' && $ms_totals['fee'] > 0 ? ' · onaylanan hakediş ' . format_money($ms_totals['fee_approved']) : '' ?></p></div>
            <?php if ($role === 'agency' && $catalog): ?><button type="button" class="btn btn-secondary btn-sm" @click="extra = true"><i data-lucide="list-plus"></i>Ek kalem ekle</button><?php endif; ?>
        </div>
        <div class="progress tone-success" style="border-radius:0;height:3px"><span style="width:<?= $ms_totals['count'] ? round($ms_totals['approved'] / $ms_totals['count'] * 100) : 0 ?>%"></span></div>
        <div class="divide">
        <?php $ms_no = 0; foreach ($milestones as $m):
            if ($m['status'] === 'cancelled' && $role === 'freelancer') continue;
            if ($role === 'freelancer' && $m['status'] === 'proposed') continue; ?>
            <div class="card-pad-sm" style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;<?= $m['status'] === 'cancelled' ? 'opacity:.5' : '' ?>">
                <span class="badge badge-square" style="flex-shrink:0;<?= $m['status'] === 'approved' ? 'background:var(--success-soft);color:var(--success)' : '' ?>"><?php $ms_no++; ?><?= $m['status'] === 'approved' ? '✓' : $ms_no ?></span>
                <div style="flex:1;min-width:200px">
                    <p style="font-weight:500;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= e($m['title']) ?> <?= milestone_badge($m['status'], $role) ?><?php if ((int)$m['is_extra'] === 1): ?><?= ui_badge('Ek kalem', 'accent') ?><?php endif; ?><?php if ((int)$m['needs_delivery'] === 0 && (int)$m['no_work'] === 0): ?><?= ui_badge('Yerinde · teslim yok', 'neutral') ?><?php endif; ?></p>
                    <p class="xsmall text-muted" style="margin-top:2px"><?= $m['due_date'] ? 'Hedef ' . format_date($m['due_date']) : '' ?><?= $m['approved_at'] ? ' · onay ' . format_date($m['approved_at']) : '' ?><?= $m['description'] ? ' · ' . e($m['description']) : '' ?></p>
                    <?php if ($role === 'agency' && $m['status'] === 'proposed'): ?>
                        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap" x-data="{ no: false }">
                            <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="extra_approve"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>"><button class="btn btn-primary btn-sm"><i data-lucide="check"></i>Onayla · <?= format_money((float)$m['agency_amount']) ?> + KDV</button></form>
                            <button type="button" class="btn btn-ghost btn-sm" @click="no = !no">Reddet</button>
                            <form x-show="no" x-cloak method="POST" action="" style="display:flex;gap:6px;width:100%"><?= csrf_field() ?><input type="hidden" name="action" value="extra_reject"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>"><input class="input" name="note" placeholder="Nedeniniz (isteğe bağlı)"><button class="btn btn-secondary btn-sm">Gönder</button></form>
                        </div>
                    <?php elseif ($role === 'freelancer' && $is_assignee && $m['status'] === 'pending_freelancer'): ?>
                        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap" x-data="{ no: false }">
                            <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="extra_accept"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>"><button class="btn btn-primary btn-sm"><i data-lucide="check"></i>Kabul et · +<?= format_money((float)$m['fee']) ?></button></form>
                            <button type="button" class="btn btn-ghost btn-sm" @click="no = !no">Kabul etmiyorum</button>
                            <form x-show="no" x-cloak method="POST" action="" style="display:flex;gap:6px;width:100%"><?= csrf_field() ?><input type="hidden" name="action" value="extra_decline"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>"><input class="input" name="note" placeholder="Nedeniniz"><button class="btn btn-secondary btn-sm">Gönder</button></form>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($role === 'freelancer'): ?><span class="money"><?= format_money((float)$m['fee']) ?></span>
                <?php elseif ((float)$m['agency_amount'] > 0 && (int)$m['is_extra'] === 1): ?><span class="money"><?= format_money((float)$m['agency_amount']) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if ($role === 'agency' && $catalog): ?>
        <div x-show="extra" x-cloak class="modal-backdrop" @keydown.escape.window="extra = false">
            <form method="POST" action="" class="modal" @click.outside="extra = false"><?= csrf_field() ?>
                <input type="hidden" name="action" value="add_extra">
                <div class="modal-head"><div><p class="h3">Ek kalem ekle</p><p class="small text-muted">Katalog fiyatıyla işe eklenir; ekip planlamayı yapar.</p></div><button type="button" class="icon-btn" @click="extra = false"><i data-lucide="x"></i></button></div>
                <div class="modal-body stack">
                    <div class="panel" style="padding:4px 0;max-height:300px;overflow-y:auto">
                        <?php foreach ($catalog as $sv): ?>
                            <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:7px 14px">
                                <span class="small"><?= e($sv['name']) ?> <span class="text-muted xsmall">· <?= format_money((float)$sv['agency_price']) ?> / <?= e($sv['unit']) ?></span></span>
                                <input class="input input-sm" type="number" min="0" step="0.5" name="qty[<?= (int)$sv['id'] ?>]" placeholder="0" style="width:80px">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <textarea name="note" rows="2" class="textarea" placeholder="Not: ne için gerekli, teslim beklentisi"></textarea>
                </div>
                <div class="modal-foot"><button type="button" class="btn btn-ghost" @click="extra = false">Vazgeç</button><button class="btn btn-primary">Ekle</button></div>
            </form>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- HİZMETLER -->
    <?php if ($items): ?>
    <section class="card">
        <div class="card-head"><div><p class="card-title">Hizmetler</p><p class="card-sub"><?= $role === 'agency' ? 'Katalog fiyatlarıyla hesaplanmıştır, KDV hariç.' : 'Kalem bazında hakedişiniz.' ?></p></div></div>
        <?php platform_items_table($items, $role, $job); ?>
        <div class="card-foot" style="display:flex;justify-content:space-between;align-items:center">
            <span class="small text-muted"><?= $role === 'agency' ? 'Toplam (KDV hariç)' : 'Toplam hakediş' ?></span>
            <span class="money" style="font-size:16px;font-weight:600"><?= format_money((float)$price, $job['currency']) ?></span>
        </div>
    </section>
    <?php endif; ?>

    <!-- BRIEF -->
    <section class="card">
        <div class="card-head"><p class="card-title">Brief</p></div>
        <div class="card-pad stack">
            <p class="prose-text"><?= e($job['description'] ?? '') ?></p>
            <?php if (!empty($job['deliverables'])): ?>
                <div><p class="eyebrow" style="margin-bottom:6px">Beklenen teslimatlar</p><p class="prose-text"><?= e($job['deliverables']) ?></p></div>
            <?php endif; ?>
            <?php if (!empty($job['agency_notes'])): ?>
                <div class="panel" style="padding:12px 14px"><p class="eyebrow" style="margin-bottom:6px">Ek notlar</p><p class="prose-text small"><?= e($job['agency_notes']) ?></p></div>
            <?php endif; ?>
            <?php $refs = array_filter(preg_split('/\s+/', trim((string)$job['reference_links']))); if ($refs): ?>
                <div>
                    <p class="eyebrow" style="margin-bottom:6px">Referanslar</p>
                    <div class="stack-sm">
                    <?php foreach ($refs as $ref): ?>
                        <?php if (is_safe_url($ref)): ?>
                            <a href="<?= e($ref) ?>" target="_blank" rel="noopener" class="small link" style="display:flex;gap:6px;align-items:center;word-break:break-all"><i data-lucide="link" style="width:13px;height:13px;flex-shrink:0"></i><?= e($ref) ?></a>
                        <?php else: ?><span class="small text-ink-2"><?= e($ref) ?></span><?php endif; ?>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- TESLİMATLAR -->
    <?php if ($deliveries): ?>
    <section class="card">
        <div class="card-head"><p class="card-title">Teslimatlar</p><span class="xsmall text-muted"><?= count($deliveries) ?> kayıt</span></div>
        <div class="divide">
            <?php foreach ($deliveries as $i => $d): ?>
            <div class="card-pad-sm">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
                    <?php if ((string)$d['url'] === ''): ?>
                        <span class="small" style="display:inline-flex;gap:6px;align-items:center;font-weight:500"><i data-lucide="map-pin-check" style="width:14px;height:14px"></i>Yerinde iş yapıldı bildirimi</span>
                    <?php else: ?>
                    <a href="<?= e($d['url']) ?>" target="_blank" rel="noopener" class="small link" style="display:inline-flex;gap:6px;align-items:center;font-weight:500"><i data-lucide="external-link" style="width:14px;height:14px"></i>Sürüm <?= count($deliveries) - $i ?></a>
                    <?php endif; ?>
                    <?= delivery_status_badge($d['status'], $role) ?>
                </div>
                <?php if (!empty($d['note'])): ?><p class="small prose-text" style="margin-top:6px"><?= e($d['note']) ?></p><?php endif; ?>
                <?php if (!empty($d['feedback'])): ?><p class="small" style="margin-top:6px;color:var(--warning)"><span style="font-weight:500">Geri bildirim:</span> <?= e($d['feedback']) ?></p><?php endif; ?>
                <p class="xsmall text-faint" style="margin-top:6px"><?= format_date($d['created_at'], true) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- İŞ KAYDI -->
    <?php if ($events): ?>
    <section class="card" id="kayit">
        <div class="card-head"><div><p class="card-title">İş kaydı</p><p class="card-sub">Bu işte yapılan her işlem, kim tarafından ve ne zaman yapıldığıyla.</p></div><a class="btn btn-ghost btn-sm" href="?id=<?= $job_id ?>&export=1"><i data-lucide="download"></i>CSV</a></div>
        <div class="card-pad"><?= render_job_events($events, $role) ?></div>
    </section>
    <?php endif; ?>

    <?php if ($role === 'agency' && in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)): ?>
    <details class="small" style="color:var(--muted)">
        <summary style="cursor:pointer">İşi iptal et</summary>
        <form method="POST" action="" style="display:flex;gap:8px;margin-top:10px;max-width:520px" onsubmit="return confirm('İş iptal edilsin mi?');"><?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="text" name="reason" class="input" placeholder="İptal nedeni">
            <button class="btn btn-danger">İptal et</button>
        </form>
    </details>
    <?php endif; ?>
</div>

<!-- SAĞ SÜTUN -->
<aside class="stack-lg" style="min-width:0">
    <section class="card">
        <div class="card-head"><p class="card-title">Plan</p></div>
        <div class="card-pad">
            <dl class="dl" style="grid-template-columns:110px minmax(0,1fr)">
                <dt>Çekim / başlangıç</dt><dd><?= $job['start_date'] ? format_date($job['start_date']) : '—' ?></dd>
                <dt>Teslim</dt><dd>
                    <?= $job['deadline'] ? format_date($job['deadline']) : '—' ?>
                    <?php if ($days_left !== null && !$is_closed): ?>
                        <span class="xsmall" style="margin-left:4px;color:<?= $days_left < 0 ? 'var(--danger)' : 'var(--muted)' ?>"><?= $days_left < 0 ? abs($days_left) . ' gün gecikti' : ($days_left === 0 ? 'bugün' : "{$days_left} gün") ?></span>
                    <?php endif; ?>
                </dd>
                <dt>Lokasyon</dt><dd><?= e($location ?: '—') ?></dd>
                <?php if ((int)$job['raw_delivery'] === 1): ?><dt>Ham görüntü</dt><dd>Teslim edilecek</dd><?php endif; ?>
                <?php if ($role === 'agency'): ?>
                    <dt>Giriş tarihi</dt><dd><?= format_date($job['created_at']) ?></dd>
                    <dt>Revizyon</dt><dd class="num"><?= (int)$job['revision_count'] ?> / <?= $free_revs ?> ücretsiz<?php if ($rev_fee > 0): ?><span class="xsmall text-muted"> · sonrası <?= format_money($rev_fee) ?></span><?php endif; ?></dd>
                <?php else: ?>
                    <dt>Seviye şartı</dt><dd><?= tier_badge($job['min_tier']) ?></dd>
                <?php endif; ?>
            </dl>
            <?php if ((int)$job['is_rush'] === 1): ?>
                <div class="alert alert-warning" style="margin-top:14px"><i data-lucide="zap"></i><div class="xsmall">
                    <?= $role === 'agency'
                        ? 'Acil iş olarak işlendi' . ((float)$job['rush_fee'] > 0 ? '; ' . format_money((float)$job['rush_fee'], $job['currency']) . ' acil iş farkı uygulandı.' : '.')
                        : 'Acil iş: hakedişe acil iş primi dahildir. Tarihlere özellikle dikkat edin.' ?>
                </div></div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($role === 'agency' || $is_assignee): ?>
    <section class="card" id="sorun" x-data="{ open: false }">
        <div class="card-pad-sm">
            <?php if ($my_issue && $my_issue['status'] === 'open'): ?>
                <p class="small" style="display:flex;gap:8px;align-items:center"><i data-lucide="triangle-alert" style="width:15px;height:15px;color:var(--warning)"></i><strong>Sorun bildiriminiz inceleniyor</strong></p>
                <p class="xsmall text-muted" style="margin-top:4px"><?= e(ISSUE_REASONS[$my_issue['reason']] ?? '') ?> · <?= time_ago($my_issue['created_at']) ?></p>
            <?php else: ?>
                <?php if ($my_issue && $my_issue['status'] === 'resolved'): ?><p class="xsmall text-muted" style="margin-bottom:8px">Son bildiriminiz çözüldü: <?= e(mb_strimwidth((string)$my_issue['resolution'], 0, 120, '…')) ?></p><?php endif; ?>
                <button type="button" class="small" style="display:flex;gap:8px;align-items:center;color:var(--muted)" @click="open = !open"><i data-lucide="flag" style="width:14px;height:14px"></i>Sorun bildir</button>
                <form x-show="open" x-cloak method="POST" action="" class="stack-sm" style="margin-top:10px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="issue">
                    <select name="reason" class="select"><?php foreach (ISSUE_REASONS as $rk => $rl): ?><option value="<?= $rk ?>"><?= e($rl) ?></option><?php endforeach; ?></select>
                    <textarea name="details" rows="3" required class="textarea" placeholder="Ne oldu? Ekibimiz en kısa sürede dönecek."></textarea>
                    <button class="btn btn-secondary btn-sm">Bildir</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="card" id="mesajlar">
        <div class="card-head"><div><p class="card-title">Yazışma</p><p class="card-sub"><?= e(site_setting('platform_team_name')) ?><?= $role === 'agency' ? '' : ' · size özel' ?></p></div></div>
        <div class="card-pad">
            <?php platform_message_box($messages, $role, $role === 'agency' ? 'Ekibe mesaj yazın' : ($is_assignee ? 'Ekibe mesaj yazın' : 'İşle ilgili sorunuzu yazın')); ?>
        </div>
    </section>
</aside>
</div>
<?php platform_footer();
