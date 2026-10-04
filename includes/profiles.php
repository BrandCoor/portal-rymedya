<?php
/**
 * ====================================================================
 * AJANS VE FREELANCER PROFİL ŞEMASI
 * ====================================================================
 * Profil formu (platform/profile.php), doğrulama ve yöneticinin gördüğü
 * profil özeti bu şemadan üretilir.
 *
 * Alan: [etiket, tip, depo, seçenekler]
 *   depo: c:<sütun> → contacts, p:<sütun> → *_profiles, u:<sütun> → users,
 *         x:<anahtar> → *_profiles.extra (JSON)
 *   tip : text | email | tel | url | textarea | select | bool | city | number | money | iban | tckn | vkn | skills | readonly
 *   seçenekler: 'req' => true (zorunlu), 'opts' => [...] (select), 'hint', 'ph' (placeholder),
 *               'wide' => true (tam satır), 'core' => true (tamamlanma oranına sayılır)
 */

const PROFILE_SCHEMA = [
    'agency' => [
        'company' => ['title' => 'Firma', 'sub' => 'Ajansınızın tanıtım bilgileri.', 'fields' => [
            'company_title' => ['Ajans / firma adı', 'text', 'c:company_title', ['req' => true, 'core' => true]],
            'legal_type'    => ['Şirket türü', 'select', 'x:legal_type', ['opts' => ['' => 'Seçin', 'sahis' => 'Şahıs şirketi', 'ltd' => 'Limited şirket', 'as' => 'Anonim şirket', 'koll' => 'Kollektif / komandit', 'diger' => 'Diğer'], 'core' => true]],
            'sector'        => ['Sektör / uzmanlık', 'text', 'x:sector', ['ph' => 'Reklam, dijital, PR, etkinlik…', 'core' => true]],
            'employees'     => ['Çalışan sayısı', 'select', 'x:employees', ['opts' => ['' => 'Seçin', '1-5' => '1–5', '6-20' => '6–20', '21-50' => '21–50', '51-200' => '51–200', '200+' => '200+']]],
            'founded'       => ['Kuruluş yılı', 'number', 'x:founded', ['min' => 1900, 'max' => 2100]],
            'website'       => ['Web sitesi', 'url', 'p:website', ['ph' => 'https://', 'core' => true]],
            'instagram'     => ['Instagram', 'text', 'x:instagram', ['ph' => '@kullaniciadi']],
            'linkedin'      => ['LinkedIn', 'url', 'x:linkedin', ['ph' => 'https://linkedin.com/company/…']],
            'about'         => ['Ajans hakkında', 'textarea', 'x:about', ['wide' => true, 'ph' => 'Çalıştığınız markalar, uzmanlık alanlarınız, beklentileriniz']],
        ]],
        'contact' => ['title' => 'Yetkili kişi', 'sub' => 'İşlerle ilgili sizinle iletişim kuracağımız kişi.', 'fields' => [
            'full_name' => ['Ad soyad', 'text', 'u:full_name', ['req' => true, 'core' => true]],
            'position'  => ['Görevi / unvanı', 'text', 'x:position', ['ph' => 'Prodüksiyon müdürü', 'core' => true]],
            'phone'     => ['Telefon', 'tel', 'c:phone', ['req' => true, 'core' => true]],
            'phone2'    => ['İkinci telefon', 'tel', 'x:phone2', []],
            'email'     => ['E-posta (giriş adresi)', 'readonly', 'c:email', ['hint' => 'Değişiklik için platform ekibine yazın.']],
            'contact_pref' => ['Tercih edilen iletişim', 'select', 'x:contact_pref', ['opts' => ['' => 'Fark etmez', 'email' => 'E-posta', 'phone' => 'Telefon', 'whatsapp' => 'WhatsApp']]],
        ]],
        'finance' => ['title' => 'Muhasebe iletişimi', 'sub' => 'Fatura ve ödeme yazışmaları bu kişiye de gönderilebilir.', 'fields' => [
            'acc_name'  => ['Ad soyad', 'text', 'x:acc_name', []],
            'acc_email' => ['E-posta', 'email', 'x:acc_email', []],
            'acc_phone' => ['Telefon', 'tel', 'x:acc_phone', []],
        ]],
        'invoice' => ['title' => 'Fatura bilgileri', 'sub' => 'Faturalar bu bilgilerle kesilir; eksiksiz ve resmi kayıtlarla aynı olmalı.', 'fields' => [
            'invoice_title' => ['Resmi fatura ünvanı', 'text', 'x:invoice_title', ['wide' => true, 'hint' => 'Boşsa firma adı kullanılır.', 'core' => true]],
            'tax_office'    => ['Vergi dairesi', 'text', 'c:tax_office', ['core' => true]],
            'tax_number'    => ['Vergi / T.C. kimlik no', 'vkn', 'c:tax_number', ['core' => true]],
            'mersis'        => ['MERSİS no', 'text', 'x:mersis', ['ph' => '16 hane']],
            'kep'           => ['KEP adresi', 'email', 'x:kep', []],
            'e_invoice'     => ['e-Fatura mükellefiyim', 'bool', 'x:e_invoice', []],
            'city'          => ['İl', 'city', 'c:city', ['req' => true, 'core' => true]],
            'district'      => ['İlçe', 'text', 'c:district', ['core' => true]],
            'postcode'      => ['Posta kodu', 'text', 'x:postcode', []],
            'address'       => ['Fatura adresi', 'textarea', 'c:address', ['wide' => true, 'core' => true]],
        ]],
        'prefs' => ['title' => 'Çalışma tercihleri', 'sub' => 'Ekibimizin işlerinizde dikkat etmesini istedikleriniz.', 'fields' => [
            'brand_notes'    => ['Genel notlar', 'textarea', 'x:brand_notes', ['wide' => true, 'ph' => 'Marka kuralları, logo kullanımı, tercih edilen teslim formatları, onay süreçleri…']],
            'delivery_specs' => ['Varsayılan teslim formatı', 'text', 'x:delivery_specs', ['wide' => true, 'ph' => 'Örn. 1080p MP4 H.264, 16:9 + 9:16']],
        ]],
    ],
    'freelancer' => [
        'personal' => ['title' => 'Kişisel bilgiler', 'sub' => '', 'fields' => [
            'full_name' => ['Ad soyad', 'text', 'u:full_name', ['req' => true, 'core' => true]],
            'title'     => ['Unvan', 'text', 'p:title', ['ph' => 'Görüntü yönetmeni, kurgucu…', 'core' => true]],
            'phone'     => ['Telefon', 'tel', 'c:phone', ['req' => true, 'core' => true]],
            'email'     => ['E-posta (giriş adresi)', 'readonly', 'c:email', ['hint' => 'Değişiklik için platform ekibine yazın.']],
            'city'      => ['İl', 'city', 'p:city', ['req' => true, 'core' => true, 'hint' => 'Yerinde çekim işleri ilinize göre listelenir.']],
            'district'  => ['İlçe', 'text', 'c:district', []],
            'address'   => ['Adres', 'textarea', 'c:address', ['wide' => true, 'hint' => 'Ödeme belgesi ve ekipman teslimatları için.']],
        ]],
        'skills' => ['title' => 'Uzmanlık ve deneyim', 'sub' => 'İş havuzunda yalnızca seçtiğiniz alanlardaki işleri görürsünüz.', 'fields' => [
            'skills'      => ['Uzmanlık alanları', 'skills', 'p:skills', ['req' => true, 'wide' => true, 'core' => true]],
            'experience'  => ['Deneyim (yıl)', 'number', 'x:experience', ['min' => 0, 'max' => 60, 'core' => true]],
            'languages'   => ['Diller', 'text', 'x:languages', ['ph' => 'Türkçe, İngilizce']],
            'software'    => ['Kullandığı yazılımlar', 'text', 'x:software', ['wide' => true, 'ph' => 'Premiere Pro, DaVinci Resolve, After Effects']],
            'equipment'   => ['Ekipman', 'textarea', 'p:equipment', ['wide' => true, 'ph' => 'Kamera, lens, ışık, ses ve gimbal ekipmanınız', 'core' => true]],
            'bio'         => ['Hakkımda', 'textarea', 'p:bio', ['wide' => true, 'ph' => 'Çalıştığınız projeler, markalar, tarzınız', 'core' => true]],
            'references'  => ['Referanslar', 'textarea', 'x:references', ['wide' => true, 'ph' => 'Birlikte çalıştığınız ajans/markalar (isteğe bağlı)']],
        ]],
        'links' => ['title' => 'Portfolyo ve bağlantılar', 'sub' => 'Ekibimiz atama yaparken bu çalışmalara bakar.', 'fields' => [
            'portfolio_url' => ['Showreel / portfolyo', 'url', 'p:portfolio_url', ['ph' => 'https://vimeo.com/…', 'core' => true]],
            'website'       => ['Web sitesi', 'url', 'x:website', ['ph' => 'https://']],
            'instagram'     => ['Instagram', 'text', 'x:instagram', ['ph' => '@kullaniciadi']],
            'linkedin'      => ['LinkedIn', 'url', 'x:linkedin', ['ph' => 'https://linkedin.com/in/…']],
            'behance'       => ['Behance / Vimeo / YouTube', 'url', 'x:behance', ['ph' => 'https://']],
        ]],
        'work' => ['title' => 'Çalışma koşulları', 'sub' => '', 'fields' => [
            'is_available'  => ['Yeni iş almaya müsaitim', 'bool', 'p:is_available', ['hint' => 'Kapalıyken işleri görür ama alamaz, teklif veremezsiniz.']],
            'day_rate'      => ['Günlük ücret beklentisi', 'money', 'p:day_rate', ['core' => true]],
            'half_day_rate' => ['Yarım gün ücret beklentisi', 'money', 'x:half_day_rate', []],
            'travel'        => ['Seyahat', 'select', 'x:travel', ['opts' => ['' => 'Seçin', 'city' => 'Yalnızca kendi ilimde', 'near' => 'Yakın illere gidebilirim', 'tr' => 'Türkiye geneli', 'abroad' => 'Yurt dışı dahil'], 'core' => true]],
            'notice_days'   => ['En az kaç gün önceden haber', 'number', 'x:notice_days', ['min' => 0, 'max' => 60]],
            'weekend'       => ['Hafta sonu çalışabilirim', 'bool', 'x:weekend', []],
            'vehicle'       => ['Aracım var', 'bool', 'x:vehicle', []],
            'license'       => ['Ehliyetim var', 'bool', 'x:license', []],
        ]],
        'emergency' => ['title' => 'Acil durumda ulaşılacak kişi', 'sub' => 'Yalnızca yerinde işlerde acil bir durum olursa kullanılır.', 'fields' => [
            'em_name'  => ['Ad soyad', 'text', 'x:em_name', []],
            'em_phone' => ['Telefon', 'tel', 'x:em_phone', []],
            'em_rel'   => ['Yakınlığı', 'text', 'x:em_rel', ['ph' => 'Eş, kardeş…']],
        ]],
        'payout' => ['title' => 'Ödeme ve vergi', 'sub' => 'Hakedişler bu bilgilerle ödenir ve belgelenir. IBAN kendi adınıza (veya şirketinize) ait olmalıdır.', 'fields' => [
            'payout_type'  => ['Ödeme belgesi', 'select', 'x:payout_type', ['opts' => ['' => 'Seçin', 'person' => 'Şahıs — gider pusulası düzenlensin', 'smm' => 'Serbest meslek makbuzu keserim', 'company' => 'Şirketim adına fatura keserim'], 'core' => true, 'wide' => true]],
            'id_number'    => ['T.C. kimlik no', 'tckn', 'c:id_number', ['hint' => 'Gider pusulası ve vergi bildirimi için gereklidir.', 'core' => true]],
            'company_name' => ['Şirket ünvanı', 'text', 'x:company_name', ['hint' => 'Fatura kesiyorsanız.']],
            'tax_office'   => ['Vergi dairesi', 'text', 'c:tax_office', []],
            'tax_number'   => ['Vergi no', 'vkn', 'c:tax_number', []],
            'iban'         => ['IBAN', 'iban', 'c:iban', ['core' => true, 'ph' => 'TR00 0000 0000 0000 0000 0000 00']],
            'iban_holder'  => ['Hesap sahibi', 'text', 'x:iban_holder', ['core' => true]],
            'bank_name'    => ['Banka', 'text', 'x:bank_name', []],
        ]],
    ],
];

/** Profil tablosuna ek alan sütunu */
function run_profile_migrations(): void {
    global $db;
    foreach (['freelancer_profiles', 'agency_profiles'] as $t) {
        $has = $db->query("SHOW COLUMNS FROM {$t} LIKE 'extra'")->fetch();
        if (!$has) $db->exec("ALTER TABLE {$t} ADD COLUMN extra MEDIUMTEXT NULL");
    }
}

function profile_table(string $role): string {
    return $role === 'agency' ? 'agency_profiles' : 'freelancer_profiles';
}

/** Tüm alan değerleri (düz dizi) */
function profile_values(string $role, int $uid): array {
    global $db;
    $t = profile_table($role);
    $p = $db->prepare("SELECT * FROM {$t} WHERE user_id = ?");
    $p->execute([$uid]);
    $prow = $p->fetch() ?: [];
    $u = $db->prepare("SELECT * FROM users WHERE id = ?");
    $u->execute([$uid]);
    $urow = $u->fetch() ?: [];
    $c = $db->prepare("SELECT * FROM contacts WHERE id = ?");
    $c->execute([(int)($prow['contact_id'] ?? $urow['contact_id'] ?? 0)]);
    $crow = $c->fetch() ?: [];
    $extra = json_decode((string)($prow['extra'] ?? ''), true) ?: [];
    $rows = ['u' => $urow, 'p' => $prow, 'c' => $crow, 'x' => $extra];
    $out = [];
    foreach (PROFILE_SCHEMA[$role] as $sec) {
        foreach ($sec['fields'] as $k => [$label, $type, $store]) {
            [$src, $col] = explode(':', $store, 2);
            $out[$k] = $rows[$src][$col] ?? '';
        }
    }
    return $out;
}

function iban_valid(string $iban): bool {
    $iban = strtoupper(preg_replace('/\s+/', '', $iban));
    if (!preg_match('/^TR\d{24}$/', $iban)) return false;
    $r = substr($iban, 4) . '2927' . substr($iban, 2, 2);   // T=29, R=27
    $mod = 0;
    foreach (str_split($r, 7) as $part) $mod = (int)($mod . $part) % 97;
    return $mod === 1;
}

function tckn_valid(string $n): bool {
    if (!preg_match('/^[1-9]\d{10}$/', $n)) return false;
    $d = array_map('intval', str_split($n));
    $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $even = $d[1] + $d[3] + $d[5] + $d[7];
    return (($odd * 7 - $even) % 10 + 10) % 10 === $d[9] && array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
}

/**
 * Formu doğrular ve kaydeder. Döner: hata listesi (boşsa kaydedildi)
 */
function profile_save(string $role, int $uid, int $cid, array $post): array {
    global $db;
    $errors = [];
    $vals = ['u' => [], 'p' => [], 'c' => [], 'x' => []];
    foreach (PROFILE_SCHEMA[$role] as $sec) {
        foreach ($sec['fields'] as $k => [$label, $type, $store, $o]) {
            if ($type === 'readonly') continue;
            [$src, $col] = explode(':', $store, 2);
            $raw = $post[$k] ?? null;
            $v = is_array($raw) ? '' : trim((string)$raw);
            switch ($type) {
                case 'bool':
                    $v = !empty($raw) ? 1 : 0;
                    break;
                case 'skills':
                    $v = implode(',', array_values(array_intersect(array_keys(JOB_CATEGORIES), (array)($raw ?? []))));
                    break;
                case 'city':
                    $v = normalize_city($v);
                    break;
                case 'select':
                    if (!array_key_exists($v, $o['opts'] ?? [])) $v = '';
                    break;
                case 'number':
                    if ($v !== '') {
                        if (!is_numeric($v) || (isset($o['min']) && $v < $o['min']) || (isset($o['max']) && $v > $o['max'])) {
                            $errors[] = "{$label}: geçerli bir sayı girin.";
                        }
                        $v = (string)(int)$v;
                    }
                    break;
                case 'money':
                    $v = $v === '' ? '' : (string)parse_money($v);
                    if ($v === '0' || $v === '0.0') $v = '';
                    break;
                case 'url':
                    if ($v !== '' && !preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
                    if ($v !== '' && !is_safe_url($v)) $errors[] = "{$label}: geçerli bir bağlantı girin.";
                    break;
                case 'email':
                    if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[] = "{$label}: geçerli bir e-posta girin.";
                    break;
                case 'iban':
                    $v = strtoupper(preg_replace('/\s+/', '', $v));
                    if ($v !== '' && !iban_valid($v)) $errors[] = 'IBAN geçersiz. TR ile başlayan 26 haneli IBAN\'ınızı kontrol edin.';
                    if ($v !== '') $v = trim(chunk_split($v, 4, ' '));
                    break;
                case 'tckn':
                    $v = preg_replace('/\D/', '', $v);
                    if ($v !== '' && !tckn_valid($v)) $errors[] = 'T.C. kimlik numarası geçersiz.';
                    break;
                case 'vkn':
                    $v = preg_replace('/\D/', '', $v);
                    if ($v !== '' && !in_array(strlen($v), [10, 11], true)) $errors[] = "{$label}: 10 haneli vergi no veya 11 haneli T.C. kimlik no girin.";
                    break;
                case 'textarea':
                    $v = mb_substr($v, 0, 4000);
                    break;
                default:
                    $v = mb_substr($v, 0, 255);
            }
            if (!empty($o['req']) && ($v === '' || $v === null)) {
                $errors[] = $type === 'skills' ? 'En az bir uzmanlık alanı seçin.' : "{$label} zorunludur.";
            }
            $vals[$src][$col] = $v;
        }
    }
    if ($errors) return array_values(array_unique($errors));

    $t = profile_table($role);
    $p = $db->prepare("SELECT extra FROM {$t} WHERE user_id = ?");
    $p->execute([$uid]);
    $extra = array_merge(json_decode((string)$p->fetchColumn(), true) ?: [], $vals['x']);

    $name = $vals['u']['full_name'];
    $db->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?")->execute([$name, $vals['c']['phone'] ?? null, $uid]);

    $c = $vals['c'];
    if ($role === 'freelancer') {
        $c['company_title'] = $name;
        $c['city'] = $vals['p']['city'] ?? '';
    }
    $c['authorized_person'] = $name;
    $set = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($c)));
    $db->prepare("UPDATE contacts SET {$set} WHERE id = ?")->execute(array_merge(array_map(fn($v) => $v === '' ? null : $v, array_values($c)), [$cid]));

    $pv = $vals['p'];
    $pv['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
    $set = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($pv)));
    $nullable = ['day_rate', 'portfolio_url'];
    $db->prepare("UPDATE {$t} SET {$set} WHERE user_id = ?")->execute(array_merge(array_map(fn($k, $v) => $v === '' && in_array($k, $nullable, true) ? null : $v, array_keys($pv), array_values($pv)), [$uid]));

    $_SESSION['client_user']['full_name'] = $name;
    $_SESSION['client_user']['company_name'] = $role === 'agency' ? ($c['company_title'] ?? $name) : $name;
    return [];
}

/** Tamamlanma oranı (%) ve eksik önemli alanlar */
function profile_completion(string $role, array $v): array {
    $core = $missing = [];
    foreach (PROFILE_SCHEMA[$role] as $sec) {
        foreach ($sec['fields'] as $k => [$label, $type, $store, $o]) {
            if (empty($o['core'])) continue;
            $core[] = $k;
            if ((string)($v[$k] ?? '') === '') $missing[] = $label;
        }
    }
    $pct = $core ? (int)round((count($core) - count($missing)) / count($core) * 100) : 100;
    return [$pct, $missing];
}

/** Bir alanın okunur değeri */
function profile_display(array $f, $v): string {
    [, $type, , $o] = $f;
    if ($type === 'bool') return (int)$v === 1 ? 'Evet' : 'Hayır';
    if ((string)$v === '') return '';
    if ($type === 'select') return (string)($o['opts'][$v] ?? $v);
    if ($type === 'skills') return implode(', ', array_map('job_category_label', array_filter(explode(',', (string)$v))));
    if ($type === 'money') return format_money((float)$v);
    return (string)$v;
}

/** Form (profil sayfası) */
function profile_form_html(string $role, array $v): string {
    ob_start();
    foreach (PROFILE_SCHEMA[$role] as $sk => $sec): ?>
        <section class="card" id="p-<?= e($sk) ?>">
            <div class="card-head"><div><p class="card-title"><?= e($sec['title']) ?></p><?php if ($sec['sub'] !== ''): ?><p class="card-sub"><?= e($sec['sub']) ?></p><?php endif; ?></div></div>
            <div class="card-pad grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($sec['fields'] as $k => $f): [$label, $type, , $o] = $f; $val = $v[$k] ?? ''; $req = !empty($o['req']);
                $wide = !empty($o['wide']) || $type === 'textarea' || $type === 'skills'; ?>
                <?php if ($type === 'bool'): ?>
                    <label class="check option-card<?= $wide ? ' sm:col-span-2' : '' ?>" style="padding:11px 13px;align-items:flex-start">
                        <input type="checkbox" name="<?= e($k) ?>" value="1" <?= (int)$val === 1 ? 'checked' : '' ?>>
                        <span class="small"><?= e($label) ?><?php if (!empty($o['hint'])): ?><br><span class="xsmall text-muted"><?= e($o['hint']) ?></span><?php endif; ?></span>
                    </label>
                <?php else: ?>
                <div class="field<?= $wide ? ' sm:col-span-2' : '' ?>">
                    <label class="label" for="pf-<?= e($k) ?>"><?= e($label) ?><?= $req ? ' <span class="req">*</span>' : '' ?></label>
                    <?php if ($type === 'textarea'): ?>
                        <textarea class="textarea" id="pf-<?= e($k) ?>" name="<?= e($k) ?>" rows="3" placeholder="<?= e($o['ph'] ?? '') ?>"<?= $req ? ' required' : '' ?>><?= e($val) ?></textarea>
                    <?php elseif ($type === 'select'): ?>
                        <select class="select" id="pf-<?= e($k) ?>" name="<?= e($k) ?>"><?php foreach ($o['opts'] as $ok => $ol): ?><option value="<?= e($ok) ?>" <?= (string)$val === (string)$ok ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
                    <?php elseif ($type === 'city'): ?>
                        <?= city_select($k, (string)$val, ['required' => $req]) ?>
                    <?php elseif ($type === 'skills'): $sel = array_filter(explode(',', (string)$val)); ?>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <?php foreach (JOB_CATEGORIES as $ck => $cv): ?>
                            <label class="option-card" style="align-items:center;padding:10px 12px">
                                <input type="checkbox" name="<?= e($k) ?>[]" value="<?= $ck ?>" <?= in_array($ck, $sel, true) ? 'checked' : '' ?>>
                                <i data-lucide="<?= $cv['icon'] ?>" style="width:15px;height:15px;color:var(--muted)"></i><span class="small"><?= e($cv['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($type === 'readonly'): ?>
                        <input class="input" type="text" value="<?= e($val) ?>" disabled>
                    <?php elseif ($type === 'money'): ?>
                        <div class="input-group"><input class="input" id="pf-<?= e($k) ?>" type="text" inputmode="decimal" name="<?= e($k) ?>" value="<?= e((string)$val !== '' ? number_format((float)$val, 2, ',', '.') : '') ?>"><span class="addon">TL</span></div>
                    <?php else:
                        $html_type = ['email' => 'email', 'tel' => 'tel', 'url' => 'text', 'number' => 'number'][$type] ?? 'text';
                        $extra = $type === 'number' ? ' min="' . (int)($o['min'] ?? 0) . '" max="' . (int)($o['max'] ?? 9999) . '"' : '';
                        $extra .= in_array($type, ['tckn', 'vkn'], true) ? ' inputmode="numeric" maxlength="11"' : '';
                        $extra .= $type === 'iban' ? ' style="font-family:var(--font-mono,monospace)" maxlength="34"' : ''; ?>
                        <input class="input" id="pf-<?= e($k) ?>" type="<?= $html_type ?>" name="<?= e($k) ?>" value="<?= e((string)$val) ?>" placeholder="<?= e($o['ph'] ?? '') ?>"<?= $req ? ' required' : '' ?><?= $extra ?>>
                    <?php endif; ?>
                    <?php if (!empty($o['hint'])): ?><span class="hint"><?= e($o['hint']) ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach;
    return (string)ob_get_clean();
}

/** Yönetici için okunur profil özeti */
function profile_view_html(string $role, int $uid): string {
    $v = profile_values($role, $uid);
    [$pct, $missing] = profile_completion($role, $v);
    ob_start(); ?>
    <div class="profile-view">
        <p class="xsmall text-muted" style="margin-bottom:8px">Profil doluluğu: <strong style="color:<?= $pct >= 80 ? 'var(--success)' : 'var(--warning)' ?>">%<?= $pct ?></strong><?= $missing ? ' · eksik: ' . e(implode(', ', array_slice($missing, 0, 6))) . (count($missing) > 6 ? '…' : '') : '' ?></p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach (PROFILE_SCHEMA[$role] as $sec): $rows = [];
            foreach ($sec['fields'] as $k => $f) {
                $d = profile_display($f, $v[$k] ?? '');
                if ($d !== '' && !($f[1] === 'bool' && $d === 'Hayır')) $rows[] = [$f[0], $d, $f[1]];
            }
            if (!$rows) continue; ?>
            <div class="panel" style="padding:12px 14px">
                <p class="eyebrow" style="margin-bottom:6px"><?= e($sec['title']) ?></p>
                <dl class="dl xsmall" style="grid-template-columns:130px 1fr">
                    <?php foreach ($rows as [$l, $d, $t]): ?>
                        <dt><?= e($l) ?></dt>
                        <dd style="white-space:pre-line;overflow-wrap:anywhere"><?php if ($t === 'url' && is_safe_url($d)): ?><a class="link" href="<?= e($d) ?>" target="_blank" rel="noopener"><?= e($d) ?></a><?php else: ?><?= e($d) ?><?php endif; ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}
