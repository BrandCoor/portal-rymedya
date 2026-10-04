<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - YENİ SİPARİŞ (AJANS)
 * ====================================================================
 * Katalog siparişi: hizmet seç → fiyat anında hesaplanır → sipariş ver.
 * Özel talep     : katalog dışı işler için ekip fiyat teklifi hazırlar.
 * Termin kuralı  : aynı gün / çok yakın tarihli işler engellenir;
 *                  yakın tarihli işler acil iş farkıyla kabul edilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile  = require_platform_role('agency');
$cid      = (int)$_SESSION['client_contact_id'];
$uid      = (int)$_SESSION['client_user_id'];
$services = catalog_services();
$mode     = (($_POST['mode'] ?? $_GET['mode'] ?? 'catalog') === 'custom' || !$services) ? 'custom' : 'catalog';
$errors   = [];
$old      = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date  = valid_date($_POST['start_date'] ?? '');
    $deadline    = valid_date($_POST['deadline'] ?? '');
    $is_remote   = isset($_POST['is_remote']) ? 1 : 0;
    $city        = trim($_POST['location_city'] ?? '');
    $items       = $mode === 'catalog' ? build_order_items((array)($_POST['qty'] ?? [])) : [];

    if ($mode === 'catalog' && !$items) $errors[] = 'En az bir hizmet seçin.';
    if ($title === '') $errors[] = 'Sipariş başlığı zorunlu.';
    if (mb_strlen($description) < 20) $errors[] = 'Brief en az birkaç cümle olmalı.';
    if (!$deadline) $errors[] = 'Teslim tarihi zorunlu.';
    if ($start_date && $deadline && $deadline < $start_date) $errors[] = 'Teslim tarihi başlangıçtan önce olamaz.';
    if (!$is_remote && $city === '') $errors[] = 'Şehir girin veya "uzaktan yapılabilir" seçin.';

    $lead = assess_lead_time($start_date, $deadline, $items);
    if ($lead['level'] === 'block') {
        $errors[] = $lead['message'];
    }
    $rush = $lead['level'] === 'warn';
    if ($rush && empty($_POST['rush_ack'])) {
        $errors[] = 'Bu iş acil kapsamına giriyor. Devam etmek için acil iş koşullarını onaylayın.';
    }

    if (!$errors) {
        $category = $mode === 'catalog'
            ? dominant_category($items)
            : (array_key_exists($_POST['category'] ?? '', JOB_CATEGORIES) ? $_POST['category'] : 'other');
        $price  = $mode === 'catalog' ? price_order($items, $rush) : null;
        $budget = $mode === 'custom' ? parse_money($_POST['budget'] ?? '') : 0;
        $auto   = $mode === 'catalog' && platform_setting('platform_auto_publish') === '1';
        $policy = default_job_policy($items, $category, (bool)$is_remote);
        $code   = generate_job_code();

        $db->prepare("
            INSERT INTO platform_jobs (job_code, agency_contact_id, created_by_user_id, title, category, description, deliverables, reference_links,
                location_city, location_detail, is_remote, start_date, deadline, budget, agency_price, freelancer_fee, currency, status, pricing_source,
                is_rush, rush_fee, visibility, dispatch_mode, min_tier, priority_tier, priority_hours, skill_match_only, city_match_only, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TRY', 'submitted', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $code, $cid, $uid, $title, $category, $description, trim($_POST['deliverables'] ?? ''), trim($_POST['reference_links'] ?? ''),
            $is_remote ? null : $city, trim($_POST['location_detail'] ?? ''), $is_remote, $start_date, $deadline,
            $budget > 0 ? $budget : null, $price['agency_price'] ?? null, $price['freelancer_fee'] ?? null,
            $mode, $rush ? 1 : 0, $price['rush_fee'] ?? 0,
            $policy['visibility'], $policy['dispatch_mode'], $policy['min_tier'], $policy['priority_tier'], $policy['priority_hours'],
            $policy['skill_match_only'], $policy['city_match_only'],
        ]);
        $job_id = (int)$db->lastInsertId();
        if ($items) {
            save_job_items($job_id, $items);
        }

        $company = $_SESSION['client_user']['company_name'] ?? '';
        if ($auto) {
            job_publish($job_id);
            notify_staff("Yeni sipariş yayına alındı: {$code} · {$title} ({$company}) · " . format_money($price['agency_price']) . ($rush ? ' · ACİL' : ''), $job_id);
            set_flash('success', "Siparişiniz alındı ({$code}). Ekip ataması başladı.");
        } else {
            notify_staff(($mode === 'custom' ? 'Özel teklif talebi: ' : 'Yeni sipariş onay bekliyor: ') . "{$code} · {$title} ({$company})" . ($rush ? ' · ACİL' : ''), $job_id);
            set_flash('success', $mode === 'custom'
                ? "Talebiniz alındı ({$code}). Ekibimiz fiyat teklifini kısa süre içinde paylaşacak."
                : "Siparişiniz alındı ({$code}). Ekip onayının ardından üretime alınacak.");
        }
        redirect(BASE_URL . "/platform/job.php?id={$job_id}");
    }
}

// İstemci tarafı canlı hesaplama için veri
$svc_js = [];
foreach ($services as $s) {
    $svc_js[(int)$s['id']] = ['name' => $s['name'], 'unit' => $s['unit'], 'price' => (float)$s['agency_price'], 'lead' => (int)$s['min_lead_hours']];
}
$rules = lead_time_rules();
$qty_old = [];
foreach ((array)($old['qty'] ?? []) as $k => $v) {
    if ((float)$v > 0) $qty_old[(int)$k] = (float)$v;
}
$val = fn($k) => e(is_array($old[$k] ?? null) ? '' : ($old[$k] ?? ''));
$grouped = [];
foreach ($services as $s) {
    $grouped[$s['category']][] = $s;
}

platform_header('Yeni sipariş', 'new');
?>
<?php
$order_cfg = [
    'svc' => $svc_js, 'qty' => (object)$qty_old,
    'rules' => ['block' => (int)$rules['block_hours'], 'warn' => (int)$rules['warn_hours'], 'sameDay' => (bool)$rules['block_same_day'], 'rush' => (float)$rules['rush_percent']],
    'start' => $old['start_date'] ?? '', 'deadline' => $old['deadline'] ?? '',
    'remote' => !empty($old['is_remote']), 'rushAck' => !empty($old['rush_ack']),
];
?>
<div x-data="orderForm(<?= e(json_encode($order_cfg, JSON_UNESCAPED_UNICODE)) ?>)" x-init="init()">
    <div class="page-head">
        <div>
            <div class="crumb"><a href="<?= BASE_URL ?>/platform/jobs.php">Siparişler</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Yeni</span></div>
            <h1 class="h1"><?= $mode === 'custom' ? 'Özel iş talebi' : 'Yeni sipariş' ?></h1>
            <p class="sub"><?= $mode === 'custom' ? 'Katalogda olmayan işler için ekibimiz size özel fiyat hazırlar.' : 'Hizmetleri seçin, tutar anında hesaplanır. Sipariş verdiğiniz anda ekip ataması başlar.' ?></p>
        </div>
        <?php if ($services): ?>
        <div class="seg">
            <a href="?mode=catalog" class="<?= $mode === 'catalog' ? 'is-active' : '' ?>">Katalogdan</a>
            <a href="?mode=custom" class="<?= $mode === 'custom' ? 'is-active' : '' ?>">Özel talep</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger" style="margin-bottom:20px"><i data-lucide="alert-circle"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
    <?php endif; ?>

    <form method="POST" action="" class="grid grid-cols-1 lg:grid-cols-3 gap-6" @submit="if (lead.level === 'block') { $event.preventDefault(); window.scrollTo({top: 0, behavior: 'smooth'}); }">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="<?= $mode ?>">

        <div class="lg:col-span-2 stack-lg">
            <?php if ($mode === 'catalog'): ?>
            <!-- 1. HİZMETLER -->
            <section class="card">
                <div class="card-head">
                    <div><p class="card-title">1 · Hizmetler</p><p class="card-sub">Birden fazla hizmeti aynı siparişte birleştirebilirsiniz.</p></div>
                </div>
                <div class="divide">
                    <?php foreach ($grouped as $cat => $list): ?>
                    <div style="padding:16px 20px">
                        <p class="eyebrow" style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><i data-lucide="<?= job_category_icon($cat) ?>" style="width:14px;height:14px"></i><?= e(job_category_label($cat)) ?></p>
                        <div class="stack-sm">
                            <?php foreach ($list as $s): $sid = (int)$s['id']; ?>
                            <div class="option-card" :class="(qty[<?= $sid ?>] || 0) > 0 ? 'is-selected' : ''" style="align-items:center;cursor:default">
                                <div style="flex:1;min-width:0">
                                    <p style="font-weight:500"><?= e($s['name']) ?></p>
                                    <p class="xsmall text-muted" style="margin-top:2px">
                                        <?= e($s['description'] ?? '') ?>
                                        <?php if ((int)$s['min_lead_hours'] > (int)platform_setting('platform_min_lead_hours')): ?>
                                            <span style="white-space:nowrap"> · en az <?= (int)$s['min_lead_hours'] ?> saat önceden</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div style="text-align:right;margin-right:12px;white-space:nowrap">
                                    <span class="money"><?= format_money((float)$s['agency_price']) ?></span>
                                    <span class="xsmall text-muted">/ <?= e($s['unit']) ?></span>
                                </div>
                                <div class="stepper-input">
                                    <button type="button" @click="dec(<?= $sid ?>)" aria-label="Azalt"><i data-lucide="minus" style="width:14px;height:14px"></i></button>
                                    <input type="number" min="0" step="0.5" name="qty[<?= $sid ?>]" x-model.number="qty[<?= $sid ?>]" @input="recalc()">
                                    <button type="button" @click="inc(<?= $sid ?>)" aria-label="Artır"><i data-lucide="plus" style="width:14px;height:14px"></i></button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- 2. DETAYLAR -->
            <section class="card">
                <div class="card-head"><div><p class="card-title"><?= $mode === 'catalog' ? '2 · ' : '1 · ' ?>İş detayları</p><p class="card-sub">Brief ne kadar net olursa üretim o kadar hızlı ilerler.</p></div></div>
                <div class="card-pad stack">
                    <div class="field">
                        <label class="label">Sipariş başlığı <span class="req">*</span></label>
                        <input class="input" type="text" name="title" required value="<?= $val('title') ?>" placeholder="Örn. Yaz kampanyası ürün çekimi ve 30 sn reklam kurgusu">
                    </div>
                    <?php if ($mode === 'custom'): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="field">
                            <label class="label">İş türü</label>
                            <select class="select" name="category">
                                <?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>" <?= ($old['category'] ?? '') === $ck ? 'selected' : '' ?>><?= e($cv['label']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label class="label">Bütçe beklentiniz</label>
                            <div class="input-group"><input class="input" type="number" step="0.01" min="0" name="budget" value="<?= $val('budget') ?>" placeholder="Opsiyonel"><span class="addon">₺ + KDV</span></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="field">
                        <label class="label">Brief <span class="req">*</span></label>
                        <textarea class="textarea" name="description" rows="5" required placeholder="Marka, hedef kitle, konsept, mekan, oyuncu ihtiyacı, kullanılacak mecralar..."><?= $val('description') ?></textarea>
                    </div>
                    <div class="field">
                        <label class="label">Beklenen teslimatlar</label>
                        <textarea class="textarea" name="deliverables" rows="3" placeholder="1 adet 30 sn 16:9 master&#10;3 adet 15 sn dikey versiyon&#10;Ham görüntüler"><?= $val('deliverables') ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="field">
                            <label class="label">Çekim / başlangıç tarihi</label>
                            <input class="input" type="date" name="start_date" x-model="start" @change="assess()" min="<?= date('Y-m-d') ?>" value="<?= $val('start_date') ?>">
                            <span class="hint">Yerinde çekim gerektiren işlerde zorunlu sayılır.</span>
                        </div>
                        <div class="field">
                            <label class="label">Teslim tarihi <span class="req">*</span></label>
                            <input class="input" type="date" name="deadline" required x-model="deadline" @change="assess()" min="<?= date('Y-m-d') ?>" value="<?= $val('deadline') ?>">
                        </div>
                    </div>

                    <template x-if="lead.level === 'block'">
                        <div class="alert alert-danger"><i data-lucide="calendar-x"></i><span x-text="lead.message"></span></div>
                    </template>
                    <template x-if="lead.level === 'warn'">
                        <div class="alert alert-warning" style="flex-direction:column;gap:8px">
                            <div style="display:flex;gap:10px"><i data-lucide="alarm-clock"></i><span x-text="lead.message"></span></div>
                            <label class="check" style="margin-left:26px"><input type="checkbox" name="rush_ack" value="1" x-model="rushAck"><span class="small">Acil iş koşullarını kabul ediyorum.</span></label>
                        </div>
                    </template>

                    <div class="panel" style="padding:14px 16px">
                        <label class="check"><input type="checkbox" name="is_remote" value="1" x-model="remote" <?= !empty($old['is_remote']) ? 'checked' : '' ?>><span>Uzaktan yapılabilir <span class="text-muted">(kurgu, renk, animasyon gibi işler)</span></span></label>
                        <div x-show="!remote" class="grid grid-cols-1 sm:grid-cols-3 gap-3" style="margin-top:12px">
                            <div class="field"><label class="label">Şehir <span class="req">*</span></label><input class="input" type="text" name="location_city" value="<?= $val('location_city') ?>" placeholder="İstanbul"></div>
                            <div class="field sm:col-span-2"><label class="label">Lokasyon</label><input class="input" type="text" name="location_detail" value="<?= $val('location_detail') ?>" placeholder="Stüdyo, mekan veya ilçe"></div>
                        </div>
                    </div>

                    <div class="field">
                        <label class="label">Referanslar</label>
                        <textarea class="textarea" name="reference_links" rows="2" placeholder="Örnek videolar, moodboard veya brief dosyası bağlantıları"><?= $val('reference_links') ?></textarea>
                    </div>
                </div>
            </section>
        </div>

        <!-- ÖZET -->
        <aside>
            <div class="card sticky-summary">
                <div class="card-head"><p class="card-title">Sipariş özeti</p></div>
                <div class="card-pad stack-sm">
                    <?php if ($mode === 'catalog'): ?>
                        <template x-if="!lines().length"><p class="small text-muted">Henüz hizmet seçmediniz.</p></template>
                        <template x-for="l in lines()" :key="l.id">
                            <div style="display:flex;justify-content:space-between;gap:12px" class="small">
                                <span style="min-width:0"><span x-text="l.name"></span> <span class="text-muted" x-text="'× ' + fmtQty(l.qty) + ' ' + l.unit"></span></span>
                                <span class="num" style="white-space:nowrap" x-text="money(l.total)"></span>
                            </div>
                        </template>
                        <div class="hairline" style="margin:12px 0"></div>
                        <div style="display:flex;justify-content:space-between" class="small"><span class="text-muted">Ara toplam</span><span class="num" x-text="money(subtotal())"></span></div>
                        <div x-show="lead.level === 'warn' && rules.rush > 0" style="display:flex;justify-content:space-between;color:var(--warning)" class="small"><span x-text="'Acil iş farkı (%' + rules.rush + ')'"></span><span class="num" x-text="money(rushFee())"></span></div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:8px"><span style="font-weight:500">Toplam</span><span class="money" style="font-size:22px" x-text="money(total())"></span></div>
                        <p class="xsmall text-muted">KDV hariç. Fatura iş teslim edilip onaylandığında kesilir.</p>
                    <?php else: ?>
                        <p class="small text-ink-2">Talebiniz ekibimize iletilir. Fiyat teklifimizi onayladığınızda iş üretime alınır.</p>
                    <?php endif; ?>

                    <div class="hairline" style="margin:14px 0"></div>
                    <ul class="stack-sm xsmall text-muted" style="list-style:none;padding:0">
                        <li style="display:flex;gap:8px"><i data-lucide="shield-check" style="width:14px;height:14px;flex-shrink:0"></i>Teslimat size ulaşmadan önce kalite kontrolden geçer.</li>
                        <li style="display:flex;gap:8px"><i data-lucide="repeat" style="width:14px;height:14px;flex-shrink:0"></i><?= (int)platform_setting('platform_max_revisions') ?> revizyon hakkı dahil.</li>
                        <li style="display:flex;gap:8px"><i data-lucide="calendar-clock" style="width:14px;height:14px;flex-shrink:0"></i>En az <?= $rules['block_hours'] ?> saat önceden sipariş; <?= $rules['warn_hours'] ?> saatten kısa süreli işler acil sayılır.</li>
                    </ul>
                    <button type="submit" class="btn btn-accent btn-lg btn-block" style="margin-top:16px" :disabled="lead.level === 'block' || (lead.level === 'warn' && !rushAck)<?= $mode === 'catalog' ? ' || !lines().length' : '' ?>">
                        <?= $mode === 'catalog' ? 'Siparişi ver' : 'Teklif iste' ?>
                    </button>
                </div>
            </div>
        </aside>
    </form>
</div>

<script src="<?= BASE_URL ?>/assets/js/order-form.js?v=<?= UI_ASSET_VERSION ?>"></script>
<?php platform_footer();
