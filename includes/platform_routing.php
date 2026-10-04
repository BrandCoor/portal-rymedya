<?php
/**
 * ====================================================================
 * OTOMATİK YÖNLENDİRME KURALLARI (YENİ İŞ GİRİŞİ)
 * ====================================================================
 * Ajans iş girdiğinde kurallar sırayla denenir; koşullarının tamamını
 * sağlayan İLK kural uygulanır:
 *   - Onay bekle   → iş "Aksiyon bekleyen" listesine düşer (submitted)
 *   - Atamaya gönder → iş onaysız yayına alınır (open, havuz kuralları)
 *   - Ekibe ayır   → iş yayına alınır ama freelancer'lar görmez (internal)
 * Kural, işin görünürlük / dağıtım ayarlarını da değiştirebilir.
 * Hiçbir kural eşleşmezse "platform_auto_publish" ayarı geçerlidir.
 * Özel teklif talepleri fiyat gerektirdiği için her durumda onaya düşer;
 * kural yalnızca görünürlük ayarlarını ve bildirimi belirler.
 * Kurallar system_settings.platform_routing_rules (JSON) içinde saklanır.
 */

const ROUTE_ACTIONS = [
    'review'   => ['Onay bekle (Aksiyon bekleyen)', 'warning'],
    'publish'  => ['Atamaya gönder (onaysız yayın)', 'success'],
    'internal' => ['Ekibe ayır (freelancer görmez)', 'violet'],
];

/** Sayısal koşullar: anahtar => [etiket, birim, karşılaştırma] */
const ROUTE_NUM_CONDITIONS = [
    'price_min'     => ['İş tutarı (ajans, KDV hariç)', '₺', '≥'],
    'price_max'     => ['İş tutarı (ajans, KDV hariç)', '₺', '≤'],
    'fee_min'       => ['Freelancer ücreti', '₺', '≥'],
    'margin_max'    => ['Marj oranı', '%', '≤'],
    'lead_max'      => ['Başlangıca kalan süre', 'saat', '≤'],
    'lead_min'      => ['Başlangıca kalan süre', 'saat', '≥'],
    'duration_max'  => ['Başlangıç → teslim süresi', 'gün', '≤'],
    'items_min'     => ['Toplam hizmet adedi', 'adet', '≥'],
    'agency_jobs_max' => ['Ajansın tamamlanmış iş sayısı', 'iş', '≤'],
];

function routing_rules(): array {
    $rules = json_decode((string)get_setting('platform_routing_rules', ''), true);
    return is_array($rules) ? array_values($rules) : [];
}

function routing_save(array $rules): void {
    global $db;
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('platform_routing_rules', ?, 'platform') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
       ->execute([json_encode(array_values($rules), JSON_UNESCAPED_UNICODE)]);
    get_settings(true);
}

/**
 * Kuralın değerlendirdiği iş bilgileri (henüz kaydedilmemiş iş için de kullanılır)
 * $ctx: source, price, fee, rush, start_date, deadline, items[], category, is_remote, city, agency_id, title, description
 */
function routing_facts(array $ctx): array {
    global $db;
    static $agency_cache = [];
    $aid = (int)($ctx['agency_id'] ?? 0);
    if ($aid && !isset($agency_cache[$aid])) {
        $done = $db->prepare("SELECT COUNT(*) FROM platform_jobs WHERE agency_contact_id = ? AND status = 'completed'");
        $done->execute([$aid]);
        $over = $db->prepare("SELECT COUNT(*) FROM invoices WHERE contact_id = ? AND invoice_type = 'sales' AND payment_status != 'paid' AND due_date IS NOT NULL AND due_date < CURRENT_DATE()");
        $over->execute([$aid]);
        $agency_cache[$aid] = ['jobs' => (int)$done->fetchColumn(), 'overdue' => (int)$over->fetchColumn() > 0];
    }
    $ref = $ctx['start_date'] ?: $ctx['deadline'];
    $price = (float)($ctx['price'] ?? 0);
    $fee = (float)($ctx['fee'] ?? 0);
    return [
        'source'   => $ctx['source'],
        'price'    => $price,
        'fee'      => $fee,
        'margin'   => $price > 0 ? ($price - $fee) / $price * 100 : null,
        'rush'     => !empty($ctx['rush']),
        'lead'     => $ref ? (strtotime($ref . ' 00:00:00') - time()) / 3600 : null,
        'duration' => $ctx['start_date'] && $ctx['deadline'] ? (strtotime($ctx['deadline']) - strtotime($ctx['start_date'])) / 86400 : null,
        'items'    => array_sum(array_map(fn($i) => (float)$i['quantity'], $ctx['items'] ?? [])),
        'services' => array_map(fn($i) => (int)($i['service_id'] ?? 0), $ctx['items'] ?? []),
        'category' => $ctx['category'],
        'remote'   => !empty($ctx['is_remote']),
        'city'     => normalize_city($ctx['city'] ?? ''),
        'agency'   => $aid,
        'agency_jobs' => $aid ? $agency_cache[$aid]['jobs'] : 0,
        'overdue'  => $aid ? $agency_cache[$aid]['overdue'] : false,
        'text'     => mb_strtolower(($ctx['title'] ?? '') . ' ' . ($ctx['description'] ?? '')),
    ];
}

/** Liste koşulu metnini (virgülle ayrılmış) diziye çevirir */
function routing_list(?string $s): array {
    return array_values(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)$s)), fn($x) => $x !== ''));
}

/**
 * Kural koşullarını kontrol eder; [eşleşti mi, sağlanmayan koşulların açıklaması]
 */
function routing_match(array $rule, array $f): array {
    $c = $rule['cond'] ?? [];
    $fail = [];
    $num = function (string $k, $val, callable $ok) use ($c, &$fail) {
        if (!isset($c[$k]) || $c[$k] === '' || $c[$k] === null) return;
        if ($val === null || !$ok((float)$val, (float)$c[$k])) {
            [$l, $u, $op] = ROUTE_NUM_CONDITIONS[$k];
            $fail[] = "{$l} {$op} " . number_format((float)$c[$k], 0, ',', '.') . " {$u}";
        }
    };
    $num('price_min', $f['price'], fn($v, $t) => $v >= $t);
    $num('price_max', $f['price'], fn($v, $t) => $v <= $t);
    $num('fee_min', $f['fee'], fn($v, $t) => $v >= $t);
    $num('margin_max', $f['margin'], fn($v, $t) => $v <= $t);
    $num('lead_max', $f['lead'], fn($v, $t) => $v <= $t);
    $num('lead_min', $f['lead'], fn($v, $t) => $v >= $t);
    $num('duration_max', $f['duration'], fn($v, $t) => $v <= $t);
    $num('items_min', $f['items'], fn($v, $t) => $v >= $t);
    $num('agency_jobs_max', $f['agency'] ? $f['agency_jobs'] : null, fn($v, $t) => $v <= $t);

    $src = $c['source'] ?? '';
    if ($src !== '' && $src !== $f['source']) $fail[] = $src === 'catalog' ? 'Katalogdan iş' : 'Özel teklif talebi';
    $rush = $c['rush'] ?? '';
    if ($rush !== '' && ($rush === 'yes') !== $f['rush']) $fail[] = $rush === 'yes' ? 'Acil iş' : 'Acil olmayan iş';
    $loc = $c['location'] ?? '';
    if ($loc !== '' && ($loc === 'remote') !== $f['remote']) $fail[] = $loc === 'remote' ? 'Uzaktan iş' : 'Yerinde iş';
    if (!empty($c['overdue']) && !$f['overdue']) $fail[] = 'Ajansın vadesi geçmiş borcu var';
    if (!empty($c['categories']) && !in_array($f['category'], (array)$c['categories'], true)) $fail[] = 'İş türü';
    if (!empty($c['services']) && !array_intersect(array_map('intval', (array)$c['services']), $f['services'])) $fail[] = 'Seçili hizmetlerden biri';
    if (!empty($c['agencies']) && !in_array($f['agency'], array_map('intval', (array)$c['agencies']), true)) $fail[] = 'Seçili ajanslar';
    $cities = array_filter(array_map('normalize_city', explode(',', (string)($c['cities'] ?? ''))));
    if ($cities && ($f['remote'] || !in_array($f['city'], $cities, true))) $fail[] = 'İl';
    $words = routing_list($c['keywords'] ?? '');
    if ($words && !array_filter($words, fn($w) => str_contains($f['text'], $w))) $fail[] = 'Anahtar kelime';
    return [!$fail, $fail];
}

/** Kuralın koşullarını okunur metin olarak özetler */
function routing_summary(array $rule): string {
    global $db;
    $c = $rule['cond'] ?? [];
    $p = [];
    foreach (ROUTE_NUM_CONDITIONS as $k => [$l, $u, $op]) {
        if (isset($c[$k]) && $c[$k] !== '' && $c[$k] !== null) $p[] = "{$l} {$op} " . number_format((float)$c[$k], 0, ',', '.') . " {$u}";
    }
    if (($c['source'] ?? '') !== '') $p[] = $c['source'] === 'catalog' ? 'katalog işi' : 'özel talep';
    if (($c['rush'] ?? '') !== '') $p[] = $c['rush'] === 'yes' ? 'acil' : 'acil değil';
    if (($c['location'] ?? '') !== '') $p[] = $c['location'] === 'remote' ? 'uzaktan' : 'yerinde';
    if (!empty($c['overdue'])) $p[] = 'ajansın vadesi geçmiş borcu var';
    if (!empty($c['categories'])) $p[] = 'tür: ' . implode(', ', array_map('job_category_label', (array)$c['categories']));
    if (!empty($c['services'])) {
        $ids = implode(',', array_map('intval', (array)$c['services']));
        $p[] = 'hizmet: ' . implode(', ', $db->query("SELECT name FROM platform_services WHERE id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!empty($c['agencies'])) $p[] = count((array)$c['agencies']) . ' seçili ajans';
    if (!empty($c['cities'])) $p[] = 'şehir: ' . $c['cities'];
    if (!empty($c['keywords'])) $p[] = 'kelime: ' . $c['keywords'];
    return $p ? implode(' · ', $p) : 'Her yeni iş';
}

/**
 * Yeni iş için yönlendirme kararı.
 * Döner: [route, policy, rule|null]
 */
function routing_decide(array $ctx, array $policy): array {
    $f = routing_facts($ctx);
    foreach (routing_rules() as $rule) {
        if (empty($rule['enabled'])) continue;
        [$ok] = routing_match($rule, $f);
        if (!$ok) continue;
        $a = $rule['action'] ?? [];
        $route = array_key_exists($a['route'] ?? '', ROUTE_ACTIONS) ? $a['route'] : 'review';
        foreach (['visibility' => 'visibility', 'dispatch' => 'dispatch_mode', 'min_tier' => 'min_tier', 'priority_tier' => 'priority_tier'] as $k => $col) {
            if (($a[$k] ?? '') !== '') $policy[$col] = $a[$k] === 'none' ? null : $a[$k];
        }
        if (($a['priority_hours'] ?? '') !== '') $policy['priority_hours'] = (int)$a['priority_hours'];
        if (($a['skill_match'] ?? '') !== '') $policy['skill_match_only'] = (int)$a['skill_match'];
        if (($a['city_match'] ?? '') !== '') $policy['city_match_only'] = (int)$a['city_match'];
        if ($policy['priority_tier'] === null) $policy['priority_hours'] = 0;
        if ($route === 'internal') $policy['visibility'] = 'internal';
        if ($ctx['source'] !== 'catalog') $route = 'review';   // özel talep önce fiyatlanır
        return [$route, $policy, $rule];
    }
    $route = $ctx['source'] === 'catalog' && platform_setting('platform_auto_publish') === '1' ? 'publish' : 'review';
    return [$route, $policy, null];
}

/** Boş kural şablonu */
function routing_blank(): array {
    return ['id' => bin2hex(random_bytes(4)), 'name' => '', 'enabled' => 1, 'note' => '', 'cond' => [], 'action' => ['route' => 'review', 'notify' => 0]];
}
