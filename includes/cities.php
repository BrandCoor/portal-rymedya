<?php
/**
 * ====================================================================
 * TÜRKİYE İLLERİ (81 İL) — ŞEHİR SEÇİMİ
 * ====================================================================
 * Şehir alanları elle yazılmaz; bu listeden seçilir. Böylece ajans işi,
 * freelancer profili ve şehir eşleşmesi (havuz görünürlüğü) aynı yazımı kullanır.
 */

const TR_CITIES = [
    'Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Aksaray', 'Amasya', 'Ankara', 'Antalya', 'Ardahan', 'Artvin',
    'Aydın', 'Balıkesir', 'Bartın', 'Batman', 'Bayburt', 'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur',
    'Bursa', 'Çanakkale', 'Çankırı', 'Çorum', 'Denizli', 'Diyarbakır', 'Düzce', 'Edirne', 'Elazığ', 'Erzincan',
    'Erzurum', 'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari', 'Hatay', 'Iğdır', 'Isparta', 'İstanbul',
    'İzmir', 'Kahramanmaraş', 'Karabük', 'Karaman', 'Kars', 'Kastamonu', 'Kayseri', 'Kırıkkale', 'Kırklareli', 'Kırşehir',
    'Kilis', 'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Mardin', 'Mersin', 'Muğla', 'Muş',
    'Nevşehir', 'Niğde', 'Ordu', 'Osmaniye', 'Rize', 'Sakarya', 'Samsun', 'Siirt', 'Sinop', 'Sivas',
    'Şanlıurfa', 'Şırnak', 'Tekirdağ', 'Tokat', 'Trabzon', 'Tunceli', 'Uşak', 'Van', 'Yalova', 'Yozgat',
    'Zonguldak',
];

/** Türkçe kurallara göre küçük harf (I → ı, İ → i) */
function tr_lower(string $s): string {
    return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], trim($s)), 'UTF-8');
}

/**
 * Serbest metni listedeki il adına çevirir; bulamazsa boş döner.
 * Büyük/küçük harf, Türkçe karakter ve yaygın yazımlar (istanbul, ISTANBUL, Izmir, Afyon, Antep, Urfa, Maraş, İçel) tanınır.
 */
function normalize_city(?string $v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    static $map = null;
    if ($map === null) {
        $plain = fn($s) => strtr(tr_lower($s), ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c']);
        $map = ['plain' => $plain, 'idx' => []];
        foreach (TR_CITIES as $c) $map['idx'][$plain($c)] = $c;
        foreach (['afyon' => 'Afyonkarahisar', 'antep' => 'Gaziantep', 'urfa' => 'Şanlıurfa', 'maras' => 'Kahramanmaraş', 'icel' => 'Mersin', 'k.maras' => 'Kahramanmaraş', 'hakkâri' => 'Hakkari'] as $k => $c) {
            $map['idx'][$k] = $c;
        }
    }
    // "Kadıköy / İstanbul", "İstanbul, Türkiye" gibi girişlerde il parçası aranır
    foreach (preg_split('#\s*[/,\-]\s*#u', $v) ?: [$v] as $part) {
        $k = ($map['plain'])($part);
        if (isset($map['idx'][$k])) return $map['idx'][$k];
    }
    return '';
}

/** Geçerli bir il mi? (boş değer için false) */
function valid_city(?string $v): bool {
    return in_array((string)$v, TR_CITIES, true);
}

/**
 * İl açılır kutusu. Listede olmayan eski bir değer varsa kaybolmasın diye
 * "(kayıtlı)" olarak gösterilir; kaydedilirken yeniden seçilmesi istenir.
 * $opts: required, placeholder, class, style, attrs (ham HTML öznitelikleri)
 */
function city_select(string $name, ?string $value, array $opts = []): string {
    $value = (string)$value;
    $norm = normalize_city($value) ?: $value;
    $html = '<select name="' . e($name) . '" class="' . e($opts['class'] ?? 'select') . '"'
          . (!empty($opts['required']) ? ' required' : '')
          . (isset($opts['style']) ? ' style="' . e($opts['style']) . '"' : '')
          . (isset($opts['attrs']) ? ' ' . $opts['attrs'] : '') . '>';
    $html .= '<option value="">' . e($opts['placeholder'] ?? 'İl seçin') . '</option>';
    if ($norm !== '' && !valid_city($norm)) {
        $html .= '<option value="" selected disabled>' . e($value) . ' (listede yok, yeniden seçin)</option>';
    }
    foreach (TR_CITIES as $c) {
        $html .= '<option value="' . e($c) . '"' . ($c === $norm ? ' selected' : '') . '>' . e($c) . '</option>';
    }
    return $html . '</select>';
}

/**
 * Eski serbest metin şehir kayıtlarını il adına çevirir (migration v7)
 */
function run_city_migrations(): void {
    global $db;
    foreach ([['freelancer_profiles', 'user_id', 'city'], ['contacts', 'id', 'city'], ['platform_jobs', 'id', 'location_city']] as [$t, $pk, $col]) {
        try {
            $rows = $db->query("SELECT `{$pk}` AS k, `{$col}` AS v FROM `{$t}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''")->fetchAll();
        } catch (Throwable $e) {
            continue;
        }
        $up = $db->prepare("UPDATE `{$t}` SET `{$col}` = ? WHERE `{$pk}` = ?");
        foreach ($rows as $r) {
            $n = normalize_city($r['v']);
            if ($n !== '' && $n !== $r['v']) $up->execute([$n, $r['k']]);
        }
    }
}
