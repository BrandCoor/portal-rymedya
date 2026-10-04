<?php
/**
 * ====================================================================
 * İŞ KALEMLERİNİ DÜZENLEME (miktar, fiyat, ad, silme, ekleme)
 * ====================================================================
 * - Ajans: kabul edilmemiş işte job_edit.php ile tüm kalemleri; kabul
 *   edilmiş işte kendi eklediği, henüz teslim edilmemiş ek kalemlerin
 *   miktarını değiştirir / siler (fiyat katalogdan, değişmez).
 * - Ekip (yönetici): her durumda (teslim / tamamlandıktan sonra da) tüm
 *   kalemlerin miktarını, birim fiyatlarını ve adını düzeltir, siler, ekler.
 * Tutar farkı işe (ajans fiyatı / freelancer ücreti) ve kalemin aşamasına
 * yansıtılır; acil işlerde ana kalem farkına acil oranı da uygulanır.
 * Kesilmiş faturalar otomatik değişmez (ekranda uyarılır).
 */

/** Ajansın düzenleyebileceği ek kalem aşaması mı? */
function agency_can_edit_extra(array $job, array $m): bool {
    global $db;
    if ((int)$m['is_extra'] !== 1 || $m['created_by_type'] !== 'agency') return false;
    if (!in_array($m['status'], ['pending_freelancer', 'open'], true)) return false;
    if (in_array($job['status'], ['completed', 'cancelled'], true)) return false;
    $d = $db->prepare("SELECT COUNT(*) FROM platform_deliveries WHERE milestone_id = ?");
    $d->execute([$m['id']]);
    return (int)$d->fetchColumn() === 0;
}

/**
 * Kalemleri günceller.
 * $rows: item_id => ['qty' => float, 'agency_unit' => ?float, 'fl_unit' => ?float, 'name' => ?string, 'delete' => bool]
 * $by: 'staff' | 'agency'. Döner: değişen kalem sayısı
 */
function job_items_apply(array $job, array $rows, string $by): int {
    global $db;
    $job_id = (int)$job['id'];
    $items = [];
    foreach (job_items($job_id) as $it) $items[(int)$it['id']] = $it;
    $rush_pct = (int)$job['is_rush'] === 1 ? (float)platform_setting('platform_rush_fee_percent') : 0.0;
    $d_ag = $d_fl = $d_rush = 0.0;
    $ms_delta = [];   // milestone_id => [ag, fl]
    $changed = 0;
    $agency_lines = $fl_lines = [];

    foreach ($rows as $iid => $r) {
        $iid = (int)$iid;
        if (!isset($items[$iid]) || ($items[$iid]['status'] ?? 'active') === 'cancelled') continue;
        $it = $items[$iid];
        $q0 = (float)$it['quantity'];
        $au0 = (float)$it['agency_unit_price'];
        $fu0 = (float)$it['freelancer_unit_fee'];
        $del = !empty($r['delete']);
        $q = $del ? 0.0 : max(0, round((float)($r['qty'] ?? $q0), 2));
        if ($q <= 0) $del = true;
        $au = $by === 'staff' && isset($r['agency_unit']) ? max(0, round((float)$r['agency_unit'], 2)) : $au0;
        $fu = $by === 'staff' && isset($r['fl_unit']) ? max(0, round((float)$r['fl_unit'], 2)) : $fu0;
        $name = $by === 'staff' && isset($r['name']) && trim((string)$r['name']) !== '' ? mb_substr(trim((string)$r['name']), 0, 190) : $it['name'];
        if (!$del && abs($q - $q0) < 0.001 && abs($au - $au0) < 0.001 && abs($fu - $fu0) < 0.001 && $name === $it['name']) continue;

        $ag_delta = ($del ? 0 : $q * $au) - $q0 * $au0;
        $fl_delta = ($del ? 0 : $q * $fu) - $q0 * $fu0;
        if ($del) {
            $db->prepare("UPDATE platform_job_items SET status = 'cancelled' WHERE id = ?")->execute([$iid]);
        } else {
            $db->prepare("UPDATE platform_job_items SET quantity = ?, agency_unit_price = ?, freelancer_unit_fee = ?, name = ? WHERE id = ?")->execute([$q, $au, $fu, $name, $iid]);
        }
        // Teklifte olan (onaylanmamış) ek kalem işin fiyatına henüz eklenmemiştir
        $counted = ($it['status'] ?? 'active') !== 'proposed';
        if ($counted) {
            $rush_part = (int)$it['is_extra'] === 0 ? round($ag_delta * $rush_pct / 100, 2) : 0;
            $d_ag += $ag_delta + $rush_part;
            $d_rush += $rush_part;
            $d_fl += $fl_delta;
        }
        if ($it['milestone_id']) {
            $ms_delta[(int)$it['milestone_id']][0] = ($ms_delta[(int)$it['milestone_id']][0] ?? 0) + $ag_delta;
            $ms_delta[(int)$it['milestone_id']][1] = ($ms_delta[(int)$it['milestone_id']][1] ?? 0) + $fl_delta;
        }
        $what = $del ? 'çıkarıldı' : trim((abs($q - $q0) >= 0.001 ? qty_label($q0) . ' → ' . qty_label($q) . ' ' . $it['unit'] : '') . ($name !== $it['name'] ? ' · ad: ' . $name : ''));
        $agency_lines[] = $it['name'] . ': ' . ($what !== '' ? $what : 'fiyat güncellendi') . (abs($ag_delta) >= 0.01 ? ' (' . ($ag_delta > 0 ? '+' : '') . format_money($ag_delta) . ')' : '');
        $fl_lines[] = $it['name'] . ': ' . ($what !== '' ? $what : 'ücret güncellendi') . (abs($fl_delta) >= 0.01 ? ' (' . ($fl_delta > 0 ? '+' : '') . format_money($fl_delta) . ')' : '');
        $changed++;
    }
    if (!$changed) return 0;

    // Aşamalar: tutar farkı; tüm kalemleri çıkarılan ve başlamamış aşama iptal edilir
    foreach ($ms_delta as $mid => [$ag, $fl]) {
        $m = get_milestone($mid);
        if (!$m) continue;
        $left = (int)$db->query("SELECT COUNT(*) FROM platform_job_items WHERE milestone_id = {$mid} AND status != 'cancelled'")->fetchColumn();
        if ($left === 0 && in_array($m['status'], ['proposed', 'pending_freelancer', 'open'], true) && (int)$m['is_extra'] === 1) {
            $db->prepare("UPDATE platform_milestones SET status = 'cancelled' WHERE id = ?")->execute([$mid]);
            job_event($job_id, 'extra', 'Ek kalem kaldırıldı: ' . $m['title'], ['milestone_id' => $mid, 'visibility' => 'all']);
            continue;
        }
        $db->prepare("UPDATE platform_milestones SET fee = GREATEST(0, fee + ?), agency_amount = GREATEST(0, agency_amount + ?) WHERE id = ?")
           ->execute([round($fl, 2), (float)$m['agency_amount'] > 0 || (int)$m['is_extra'] === 1 ? round($ag, 2) : 0, $mid]);
        if ((int)$m['is_extra'] === 1) {
            $names = $db->query("SELECT name, quantity FROM platform_job_items WHERE milestone_id = {$mid} AND status != 'cancelled' ORDER BY id")->fetchAll();
            $title = 'Ek: ' . implode(', ', array_map(fn($i) => $i['name'] . ((float)$i['quantity'] != 1 ? ' × ' . qty_label((float)$i['quantity']) : ''), $names));
            $db->prepare("UPDATE platform_milestones SET title = ? WHERE id = ?")->execute([mb_strimwidth($title, 0, 190, '…'), $mid]);
        }
    }

    if (abs($d_ag) >= 0.005 || abs($d_fl) >= 0.005) {
        $db->prepare("UPDATE platform_jobs SET agency_price = GREATEST(0, COALESCE(agency_price, 0) + ?), freelancer_fee = GREATEST(0, COALESCE(freelancer_fee, 0) + ?), rush_fee = GREATEST(0, rush_fee + ?) WHERE id = ?")
           ->execute([round($d_ag, 2), round($d_fl, 2), round($d_rush, 2), $job_id]);
    }
    // Ana kalemler değiştiyse ve iş henüz başlamadıysa aşama planı kalemlere göre yeniden kurulur
    if (job_milestones($job_id)) milestones_sync($job_id);

    $by_label = $by === 'staff' ? 'Ekip' : 'Ajans';
    job_event($job_id, 'change', "{$by_label} kalemleri güncelledi", ['new' => implode("\n", $agency_lines), 'amount' => abs($d_ag) >= 0.01 ? round($d_ag, 2) : null, 'visibility' => 'agency']);
    job_event($job_id, 'change', "Kalemler güncellendi", ['new' => implode("\n", $fl_lines), 'amount' => abs($d_fl) >= 0.01 ? round($d_fl, 2) : null, 'visibility' => 'freelancer']);

    if ($by === 'agency') {
        notify_staff("{$job['job_code']} · ajans ek kalemi güncelledi: " . implode('; ', $agency_lines), $job_id);
    } else {
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} · iş kalemleri ekip tarafından güncellendi.", "/platform/job.php?id={$job_id}", $job_id);
    }
    if (!empty($job['assigned_user_id'])) {
        notify_user((int)$job['assigned_user_id'], "{$job['job_code']} · iş kalemleri güncellendi: " . mb_strimwidth(implode('; ', $fl_lines), 0, 200, '…'), "/platform/job.php?id={$job_id}#asamalar", $job_id);
    }
    return $changed;
}

/**
 * Ekip işe katalogdan kalem ekler. İş başlamadıysa ana kaleme, başladıysa
 * (ajans onayı beklemeden kapsama giren) ek kalem olarak eklenir.
 */
function job_item_add_staff(array $job, int $service_id, float $qty): bool {
    global $db;
    $items = build_order_items([$service_id => $qty]);
    if (!$items) return false;
    $job_id = (int)$job['id'];
    $started = !in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)
        || (int)$db->query("SELECT COUNT(*) FROM platform_deliveries WHERE job_id = {$job_id}")->fetchColumn() > 0;
    if ($started) {
        extra_create($job, 'Ek: ' . $items[0]['name'] . ($qty != 1 ? ' × ' . qty_label($qty) : ''), '', $items[0]['quantity'] * $items[0]['agency_unit_price'], $items[0]['quantity'] * $items[0]['freelancer_unit_fee'], $job['deadline'], 'agency', $items);
        return true;
    }
    $it = $items[0];
    $db->prepare("INSERT INTO platform_job_items (job_id, service_id, name, unit, quantity, agency_unit_price, freelancer_unit_fee) VALUES (?, ?, ?, ?, ?, ?, ?)")
       ->execute([$job_id, $it['service_id'], $it['name'], $it['unit'], $it['quantity'], $it['agency_unit_price'], $it['freelancer_unit_fee']]);
    $rush_pct = (int)$job['is_rush'] === 1 ? (float)platform_setting('platform_rush_fee_percent') : 0.0;
    $ag = $it['quantity'] * $it['agency_unit_price'];
    $rush = round($ag * $rush_pct / 100, 2);
    $db->prepare("UPDATE platform_jobs SET agency_price = COALESCE(agency_price, 0) + ?, freelancer_fee = COALESCE(freelancer_fee, 0) + ?, rush_fee = rush_fee + ? WHERE id = ?")
       ->execute([round($ag + $rush, 2), round($it['quantity'] * $it['freelancer_unit_fee'], 2), $rush, $job_id]);
    if (job_milestones($job_id)) milestones_sync($job_id);
    job_event($job_id, 'change', 'Ekip kalem ekledi: ' . $it['name'] . ' × ' . qty_label((float)$it['quantity']), ['amount' => round($ag + $rush, 2), 'visibility' => 'agency']);
    notify_contact_users($job['agency_contact_id'], "{$job['job_code']} · ekip işe kalem ekledi: {$it['name']}", "/platform/job.php?id={$job_id}", $job_id);
    return true;
}
