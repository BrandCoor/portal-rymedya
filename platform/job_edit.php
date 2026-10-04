<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞ DÜZENLEME (AJANS)
 * ====================================================================
 * Ekip ataması yapılana kadar (full):
 *   başlık, brief, teslimatlar, referanslar, ek notlar, tarihler,
 *   lokasyon ve katalog kalemleri (tutar yeniden hesaplanır)
 * Üretim sürecinde (limited):
 *   referanslar ve ek notlar; teslim tarihi yalnızca ileri alınabilir
 * Teslimden sonra: düzenleme kapalı
 * Her değişiklik geçmişe yazılır; ekip ve atanmış freelancer bilgilendirilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile = require_platform_role('agency');
$uid     = (int)$_SESSION['client_user_id'];
$cid     = (int)$_SESSION['client_contact_id'];
$job_id  = (int)($_GET['id'] ?? 0);
$job     = get_job($job_id);

if (!$job || (int)$job['agency_contact_id'] !== $cid) {
    set_flash('error', 'İş bulunamadı.');
    redirect(BASE_URL . '/platform/jobs.php');
}
$scope    = agency_edit_scope($job);
$job_url  = BASE_URL . "/platform/job.php?id={$job_id}";
if ($scope === 'locked') {
    set_flash('error', 'Teslim edilmiş, tamamlanmış veya iptal edilmiş işler düzenlenemez.');
    redirect($job_url);
}

$is_catalog = $job['pricing_source'] === 'catalog';
$cur_items  = job_items($job_id);
$services   = $is_catalog && $scope === 'full' ? catalog_services() : [];
$errors     = [];

$cur_qty = [];
foreach ($cur_items as $it) {
    if ($it['service_id']) {
        $cur_qty[(int)$it['service_id']] = (float)$it['quantity'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $changes = [];   // [label, old, new]
    $set     = [];   // column => value
    $norm    = fn($v) => trim(str_replace("\r\n", "\n", (string)$v));

    // Her iki kapsamda da düzenlenebilenler
    foreach (['reference_links' => 'Referanslar', 'agency_notes' => 'Ek notlar'] as $col => $label) {
        $new = $norm($_POST[$col] ?? '');
        if ($new !== $norm($job[$col] ?? '')) {
            $set[$col] = $new;
            $changes[] = [$label, $job[$col], $new];
        }
    }

    if ($scope === 'limited') {
        $deadline = valid_date($_POST['deadline'] ?? '');
        if ($deadline && $deadline !== $job['deadline']) {
            if ($job['deadline'] && $deadline < $job['deadline']) {
                $errors[] = 'Üretim sürecinde teslim tarihi yalnızca ileri alınabilir. Daha erken teslim için ekiple yazışın.';
            } else {
                $set['deadline'] = $deadline;
                $changes[] = ['Teslim tarihi', format_date($job['deadline']), format_date($deadline)];
            }
        }
    } else {
        $title       = trim($_POST['title'] ?? '');
        $description = $norm($_POST['description'] ?? '');
        $start_date  = valid_date($_POST['start_date'] ?? '');
        $deadline    = valid_date($_POST['deadline'] ?? '');
        $is_remote   = isset($_POST['is_remote']) ? 1 : 0;
        $city        = $is_remote ? null : trim($_POST['location_city'] ?? '');
        $detail      = trim($_POST['location_detail'] ?? '');

        if ($title === '') $errors[] = 'İş başlığı zorunlu.';
        if (mb_strlen($description) < 20) $errors[] = 'Brief en az birkaç cümle olmalı.';
        if (!$deadline) $errors[] = 'Teslim tarihi zorunlu.';
        if ($start_date && $deadline && $deadline < $start_date) $errors[] = 'Teslim tarihi başlangıçtan önce olamaz.';
        if (!$is_remote && $city === '') $errors[] = 'Şehir girin veya "uzaktan yapılabilir" seçin.';

        // Kalemler
        $items = null;
        if ($is_catalog) {
            $new_qty = [];
            foreach ((array)($_POST['qty'] ?? []) as $sid => $q) {
                $q = round((float)str_replace(',', '.', (string)$q), 2);
                if ($q > 0) $new_qty[(int)$sid] = $q;
            }
            ksort($new_qty);
            $old_sorted = $cur_qty;
            ksort($old_sorted);
            if ($new_qty != $old_sorted) {
                $items = build_order_items($new_qty);
                if (!$items) $errors[] = 'En az bir hizmet seçili olmalı.';
            }
        }
        $items_for_rules = $items ?? array_map(fn($i) => $i + ['min_lead_hours' => (int)($i['min_lead_hours'] ?? 0)], $cur_items);

        // Termin: tarih veya kalem değiştiyse yeniden değerlendirilir
        $dates_changed = $start_date !== ($job['start_date'] ?: null) || $deadline !== ($job['deadline'] ?: null);
        $rush = (int)$job['is_rush'] === 1;
        if ($dates_changed || $items !== null) {
            $lead = assess_lead_time($start_date, $deadline, $items_for_rules);
            // Kalem değişikliği de aynı kurala tabidir: başlangıca çok az kalmışsa kapsam değiştirilemez
            if ($lead['level'] === 'block') {
                $errors[] = $lead['message'];
            }
            $rush = $lead['level'] === 'warn';
            if ($rush && (int)$job['is_rush'] !== 1 && empty($_POST['rush_ack'])) {
                $errors[] = 'Yeni tarih acil iş kapsamına giriyor. Devam etmek için acil iş koşullarını onaylayın.';
            }
        }

        if (!$errors) {
            $fields = [
                'title' => ['Başlık', $title], 'description' => ['Brief', $description],
                'deliverables' => ['Teslimatlar', $norm($_POST['deliverables'] ?? '')],
            ];
            foreach ($fields as $col => [$label, $new]) {
                if ($new !== $norm($job[$col] ?? '')) {
                    $set[$col] = $new;
                    $changes[] = [$label, $job[$col], $new];
                }
            }
            if ($start_date !== ($job['start_date'] ?: null)) {
                $set['start_date'] = $start_date;
                $changes[] = ['Başlangıç tarihi', format_date($job['start_date']), format_date($start_date)];
            }
            if ($deadline !== ($job['deadline'] ?: null)) {
                $set['deadline'] = $deadline;
                $changes[] = ['Teslim tarihi', format_date($job['deadline']), format_date($deadline)];
            }
            $old_loc = (int)$job['is_remote'] === 1 ? 'Uzaktan' : trim(($job['location_city'] ?? '') . ' ' . ($job['location_detail'] ?? ''));
            $new_loc = $is_remote ? 'Uzaktan' : trim($city . ' ' . $detail);
            if ($old_loc !== $new_loc) {
                $set += ['is_remote' => $is_remote, 'location_city' => $city, 'location_detail' => $detail];
                $changes[] = ['Lokasyon', $old_loc, $new_loc];
            }

            // Fiyat: katalog işlerinde kalem veya acil durumu değiştiyse yeniden hesaplanır
            if ($is_catalog && ($items !== null || $rush !== ((int)$job['is_rush'] === 1))) {
                $calc_items = $items ?? array_map(fn($i) => [
                    'quantity' => (float)$i['quantity'], 'agency_unit_price' => (float)$i['agency_unit_price'], 'freelancer_unit_fee' => (float)$i['freelancer_unit_fee'],
                ], $cur_items);
                $price = price_order($calc_items, $rush);
                if ($items !== null) {
                    $describe = fn(array $list) => implode(', ', array_map(fn($i) => $i['name'] . ' × ' . qty_label((float)$i['quantity']), $list)) ?: '—';
                    $changes[] = ['Hizmet kalemleri', $describe($cur_items), $describe($items)];
                    $set['category'] = dominant_category($items, $job['category']);
                    // Yeni kalemler daha yüksek seviye gerektiriyorsa havuz şartı yükselir
                    $policy = default_job_policy($items, $set['category'], (bool)($set['is_remote'] ?? $job['is_remote']));
                    if (tier_rank($policy['min_tier']) > tier_rank($job['min_tier'])) {
                        $set['min_tier'] = $policy['min_tier'];
                    }
                }
                if (abs($price['agency_price'] - (float)$job['agency_price']) > 0.009) {
                    $changes[] = ['İş tutarı', format_money((float)$job['agency_price'], $job['currency']), format_money($price['agency_price'], $job['currency'])];
                }
                $set += ['agency_price' => $price['agency_price'], 'freelancer_fee' => $price['freelancer_fee'], 'rush_fee' => $price['rush_fee']];
            }
            if ($rush !== ((int)$job['is_rush'] === 1)) {
                $set['is_rush'] = $rush ? 1 : 0;
                $changes[] = ['Acil iş', (int)$job['is_rush'] === 1 ? 'Evet' : 'Hayır', $rush ? 'Evet' : 'Hayır'];
                if (!$is_catalog) {
                    $set['rush_fee'] = 0;
                }
            }
        }
    }

    if (!$errors && !$changes) {
        set_flash('info', 'Değişiklik yapılmadı.');
        redirect($job_url);
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $cols = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($set)));
            $db->prepare("UPDATE platform_jobs SET {$cols} WHERE id = ?")->execute([...array_values($set), $job_id]);
            if ($scope === 'full' && $is_catalog && isset($items) && $items !== null) {
                save_job_items($job_id, $items);
            }
            if (job_milestones($job_id)) {
                milestones_sync($job_id);
            }
            foreach ($changes as [$label, $old, $new]) {
                log_job_change($job_id, 'agency', $uid, $label, $old, $new);
            }
            // Özel teklif bekleyen işte kapsam değiştiyse teklif yeniden hazırlanır
            $requote = $job['status'] === 'quote_sent' && array_intersect(array_column($changes, 0), ['Brief', 'Teslimatlar', 'Başlangıç tarihi', 'Teslim tarihi', 'Lokasyon', 'Acil iş']);
            if ($requote) {
                $db->prepare("UPDATE platform_jobs SET status = 'submitted' WHERE id = ?")->execute([$job_id]);
                log_job_change($job_id, 'system', null, 'Fiyat teklifi', 'Onay bekliyor', 'Kapsam değiştiği için yeniden hazırlanacak');
            }
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }

        $labels = implode(', ', array_unique(array_column($changes, 0)));
        notify_staff("{$job['job_code']} ajans tarafından güncellendi: {$labels}" . (!empty($requote) ? ' · teklif yeniden hazırlanmalı' : ''), $job_id);
        if (!empty($job['assigned_user_id'])) {
            $fl_labels = implode(', ', array_diff(array_unique(array_column($changes, 0)), JOB_CHANGE_PRICE_LABELS));
            if ($fl_labels !== '') {
                notify_user((int)$job['assigned_user_id'], "{$job['job_code']} güncellendi: {$fl_labels}", "/platform/job.php?id={$job_id}", $job_id);
            }
        }
        set_flash('success', !empty($requote) ? 'Değişiklikler kaydedildi. Kapsam değiştiği için ekibimiz fiyat teklifini güncelleyecek.' : 'Değişiklikler kaydedildi.');
        redirect($job_url);
    }
}

// ====================================================================
// FORM
// ====================================================================
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST + $job : $job;
$val = fn($k) => e(is_array($src[$k] ?? null) ? '' : ($src[$k] ?? ''));
$svc_js = [];
$grouped = [];
foreach ($services as $s) {
    $svc_js[(int)$s['id']] = ['name' => $s['name'], 'unit' => $s['unit'], 'price' => (float)$s['agency_price'], 'lead' => (int)$s['min_lead_hours']];
    $grouped[$s['category']][] = $s;
}
// Katalogdan kaldırılmış ama işte duran kalemler de düzenlenebilsin
foreach ($cur_items as $it) {
    if ($it['service_id'] && $services && !isset($svc_js[(int)$it['service_id']])) {
        $svc_js[(int)$it['service_id']] = ['name' => $it['name'], 'unit' => $it['unit'], 'price' => (float)$it['agency_unit_price'], 'lead' => (int)($it['min_lead_hours'] ?? 0)];
    }
}
$qty_form = $cur_qty;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qty'])) {
    $qty_form = [];
    foreach ((array)$_POST['qty'] as $k => $v) {
        if ((float)$v > 0) $qty_form[(int)$k] = (float)$v;
    }
}
$rules = lead_time_rules();
$order_cfg = [
    'svc' => (object)$svc_js, 'qty' => (object)$qty_form,
    'rules' => ['block' => (int)$rules['block_hours'], 'warn' => (int)$rules['warn_hours'], 'sameDay' => (bool)$rules['block_same_day'], 'rush' => (float)$rules['rush_percent']],
    'start' => $src['start_date'] ?? '', 'deadline' => $src['deadline'] ?? '',
    'remote' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['is_remote']) : (int)$job['is_remote'] === 1,
    'rushAck' => !empty($_POST['rush_ack']),
    'keepDates' => ['start' => $job['start_date'] ?? '', 'deadline' => $job['deadline'] ?? ''],
    'wasRush' => (int)$job['is_rush'] === 1,
];

platform_header('İşi düzenle · ' . $job['job_code'], 'jobs');
?>
<div x-data="orderForm(<?= e(json_encode($order_cfg, JSON_UNESCAPED_UNICODE)) ?>)" x-init="init()">
    <div class="page-head">
        <div>
            <div class="crumb">
                <a href="<?= BASE_URL ?>/platform/jobs.php">İşler</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i>
                <a href="<?= $job_url ?>" class="code-tag"><?= e($job['job_code']) ?></a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Düzenle</span>
            </div>
            <h1 class="h1">İşi düzenle</h1>
            <p class="sub"><?= $scope === 'full'
                ? 'Ekip ataması yapılana kadar tüm detayları değiştirebilirsiniz. Kalem değişikliklerinde güncel katalog fiyatları uygulanır.'
                : 'İş üretimde. Referans ve not ekleyebilir, teslim tarihini ileri alabilirsiniz. Diğer değişiklikler için ekiple yazışın.' ?></p>
        </div>
        <?= ui_badge($scope === 'full' ? 'Tam düzenleme' : 'Sınırlı düzenleme', $scope === 'full' ? 'success' : 'warning', true) ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger" style="margin-bottom:20px"><i data-lucide="alert-circle"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
    <?php endif; ?>
    <?php if ($job['status'] === 'quote_sent'): ?>
        <div class="alert alert-warning" style="margin-bottom:20px"><i data-lucide="info"></i><div>Bu iş için fiyat teklifi gönderildi. Brief, tarih veya lokasyon değişikliği teklifin yeniden hazırlanmasını gerektirir.</div></div>
    <?php endif; ?>
    <?php if ($scope === 'full' && $job['status'] === 'open'): ?>
        <div class="alert alert-info" style="margin-bottom:20px"><i data-lucide="info"></i><div>İş ekip ataması aşamasında. Kaydettiğiniz değişiklikler işi değerlendiren ekip üyelerine yansır.</div></div>
    <?php endif; ?>

    <form method="POST" action="" class="grid grid-cols-1 lg:grid-cols-3 gap-6" @submit="if (lead.level === 'block') { $event.preventDefault(); window.scrollTo({top: 0, behavior: 'smooth'}); }">
        <?= csrf_field() ?>
        <div class="lg:col-span-2 stack-lg" style="min-width:0">

        <?php if ($scope === 'full'): ?>
            <?php if ($services): ?>
            <section class="card">
                <div class="card-head"><div><p class="card-title">Hizmetler</p><p class="card-sub">Miktarı sıfırlanan kalem işten çıkarılır.</p></div></div>
                <div class="divide">
                    <?php foreach ($grouped as $cat => $list): ?>
                    <div style="padding:16px 20px">
                        <p class="eyebrow" style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><i data-lucide="<?= job_category_icon($cat) ?>" style="width:14px;height:14px"></i><?= e(job_category_label($cat)) ?></p>
                        <div class="stack-sm">
                            <?php foreach ($list as $s): $sid = (int)$s['id']; ?>
                            <div class="option-card" :class="(qty[<?= $sid ?>] || 0) > 0 ? 'is-selected' : ''" style="align-items:center;cursor:default">
                                <div style="flex:1;min-width:0">
                                    <p style="font-weight:500"><?= e($s['name']) ?></p>
                                    <?php if (!empty($s['description'])): ?><p class="xsmall text-muted" style="margin-top:2px"><?= e($s['description']) ?></p><?php endif; ?>
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
                    <?php foreach ($cur_items as $it): if ($it['service_id'] && !in_array((int)$it['service_id'], array_column($services, 'id'))): $sid = (int)$it['service_id']; ?>
                    <div style="padding:16px 20px">
                        <div class="option-card is-selected" style="align-items:center;cursor:default">
                            <div style="flex:1;min-width:0"><p style="font-weight:500"><?= e($it['name']) ?></p><p class="xsmall text-muted">Katalogdan kaldırıldı; yalnızca miktarı azaltılabilir veya çıkarılabilir.</p></div>
                            <div class="stepper-input">
                                <button type="button" @click="dec(<?= $sid ?>)"><i data-lucide="minus" style="width:14px;height:14px"></i></button>
                                <input type="number" min="0" max="<?= (float)$it['quantity'] ?>" step="0.5" name="qty[<?= $sid ?>]" x-model.number="qty[<?= $sid ?>]" @input="recalc()">
                                <button type="button" disabled style="opacity:.3"><i data-lucide="plus" style="width:14px;height:14px"></i></button>
                            </div>
                        </div>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
            </section>
            <?php elseif ($cur_items): ?>
            <section class="card">
                <div class="card-head"><div><p class="card-title">Hizmetler</p><p class="card-sub">Bu işin kalemleri ekibimiz tarafından fiyatlandırıldı. Kapsam değişikliği için ekiple yazışın.</p></div></div>
                <?php platform_items_table($cur_items, 'agency', $job); ?>
            </section>
            <?php endif; ?>

            <section class="card">
                <div class="card-head"><p class="card-title">İş detayları</p></div>
                <div class="card-pad stack">
                    <div class="field">
                        <label class="label">İş başlığı <span class="req">*</span></label>
                        <input class="input" type="text" name="title" required value="<?= $val('title') ?>">
                    </div>
                    <div class="field">
                        <label class="label">Brief <span class="req">*</span></label>
                        <textarea class="textarea" name="description" rows="6" required><?= $val('description') ?></textarea>
                    </div>
                    <div class="field">
                        <label class="label">Beklenen teslimatlar</label>
                        <textarea class="textarea" name="deliverables" rows="3"><?= $val('deliverables') ?></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="field">
                            <label class="label">Çekim / başlangıç tarihi</label>
                            <input class="input" type="date" name="start_date" x-model="start" @change="assess()">
                        </div>
                        <div class="field">
                            <label class="label">Teslim tarihi <span class="req">*</span></label>
                            <input class="input" type="date" name="deadline" required x-model="deadline" @change="assess()">
                        </div>
                    </div>
                    <template x-if="lead.level === 'block'">
                        <div class="alert alert-danger"><i data-lucide="calendar-x"></i><span x-text="lead.message"></span></div>
                    </template>
                    <template x-if="lead.level === 'warn'">
                        <div class="alert alert-warning" style="flex-direction:column;gap:8px">
                            <div style="display:flex;gap:10px"><i data-lucide="alarm-clock"></i><span x-text="lead.message"></span></div>
                            <label class="check" style="margin-left:26px" x-show="!wasRush"><input type="checkbox" name="rush_ack" value="1" x-model="rushAck"><span class="small">Acil iş koşullarını kabul ediyorum.</span></label>
                        </div>
                    </template>
                    <div class="panel" style="padding:14px 16px">
                        <label class="check"><input type="checkbox" name="is_remote" value="1" x-model="remote"><span>Uzaktan yapılabilir</span></label>
                        <div x-show="!remote" class="grid grid-cols-1 sm:grid-cols-3 gap-3" style="margin-top:12px">
                            <div class="field"><label class="label">Şehir <span class="req">*</span></label><input class="input" type="text" name="location_city" value="<?= $val('location_city') ?>"></div>
                            <div class="field sm:col-span-2"><label class="label">Lokasyon</label><input class="input" type="text" name="location_detail" value="<?= $val('location_detail') ?>"></div>
                        </div>
                    </div>
                </div>
            </section>
        <?php else: /* limited */ ?>
            <section class="card">
                <div class="card-head"><p class="card-title">Teslim tarihi</p></div>
                <div class="card-pad stack-sm">
                    <div class="field" style="max-width:260px">
                        <label class="label">Yeni teslim tarihi</label>
                        <input class="input" type="date" name="deadline" min="<?= e($job['deadline'] ?? date('Y-m-d')) ?>" value="<?= $val('deadline') ?>">
                    </div>
                    <p class="hint">Mevcut teslim tarihi <?= format_date($job['deadline']) ?>. Yalnızca ileri bir tarih seçebilirsiniz; değişiklik ekibe bildirilir.</p>
                </div>
            </section>
        <?php endif; ?>

            <section class="card">
                <div class="card-head"><div><p class="card-title">Referanslar ve notlar</p><p class="card-sub">Üretim sürecinde de güncelleyebilirsiniz.</p></div></div>
                <div class="card-pad stack">
                    <div class="field">
                        <label class="label">Referanslar</label>
                        <textarea class="textarea" name="reference_links" rows="3" placeholder="Her satıra bir bağlantı"><?= $val('reference_links') ?></textarea>
                    </div>
                    <div class="field">
                        <label class="label">Ek notlar</label>
                        <textarea class="textarea" name="agency_notes" rows="4" placeholder="Brief'e eklemek istedikleriniz: logo dosyası, renk kodları, müşteri geri bildirimleri"><?= $val('agency_notes') ?></textarea>
                    </div>
                </div>
            </section>
        </div>

        <aside>
            <div class="card sticky-summary">
                <div class="card-head"><p class="card-title">Özet</p></div>
                <div class="card-pad stack-sm">
                    <?php if ($scope === 'full' && $services): ?>
                        <template x-for="l in lines()" :key="l.id">
                            <div style="display:flex;justify-content:space-between;gap:12px" class="small">
                                <span style="min-width:0"><span x-text="l.name"></span> <span class="text-muted" x-text="'× ' + fmtQty(l.qty)"></span></span>
                                <span class="num" style="white-space:nowrap" x-text="money(l.total)"></span>
                            </div>
                        </template>
                        <div class="hairline" style="margin:12px 0"></div>
                        <div x-show="isRush() && rules.rush > 0" style="display:flex;justify-content:space-between;color:var(--warning)" class="small"><span x-text="'Acil iş farkı (%' + rules.rush + ')'"></span><span class="num" x-text="money(rushFee())"></span></div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline"><span style="font-weight:500">Yeni toplam</span><span class="money" style="font-size:20px" x-text="money(total())"></span></div>
                        <p class="xsmall text-muted">Mevcut tutar: <?= format_money((float)$job['agency_price'], $job['currency']) ?> · KDV hariç</p>
                    <?php else: ?>
                        <dl class="dl" style="grid-template-columns:100px 1fr">
                            <dt>Durum</dt><dd><?= job_status_badge($job['status'], 'agency') ?></dd>
                            <dt>Tutar</dt><dd class="money"><?= $job['agency_price'] !== null ? format_money((float)$job['agency_price'], $job['currency']) : '—' ?></dd>
                            <dt>Teslim</dt><dd><?= format_date($job['deadline']) ?></dd>
                        </dl>
                    <?php endif; ?>
                    <div class="hairline" style="margin:14px 0"></div>
                    <p class="xsmall text-muted">Kaydettiğiniz her değişiklik iş geçmişine eklenir ve ekibe bildirilir.</p>
                    <button type="submit" class="btn btn-primary btn-block" style="margin-top:12px" :disabled="lead.level === 'block' || (lead.level === 'warn' && !wasRush && !rushAck)<?= $scope === 'full' && $services ? ' || !lines().length' : '' ?>">Değişiklikleri kaydet</button>
                    <a href="<?= $job_url ?>" class="btn btn-ghost btn-block">Vazgeç</a>
                </div>
            </div>
        </aside>
    </form>
</div>
<script src="<?= BASE_URL ?>/assets/js/order-form.js?v=<?= UI_ASSET_VERSION ?>"></script>
<?php platform_footer();
