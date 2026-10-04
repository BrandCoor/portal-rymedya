<?php
/**
 * ====================================================================
 * EK ÜCRETLER: ÜCRETLİ REVİZYON · HAM GÖRÜNTÜ TESLİMİ
 * ====================================================================
 * Ücretsiz revizyon hakkı dolduğunda ajans revizyonu ancak ücretini
 * onaylayarak isteyebilir; ücret işe ek kalem olarak yazılır ve revize
 * edilen aşamanın tutarına eklenir (freelancer payı aşama onayında hakedişe geçer).
 * Revizyon ücreti 0 ise hak dolduktan sonra ajans revizyon isteyemez.
 * Ham görüntü teslimi istenirse işe "Ham görüntü teslimi" kalemi eklenir;
 * ayrı bir teslim aşaması olur. Tutarlar ayarlardan, iş bazında değiştirilebilir.
 */

function run_fee_migrations(): void {
    global $db;
    if (!column_exists('platform_jobs', 'free_revisions')) {
        $db->query("ALTER TABLE `platform_jobs` ADD COLUMN `free_revisions` INT NULL");
    }
    if (!column_exists('platform_jobs', 'revision_fee')) {
        $db->query("ALTER TABLE `platform_jobs` ADD COLUMN `revision_fee` DECIMAL(14,2) NULL");
    }
}

/** İşe tanınan ücretsiz revizyon sayısı (işe özel değer yoksa ayar) */
function job_free_revisions(array $job): int {
    return isset($job['free_revisions']) && $job['free_revisions'] !== null ? (int)$job['free_revisions'] : (int)platform_setting('platform_max_revisions');
}

/** Ücretli revizyon tutarı (ajans, KDV hariç) */
function job_revision_fee(array $job): float {
    return isset($job['revision_fee']) && $job['revision_fee'] !== null ? (float)$job['revision_fee'] : (float)platform_setting('platform_revision_fee');
}

/** Sıradaki revizyon ücretli mi? */
function revision_is_paid(array $job): bool {
    return (int)$job['revision_count'] >= job_free_revisions($job);
}

/**
 * Ücretli revizyonu işe yazar: ek kalem + aşama ve iş tutarları güncellenir
 */
function revision_charge(array $job, ?array $m, int $n): void {
    global $db;
    $fee = round(job_revision_fee($job), 2);
    if ($fee <= 0) return;
    $ffee = round($fee * (float)platform_setting('platform_revision_freelancer_share') / 100, 2);
    $job_id = (int)$job['id'];
    $db->prepare("INSERT INTO platform_job_items (job_id, service_id, name, unit, quantity, agency_unit_price, freelancer_unit_fee, milestone_id, is_extra, status) VALUES (?, NULL, ?, 'adet', 1, ?, ?, ?, 1, 'active')")
       ->execute([$job_id, "Ücretli revizyon #{$n}", $fee, $ffee, $m['id'] ?? null]);
    if ($m) {
        $db->prepare("UPDATE platform_milestones SET agency_amount = agency_amount + ?, fee = fee + ? WHERE id = ?")->execute([$fee, $ffee, $m['id']]);
    }
    $db->prepare("UPDATE platform_jobs SET agency_price = COALESCE(agency_price, 0) + ?, freelancer_fee = COALESCE(freelancer_fee, 0) + ? WHERE id = ?")->execute([$fee, $ffee, $job_id]);
    job_event($job_id, 'extra', "Ücretli revizyon #{$n}", ['amount' => $fee, 'milestone_id' => $m['id'] ?? null, 'new' => 'Ücretsiz revizyon hakkı doldu; ajans ücretini onayladı', 'visibility' => 'agency']);
    if ($ffee > 0) {
        job_event($job_id, 'extra', "Ücretli revizyon #{$n}", ['amount' => $ffee, 'milestone_id' => $m['id'] ?? null, 'new' => 'Aşama onaylanınca hakedişinize eklenir', 'visibility' => 'freelancer']);
    }
}

/**
 * Ham görüntü teslimi ücreti: [ajans tutarı, freelancer payı]
 * percent: yerinde (çekim) kalemlerinin tutarı üzerinden yüzde · fixed: sabit tutar
 */
function raw_delivery_fee(array $items): array {
    $val = (float)platform_setting('platform_raw_fee_value');
    if (platform_setting('platform_raw_fee_mode') === 'fixed') {
        $ag = $val;
    } else {
        $base = 0.0;
        foreach ($items as $it) {
            if (JOB_CATEGORIES[$it['category'] ?? 'other']['onsite'] ?? false) {
                $base += (float)$it['quantity'] * (float)$it['agency_unit_price'];
            }
        }
        $ag = $base * $val / 100;
    }
    $ag = round(max(0, $ag), 2);
    return [$ag, round($ag * (float)platform_setting('platform_raw_freelancer_share') / 100, 2)];
}

/** Sipariş kalemlerine eklenecek "Ham görüntü teslimi" kalemi */
function raw_delivery_item(array $items): array {
    [$ag, $fl] = raw_delivery_fee($items);
    return ['service_id' => null, 'name' => 'Ham görüntü teslimi', 'unit' => 'proje', 'category' => null,
            'quantity' => 1, 'agency_unit_price' => $ag, 'freelancer_unit_fee' => $fl, 'min_tier' => 'standard', 'min_lead_hours' => 0];
}

/** Ham görüntü ücretinin ajansa gösterilecek açıklaması */
function raw_delivery_fee_label(): string {
    $v = (float)platform_setting('platform_raw_fee_value');
    if ($v <= 0) return 'ek ücret yok';
    return platform_setting('platform_raw_fee_mode') === 'fixed'
        ? '+' . format_money($v) . ' + KDV'
        : 'çekim tutarının %' . rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '\'i kadar ek ücret';
}
