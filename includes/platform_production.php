<?php
/**
 * ====================================================================
 * YAPIM SÜRESİ (EN ERKEN TESLİM TARİHİ)
 * ====================================================================
 * Her hizmetin katalogda bir yapım süresi vardır:
 *   gün = taban süre + birim başına ek süre × (miktar − 1)
 * İşteki kalemlerin süreleri toplanır (veya en uzunu alınır), teslim payı
 * ve ham görüntü teslim süresi eklenir. Teslim tarihi başlangıçtan (yoksa
 * en erken başlanabilecek günden) bu kadar gün sonradan önce olamaz.
 * Kurallar Platform kuralları → "Yapım süresi" bölümünden yönetilir.
 */

/** Kategoriye göre öneri (katalogda süre girilmemiş hizmetler için ilk değer) */
const PRODUCTION_DEFAULTS = [
    'shooting' => [0, 0], 'drone' => [0, 0], 'photo' => [1, 0.5], 'editing' => [2, 1], 'color' => [2, 0.5],
    'sound' => [1, 0.5], 'motion' => [3, 2], 'social' => [2, 1], 'full_production' => [5, 1], 'other' => [2, 0],
];

function run_flow_v14_migrations(): void {
    global $db;
    $cols = array_column($db->query("SHOW COLUMNS FROM platform_services")->fetchAll(), 'Field');
    if (!in_array('production_days', $cols, true)) {
        $db->exec("ALTER TABLE platform_services ADD COLUMN production_days DECIMAL(5,1) NOT NULL DEFAULT 0, ADD COLUMN production_days_per_unit DECIMAL(5,1) NOT NULL DEFAULT 0");
        $up = $db->prepare("UPDATE platform_services SET production_days = ?, production_days_per_unit = ? WHERE category = ?");
        foreach (PRODUCTION_DEFAULTS as $cat => [$d, $pu]) $up->execute([$d, $pu, $cat]);
    }
    // İstek üzerine: freelancer teslimi doğrudan ajansa gider (kalite kontrol ayardan yeniden açılabilir)
    // (tek seferlik: sonradan yönetici yeniden açarsa dokunulmaz)
    if (get_setting('flow_v14_qa_off', '') !== '1') {
        $db->exec("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('platform_qa_required', '0', 'platform') ON DUPLICATE KEY UPDATE setting_value = '0'");
        $db->exec("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('flow_v14_qa_off', '1', 'system') ON DUPLICATE KEY UPDATE setting_value = '1'");
    }
}

/** Kural ayarları */
function production_rules(): array {
    return [
        'min_days'      => max(0, (int)platform_setting('platform_min_production_days')),
        'custom_days'   => max(0, (int)platform_setting('platform_custom_min_days')),
        'buffer_days'   => max(0, (int)platform_setting('platform_production_buffer_days')),
        'raw_days'      => max(0, (int)platform_setting('platform_raw_delivery_days')),
        'combine'       => platform_setting('platform_production_combine') === 'max' ? 'max' : 'sum',
        'skip_weekends' => platform_setting('platform_production_skip_weekends') === '1',
    ];
}

/** Bir kalemin yapım süresi (gün) */
function production_item_days(array $svc, float $qty): float {
    $base = (float)($svc['production_days'] ?? 0);
    $per = (float)($svc['production_days_per_unit'] ?? 0);
    return max(0, $base + $per * max(0, $qty - 1));
}

/**
 * İş için gereken en kısa yapım süresi.
 * $items: build_order_items() çıktısı (service_id, quantity) veya iş kalemleri
 * Döner: ['days' => int, 'lines' => [[ad, gün], ...], 'rules' => ...]
 */
function production_required(array $items, bool $raw = false, string $mode = 'catalog'): array {
    global $db;
    $r = production_rules();
    $lines = [];
    $vals = [];
    $ids = array_filter(array_map(fn($i) => (int)($i['service_id'] ?? 0), $items));
    $svc = [];
    if ($ids) {
        $st = $db->query("SELECT id, name, production_days, production_days_per_unit FROM platform_services WHERE id IN (" . implode(',', array_unique($ids)) . ")");
        foreach ($st->fetchAll() as $s) $svc[(int)$s['id']] = $s;
    }
    foreach ($items as $it) {
        $sid = (int)($it['service_id'] ?? 0);
        if (!$sid || !isset($svc[$sid])) continue;   // ham görüntü vb. sistem kalemleri ayrıca hesaplanır
        $d = production_item_days($svc[$sid], (float)($it['quantity'] ?? 1));
        $vals[] = $d;
        if ($d > 0) $lines[] = [$svc[$sid]['name'], $d];
    }
    $work = $vals ? ($r['combine'] === 'max' ? max($vals) : array_sum($vals)) : 0;
    if ($mode === 'custom') $work = max($work, $r['custom_days']);
    if ($raw && $r['raw_days'] > 0) { $work += $r['raw_days']; $lines[] = ['Ham görüntü teslimi', $r['raw_days']]; }
    if ($work > 0 && $r['buffer_days'] > 0) { $work += $r['buffer_days']; $lines[] = ['Teslim payı', $r['buffer_days']]; }
    $days = (int)max($r['min_days'], (int)ceil($work));
    return ['days' => $days, 'lines' => $lines, 'rules' => $r];
}

/** $from tarihine $days gün ekler (ayara göre hafta sonu atlanır) */
function production_add_days(string $from, int $days, bool $skip_weekends): string {
    $t = strtotime($from . ' 12:00:00');
    while ($days > 0) {
        $t = strtotime('+1 day', $t);
        if ($skip_weekends && (int)date('N', $t) >= 6) continue;
        $days--;
    }
    return date('Y-m-d', $t);
}

/** Başlangıç yoksa işin en erken başlayabileceği gün (iş giriş kuralına göre) */
function production_earliest_start(array $items = []): string {
    $rules = lead_time_rules($items);
    $days = max($rules['block_same_day'] ? 1 : 0, (int)ceil($rules['block_hours'] / 24));
    return date('Y-m-d', strtotime("+{$days} day"));
}

/**
 * Teslim tarihini denetler. Döner: [en erken teslim tarihi, hata mesajı|null, gereken gün]
 */
function production_check(?string $start, ?string $deadline, array $items, bool $raw = false, string $mode = 'catalog'): array {
    $req = production_required($items, $raw, $mode);
    $from = $start ?: production_earliest_start($items);
    $min = production_add_days($from, $req['days'], $req['rules']['skip_weekends']);
    if ($deadline && $deadline < $min) {
        $unit = $req['rules']['skip_weekends'] ? 'iş günü' : 'gün';
        $msg = 'Seçtiğiniz işin yapım süresi en az ' . $req['days'] . ' ' . $unit
             . ($start ? ' (başlangıçtan sonra)' : '') . '. En erken teslim tarihi: ' . format_date($min) . '.';
        return [$min, $msg, $req['days']];
    }
    return [$min, null, $req['days']];
}

/** İstemci tarafı için kural paketi */
function production_js_rules(): array {
    return production_rules() + ['earliest' => production_earliest_start()];
}
