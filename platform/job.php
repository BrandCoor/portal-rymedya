<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - SİPARİŞ / İŞ DETAYI (AJANS & FREELANCER)
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
    set_flash('error', 'Sipariş bulunamadı veya görüntüleme yetkiniz yok.');
    redirect(BASE_URL . '/platform/index.php');
}

$self_url = BASE_URL . "/platform/job.php?id={$job_id}";
$back = function (string $type, string $msg, string $anchor = '') use ($self_url) {
    set_flash($type, $msg);
    redirect($self_url . $anchor);
};

// ====================================================================
// İŞLEMLER
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $is_assignee = $role === 'freelancer' && (int)$job['assigned_user_id'] === $uid;

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
            job_publish($job_id);
            log_job_change($job_id, 'agency', $uid, 'Fiyat teklifi', 'Onay bekliyor', 'Onaylandı');
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
            log_job_change($job_id, 'agency', $uid, 'Fiyat teklifi', 'Onay bekliyor', 'Geri çevrildi');
            notify_staff("{$job['job_code']} fiyat teklifi ajans tarafından geri çevrildi: \"" . mb_substr($reason, 0, 120) . "\"", $job_id);
            $back('success', 'Geri bildiriminiz iletildi. Ekibimiz teklifi güncelleyecek.');
        }
        if ($action === 'cancel' && in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)) {
            $reason = trim($_POST['reason'] ?? '') ?: 'Ajans tarafından iptal edildi';
            $db->prepare("UPDATE platform_jobs SET status = 'cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $job_id]);
            $db->prepare("UPDATE platform_applications SET status = 'rejected', reviewed_at = NOW(), reject_reason = 'Sipariş müşteri tarafından iptal edildi.' WHERE job_id = ? AND status = 'pending'")->execute([$job_id]);
            log_job_change($job_id, 'agency', $uid, 'Durum', job_status_label($job['status'], 'agency'), 'İptal edildi');
            notify_staff("{$job['job_code']} ajans tarafından iptal edildi: {$reason}", $job_id);
            $back('success', 'Sipariş iptal edildi.');
        }
        if ($action === 'approve_delivery' && $job['status'] === 'delivered') {
            $rating = (int)($_POST['rating'] ?? 0);
            job_complete($job, $rating >= 1 && $rating <= 5 ? $rating : null, trim($_POST['review'] ?? ''));
            notify_staff("{$job['job_code']} ajans tarafından onaylandı ve kapandı." . ($rating ? " Puan: {$rating}/5" : ''), $job_id);
            $back('success', 'Teslimatı onayladınız, sipariş tamamlandı.');
        }
        if ($action === 'request_revision' && $job['status'] === 'delivered') {
            $feedback = trim($_POST['feedback'] ?? '');
            if (mb_strlen($feedback) < 5) {
                $back('error', 'Revizyon notlarınızı yazın.');
            }
            job_request_revision($job, $feedback);
            notify_staff("{$job['job_code']} için ajans revizyon istedi: \"" . mb_substr($feedback, 0, 140) . "\"", $job_id);
            $back('success', 'Revizyon talebiniz iletildi.');
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
            if (job_assign_freelancer($job_id, $uid, true)) {
                log_job_change($job_id, 'freelancer', $uid, 'Atama', '—', 'Freelancer işi aldı');
                notify_staff("{$job['job_code']} işini {$me_name} aldı.", $job_id);
                $back('success', 'İş size atandı. Brief\'i inceleyip hazır olduğunuzda "İşe başla" deyin.');
            }
            set_flash('error', 'Bu iş az önce başka biri tarafından alındı.');
            redirect(BASE_URL . '/platform/pool.php');
        }

        if ($action === 'apply' && $job['status'] === 'open' && $job['dispatch_mode'] === 'application'
            && (!$my_application || $my_application['status'] === 'withdrawn')) {
            if (job_visibility_reason($job, $profile) !== null) {
                $back('error', 'Bu işe teklif verme yetkiniz yok.');
            }
            if (!$cap['can_take']) {
                $back('error', "Aktif iş limitiniz dolu ({$cap['active']}/{$cap['limit']}). Kapasiteniz açıldığında teklif verebilirsiniz.");
            }
            $fee  = parse_money($_POST['proposed_fee'] ?? '');
            $from = valid_date($_POST['available_from'] ?? '');
            $note = trim($_POST['note'] ?? '');
            if (mb_strlen($note) < 10) {
                $back('error', 'Teklifinize kısa bir açıklama ekleyin (en az bir cümle).', '#teklif');
            }
            if ($from && $job['deadline'] && $from > $job['deadline']) {
                $back('error', 'Müsaitlik tarihiniz teslim tarihinden sonra olamaz.', '#teklif');
            }
            if ($my_application) {
                $db->prepare("UPDATE platform_applications SET proposed_fee = ?, available_from = ?, note = ?, status = 'pending', reject_reason = NULL, reviewed_at = NULL, created_at = NOW() WHERE id = ?")
                   ->execute([$fee > 0 ? $fee : null, $from, $note, $my_application['id']]);
            } else {
                $db->prepare("INSERT INTO platform_applications (job_id, user_id, proposed_fee, available_from, note, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())")
                   ->execute([$job_id, $uid, $fee > 0 ? $fee : null, $from, $note]);
            }
            notify_staff("{$job['job_code']} için yeni teklif: {$me_name}" . ($fee > 0 ? ' · ' . format_money($fee) : ''), $job_id);
            $back('success', 'Teklifiniz iletildi. Değerlendirme sonucunu bildirim olarak alacaksınız.');
        }

        if ($action === 'withdraw_application' && $my_application && $my_application['status'] === 'pending') {
            $db->prepare("UPDATE platform_applications SET status = 'withdrawn' WHERE id = ?")->execute([$my_application['id']]);
            notify_staff("{$job['job_code']} teklifi {$me_name} tarafından geri çekildi.", $job_id);
            $back('success', 'Teklifiniz geri çekildi.');
        }

        if ($is_assignee) {
            if ($action === 'start' && $job['status'] === 'assigned') {
                $db->prepare("UPDATE platform_jobs SET status = 'in_progress' WHERE id = ?")->execute([$job_id]);
                notify_staff("{$job['job_code']} üretime başladı ({$me_name}).", $job_id);
                notify_contact_users($job['agency_contact_id'], "{$job['job_code']} siparişiniz üretime başladı.", "/platform/job.php?id={$job_id}", $job_id);
                $back('success', 'İyi çalışmalar. Bitirdiğinizde teslim bağlantısını gönderin.');
            }
            if ($action === 'release' && in_array($job['status'], ['assigned', 'in_progress'], true)) {
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') {
                    $back('error', 'İşi bırakma nedeninizi yazın.');
                }
                job_unassign($job_id, 'freelancer');
                recompute_freelancer_metrics($uid);
                log_job_change($job_id, 'freelancer', $uid, 'Atama', $me_name, 'İş bırakıldı, havuza döndü');
                notify_staff("{$job['job_code']} işini {$me_name} bıraktı: \"" . mb_substr($reason, 0, 120) . "\". İş havuza döndü.", $job_id);
                set_flash('success', 'İşi bıraktınız. Bu durum güvenilirlik puanınıza yansır.');
                redirect(BASE_URL . '/platform/jobs.php');
            }
            if ($action === 'deliver' && in_array($job['status'], ['in_progress', 'revision'], true)) {
                $url = trim($_POST['url'] ?? '');
                if (!is_safe_url($url)) {
                    $back('error', 'Geçerli bir teslim bağlantısı girin (https://...).', '#teslim');
                }
                job_submit_delivery($job, $url, trim($_POST['note'] ?? ''), 'freelancer', $uid);
                notify_staff("{$job['job_code']} teslim edildi ({$me_name})" . (platform_setting('platform_qa_required') === '1' ? ', kalite kontrol bekliyor.' : ', ajansa iletildi.'), $job_id);
                $back('success', 'Teslimatınız gönderildi.');
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
$items       = job_items($job_id);
$changes     = job_changes($job_id);
$free_revs   = (int)platform_setting('platform_max_revisions');

if ($role === 'agency') {
    $messages   = job_messages($job_id, 'agency');
    $deliveries = job_deliveries($job_id, ['sent', 'approved', 'revision']);
    $scope      = agency_edit_scope($job);
    $price      = $job['agency_price'] ?? $job['budget'];
    $price_cap  = $job['agency_price'] !== null ? 'KDV hariç' : 'bütçe beklentiniz';
} else {
    $messages   = job_messages($job_id, 'freelancer', $uid);
    $deliveries = array_values(array_filter(job_deliveries($job_id), fn($d) => (int)$d['submitted_by_user_id'] === $uid));
    $cap        = freelancer_capacity($profile);
    $price      = $job['freelancer_fee'];
    $price_cap  = 'hakediş';
    // Atanmadan önceki değişiklikler (fiyat, kalem) freelancer'ı ilgilendirmez
    if ($job['assigned_at']) {
        $changes = array_values(array_filter($changes, fn($c) => strtotime($c['created_at']) >= strtotime($job['assigned_at'])));
    } else {
        $changes = [];
    }
    if ($my_application) {
        $ap = $db->prepare("SELECT * FROM platform_applications WHERE id = ?");
        $ap->execute([$my_application['id']]);
        $my_application = $ap->fetch();
    }
}

// Akış adımları
if ($role === 'agency') {
    $steps = ['submitted' => 'Sipariş'];
    if ($job['pricing_source'] === 'custom') {
        $steps['quote_sent'] = 'Teklif';
    }
    $steps += ['open' => 'Ekip ataması', 'in_progress' => 'Üretim', 'delivered' => 'Teslim', 'completed' => 'Tamamlandı'];
    $map = ['assigned' => 'in_progress', 'qa_review' => 'in_progress', 'revision' => 'in_progress'];
} else {
    $steps = ['assigned' => 'Atandı', 'in_progress' => 'Üretim', 'qa_review' => 'Kalite kontrol', 'delivered' => 'Müşteri onayı', 'completed' => 'Tamamlandı'];
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
            <a href="<?= BASE_URL ?>/platform/<?= $role === 'freelancer' && !$is_assignee ? 'pool.php' : 'jobs.php' ?>"><?= $role === 'agency' ? 'Siparişler' : ($is_assignee ? 'İşlerim' : 'İş havuzu') ?></a>
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
            <strong><?= $job['pricing_source'] === 'custom' ? 'Talebiniz fiyatlandırılıyor.' : 'Siparişiniz onay bekliyor.' ?></strong>
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
                <p class="eyebrow">Onayınız bekleniyor</p>
                <h2 class="h2" style="margin-top:6px">Siparişiniz teslim edildi</h2>
                <p class="small text-muted" style="margin-top:6px">Aşağıdaki teslim bağlantısını inceleyin. Onayladığınızda sipariş kapanır ve faturanız düzenlenir.</p>
                <?php if ($deliveries): ?>
                    <a href="<?= e($deliveries[0]['url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="margin-top:14px"><i data-lucide="external-link"></i>Teslimatı aç</a>
                <?php endif; ?>
                <div class="hairline" style="margin:18px 0"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary" @click="mode = 'approve'"><i data-lucide="check"></i>Onayla ve kapat</button>
                    <button type="button" class="btn btn-secondary" @click="mode = 'revision'">Revizyon iste <span class="text-muted num" style="margin-left:4px"><?= (int)$job['revision_count'] ?>/<?= $free_revs ?></span></button>
                </div>
                <?php if ((int)$job['revision_count'] >= $free_revs): ?>
                    <p class="xsmall text-muted" style="margin-top:10px">Ücretsiz revizyon hakkınız doldu; ek revizyonlar ayrıca fiyatlandırılabilir.</p>
                <?php endif; ?>

                <form x-show="mode === 'approve'" x-cloak method="POST" action="" class="stack" style="margin-top:18px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve_delivery">
                    <input type="hidden" name="rating" :value="rating">
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
                    <div><button class="btn btn-primary">Onayı gönder</button></div>
                </form>
                <form x-show="mode === 'revision'" x-cloak method="POST" action="" class="stack-sm" style="margin-top:18px"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_revision">
                    <textarea name="feedback" rows="4" required class="textarea" placeholder="Değişmesini istediğiniz noktalar. Zaman kodu ile yazmanız süreci hızlandırır (ör. 00:12 logo daha büyük)."></textarea>
                    <div><button class="btn btn-primary">Revizyonu gönder</button></div>
                </form>
            </div>
        </section>

    <?php elseif ($job['status'] === 'completed'): ?>
        <div class="alert alert-success"><i data-lucide="circle-check"></i><div>
            <strong>Sipariş tamamlandı<?= $job['completed_at'] ? ' · ' . format_date($job['completed_at']) : '' ?>.</strong>
            <?php if ($job['agency_rating']): ?>Değerlendirmeniz: <?= render_stars((float)$job['agency_rating']) ?><?php endif; ?>
            <?php if (!empty($job['sales_invoice_id'])): ?>
                <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= (int)$job['sales_invoice_id'] ?>" target="_blank" class="link" style="margin-left:6px">Faturayı görüntüle</a>
            <?php endif; ?>
        </div></div>

    <?php elseif ($job['status'] === 'cancelled'): ?>
        <div class="alert alert-danger"><i data-lucide="circle-x"></i><div><strong>Sipariş iptal edildi.</strong> <?= e($job['cancel_reason'] ?? '') ?></div></div>

    <?php else: ?>
        <div class="alert alert-neutral"><i data-lucide="activity"></i><div>
            <strong><?= e(job_status_label($job['status'], 'agency')) ?>.</strong>
            <?= $job['status'] === 'open'
                ? 'Siparişiniz uygun ekip üyeleriyle eşleştiriliyor. Atama yapıldığında bildirim alacaksınız.'
                : 'Siparişiniz ' . e(site_setting('platform_team_name')) . ' tarafından yürütülüyor. Teslim edildiğinde bildirim alacaksınız.' ?>
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
                        <dt>Müsaitlik</dt><dd><?= $my_application['available_from'] ? format_date($my_application['available_from']) . ' itibarıyla' : '—' ?></dd>
                        <dt>Not</dt><dd class="prose-text"><?= e($my_application['note'] ?? '') ?></dd>
                    </dl>
                    <form method="POST" action="" style="margin-top:16px" onsubmit="return confirm('Teklif geri çekilsin mi?');"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="withdraw_application">
                        <button class="btn btn-ghost btn-sm">Teklifi geri çek</button>
                    </form>
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
                    <p class="small text-muted" style="margin-top:6px">Ekip gelen teklifleri değerlendirip atama yapar. Sonuç ve gerekçe size bildirilir.</p>
                    <form method="POST" action="" class="stack" style="margin-top:16px"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="apply">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="field">
                                <label class="label">Ücret öneriniz</label>
                                <div class="input-group"><input type="text" inputmode="decimal" name="proposed_fee" class="input" placeholder="<?= e(number_format((float)$job['freelancer_fee'], 0, ',', '.')) ?>"><span class="addon">TL</span></div>
                                <span class="hint">Boş bırakırsanız belirtilen hakediş geçerli olur.</span>
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
            <p class="eyebrow">İş size atandı</p>
            <h2 class="h2" style="margin-top:6px">Brief'i inceleyip başlayın</h2>
            <p class="small text-muted" style="margin-top:6px">Sorularınızı sağdaki yazışma alanından ekibe iletin. Başladığınızda müşteriye bilgi verilir.</p>
            <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="start">
                    <button class="btn btn-primary"><i data-lucide="play"></i>İşe başla</button></form>
                <button type="button" class="btn btn-ghost" @click="release = !release">İşi bırak</button>
            </div>
            <form x-show="release" x-cloak method="POST" action="" class="stack-sm" style="margin-top:14px"><?= csrf_field() ?>
                <input type="hidden" name="action" value="release">
                <div class="alert alert-warning"><i data-lucide="triangle-alert"></i><div>Bırakılan işler güvenilirlik puanınızı düşürür ve iş havuza geri döner.</div></div>
                <textarea name="reason" rows="2" required class="textarea" placeholder="Bırakma nedeniniz"></textarea>
                <button class="btn btn-danger btn-sm">İşi bırak</button>
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
        <section class="card card-emphasis" id="teslim" x-data="{ release: false }"><div class="card-pad">
            <p class="eyebrow"><?= $job['status'] === 'revision' ? 'Revize teslim' : 'Teslim' ?></p>
            <h2 class="h2" style="margin-top:6px">Teslim bağlantısını gönderin</h2>
            <?php if ($days_left !== null): ?>
                <p class="small" style="margin-top:6px;color:<?= $days_left < 0 ? 'var(--danger)' : ($days_left <= 1 ? 'var(--warning)' : 'var(--muted)') ?>">
                    <?= $days_left < 0 ? abs($days_left) . ' gün gecikti' : ($days_left === 0 ? 'Teslim günü bugün' : "Teslime {$days_left} gün var") ?> · zamanında teslim performans puanınızın %25'idir.
                </p>
            <?php endif; ?>
            <form method="POST" action="" class="stack" style="margin-top:16px"><?= csrf_field() ?>
                <input type="hidden" name="action" value="deliver">
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

    <?php elseif ($is_assignee && in_array($job['status'], ['qa_review', 'delivered'], true)): ?>
        <div class="alert alert-info"><i data-lucide="eye"></i><div>
            <strong><?= e(job_status_label($job['status'], 'freelancer')) ?>.</strong>
            <?= $job['status'] === 'qa_review' ? 'Ekibimiz teslimatınızı kontrol ediyor. Onaylanırsa müşteriye iletilir.' : 'Teslimatınız müşteriye iletildi; onay bekleniyor.' ?>
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
                    <a href="<?= e($d['url']) ?>" target="_blank" rel="noopener" class="small link" style="display:inline-flex;gap:6px;align-items:center;font-weight:500"><i data-lucide="external-link" style="width:14px;height:14px"></i>Sürüm <?= count($deliveries) - $i ?></a>
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

    <!-- DEĞİŞİKLİK GEÇMİŞİ -->
    <?php if ($changes): ?>
    <section class="card">
        <div class="card-head"><p class="card-title">Değişiklik geçmişi</p></div>
        <div class="card-pad"><?= render_job_changes($changes, $role) ?></div>
    </section>
    <?php endif; ?>

    <?php if ($role === 'agency' && in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)): ?>
    <details class="small" style="color:var(--muted)">
        <summary style="cursor:pointer">Siparişi iptal et</summary>
        <form method="POST" action="" style="display:flex;gap:8px;margin-top:10px;max-width:520px" onsubmit="return confirm('Sipariş iptal edilsin mi?');"><?= csrf_field() ?>
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
                <?php if ($role === 'agency'): ?>
                    <dt>Sipariş tarihi</dt><dd><?= format_date($job['created_at']) ?></dd>
                    <dt>Revizyon</dt><dd class="num"><?= (int)$job['revision_count'] ?> / <?= $free_revs ?> ücretsiz</dd>
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

    <section class="card" id="mesajlar">
        <div class="card-head"><div><p class="card-title">Yazışma</p><p class="card-sub"><?= e(site_setting('platform_team_name')) ?><?= $role === 'agency' ? '' : ' · size özel' ?></p></div></div>
        <div class="card-pad">
            <?php platform_message_box($messages, $role, $role === 'agency' ? 'Ekibe mesaj yazın' : ($is_assignee ? 'Ekibe mesaj yazın' : 'İşle ilgili sorunuzu yazın')); ?>
        </div>
    </section>
</aside>
</div>
<?php platform_footer();
