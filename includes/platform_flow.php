<?php
/**
 * ====================================================================
 * İŞ AKIŞI: İŞ KAYDI · AŞAMALAR · EK KALEMLER · SORUNLAR · OTOMASYON
 * ====================================================================
 * Ajans işi girer → RY Medya kontrol eder → ekip veya freelancer yapar.
 * Ajans ve freelancer birbirini görmez; her şey platform üzerinden yürür.
 *
 * Aşama (milestone): İş, teslim edilip onaylanan parçalara bölünür.
 *   Katalog işlerinde her hizmet kalemi bir aşamadır. Her aşama ayrı
 *   teslim edilir, kalite kontrolden geçer, ajans onaylar; onaylanan
 *   aşamanın freelancer hakedişi o anda kayda (alış faturası) geçer.
 *   Son aşama onaylanınca iş kapanır ve ajansa satış faturası kesilir.
 *
 * İş kaydı: Her işlem (kim, ne zaman, eski/yeni değer, tutar) kalem kalem
 *   platform_job_changes tablosuna yazılır ve görünürlüğe göre filtrelenir.
 */

const MILESTONE_STATUSES = [
    'proposed'           => ['Ajans onayında', 'warning'],      // ekibin önerdiği ek kalem
    'pending_freelancer' => ['Freelancer onayında', 'warning'], // ek kalem freelancer kabulü bekliyor
    'open'               => ['Sırada', 'neutral'],
    'in_review'          => ['İncelemede', 'violet'],
    'revision'           => ['Revizyonda', 'warning'],
    'approved'           => ['Onaylandı', 'success'],
    'cancelled'          => ['İptal', 'danger'],
];
const MILESTONE_LIVE = ['open', 'in_review', 'revision', 'approved'];   // işin kapsamındaki aşamalar

/**
 * Olay türleri: [etiket, ikon, varsayılan görünürlük]
 * Görünürlük: all | staff | agency (ekip + ajans) | freelancer (ekip + freelancer)
 */
const JOB_EVENT_TYPES = [
    'created'            => ['İş girildi', 'file-plus', 'agency'],
    'published'          => ['Yayına alındı', 'radio-tower', 'agency'],
    'quote'              => ['Fiyat teklifi', 'receipt-text', 'agency'],
    'policy'             => ['Görünürlük politikası', 'eye', 'staff'],
    'offer'              => ['Teklif verildi', 'hand', 'freelancer'],
    'offer_updated'      => ['Teklif güncellendi', 'pencil', 'freelancer'],
    'offer_withdrawn'    => ['Teklif geri çekildi', 'undo-2', 'freelancer'],
    'offer_rejected'     => ['Teklif reddedildi', 'circle-slash', 'freelancer'],
    'assigned'           => ['Atama', 'user-check', 'freelancer'],
    'award_declined'     => ['Atama reddedildi', 'user-x', 'freelancer'],
    'released'           => ['İş bırakıldı', 'log-out', 'freelancer'],
    'unassigned'         => ['Atama kaldırıldı', 'user-minus', 'freelancer'],
    'internal'           => ['Ekibe alındı', 'users', 'all'],
    'started'            => ['Üretim başladı', 'play', 'all'],
    'milestone'          => ['Aşama', 'flag', 'all'],
    'delivered'          => ['Teslim', 'upload', 'freelancer'],
    'qa_approved'        => ['Kalite kontrol onayı', 'shield-check', 'all'],
    'qa_rejected'        => ['Kalite kontrol düzeltmesi', 'shield-alert', 'freelancer'],
    'revision'           => ['Revizyon talebi', 'rotate-ccw', 'all'],
    'milestone_approved' => ['Aşama onaylandı', 'circle-check', 'all'],
    'auto_approved'      => ['Otomatik onay', 'timer', 'all'],
    'completed'          => ['İş tamamlandı', 'flag-triangle-right', 'all'],
    'cancelled'          => ['İptal', 'circle-x', 'all'],
    'extra'              => ['Ek kalem', 'list-plus', 'all'],
    'invoice'            => ['Fatura', 'file-text', 'staff'],
    'payment'            => ['Ödeme', 'banknote', 'freelancer'],
    'rating'             => ['Değerlendirme', 'star', 'staff'],
    'issue'              => ['Sorun bildirimi', 'triangle-alert', 'staff'],
    'reminder'           => ['Hatırlatma', 'bell', 'staff'],
    'routing'            => ['Otomatik yönlendirme', 'route', 'staff'],
    'change'             => ['Değişiklik', 'pencil', 'all'],
];

/**
 * ====================================================================
 * MIGRATION (v6)
 * ====================================================================
 */
function run_platform_migrations_v6(): void {
    global $db;
    $add = function (string $table, string $column, string $ddl) use ($db) {
        if (!column_exists($table, $column)) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        }
    };
    $db->query("CREATE TABLE IF NOT EXISTS `platform_milestones` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `job_id` INT UNSIGNED NOT NULL,
        `seq` INT NOT NULL DEFAULT 1,
        `title` VARCHAR(200) NOT NULL,
        `description` TEXT NULL,
        `fee` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `agency_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `due_date` DATE NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'open',
        `is_extra` TINYINT(1) NOT NULL DEFAULT 0,
        `no_work` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by_type` VARCHAR(20) NULL,
        `created_by_user_id` INT UNSIGNED NULL,
        `freelancer_user_id` INT UNSIGNED NULL,
        `purchase_invoice_id` INT UNSIGNED NULL,
        `approved_at` DATETIME NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_job` (`job_id`, `seq`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `platform_job_issues` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `job_id` INT UNSIGNED NOT NULL,
        `opened_by_type` VARCHAR(20) NOT NULL,
        `opened_by_user_id` INT UNSIGNED NULL,
        `reason` VARCHAR(40) NOT NULL,
        `details` TEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'open',
        `resolution` TEXT NULL,
        `resolved_by` INT UNSIGNED NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `resolved_at` DATETIME NULL,
        KEY `idx_job` (`job_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $add('platform_job_changes', 'event_type', "`event_type` VARCHAR(30) NOT NULL DEFAULT 'change'");
    $add('platform_job_changes', 'visibility', "`visibility` VARCHAR(20) NOT NULL DEFAULT 'all'");
    $add('platform_job_changes', 'amount', "`amount` DECIMAL(15,2) NULL");
    $add('platform_job_changes', 'milestone_id', "`milestone_id` INT UNSIGNED NULL");
    $add('platform_deliveries', 'milestone_id', "`milestone_id` INT UNSIGNED NULL");
    $add('platform_job_items', 'milestone_id', "`milestone_id` INT UNSIGNED NULL");
    $add('platform_job_items', 'is_extra', "`is_extra` TINYINT(1) NOT NULL DEFAULT 0");
    $add('platform_job_items', 'status', "`status` VARCHAR(20) NOT NULL DEFAULT 'active'");
    $add('platform_job_items', 'created_at', "`created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
    $add('platform_applications', 'delivery_days', "`delivery_days` INT NULL");
    $add('platform_applications', 'updated_at', "`updated_at` DATETIME NULL");
    $add('platform_jobs', 'assigned_via', "`assigned_via` VARCHAR(20) NULL");

    // Eski kayıtların görünürlüğü (fiyat satırları freelancer'a, freelancer satırları ajansa gösterilmez)
    $db->query("UPDATE platform_job_changes SET visibility = 'agency' WHERE event_type = 'change' AND field_label IN ('Sipariş tutarı', 'İş tutarı', 'Ajans fiyatı', 'Acil iş farkı')");
    $db->query("UPDATE platform_job_changes SET visibility = 'freelancer' WHERE event_type = 'change' AND field_label IN ('Freelancer ücreti', 'Atama')");
    $db->query("UPDATE platform_job_changes SET visibility = 'staff' WHERE event_type = 'change' AND field_label IN ('Görünürlük politikası')");

    // Var olan işlere aşama oluştur (geçmiş tamamlanan işler tek aşama olarak)
    $jobs = $db->query("SELECT j.* FROM platform_jobs j WHERE NOT EXISTS (SELECT 1 FROM platform_milestones m WHERE m.job_id = j.id) AND j.status NOT IN ('submitted', 'quote_sent', 'cancelled')")->fetchAll();
    foreach ($jobs as $j) {
        if ($j['status'] === 'completed') {
            $db->prepare("INSERT INTO platform_milestones (job_id, seq, title, fee, agency_amount, due_date, status, created_by_type, freelancer_user_id, purchase_invoice_id, approved_at) VALUES (?, 1, 'İşin tamamı', ?, ?, ?, 'approved', 'system', ?, ?, ?)")
               ->execute([$j['id'], (float)$j['freelancer_fee'], (float)$j['agency_price'], $j['deadline'], $j['assigned_type'] === 'freelancer' ? $j['assigned_user_id'] : null, $j['purchase_invoice_id'], $j['completed_at']]);
        } else {
            // Teslimi başlamış eski işler bütün olarak teslim edildiği için tek aşama olur
            $has_delivery = (int)$db->query("SELECT COUNT(*) FROM platform_deliveries WHERE job_id = " . (int)$j['id'])->fetchColumn() > 0;
            milestones_create_default((int)$j['id'], $has_delivery);
            $mid = (int)$db->query("SELECT id FROM platform_milestones WHERE job_id = " . (int)$j['id'] . " ORDER BY seq LIMIT 1")->fetchColumn();
            if ($mid) {
                $db->prepare("UPDATE platform_deliveries SET milestone_id = ? WHERE job_id = ? AND milestone_id IS NULL")->execute([$mid, $j['id']]);
                $map = ['qa_review' => 'in_review', 'delivered' => 'in_review', 'revision' => 'revision'];
                if (isset($map[$j['status']])) {
                    $db->prepare("UPDATE platform_milestones SET status = ? WHERE job_id = ? AND status = 'open' ORDER BY seq LIMIT 1")->execute([$map[$j['status']], $j['id']]);
                }
            }
        }
    }
}

/**
 * ====================================================================
 * İŞ KAYDI (OLAYLAR)
 * ====================================================================
 */
function job_event_actor(): array {
    if (is_logged_in()) {
        return ['staff', (int)$_SESSION['user_id']];
    }
    if (is_client_logged_in()) {
        return [portal_role(), (int)$_SESSION['client_user_id']];
    }
    return ['system', null];
}

/**
 * Bir işleme ait kalemi iş kaydına yazar.
 * $o: actor, user_id, old, new, amount, milestone_id, visibility
 */
function job_event(int $job_id, string $type, string $label, array $o = []): void {
    global $db;
    [$actor, $uid] = job_event_actor();
    $actor = $o['actor'] ?? $actor;
    $uid = array_key_exists('user_id', $o) ? $o['user_id'] : $uid;
    $vis = $o['visibility'] ?? (JOB_EVENT_TYPES[$type][2] ?? 'all');
    try {
        $db->prepare("INSERT INTO platform_job_changes (job_id, user_id, actor, event_type, visibility, field_label, old_value, new_value, amount, milestone_id, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$job_id, $uid, $actor, $type, $vis, mb_substr($label, 0, 100),
                      isset($o['old']) && $o['old'] !== '' ? mb_substr((string)$o['old'], 0, 2000) : null,
                      isset($o['new']) && $o['new'] !== '' ? mb_substr((string)$o['new'], 0, 2000) : null,
                      $o['amount'] ?? null, $o['milestone_id'] ?? null]);
    } catch (Throwable $e) {
        error_log('İş kaydı yazılamadı: ' . $e->getMessage());
    }
}

function job_event_visible(array $ev, string $perspective): bool {
    if ($perspective === 'staff') return true;
    return $ev['visibility'] === 'all' || $ev['visibility'] === $perspective;
}

function job_events(int $job_id, string $perspective = 'staff', int $limit = 500): array {
    global $db;
    $st = $db->prepare("SELECT c.*, u.full_name FROM platform_job_changes c LEFT JOIN users u ON u.id = c.user_id WHERE c.job_id = ? ORDER BY c.id DESC LIMIT " . (int)$limit);
    $st->execute([$job_id]);
    return array_values(array_filter($st->fetchAll(), fn($e) => job_event_visible($e, $perspective)));
}

/**
 * İş kaydı zaman çizelgesi
 */
function render_job_events(array $events, string $perspective = 'staff', int $show = 12): string {
    if (!$events) {
        return '<p class="small text-muted">Henüz kayıt yok.</p>';
    }
    $actors = ['agency' => 'Ajans', 'staff' => site_setting('platform_team_name') ?: 'Platform ekibi', 'freelancer' => 'Freelancer', 'system' => 'Sistem'];
    if ($perspective === 'freelancer') $actors['agency'] = 'Müşteri';
    if ($perspective === 'agency') $actors['freelancer'] = 'Prodüksiyon ekibi';
    $out = '<div class="timeline">';
    foreach ($events as $i => $ev) {
        $type = JOB_EVENT_TYPES[$ev['event_type']] ?? JOB_EVENT_TYPES['change'];
        $who = $actors[$ev['actor']] ?? $ev['actor'];
        if ($perspective === 'staff' && !empty($ev['full_name'])) $who .= ' · ' . $ev['full_name'];
        $detail = '';
        if ($ev['old_value'] !== null && $ev['new_value'] !== null) {
            $detail = '<span style="text-decoration:line-through">' . e(mb_strimwidth($ev['old_value'], 0, 140, '…')) . '</span> &rarr; <span class="text-ink">' . e(mb_strimwidth($ev['new_value'], 0, 160, '…')) . '</span>';
        } elseif ($ev['new_value'] !== null) {
            $detail = e(mb_strimwidth($ev['new_value'], 0, 240, '…'));
        }
        $amount = $ev['amount'] !== null && ($perspective === 'staff' || $ev['visibility'] === $perspective)
            ? ' <span class="num" style="font-weight:500">' . format_money((float)$ev['amount']) . '</span>' : '';
        $hidden = $i >= $show ? ' x-show="all"' : '';
        $out .= '<div class="timeline-item' . ($ev['actor'] === 'agency' ? ' is-client' : ($ev['actor'] === 'staff' ? ' is-strong' : '')) . '"' . $hidden . '>'
            . '<p class="small" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><i data-lucide="' . $type[1] . '" style="width:13px;height:13px;color:var(--muted)"></i><span style="font-weight:500">' . e($ev['field_label']) . '</span>' . $amount . ' <span class="xsmall text-muted">· ' . e($who) . '</span></p>'
            . ($detail !== '' ? '<p class="xsmall text-muted" style="margin-top:2px">' . $detail . '</p>' : '')
            . '<p class="xsmall text-faint" style="margin-top:2px">' . format_date($ev['created_at'], true) . '</p></div>';
    }
    $out .= '</div>';
    if (count($events) > $show) {
        $out = '<div x-data="{ all: false }">' . $out . '<button type="button" class="small link" style="margin-top:12px" @click="all = !all" x-text="all ? \'Daha az göster\' : \'Tümünü göster (' . count($events) . ')\'"></button></div>';
    }
    return $out;
}

/**
 * İş kaydını CSV olarak indirir (görünürlüğe göre)
 */
function export_job_events(array $job, string $perspective): void {
    $events = array_reverse(job_events((int)$job['id'], $perspective, 5000));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="is-kaydi-' . preg_replace('/[^A-Za-z0-9-]/', '', $job['job_code']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tarih', 'İşlem', 'Tür', 'Yapan', 'Önceki', 'Yeni', 'Tutar'], ';');
    $actors = ['agency' => 'Ajans', 'staff' => 'Platform ekibi', 'freelancer' => 'Freelancer', 'system' => 'Sistem'];
    foreach ($events as $ev) {
        $who = $actors[$ev['actor']] ?? $ev['actor'];
        if ($perspective === 'staff' && $ev['full_name']) $who .= ' (' . $ev['full_name'] . ')';
        fputcsv($out, [$ev['created_at'], $ev['field_label'], JOB_EVENT_TYPES[$ev['event_type']][0] ?? $ev['event_type'], $who, $ev['old_value'], $ev['new_value'],
                       $ev['amount'] !== null ? number_format((float)$ev['amount'], 2, ',', '.') : ''], ';');
    }
    exit;
}

/**
 * ====================================================================
 * AŞAMALAR
 * ====================================================================
 */
function job_milestones(int $job_id, bool $live_only = false): array {
    global $db;
    $st = $db->prepare("SELECT * FROM platform_milestones WHERE job_id = ?" . ($live_only ? " AND status IN ('" . implode("','", MILESTONE_LIVE) . "')" : '') . " ORDER BY seq, id");
    $st->execute([$job_id]);
    return $st->fetchAll();
}

/**
 * İç kalem: ekibin eklediği, ajansa yansımayan ek iş / prim. Ajans bunu hiç görmez.
 */
function milestone_internal(array $m): bool {
    return (int)$m['is_extra'] === 1 && (float)$m['agency_amount'] <= 0;
}

function get_milestone(int $id): ?array {
    global $db;
    $st = $db->prepare("SELECT * FROM platform_milestones WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function milestone_badge(string $status, string $perspective = 'staff'): string {
    [$l, $t] = MILESTONE_STATUSES[$status] ?? [$status, 'neutral'];
    if ($perspective === 'agency' && $status === 'pending_freelancer') $l = 'Ekip onayında';
    if ($perspective === 'agency' && $status === 'proposed') $l = 'Onayınızda';
    if ($perspective === 'freelancer' && $status === 'pending_freelancer') $l = 'Kabulünüzü bekliyor';
    return ui_badge($l, $t, true);
}

/**
 * İş için varsayılan aşamaları oluşturur (yoksa).
 * Katalog işinde her hizmet kalemi bir aşamadır; özel işte tek aşama.
 */
function milestones_create_default(int $job_id, bool $single = false): void {
    global $db;
    $job = get_job($job_id);
    if (!$job || (int)$db->query("SELECT COUNT(*) FROM platform_milestones WHERE job_id = {$job_id}")->fetchColumn() > 0) {
        return;
    }
    $fee = (float)$job['freelancer_fee'];
    $agency = (float)$job['agency_price'];
    $items = array_values(array_filter(job_items($job_id), fn($i) => ($i['status'] ?? 'active') === 'active'));
    $ins = $db->prepare("INSERT INTO platform_milestones (job_id, seq, title, description, fee, agency_amount, due_date, status, created_by_type) VALUES (?, ?, ?, ?, ?, ?, ?, 'open', 'system')");
    if (!$single && $items && count($items) > 1 && platform_setting('platform_milestone_per_item') === '1') {
        $base_fee = array_sum(array_map(fn($i) => (float)$i['freelancer_unit_fee'] * (float)$i['quantity'], $items));
        $base_ag = array_sum(array_map(fn($i) => (float)$i['agency_unit_price'] * (float)$i['quantity'], $items));
        // Çekim türü kalemler önce, kurgu/renk/ses sonra
        usort($items, fn($a, $b) => (int)!(JOB_CATEGORIES[$a['category'] ?? 'other']['onsite'] ?? false) <=> (int)!(JOB_CATEGORIES[$b['category'] ?? 'other']['onsite'] ?? false));
        $sum_fee = 0; $sum_ag = 0;
        foreach ($items as $n => $it) {
            $last = $n === count($items) - 1;
            $line_fee = (float)$it['freelancer_unit_fee'] * (float)$it['quantity'];
            $line_ag = (float)$it['agency_unit_price'] * (float)$it['quantity'];
            $mf = $last ? round($fee - $sum_fee, 2) : round($base_fee > 0 ? $fee * $line_fee / $base_fee : $fee / count($items), 2);
            $ma = $last ? round($agency - $sum_ag, 2) : round($base_ag > 0 ? $agency * $line_ag / $base_ag : $agency / count($items), 2);
            $sum_fee += $mf; $sum_ag += $ma;
            $onsite = JOB_CATEGORIES[$it['category'] ?? 'other']['onsite'] ?? false;
            $due = $onsite && $job['start_date'] ? $job['start_date'] : $job['deadline'];
            $ins->execute([$job_id, $n + 1, $it['name'] . ((float)$it['quantity'] != 1 ? ' × ' . qty_label((float)$it['quantity']) : ''), null, $mf, $ma, $due]);
            $db->prepare("UPDATE platform_job_items SET milestone_id = ? WHERE id = ?")->execute([(int)$db->lastInsertId(), $it['id']]);
        }
    } else {
        $ins->execute([$job_id, 1, count($items) === 1 ? ($items[0]['name'] . ((float)$items[0]['quantity'] != 1 ? ' × ' . qty_label((float)$items[0]['quantity']) : '')) : 'İşin tamamı', null, $fee, $agency, $job['deadline']]);
        $mid = (int)$db->lastInsertId();
        $db->prepare("UPDATE platform_job_items SET milestone_id = ? WHERE job_id = ? AND milestone_id IS NULL")->execute([$mid, $job_id]);
    }
}

/**
 * Aşamalar henüz işlenmediyse (teslim yok, onay yok) yeniden oluşturur;
 * işlenmişse açık aşamaların ücretini yeni toplam ücrete oranlar.
 */
function milestones_sync(int $job_id): void {
    global $db;
    $ms = job_milestones($job_id);
    if (!$ms) {
        milestones_create_default($job_id);
        return;
    }
    $touched = (int)$db->query("SELECT COUNT(*) FROM platform_deliveries WHERE job_id = {$job_id}")->fetchColumn() > 0
        || array_filter($ms, fn($m) => $m['status'] !== 'open' || (int)$m['is_extra'] === 1);
    if (!$touched) {
        $db->prepare("UPDATE platform_job_items SET milestone_id = NULL WHERE job_id = ?")->execute([$job_id]);
        $db->prepare("DELETE FROM platform_milestones WHERE job_id = ?")->execute([$job_id]);
        milestones_create_default($job_id);
        return;
    }
    $job = get_job($job_id);
    $live = array_filter($ms, fn($m) => in_array($m['status'], MILESTONE_LIVE, true));
    $fixed = array_sum(array_map(fn($m) => (float)$m['fee'], array_filter($live, fn($m) => $m['status'] === 'approved')));
    $open = array_values(array_filter($live, fn($m) => $m['status'] !== 'approved'));
    $open_sum = array_sum(array_map(fn($m) => (float)$m['fee'], $open));
    $target = max(0, (float)$job['freelancer_fee'] - $fixed);
    if (!$open || abs($open_sum - $target) < 0.01) return;
    $acc = 0;
    foreach ($open as $n => $m) {
        $new = $n === count($open) - 1 ? round($target - $acc, 2) : round($open_sum > 0 ? (float)$m['fee'] * $target / $open_sum : $target / count($open), 2);
        $acc += $new;
        $db->prepare("UPDATE platform_milestones SET fee = ? WHERE id = ?")->execute([$new, $m['id']]);
    }
}

/**
 * Teslim yapılacak sıradaki aşama
 */
function milestone_next(int $job_id): ?array {
    global $db;
    $st = $db->prepare("SELECT * FROM platform_milestones WHERE job_id = ? AND status IN ('open', 'revision') AND no_work = 0 ORDER BY status = 'revision' DESC, seq, id LIMIT 1");
    $st->execute([$job_id]);
    return $st->fetch() ?: null;
}

/**
 * Aşamaların toplam ücret / onaylanan tutar özeti
 */
function milestone_totals(array $milestones): array {
    $live = array_filter($milestones, fn($m) => in_array($m['status'], MILESTONE_LIVE, true));
    $approved = array_filter($live, fn($m) => $m['status'] === 'approved');
    return [
        'count' => count($live), 'approved' => count($approved),
        'fee' => array_sum(array_map(fn($m) => (float)$m['fee'], $live)),
        'fee_approved' => array_sum(array_map(fn($m) => (float)$m['fee'], $approved)),
        'agency' => array_sum(array_map(fn($m) => (float)$m['agency_amount'], $live)),
    ];
}

/**
 * Aşamayı onaylar: hakediş (alış faturası) oluşturur. Son aşamaysa işi kapatır.
 * $by: 'agency' | 'staff' | 'system'
 */
function milestone_approve(array $job, array $m, string $by = 'agency', ?int $rating = null, string $review = ''): bool {
    global $db;
    $job_id = (int)$job['id'];
    $db->prepare("UPDATE platform_deliveries SET status = 'approved', reviewed_at = NOW() WHERE job_id = ? AND milestone_id = ? AND status = 'sent'")->execute([$job_id, $m['id']]);
    $fl = $job['assigned_type'] === 'freelancer' && !empty($job['assigned_user_id']) ? (int)$job['assigned_user_id'] : null;
    $db->prepare("UPDATE platform_milestones SET status = 'approved', approved_at = NOW(), freelancer_user_id = ? WHERE id = ?")->execute([$fl, $m['id']]);
    $label = $by === 'system' ? 'Aşama otomatik onaylandı: ' . $m['title'] : 'Aşama onaylandı: ' . $m['title'];
    $opt = ['milestone_id' => (int)$m['id'], 'amount' => (float)$m['fee'], 'visibility' => milestone_internal($m) ? 'freelancer' : 'all'];
    if ($by === 'system') $opt += ['actor' => 'system', 'user_id' => null];
    job_event($job_id, $by === 'system' ? 'auto_approved' : 'milestone_approved', $label, $opt);
    milestone_invoice($job, get_milestone((int)$m['id']));

    $remaining = (int)$db->query("SELECT COUNT(*) FROM platform_milestones WHERE job_id = {$job_id} AND status IN ('open', 'in_review', 'revision')")->fetchColumn();
    if ($remaining === 0) {
        job_complete(get_job($job_id), $rating, $review);
        return true;
    }
    // Sıradaki aşama
    $db->prepare("UPDATE platform_jobs SET status = 'in_progress', delivered_at = NULL WHERE id = ?")->execute([$job_id]);
    $next = milestone_next($job_id);
    if ($fl) {
        notify_user($fl, "{$job['job_code']} · \"{$m['title']}\" onaylandı" . ((float)$m['fee'] > 0 ? ', hakedişiniz (' . format_money((float)$m['fee']) . ') kayda geçti' : '') . ($next ? ". Sıradaki aşama: {$next['title']}" : '.'), "/platform/job.php?id={$job_id}", $job_id);
    }
    return false;
}

/**
 * Onaylanan aşama için freelancer hakediş kaydı (alış faturası)
 */
function milestone_invoice(array $job, ?array $m): void {
    global $db;
    if (!$m || !empty($m['purchase_invoice_id']) || (float)$m['fee'] <= 0 || $job['assigned_type'] !== 'freelancer' || empty($job['assignee_contact_id'])) {
        return;
    }
    $vat = (float)platform_setting('platform_freelancer_vat');
    $tax = calculate_tax_breakdown((float)$m['fee'], $vat, '0/10', 0);
    $no  = generate_invoice_number('purchase');
    $db->prepare("
        INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at)
        VALUES ('purchase', ?, ?, ?, CURRENT_DATE(), ?, ?, ?, '0/10', 0, 0, 0, ?, 'unpaid', ?, NOW())
    ")->execute([$no, $job['assignee_contact_id'], $job['internal_project_id'] ?: null, $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'], "Platform işi hakedişi {$job['job_code']} · {$m['title']}"]);
    $inv = (int)$db->lastInsertId();
    $db->prepare("UPDATE platform_milestones SET purchase_invoice_id = ? WHERE id = ?")->execute([$inv, $m['id']]);
    $db->prepare("UPDATE platform_jobs SET purchase_invoice_id = COALESCE(purchase_invoice_id, ?) WHERE id = ?")->execute([$inv, $job['id']]);
    recalculate_contact_balance((int)$job['assignee_contact_id']);
    job_event((int)$job['id'], 'invoice', "Hakediş kaydı: {$no}", ['new' => $m['title'], 'amount' => (float)$tax['grand_total'], 'milestone_id' => (int)$m['id'], 'visibility' => 'freelancer', 'actor' => 'system', 'user_id' => null]);
    notify_user((int)$job['assigned_user_id'], "{$job['job_code']} · {$m['title']}: hakedişiniz (" . format_money((float)$m['fee']) . ") kazançlarınıza eklendi.", '/platform/earnings.php', (int)$job['id']);
}

/**
 * ====================================================================
 * EK KALEMLER (İŞ SÜRERKEN KAPSAM ARTIŞI)
 * ====================================================================
 * Ajans katalogdan ekler: tutar katalogdan; atanmış freelancer kabul eder.
 * Ekip önerir: ajans onaylar, sonra (atanmışsa) freelancer kabul eder.
 * Ekip "prim" ekler: ajansa yansımaz, iş gerektirmez, doğrudan hakediş.
 */
function extra_add_from_catalog(array $job, array $items, string $note = ''): ?int {
    global $db;
    if (!$items) return null;
    $fee = array_sum(array_map(fn($i) => $i['quantity'] * $i['freelancer_unit_fee'], $items));
    $ag  = array_sum(array_map(fn($i) => $i['quantity'] * $i['agency_unit_price'], $items));
    $title = 'Ek: ' . implode(', ', array_map(fn($i) => $i['name'] . ((float)$i['quantity'] != 1 ? ' × ' . qty_label((float)$i['quantity']) : ''), $items));
    return extra_create($job, mb_strimwidth($title, 0, 190, '…'), $note, $ag, $fee, $job['deadline'], 'agency', $items);
}

/**
 * $origin: 'agency' (ajans ekledi, ajans onayı gerekmez) | 'staff' (ajans onayı gerekir) | 'staff_internal' (ajansa yansımaz)
 */
function extra_create(array $job, string $title, string $note, float $agency_amount, float $fee, ?string $due, string $origin, array $items = [], bool $no_work = false): int {
    global $db;
    $job_id = (int)$job['id'];
    $seq = (int)$db->query("SELECT COALESCE(MAX(seq), 0) + 1 FROM platform_milestones WHERE job_id = {$job_id}")->fetchColumn();
    $has_fl = $job['assigned_type'] === 'freelancer' && !empty($job['assigned_user_id']);
    $status = $origin === 'staff' && $agency_amount > 0 ? 'proposed' : ($has_fl && !$no_work ? 'pending_freelancer' : 'open');
    [$actor, $uid] = job_event_actor();
    $db->prepare("INSERT INTO platform_milestones (job_id, seq, title, description, fee, agency_amount, due_date, status, is_extra, no_work, created_by_type, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)")
       ->execute([$job_id, $seq, $title, $note !== '' ? $note : null, round($fee, 2), round($agency_amount, 2), $due, $status, $no_work ? 1 : 0, $actor, $uid]);
    $mid = (int)$db->lastInsertId();
    if ($items) {
        $ins = $db->prepare("INSERT INTO platform_job_items (job_id, service_id, name, unit, quantity, agency_unit_price, freelancer_unit_fee, milestone_id, is_extra, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        foreach ($items as $it) {
            $ins->execute([$job_id, $it['service_id'] ?? null, $it['name'], $it['unit'], $it['quantity'], $it['agency_unit_price'], $it['freelancer_unit_fee'], $mid, $status === 'proposed' ? 'proposed' : 'active']);
        }
    } elseif ($agency_amount > 0) {
        $db->prepare("INSERT INTO platform_job_items (job_id, service_id, name, unit, quantity, agency_unit_price, freelancer_unit_fee, milestone_id, is_extra, status) VALUES (?, NULL, ?, 'proje', 1, ?, ?, ?, 1, ?)")
           ->execute([$job_id, $title, $agency_amount, $fee, $mid, $status === 'proposed' ? 'proposed' : 'active']);
    }
    job_event($job_id, 'extra', ($origin === 'agency' ? 'Ek kalem eklendi: ' : ($no_work ? 'Prim eklendi: ' : 'Ek kalem önerildi: ')) . $title, ['milestone_id' => $mid, 'amount' => $agency_amount, 'new' => $note, 'visibility' => $origin === 'staff_internal' ? 'staff' : 'agency']);
    if ($status !== 'proposed') {
        job_event($job_id, 'extra', ($no_work ? 'Prim: ' : 'Ek kalem: ') . $title, ['milestone_id' => $mid, 'amount' => $fee, 'visibility' => 'freelancer']);
    }

    if ($status !== 'proposed') {
        extra_activate($job, get_milestone($mid));
    } else {
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} için ek kalem onayınızı bekliyor: {$title} · " . format_money($agency_amount) . ' + KDV', "/platform/job.php?id={$job_id}#asamalar", $job_id);
    }
    return $mid;
}

/**
 * Ek kalem kapsama girer: fiyatlar güncellenir, gerekiyorsa freelancer'a sorulur
 */
function extra_activate(array $job, array $m): void {
    global $db;
    $job_id = (int)$job['id'];
    $db->prepare("UPDATE platform_jobs SET agency_price = COALESCE(agency_price, 0) + ?, freelancer_fee = COALESCE(freelancer_fee, 0) + ? WHERE id = ?")->execute([(float)$m['agency_amount'], (float)$m['fee'], $job_id]);
    $db->prepare("UPDATE platform_job_items SET status = 'active' WHERE milestone_id = ? AND status = 'proposed'")->execute([$m['id']]);
    $has_fl = $job['assigned_type'] === 'freelancer' && !empty($job['assigned_user_id']);
    if ((int)$m['no_work'] === 1) {
        $db->prepare("UPDATE platform_milestones SET status = 'open' WHERE id = ?")->execute([$m['id']]);
        if ($has_fl) {
            milestone_approve(get_job($job_id), get_milestone((int)$m['id']), 'staff');
        }
        return;
    }
    $status = $has_fl ? 'pending_freelancer' : 'open';
    $db->prepare("UPDATE platform_milestones SET status = ? WHERE id = ?")->execute([$status, $m['id']]);
    if ($has_fl) {
        notify_user((int)$job['assigned_user_id'], "{$job['job_code']} için ek kalem: {$m['title']} · " . format_money((float)$m['fee']) . '. Kabul etmeniz bekleniyor.', "/platform/job.php?id={$job_id}#asamalar", $job_id);
    }
    notify_staff("{$job['job_code']} kapsamına ek kalem girdi: {$m['title']}", $job_id);
}

function extra_agency_decision(array $job, array $m, bool $approve, string $note = ''): void {
    global $db;
    if ($m['status'] !== 'proposed') return;
    if ($approve) {
        job_event((int)$job['id'], 'extra', 'Ek kalem ajans tarafından onaylandı: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'amount' => (float)$m['agency_amount'], 'visibility' => 'agency']);
        job_event((int)$job['id'], 'extra', 'Ek kalem: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'amount' => (float)$m['fee'], 'visibility' => 'freelancer']);
        extra_activate($job, $m);
    } else {
        $db->prepare("UPDATE platform_milestones SET status = 'cancelled' WHERE id = ?")->execute([$m['id']]);
        $db->prepare("UPDATE platform_job_items SET status = 'cancelled' WHERE milestone_id = ?")->execute([$m['id']]);
        job_event((int)$job['id'], 'extra', 'Ek kalem ajans tarafından reddedildi: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'new' => $note, 'visibility' => 'agency']);
        notify_staff("{$job['job_code']} · ek kalem reddedildi: {$m['title']}" . ($note !== '' ? " ({$note})" : ''), (int)$job['id']);
    }
}

function extra_freelancer_decision(array $job, array $m, bool $accept, string $note = ''): void {
    global $db;
    if ($m['status'] !== 'pending_freelancer') return;
    if ($accept) {
        $db->prepare("UPDATE platform_milestones SET status = 'open' WHERE id = ?")->execute([$m['id']]);
        job_event((int)$job['id'], 'extra', 'Ek kalem kabul edildi: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'amount' => (float)$m['fee'], 'visibility' => 'freelancer']);
        if (!milestone_internal($m)) {
            job_event((int)$job['id'], 'extra', 'Ek kalem üretim planına alındı: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'visibility' => 'agency']);
            notify_contact_users($job['agency_contact_id'], "{$job['job_code']} · ek kalem üretim planına alındı: {$m['title']}", "/platform/job.php?id={$job['id']}#asamalar", (int)$job['id']);
        } else {
            notify_staff("{$job['job_code']} · iç ek iş kabul edildi: {$m['title']}", (int)$job['id']);
        }
    } else {
        // Kapsamdan çıkar, fiyatı geri al
        $db->prepare("UPDATE platform_milestones SET status = 'cancelled' WHERE id = ?")->execute([$m['id']]);
        $db->prepare("UPDATE platform_job_items SET status = 'cancelled' WHERE milestone_id = ?")->execute([$m['id']]);
        $db->prepare("UPDATE platform_jobs SET agency_price = GREATEST(0, agency_price - ?), freelancer_fee = GREATEST(0, freelancer_fee - ?) WHERE id = ?")->execute([(float)$m['agency_amount'], (float)$m['fee'], $job['id']]);
        job_event((int)$job['id'], 'extra', 'Ek kalem freelancer tarafından kabul edilmedi: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'new' => $note, 'visibility' => 'freelancer']);
        notify_staff("{$job['job_code']} · freelancer ek kalemi kabul etmedi: {$m['title']}" . ($note !== '' ? " ({$note})" : ''), (int)$job['id']);
        if (!milestone_internal($m)) {
            job_event((int)$job['id'], 'extra', 'Ek kalem planlanamadı, ekibimiz sizinle iletişime geçecek: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'visibility' => 'agency']);
            notify_contact_users($job['agency_contact_id'], "{$job['job_code']} · ek kalem şu an planlanamadı: {$m['title']}. Ekibimiz sizinle iletişime geçecek.", "/platform/job.php?id={$job['id']}#asamalar", (int)$job['id']);
        }
    }
}

/**
 * ====================================================================
 * SORUN BİLDİRİMLERİ
 * ====================================================================
 */
const ISSUE_REASONS = [
    'delay'         => 'Gecikme',
    'quality'       => 'Kalite',
    'communication' => 'İletişim',
    'scope'         => 'Kapsam / brief değişikliği',
    'payment'       => 'Ödeme',
    'other'         => 'Diğer',
];

function job_issues(int $job_id, ?string $status = null): array {
    global $db;
    $st = $db->prepare("SELECT i.*, u.full_name FROM platform_job_issues i LEFT JOIN users u ON u.id = i.opened_by_user_id WHERE i.job_id = ?" . ($status ? " AND i.status = ?" : '') . " ORDER BY i.id DESC");
    $st->execute($status ? [$job_id, $status] : [$job_id]);
    return $st->fetchAll();
}

function job_issue_open(array $job, string $by_type, int $user_id, string $reason, string $details): bool {
    global $db;
    $chk = $db->prepare("SELECT COUNT(*) FROM platform_job_issues WHERE job_id = ? AND opened_by_user_id = ? AND status = 'open'");
    $chk->execute([$job['id'], $user_id]);
    if ((int)$chk->fetchColumn() > 0) return false;
    $db->prepare("INSERT INTO platform_job_issues (job_id, opened_by_type, opened_by_user_id, reason, details, status, created_at) VALUES (?, ?, ?, ?, ?, 'open', NOW())")
       ->execute([$job['id'], $by_type, $user_id, $reason, $details]);
    job_event((int)$job['id'], 'issue', 'Sorun bildirildi: ' . (ISSUE_REASONS[$reason] ?? $reason), ['new' => $details, 'visibility' => $by_type === 'agency' ? 'agency' : 'freelancer']);
    notify_staff("{$job['job_code']} için SORUN BİLDİRİMİ (" . ($by_type === 'agency' ? 'ajans' : 'freelancer') . ', ' . (ISSUE_REASONS[$reason] ?? $reason) . '): ' . mb_substr($details, 0, 160), (int)$job['id']);
    return true;
}

function job_issue_resolve(array $job, int $issue_id, string $resolution, int $staff_id): void {
    global $db;
    $st = $db->prepare("SELECT * FROM platform_job_issues WHERE id = ? AND job_id = ? AND status = 'open'");
    $st->execute([$issue_id, $job['id']]);
    $is = $st->fetch();
    if (!$is) return;
    $db->prepare("UPDATE platform_job_issues SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?")->execute([$resolution, $staff_id, $issue_id]);
    job_event((int)$job['id'], 'issue', 'Sorun çözüldü: ' . (ISSUE_REASONS[$is['reason']] ?? $is['reason']), ['new' => $resolution, 'visibility' => $is['opened_by_type'] === 'agency' ? 'agency' : 'freelancer']);
    if ($is['opened_by_user_id']) {
        notify_user((int)$is['opened_by_user_id'], "{$job['job_code']} · bildirdiğiniz sorun çözüldü: " . mb_substr($resolution, 0, 160), "/platform/job.php?id={$job['id']}", (int)$job['id']);
    }
}

/**
 * ====================================================================
 * OTOMASYONLAR
 * ====================================================================
 * - Teslim edilen aşama X gün içinde yanıtlanmazsa otomatik onay
 * - Teslim tarihinden 1 gün önce atanan kişiye hatırlatma
 * - Teslim tarihi geçen işler için ekibe uyarı
 * - Başlangıcına 48 saat kalan atanmamış işler için ekibe uyarı
 * cron/platform.php (günde birkaç kez) veya personel sayfalarında saatte bir çalışır.
 */
function platform_run_automations(bool $force = false): array {
    global $db;
    $last = (int)get_setting('platform_automation_last_run', '0');
    if (!$force && $last > time() - 3600) {
        return ['skipped' => true];
    }
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('platform_automation_last_run', ?, 'platform') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)time()]);
    $res = ['auto_approved' => 0, 'reminders' => 0, 'overdue' => 0, 'unassigned' => 0];
    $already = function (int $job_id, string $label) use ($db): bool {
        $st = $db->prepare("SELECT COUNT(*) FROM platform_job_changes WHERE job_id = ? AND event_type = 'reminder' AND field_label = ?");
        $st->execute([$job_id, $label]);
        return (int)$st->fetchColumn() > 0;
    };

    // Otomatik onay
    $days = (int)platform_setting('platform_auto_approve_days');
    if ($days > 0) {
        $st = $db->prepare("SELECT id FROM platform_jobs WHERE status = 'delivered' AND delivered_at IS NOT NULL AND delivered_at < NOW() - INTERVAL ? DAY");
        $st->execute([$days]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $jid) {
            $job = get_job((int)$jid);
            $d = job_deliveries((int)$jid, ['sent']);
            $m = $d && $d[0]['milestone_id'] ? get_milestone((int)$d[0]['milestone_id']) : null;
            if (!$job || !$m) continue;
            notify_contact_users($job['agency_contact_id'], "{$job['job_code']} · \"{$m['title']}\" {$days} gün içinde yanıtlanmadığı için otomatik onaylandı.", "/platform/job.php?id={$jid}", (int)$jid);
            milestone_approve($job, $m, 'system');
            $res['auto_approved']++;
        }
    }

    // Teslim tarihi yaklaşan / geçen işler
    $in = "'" . implode("','", ['assigned', 'in_progress', 'revision']) . "'";
    foreach ($db->query("SELECT * FROM platform_jobs WHERE status IN ({$in}) AND deadline IS NOT NULL")->fetchAll() as $j) {
        $days_left = (int)floor((strtotime($j['deadline']) - strtotime(date('Y-m-d'))) / 86400);
        if ($days_left === 1 && !$already((int)$j['id'], 'Teslim yarın')) {
            job_event((int)$j['id'], 'reminder', 'Teslim yarın', ['actor' => 'system', 'user_id' => null, 'visibility' => 'staff']);
            if ($j['assigned_type'] === 'freelancer' && $j['assigned_user_id']) {
                notify_user((int)$j['assigned_user_id'], "Hatırlatma: {$j['job_code']} · {$j['title']} teslim tarihi yarın (" . format_date($j['deadline']) . ').', "/platform/job.php?id={$j['id']}", (int)$j['id']);
            }
            $res['reminders']++;
        }
        if ($days_left < 0 && !$already((int)$j['id'], 'Teslim tarihi geçti')) {
            job_event((int)$j['id'], 'reminder', 'Teslim tarihi geçti', ['actor' => 'system', 'user_id' => null, 'visibility' => 'staff']);
            notify_staff("{$j['job_code']} teslim tarihi geçti (" . format_date($j['deadline']) . '), teslim yapılmadı.', (int)$j['id']);
            if ($j['assigned_type'] === 'freelancer' && $j['assigned_user_id']) {
                notify_user((int)$j['assigned_user_id'], "{$j['job_code']} teslim tarihi geçti. Lütfen teslimi tamamlayın veya ekiple yazışın.", "/platform/job.php?id={$j['id']}", (int)$j['id']);
            }
            $res['overdue']++;
        }
    }

    // Başlangıcı yaklaşan atanmamış işler
    foreach ($db->query("SELECT id, job_code, start_date, deadline FROM platform_jobs WHERE status IN ('open', 'submitted') AND COALESCE(start_date, deadline) <= DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY)")->fetchAll() as $j) {
        if (!$already((int)$j['id'], 'Atama bekliyor, başlangıç yakın')) {
            job_event((int)$j['id'], 'reminder', 'Atama bekliyor, başlangıç yakın', ['actor' => 'system', 'user_id' => null, 'visibility' => 'staff']);
            notify_staff("{$j['job_code']} henüz atanmadı; başlangıç " . format_date($j['start_date'] ?: $j['deadline']) . '.', (int)$j['id']);
            $res['unassigned']++;
        }
    }
    return $res;
}

/**
 * Rol bazında "yapılacaklar" sayısı (menü rozeti)
 */
function platform_todo_count(string $role, int $uid, int $cid): int {
    global $db;
    if ($role === 'agency') {
        $st = $db->prepare("SELECT (SELECT COUNT(*) FROM platform_jobs WHERE agency_contact_id = ? AND status IN ('quote_sent', 'delivered'))
                                 + (SELECT COUNT(*) FROM platform_milestones m JOIN platform_jobs j ON j.id = m.job_id WHERE j.agency_contact_id = ? AND m.status = 'proposed')");
        $st->execute([$cid, $cid]);
    } else {
        $st = $db->prepare("SELECT (SELECT COUNT(*) FROM platform_jobs WHERE assigned_user_id = ? AND assigned_type = 'freelancer' AND status IN ('assigned', 'revision'))
                                 + (SELECT COUNT(*) FROM platform_milestones m JOIN platform_jobs j ON j.id = m.job_id WHERE j.assigned_user_id = ? AND m.status = 'pending_freelancer')");
        $st->execute([$uid, $uid]);
    }
    return (int)$st->fetchColumn();
}
