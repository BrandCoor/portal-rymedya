<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞ DETAYI (AJANS & FREELANCER)
 * ====================================================================
 * Ajans  : fiyat onayı, iptal, teslim onayı / revizyon, puan, mesaj
 * Freelancer: işi al / başvur, başla, bırak, teslim et, mesaj
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

$job_id = (int)($_GET['id'] ?? 0);
$job = get_job($job_id);

// ====================================================================
// ERİŞİM KONTROLÜ
// ====================================================================
$my_application = null;
if ($job && $role === 'freelancer') {
    $ap = $db->prepare("SELECT * FROM platform_applications WHERE job_id = ? AND user_id = ?");
    $ap->execute([$job_id, $uid]);
    $my_application = $ap->fetch() ?: null;
}
$allowed = $job && (
    ($role === 'agency' && (int)$job['agency_contact_id'] === $cid) ||
    ($role === 'freelancer' && (freelancer_can_see_job($job, $profile) || $my_application))
);
if (!$allowed) {
    set_flash('error', 'İş bulunamadı veya bu işi görüntüleme yetkiniz yok.');
    redirect(BASE_URL . '/platform/index.php');
}

$is_assignee = $role === 'freelancer' && (int)$job['assigned_user_id'] === $uid;
$self_url = BASE_URL . "/platform/job.php?id={$job_id}";
$back = fn(string $type, string $msg) => [set_flash($type, $msg), redirect($self_url)];

// ====================================================================
// İŞLEMLER
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ---------------- AJANS ----------------
    if ($role === 'agency') {
        if ($action === 'message') {
            $msg = trim($_POST['message'] ?? '');
            if ($msg !== '') {
                add_job_message($job_id, 'agency', 'agency', $uid, $_SESSION['client_user']['company_name'] ?? $me_name, $msg);
                notify_staff("💬 {$job['job_code']} için ajans mesajı: \"" . mb_substr($msg, 0, 120) . "\"", $job_id);
            }
            redirect($self_url . '#mesajlar');
        }
        if ($action === 'approve_quote' && $job['status'] === 'quote_sent') {
            job_publish($job_id);
            notify_staff("✅ {$job['job_code']} fiyatı ajans tarafından onaylandı (" . format_money((float)$job['agency_price'], $job['currency']) . "). Atama yapılabilir.", $job_id);
            $back('success', 'Fiyatı onayladınız. Ekip ataması yapılıyor.');
        }
        if ($action === 'reject_quote' && $job['status'] === 'quote_sent') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                $back('error', 'Lütfen fiyatı neden uygun bulmadığınızı yazınız.');
            }
            $db->prepare("UPDATE platform_jobs SET status = 'submitted' WHERE id = ?")->execute([$job_id]);
            add_job_message($job_id, 'agency', 'agency', $uid, $_SESSION['client_user']['company_name'] ?? $me_name, "Fiyat teklifini uygun bulmadık: {$reason}");
            notify_staff("↩️ {$job['job_code']} fiyat teklifi reddedildi: \"" . mb_substr($reason, 0, 120) . "\"", $job_id);
            $back('success', 'Geri bildiriminiz iletildi. Ekibimiz yeni bir teklif hazırlayacak.');
        }
        if ($action === 'cancel' && in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)) {
            $reason = trim($_POST['reason'] ?? '') ?: 'Ajans tarafından iptal edildi';
            $db->prepare("UPDATE platform_jobs SET status = 'cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $job_id]);
            $db->prepare("UPDATE platform_applications SET status = 'rejected' WHERE job_id = ? AND status = 'pending'")->execute([$job_id]);
            notify_staff("❌ {$job['job_code']} ajans tarafından iptal edildi: {$reason}", $job_id);
            $back('success', 'İş talebi iptal edildi.');
        }
        if ($action === 'approve_delivery' && $job['status'] === 'delivered') {
            $rating = (int)($_POST['rating'] ?? 0);
            job_complete($job, $rating >= 1 && $rating <= 5 ? $rating : null, trim($_POST['review'] ?? ''));
            notify_staff("🏁 {$job['job_code']} ajans tarafından ONAYLANDI ve kapatıldı." . ($rating ? " Puan: {$rating}/5" : ''), $job_id);
            $back('success', 'Teslimatı onayladınız, iş tamamlandı. Teşekkür ederiz!');
        }
        if ($action === 'request_revision' && $job['status'] === 'delivered') {
            $feedback = trim($_POST['feedback'] ?? '');
            if ($feedback === '') {
                $back('error', 'Lütfen revizyon notlarınızı yazınız.');
            }
            job_request_revision($job, $feedback);
            notify_staff("✏️ {$job['job_code']} için ajans revizyon istedi: \"" . mb_substr($feedback, 0, 140) . "\"", $job_id);
            $back('success', 'Revizyon talebiniz iletildi.');
        }
    }

    // ---------------- FREELANCER ----------------
    if ($role === 'freelancer') {
        if ($action === 'take' && $job['status'] === 'open' && $job['dispatch_mode'] === 'first_come') {
            if ((int)$profile['is_available'] !== 1) {
                $back('error', 'Durumunuz "Müsait Değil". Profilinizden değiştirip tekrar deneyin.');
            }
            if (freelancer_active_job_count($uid) >= (int)platform_setting('platform_max_active_jobs')) {
                $back('error', 'Eşzamanlı iş limitinize ulaştınız.');
            }
            if (job_visibility_reason($job, $profile) !== null) {
                $back('error', 'Bu işi alma yetkiniz yok.');
            }
            if (job_assign_freelancer($job_id, $uid, true)) {
                notify_staff("🙋 {$job['job_code']} işini {$me_name} aldı.", $job_id);
                $back('success', 'İş size atandı! Hazır olduğunuzda "İşe Başla" butonuna basın.');
            }
            set_flash('error', 'Üzgünüz, bu iş az önce başka biri tarafından alındı.');
            redirect(BASE_URL . '/platform/pool.php');
        }
        if ($action === 'apply' && $job['status'] === 'open' && $job['dispatch_mode'] === 'application' && !$my_application) {
            if (job_visibility_reason($job, $profile) !== null) {
                $back('error', 'Bu işe başvuru yetkiniz yok.');
            }
            $fee = parse_money($_POST['proposed_fee'] ?? '');
            $db->prepare("INSERT INTO platform_applications (job_id, user_id, proposed_fee, note, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())")
               ->execute([$job_id, $uid, $fee > 0 ? $fee : null, trim($_POST['note'] ?? '')]);
            notify_staff("📝 {$job['job_code']} için yeni başvuru: {$me_name}" . ($fee > 0 ? ' · Önerilen ücret: ' . format_money($fee) : ''), $job_id);
            $back('success', 'Başvurunuz alındı. Seçilirseniz bildirim alacaksınız.');
        }
        if ($action === 'withdraw_application' && $my_application && $my_application['status'] === 'pending') {
            $db->prepare("UPDATE platform_applications SET status = 'withdrawn' WHERE id = ?")->execute([$my_application['id']]);
            $back('success', 'Başvurunuz geri çekildi.');
        }

        if ($is_assignee) {
            if ($action === 'message') {
                $msg = trim($_POST['message'] ?? '');
                if ($msg !== '') {
                    add_job_message($job_id, 'freelancer', 'freelancer', $uid, $me_name, $msg);
                    notify_staff("💬 {$job['job_code']} için freelancer mesajı ({$me_name}): \"" . mb_substr($msg, 0, 120) . "\"", $job_id);
                }
                redirect($self_url . '#mesajlar');
            }
            if ($action === 'start' && $job['status'] === 'assigned') {
                $db->prepare("UPDATE platform_jobs SET status = 'in_progress' WHERE id = ?")->execute([$job_id]);
                notify_staff("▶️ {$job['job_code']} üzerinde çalışmaya başlandı ({$me_name}).", $job_id);
                notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işiniz üzerinde çalışılmaya başlandı.", "/platform/job.php?id={$job_id}", $job_id);
                $back('success', 'Kolay gelsin! Bitirdiğinizde "Teslim Et" ile gönderin.');
            }
            if ($action === 'release' && $job['status'] === 'assigned') {
                job_unassign($job_id);
                notify_staff("↩️ {$job['job_code']} işini {$me_name} bıraktı, iş havuza döndü.", $job_id);
                set_flash('success', 'İşi bıraktınız, iş havuza geri döndü.');
                redirect(BASE_URL . '/platform/index.php');
            }
            if ($action === 'deliver' && in_array($job['status'], ['in_progress', 'revision'], true)) {
                $url = trim($_POST['url'] ?? '');
                if (!is_safe_url($url)) {
                    $back('error', 'Geçerli bir teslim bağlantısı giriniz (https://...).');
                }
                job_submit_delivery($job, $url, trim($_POST['note'] ?? ''), 'freelancer', $uid);
                notify_staff("📦 {$job['job_code']} teslim edildi ({$me_name})" . (platform_setting('platform_qa_required') === '1' ? ' — kalite kontrol bekliyor.' : ' — ajansa iletildi.'), $job_id);
                $back('success', 'Teslimatınız gönderildi.');
            }
        }
    }
    redirect($self_url);
}

// ====================================================================
// GÖRÜNTÜLEME VERİLERİ
// ====================================================================
$job = get_job($job_id);
$is_assignee = $role === 'freelancer' && (int)$job['assigned_user_id'] === $uid;
$channel = $role === 'agency' ? 'agency' : 'freelancer';
$messages = ($role === 'agency' || $is_assignee) ? job_messages($job_id, $channel) : [];

if ($role === 'agency') {
    // Ajans yalnızca kalite kontrolden geçmiş (ajansa gönderilmiş) teslimatları görür
    // (kalite kontrolde geri çevrilenler 'qa_rejected' durumundadır ve ajansa gösterilmez)
    $deliveries = job_deliveries($job_id, ['sent', 'approved', 'revision']);
} else {
    $deliveries = $is_assignee ? job_deliveries($job_id) : [];
}

$price_for_view = $role === 'agency' ? ($job['agency_price'] ?? $job['budget']) : $job['freelancer_fee'];
$show_agency = $role === 'agency' || platform_setting('platform_show_agency_name') === '1';
$free_revisions = (int)platform_setting('platform_max_revisions');

// Ajans için iş akışı adımları
$steps = ['submitted' => 'Talep', 'quote_sent' => 'Fiyat', 'open' => 'Atama', 'in_progress' => 'Üretim', 'delivered' => 'Teslim', 'completed' => 'Tamam'];
$order = ['submitted' => 0, 'quote_sent' => 1, 'open' => 2, 'assigned' => 3, 'in_progress' => 3, 'qa_review' => 3, 'revision' => 3, 'delivered' => 4, 'completed' => 5, 'cancelled' => -1];
$cur = $order[$job['status']] ?? 0;

platform_header($job['job_code'] . ' · ' . $job['title'], 'jobs');
?>
<div class="mb-5">
    <a href="<?= BASE_URL ?>/platform/<?= $role === 'freelancer' && !$is_assignee ? 'pool.php' : 'jobs.php' ?>" class="text-xs font-semibold text-slate-500 hover:text-slate-800">← Geri</a>
    <div class="mt-2 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-xs font-bold text-slate-500"><?= e($job['job_code']) ?></span>
                <?= job_status_badge($job['status'], $role) ?>
                <span class="text-[10px] font-bold text-slate-600 bg-slate-100 px-2 py-0.5 rounded-full"><?= e(job_category_label($job['category'])) ?></span>
            </div>
            <h1 class="text-2xl font-black text-slate-900 mt-1"><?= e($job['title']) ?></h1>
            <?php if ($show_agency && $role === 'freelancer'): ?><p class="text-xs text-slate-500 mt-0.5">🏢 <?= e($job['agency_name'] ?? '-') ?></p><?php endif; ?>
        </div>
        <?php if ($price_for_view !== null): ?>
        <div class="text-left lg:text-right bg-white border border-slate-200 rounded-2xl px-5 py-3">
            <p class="text-[10px] font-bold uppercase text-slate-400">
                <?= $role === 'agency' ? ($job['agency_price'] !== null ? 'İş Bedeli (KDV hariç)' : 'Belirttiğiniz Bütçe') : 'Hakedişiniz' ?>
            </p>
            <p class="text-2xl font-black <?= $role === 'agency' ? 'text-slate-900' : 'text-emerald-600' ?>"><?= format_money((float)$price_for_view, $job['currency']) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($role === 'agency' && $job['status'] !== 'cancelled'): ?>
<!-- İLERLEME ÇUBUĞU -->
<div class="mb-6 bg-white border border-slate-200 rounded-2xl p-4">
    <div class="flex items-center">
        <?php $i = 0; foreach ($steps as $sk => $sl): $done = $cur >= $i; ?>
            <div class="flex flex-col items-center flex-1 relative">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold <?= $done ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-500' ?>"><?= $done && $cur > $i ? '✓' : $i + 1 ?></div>
                <span class="text-[10px] font-bold mt-1 <?= $done ? 'text-indigo-700' : 'text-slate-400' ?>"><?= $sl ?></span>
            </div>
            <?php if ($i < count($steps) - 1): ?><div class="flex-1 h-0.5 -mt-4 <?= $cur > $i ? 'bg-indigo-600' : 'bg-slate-200' ?>"></div><?php endif; ?>
        <?php $i++; endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- SOL: AKSİYON + DETAY -->
    <div class="lg:col-span-2 space-y-6">

        <?php /* =================== AJANS AKSİYONLARI =================== */ if ($role === 'agency'): ?>
            <?php if ($job['status'] === 'submitted'): ?>
                <div class="p-5 bg-sky-50 border border-sky-200 rounded-2xl text-sm text-sky-900">
                    <strong>Talebiniz inceleniyor.</strong> Ekibimiz brief'i değerlendirip fiyat ve planlama bilgisini paylaşacak. Sorularınız için aşağıdan mesaj yazabilirsiniz.
                </div>
            <?php elseif ($job['status'] === 'quote_sent'): ?>
                <div class="p-5 bg-amber-50 border border-amber-300 rounded-2xl" x-data="{ reject: false }">
                    <h2 class="text-sm font-bold text-amber-900">Fiyat Teklifimiz</h2>
                    <p class="text-3xl font-black text-slate-900 mt-1"><?= format_money((float)$job['agency_price'], $job['currency']) ?> <span class="text-xs font-semibold text-slate-500">+ KDV</span></p>
                    <?php if ($job['budget'] !== null): ?><p class="text-xs text-slate-500">Belirttiğiniz bütçe: <?= format_money((float)$job['budget'], $job['currency']) ?></p><?php endif; ?>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="approve_quote">
                            <button class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl">✓ Fiyatı Onayla, İşe Başlansın</button></form>
                        <button type="button" @click="reject = !reject" class="px-5 py-2.5 bg-white border border-slate-300 text-slate-700 text-sm font-bold rounded-xl">Fiyatı Uygun Bulmadım</button>
                    </div>
                    <form x-show="reject" x-cloak method="POST" action="" class="mt-3 space-y-2"><?= csrf_field() ?><input type="hidden" name="action" value="reject_quote">
                        <textarea name="reason" rows="2" required placeholder="Bütçe beklentiniz veya kapsam değişikliği önerinizi yazın" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs"></textarea>
                        <button class="px-4 py-2 bg-slate-900 text-white text-xs font-bold rounded-xl">Gönder</button></form>
                </div>
            <?php elseif ($job['status'] === 'delivered'): ?>
                <div class="p-5 bg-teal-50 border border-teal-300 rounded-2xl" x-data="{ mode: null, rating: 5 }">
                    <h2 class="text-sm font-bold text-teal-900">📦 İşiniz Teslim Edildi</h2>
                    <p class="text-xs text-teal-800 mt-1">Teslim dosyalarını aşağıdan inceleyin. Onayladığınızda iş kapanır ve faturanız oluşturulur.</p>
                    <?php if ((int)$job['revision_count'] >= $free_revisions): ?>
                        <p class="text-[11px] text-amber-700 mt-2">Not: <?= $free_revisions ?> ücretsiz revizyon hakkınız kullanıldı. Ek revizyonlar ayrıca ücretlendirilebilir.</p>
                    <?php endif; ?>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" @click="mode = 'approve'" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl">✓ Onayla ve Kapat</button>
                        <button type="button" @click="mode = 'revision'" class="px-5 py-2.5 bg-white border border-slate-300 text-slate-700 text-sm font-bold rounded-xl">Revizyon İste (<?= (int)$job['revision_count'] ?>/<?= $free_revisions ?>)</button>
                    </div>
                    <form x-show="mode === 'approve'" x-cloak method="POST" action="" class="mt-3 space-y-2"><?= csrf_field() ?><input type="hidden" name="action" value="approve_delivery">
                        <input type="hidden" name="rating" :value="rating">
                        <div class="flex items-center gap-1 text-2xl">
                            <template x-for="n in 5"><button type="button" @click="rating = n" :class="n <= rating ? 'text-amber-500' : 'text-slate-300'">★</button></template>
                        </div>
                        <textarea name="review" rows="2" placeholder="Deneyiminizi kısaca yazın (opsiyonel)" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs"></textarea>
                        <button class="px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-xl">Onayı Gönder</button></form>
                    <form x-show="mode === 'revision'" x-cloak method="POST" action="" class="mt-3 space-y-2"><?= csrf_field() ?><input type="hidden" name="action" value="request_revision">
                        <textarea name="feedback" rows="3" required placeholder="Neyin değişmesini istiyorsunuz? (zaman kodu ile yazarsanız daha hızlı olur: 00:12 logo daha büyük...)" class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs"></textarea>
                        <button class="px-4 py-2 bg-slate-900 text-white text-xs font-bold rounded-xl">Revizyonu Gönder</button></form>
                </div>
            <?php elseif ($job['status'] === 'completed'): ?>
                <div class="p-5 bg-emerald-50 border border-emerald-200 rounded-2xl text-sm text-emerald-900">
                    <strong>İş tamamlandı.</strong> <?= $job['agency_rating'] ? 'Puanınız: ' . render_stars((float)$job['agency_rating']) : '' ?>
                    <?php if (!empty($job['sales_invoice_id'])): ?>
                        <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= (int)$job['sales_invoice_id'] ?>" target="_blank" class="ml-2 font-bold underline">Faturayı Görüntüle</a>
                    <?php endif; ?>
                </div>
            <?php elseif ($job['status'] === 'cancelled'): ?>
                <div class="p-5 bg-rose-50 border border-rose-200 rounded-2xl text-sm text-rose-800"><strong>İş iptal edildi.</strong> <?= e($job['cancel_reason'] ?? '') ?></div>
            <?php else: ?>
                <div class="p-5 bg-white border border-slate-200 rounded-2xl text-sm text-slate-700">
                    <strong><?= e(JOB_STATUSES[$job['status']]['agency']) ?>.</strong> İşiniz RY Medya Prodüksiyon ekibi tarafından yürütülüyor. Teslim edildiğinde bildirim alacaksınız.
                </div>
            <?php endif; ?>

            <?php if (in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)): ?>
            <details class="text-xs">
                <summary class="cursor-pointer text-slate-400 hover:text-rose-600">İş talebini iptal et</summary>
                <form method="POST" action="" class="mt-2 flex gap-2" onsubmit="return confirm('İş talebi iptal edilsin mi?');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                    <input type="text" name="reason" placeholder="İptal nedeni" class="flex-1 py-2 px-3 bg-white border border-slate-200 rounded-xl">
                    <button class="px-4 py-2 bg-rose-600 text-white font-bold rounded-xl">İptal Et</button></form>
            </details>
            <?php endif; ?>

        <?php /* =================== FREELANCER AKSİYONLARI =================== */ else: ?>
            <?php if ($job['status'] === 'open' && !$is_assignee): ?>
                <?php if ($job['dispatch_mode'] === 'first_come'): ?>
                    <div class="p-5 bg-emerald-50 border border-emerald-300 rounded-2xl">
                        <h2 class="text-sm font-bold text-emerald-900">⚡ Bu iş "ilk alan alır" modunda</h2>
                        <p class="text-xs text-emerald-800 mt-1">İşi alırsanız hemen size atanır. Teslim tarihine uyabileceğinizden emin olun.</p>
                        <form method="POST" action="" class="mt-3" onsubmit="return confirm('Bu işi almak istediğinize emin misiniz?');"><?= csrf_field() ?><input type="hidden" name="action" value="take">
                            <button class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl shadow-lg">İşi Al</button></form>
                    </div>
                <?php elseif (!$my_application || $my_application['status'] === 'withdrawn'): ?>
                    <?php if (!$my_application): ?>
                    <form method="POST" action="" class="p-5 bg-indigo-50 border border-indigo-300 rounded-2xl space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="apply">
                        <h2 class="text-sm font-bold text-indigo-900">📝 Bu işe başvurun</h2>
                        <textarea name="note" rows="3" placeholder="Neden bu iş için uygunsunuz? Benzer işleriniz, ekipmanınız, uygunluk tarihleriniz..." class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-xs"></textarea>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="number" step="0.01" min="0" name="proposed_fee" placeholder="Ücret öneriniz (opsiyonel)" class="py-2 px-3 bg-white border border-slate-200 rounded-xl text-xs w-56">
                            <button class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl">Başvur</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <p class="p-4 bg-slate-100 rounded-2xl text-xs text-slate-600">Başvurunuzu geri çektiniz.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="p-5 bg-indigo-50 border border-indigo-200 rounded-2xl text-sm text-indigo-900">
                        <strong>Başvurunuz değerlendiriliyor.</strong>
                        <form method="POST" action="" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw_application">
                            <button class="ml-2 text-xs underline text-slate-500">Başvuruyu geri çek</button></form>
                    </div>
                <?php endif; ?>
            <?php elseif ($is_assignee && $job['status'] === 'assigned'): ?>
                <div class="p-5 bg-blue-50 border border-blue-300 rounded-2xl flex flex-wrap items-center gap-3">
                    <p class="text-sm text-blue-900 flex-1"><strong>İş size atandı.</strong> Brief'i inceleyin, sorularınızı mesajla iletin, hazır olunca başlayın.</p>
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="start">
                        <button class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl">▶ İşe Başla</button></form>
                    <form method="POST" action="" onsubmit="return confirm('İşi bırakırsanız havuza geri döner. Emin misiniz?');"><?= csrf_field() ?><input type="hidden" name="action" value="release">
                        <button class="px-4 py-2.5 text-xs font-bold text-slate-500 hover:text-rose-600">İşi Bırak</button></form>
                </div>
            <?php elseif ($is_assignee && in_array($job['status'], ['in_progress', 'revision'], true)): ?>
                <?php $last_fb = job_deliveries($job_id, ['revision', 'qa_rejected']); ?>
                <?php if ($job['status'] === 'revision' && $last_fb): ?>
                    <div class="p-4 bg-orange-50 border border-orange-300 rounded-2xl text-sm text-orange-900">
                        <strong>Revizyon istendi:</strong> <span class="whitespace-pre-line"><?= e($last_fb[0]['feedback'] ?? '') ?></span>
                    </div>
                <?php endif; ?>
                <form method="POST" action="" class="p-5 bg-white border-2 border-emerald-400 rounded-2xl space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="deliver">
                    <h2 class="text-sm font-bold text-slate-900">📦 Teslim Et</h2>
                    <input type="url" name="url" required placeholder="Teslim bağlantısı (Drive, WeTransfer, Vimeo, Frame.io...)" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                    <textarea name="note" rows="2" placeholder="Teslim notu: dosya listesi, şifre, versiyon bilgisi..." class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                    <button class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl">Teslimatı Gönder</button>
                </form>
            <?php elseif ($is_assignee && in_array($job['status'], ['qa_review', 'delivered'], true)): ?>
                <div class="p-5 bg-purple-50 border border-purple-200 rounded-2xl text-sm text-purple-900">
                    <strong><?= e(JOB_STATUSES[$job['status']]['freelancer']) ?>.</strong> Teslimatınız inceleniyor; sonuç size bildirilecek.
                </div>
            <?php elseif ($is_assignee && $job['status'] === 'completed'): ?>
                <div class="p-5 bg-emerald-50 border border-emerald-200 rounded-2xl text-sm text-emerald-900">
                    <strong>İş tamamlandı! 🎉</strong> Hakedişiniz <a href="<?= BASE_URL ?>/platform/earnings.php" class="font-bold underline">Kazançlarım</a> sayfasında.
                    <?php if ($job['freelancer_rating']): ?><div class="mt-1">Ekip puanınız: <?= render_stars((float)$job['freelancer_rating']) ?></div><?php endif; ?>
                </div>
            <?php elseif ($my_application && $my_application['status'] === 'rejected'): ?>
                <p class="p-4 bg-slate-100 rounded-2xl text-xs text-slate-600">Bu iş için başka bir freelancer seçildi. İlginiz için teşekkürler.</p>
            <?php endif; ?>
        <?php endif; ?>

        <!-- İŞ DETAYI -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 space-y-4">
            <h2 class="text-sm font-bold text-slate-900">İş Detayı</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                <div><p class="text-slate-400 font-bold uppercase text-[10px]">Lokasyon</p><p class="font-semibold"><?= (int)$job['is_remote'] === 1 ? 'Uzaktan' : (e(trim(($job['location_city'] ?? '') . ' ' . ($job['location_detail'] ?? ''))) ?: '-') ?></p></div>
                <div><p class="text-slate-400 font-bold uppercase text-[10px]">Başlangıç / Çekim</p><p class="font-semibold"><?= format_date($job['start_date']) ?></p></div>
                <div><p class="text-slate-400 font-bold uppercase text-[10px]">Teslim Tarihi</p><p class="font-semibold"><?= format_date($job['deadline']) ?></p></div>
                <div><p class="text-slate-400 font-bold uppercase text-[10px]">Oluşturulma</p><p class="font-semibold"><?= format_date($job['created_at']) ?></p></div>
            </div>
            <div><p class="text-slate-400 font-bold uppercase text-[10px] mb-1">Brief</p><p class="text-sm text-slate-800 whitespace-pre-line"><?= e($job['description'] ?? '') ?></p></div>
            <?php if (!empty($job['deliverables'])): ?>
                <div><p class="text-slate-400 font-bold uppercase text-[10px] mb-1">Beklenen Teslimatlar</p><p class="text-sm text-slate-800 whitespace-pre-line"><?= e($job['deliverables']) ?></p></div>
            <?php endif; ?>
            <?php if (!empty($job['reference_links'])): ?>
                <div><p class="text-slate-400 font-bold uppercase text-[10px] mb-1">Referanslar</p>
                    <?php foreach (preg_split('/\s+/', trim($job['reference_links'])) as $ref): ?>
                        <?php if (is_safe_url($ref)): ?><a href="<?= e($ref) ?>" target="_blank" rel="noopener" class="block text-xs text-indigo-600 hover:underline break-all"><?= e($ref) ?></a>
                        <?php elseif ($ref !== ''): ?><span class="text-xs text-slate-600"><?= e($ref) ?> </span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TESLİMATLAR -->
        <?php if ($deliveries): ?>
        <div class="bg-white border border-slate-200 rounded-2xl p-5">
            <h2 class="text-sm font-bold text-slate-900 mb-3">Teslimatlar</h2>
            <div class="space-y-2">
                <?php
                $dl = ['qa' => ['Kalite kontrolde', 'bg-purple-100 text-purple-800'], 'sent' => ['İnceleme bekliyor', 'bg-teal-100 text-teal-800'], 'approved' => ['Onaylandı', 'bg-emerald-100 text-emerald-800'], 'revision' => ['Revizyon istendi', 'bg-orange-100 text-orange-800'], 'qa_rejected' => ['Kalite kontrolden döndü', 'bg-rose-100 text-rose-800']];
                foreach ($deliveries as $d): $ds = $dl[$d['status']] ?? [$d['status'], 'bg-slate-100'];
                ?>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200">
                    <div class="flex items-center justify-between gap-2">
                        <a href="<?= e($d['url']) ?>" target="_blank" rel="noopener" class="text-sm font-bold text-indigo-600 hover:underline break-all">📎 Teslim dosyasını aç</a>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $ds[1] ?>"><?= $ds[0] ?></span>
                    </div>
                    <?php if (!empty($d['note'])): ?><p class="text-xs text-slate-600 mt-1 whitespace-pre-line"><?= e($d['note']) ?></p><?php endif; ?>
                    <?php if (!empty($d['feedback'])): ?><p class="text-xs text-orange-700 mt-1"><strong>Geri bildirim:</strong> <?= e($d['feedback']) ?></p><?php endif; ?>
                    <p class="text-[10px] text-slate-400 mt-1"><?= format_date($d['created_at'], true) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- SAĞ: MESAJLAR -->
    <div id="mesajlar" class="bg-white border border-slate-200 rounded-2xl p-5 h-fit">
        <h2 class="text-sm font-bold text-slate-900 mb-1">Mesajlar</h2>
        <p class="text-[11px] text-slate-400 mb-3">RY Medya platform ekibiyle yazışma</p>
        <?php if ($role === 'agency' || $is_assignee): ?>
            <?php platform_message_box($messages, 'message', $role, 'Ekibe mesajınızı yazın...'); ?>
        <?php else: ?>
            <p class="text-xs text-slate-400 text-center py-6">İş size atandığında ekiple mesajlaşabilirsiniz.</p>
        <?php endif; ?>
    </div>
</div>
<?php platform_footer();
