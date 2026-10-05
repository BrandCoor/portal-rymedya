<?php
/**
 * ====================================================================
 * BELGE ŞABLONLARI (proforma fatura, cari ekstre) — ortak yardımcılar
 * ====================================================================
 * Şirket bilgileri, banka, notlar ve başlıklar Ayarlar → Şirket ve künye,
 * Banka ve ödeme, Belgeler bölümlerinden gelir. Çıktılar A4'e göre
 * tasarlanmıştır; stil sayfaya gömülüdür (dış CSS / CDN gerektirmez).
 */

/** Şirket (düzenleyen) bilgileri */
function doc_company(): array {
    $s = fn(string $k) => trim((string)site_setting($k));
    return [
        'name'     => $s('company_name'),
        'brand'    => $s('company_brand_name'),
        'address'  => $s('company_address'),
        'district' => $s('company_district'),
        'city'     => $s('company_city'),
        'phone'    => $s('company_phone'),
        'email'    => $s('company_email'),
        'web'      => $s('company_website'),
        'tax_office' => $s('company_tax_office'),
        'tax_no'   => $s('company_tax_number'),
        'mersis'   => $s('company_trade_registry'),
        'sicil'    => $s('company_trade_registry_no'),
        'kep'      => $s('company_kep'),
    ];
}

/** Ayarlardaki banka hesapları: [[banka, alıcı, iban], ...] */
function doc_banks(): array {
    $out = [];
    $recv = trim((string)site_setting('bank_primary_receiver')) ?: (string)site_setting('company_name');
    if (trim((string)site_setting('bank_primary_iban')) !== '') {
        $out[] = [site_setting('bank_primary_name') ?: 'Banka', $recv, site_setting('bank_primary_iban')];
    }
    if (site_setting('doc_show_second_bank') === '1' && trim((string)site_setting('bank_secondary_iban')) !== '') {
        $out[] = [site_setting('bank_secondary_name') ?: 'Banka', $recv, site_setting('bank_secondary_iban')];
    }
    if (!$out) {
        global $db;
        foreach ($db->query("SELECT account_name, bank_name, iban FROM accounts WHERE account_type = 'bank' AND iban IS NOT NULL AND iban != '' AND status = 'active' ORDER BY id LIMIT 2")->fetchAll() as $a) {
            $out[] = [$a['bank_name'] ?: $a['account_name'], $recv, $a['iban']];
        }
    }
    return $out;
}

/** Logo (ayarlarda açıksa) ya da marka adı */
function doc_brand_html(): string {
    $logo = site_setting('doc_show_logo') === '1' ? site_image('brand_logo') : '';
    if ($logo !== '') return '<img class="doc-logo" src="' . e($logo) . '" alt="' . e(site_setting('company_brand_name')) . '">';
    $mark = mb_substr((string)site_setting('brand_mark_text'), 0, 3);
    return '<div class="doc-mark">' . e($mark !== '' ? $mark : 'RY') . '</div>';
}

/** Tutarın yazıyla gösterimi: "Yalnız: Bin İki Yüz Türk Lirası Elli Kuruş" */
function tr_amount_words(float $amount, string $currency = 'TRY'): string {
    $ones = ['', 'Bir', 'İki', 'Üç', 'Dört', 'Beş', 'Altı', 'Yedi', 'Sekiz', 'Dokuz'];
    $tens = ['', 'On', 'Yirmi', 'Otuz', 'Kırk', 'Elli', 'Altmış', 'Yetmiş', 'Seksen', 'Doksan'];
    $three = function (int $n) use ($ones, $tens): string {
        $h = intdiv($n, 100); $t = intdiv($n % 100, 10); $o = $n % 10;
        return trim(($h ? ($h > 1 ? $ones[$h] : '') . 'Yüz' : '') . $tens[$t] . $ones[$o]);
    };
    $words = function (int $n) use ($three): string {
        if ($n === 0) return 'Sıfır';
        $units = ['', 'Bin', 'Milyon', 'Milyar', 'Trilyon'];
        $parts = [];
        $i = 0;
        while ($n > 0) {
            $chunk = $n % 1000;
            if ($chunk) $parts[] = ($i === 1 && $chunk === 1 ? '' : $three($chunk)) . $units[$i];
            $n = intdiv($n, 1000);
            $i++;
        }
        return implode('', array_reverse($parts));
    };
    $neg = $amount < 0;
    $amount = round(abs($amount), 2);
    $lira = (int)floor($amount);
    $kurus = (int)round(($amount - $lira) * 100);
    $cur = ['TRY' => ['Türk Lirası', 'Kuruş'], 'USD' => ['ABD Doları', 'Sent'], 'EUR' => ['Avro', 'Sent'], 'GBP' => ['İngiliz Sterlini', 'Peni']][$currency] ?? [$currency, ''];
    // Sayılar Türkçe yazımda bitişik değil, boşluklu okunur hale getirilir
    $space = fn(string $s) => trim(preg_replace('/(?<!^)(?=(Bir|İki|Üç|Dört|Beş|Altı|Yedi|Sekiz|Dokuz|On|Yirmi|Otuz|Kırk|Elli|Altmış|Yetmiş|Seksen|Doksan|Yüz|Bin|Milyon|Milyar|Trilyon)(?![a-zçğıöşü]))/u', ' ', $s));
    $out = $space($words($lira)) . ' ' . $cur[0];
    if ($kurus > 0 && $cur[1] !== '') $out .= ' ' . $space($words($kurus)) . ' ' . $cur[1];
    return ($neg ? 'Eksi ' : '') . $out;
}

/**
 * Fatura kalemleri: [açıklama, miktar, birim, birim fiyat, tutar]
 * Platform işinden kesilmişse iş kalemleri; hakediş (alış) faturasında aşamalar;
 * aksi halde fatura açıklamasıyla tek kalem. Kalem toplamı matrahla tutmazsa
 * fark "ek hizmet / düzeltme" ya da "indirim" satırıyla eşitlenir.
 */
function invoice_lines(array $inv): array {
    global $db;
    $lines = [];
    $subtotal = round((float)$inv['subtotal'], 2);
    try {
        if ($inv['invoice_type'] === 'sales') {
            $j = $db->prepare("SELECT id, job_code, title, rush_fee FROM platform_jobs WHERE sales_invoice_id = ? LIMIT 1");
            $j->execute([$inv['id']]);
            if ($job = $j->fetch()) {
                $it = $db->prepare("SELECT name, quantity, unit, agency_unit_price FROM platform_job_items WHERE job_id = ? AND status = 'active' ORDER BY is_extra, id");
                $it->execute([$job['id']]);
                foreach ($it->fetchAll() as $r) {
                    $lines[] = [$r['name'], (float)$r['quantity'], $r['unit'] ?: 'adet', (float)$r['agency_unit_price'], round((float)$r['quantity'] * (float)$r['agency_unit_price'], 2), $job['job_code']];
                }
                if ((float)$job['rush_fee'] > 0) $lines[] = ['Acil iş farkı', 1, 'adet', (float)$job['rush_fee'], (float)$job['rush_fee'], $job['job_code']];
                if (!$lines) $lines[] = [$job['title'], 1, 'proje', $subtotal, $subtotal, $job['job_code']];
            }
        } else {
            $m = $db->prepare("SELECT m.title, m.fee, j.job_code FROM platform_milestones m JOIN platform_jobs j ON j.id = m.job_id WHERE m.purchase_invoice_id = ? ORDER BY m.id");
            $m->execute([$inv['id']]);
            foreach ($m->fetchAll() as $r) $lines[] = [$r['title'], 1, 'adet', (float)$r['fee'], (float)$r['fee'], $r['job_code']];
        }
    } catch (Throwable $e) {
        $lines = [];
    }
    if (!$lines) {
        $desc = trim((string)($inv['notes'] ?? '')) ?: ($inv['project_name'] ?? '') ?: 'Prodüksiyon hizmet bedeli';
        return [[$desc, 1, 'adet', $subtotal, $subtotal, $inv['project_code'] ?? '']];
    }
    $diff = round($subtotal - array_sum(array_column($lines, 4)), 2);
    if (abs($diff) >= 0.01) {
        $lines[] = [$diff > 0 ? 'Ek hizmet / düzeltme' : 'İndirim', 1, 'adet', $diff, $diff, ''];
    }
    return $lines;
}

/**
 * Ekstre dönemi. $p: week | month | year | all | custom
 * Döner: [from|null, to|null, etiket, önceki referans, sonraki referans]
 */
function statement_period(string $p, ?string $ref, ?string $from = null, ?string $to = null): array {
    $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $d = $ref && strtotime($ref) ? date('Y-m-d', strtotime($ref)) : date('Y-m-d');
    $t = strtotime($d);
    switch ($p) {
        case 'week':
            $f = date('Y-m-d', strtotime('monday this week', $t));
            $l = date('Y-m-d', strtotime('sunday this week', $t));
            return [$f, $l, format_date($f) . ' – ' . format_date($l) . ' haftası', date('Y-m-d', strtotime('-7 day', strtotime($f))), date('Y-m-d', strtotime('+7 day', strtotime($f)))];
        case 'year':
            $y = (int)date('Y', $t);
            return ["{$y}-01-01", "{$y}-12-31", "{$y} yılı", ($y - 1) . '-06-01', ($y + 1) . '-06-01'];
        case 'custom':
            $f = $from && strtotime($from) ? date('Y-m-d', strtotime($from)) : date('Y-m-01');
            $l = $to && strtotime($to) ? date('Y-m-d', strtotime($to)) : date('Y-m-d');
            if ($l < $f) [$f, $l] = [$l, $f];
            return [$f, $l, format_date($f) . ' – ' . format_date($l), null, null];
        case 'all':
            return [null, null, 'Tüm hareketler', null, null];
        default: // month
            $f = date('Y-m-01', $t);
            $l = date('Y-m-t', $t);
            return [$f, $l, $months[(int)date('n', $t)] . ' ' . date('Y', $t), date('Y-m-d', strtotime('-1 month', strtotime($f))), date('Y-m-d', strtotime('+1 month', strtotime($f)))];
    }
}

/** Belgelerin ortak A4 stili */
function doc_css(): string {
    $accent = valid_hex_color(site_setting('brand_accent_color')) ? site_setting('brand_accent_color') : '#D2462F';
    return <<<CSS
:root{--ink:#151517;--ink2:#3D3D43;--muted:#6E6D74;--faint:#A3A2A8;--line:#DAD8D2;--line2:#ECEAE5;--soft:#F7F6F3;--accent:{$accent}}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:#E9E8E4;color:var(--ink);font:12px/1.5 "Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.bar{position:sticky;top:0;z-index:5;background:#151517;color:#fff;padding:10px 16px}
.bar-in{max-width:210mm;margin:0 auto;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.bar a,.bar button{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:8px;border:1px solid #3a3a40;background:#232327;color:#fff;font:600 12px/1 inherit;font-family:inherit;text-decoration:none;cursor:pointer}
.bar .primary{background:var(--accent);border-color:var(--accent)}
.bar .on{background:#fff;color:#151517;border-color:#fff}
.bar .grp{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.bar input{height:32px;border-radius:8px;border:1px solid #3a3a40;background:#232327;color:#fff;padding:0 8px;font:inherit}
.page{width:210mm;min-height:297mm;margin:16px auto 40px;background:#fff;padding:14mm 14mm 12mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.25);display:flex;flex-direction:column}
.grow{flex:1}
.head{display:grid;grid-template-columns:1.3fr 1fr;gap:18px;align-items:start;padding-bottom:12px;border-bottom:2px solid var(--ink)}
.doc-logo{height:46px;width:auto;max-width:200px;object-fit:contain;display:block;margin-bottom:8px}
.doc-mark{width:44px;height:44px;border-radius:10px;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;margin-bottom:8px}
.co-name{font-size:14px;font-weight:800;letter-spacing:-.01em}
.co-line{color:var(--ink2);font-size:11px;line-height:1.55}
.title-box{border:1.5px solid var(--ink);border-radius:6px;overflow:hidden}
.title-box h1{margin:0;padding:8px 10px;background:var(--ink);color:#fff;font-size:15px;letter-spacing:.08em;text-align:center}
.meta{width:100%;border-collapse:collapse;font-size:11px}
.meta td{padding:5px 10px;border-top:1px solid var(--line2)}
.meta td:first-child{color:var(--muted);width:46%}
.meta td:last-child{font-weight:700;text-align:right}
.parties{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:14px 0}
.box{border:1px solid var(--line);border-radius:6px;padding:10px 12px}
.box .lbl{font-size:9.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.box .nm{font-size:13px;font-weight:800;margin-bottom:2px}
table.items{width:100%;border-collapse:collapse;font-size:11px;margin-top:4px}
table.items th{background:var(--soft);border-top:1.5px solid var(--ink);border-bottom:1.5px solid var(--ink);padding:7px 6px;text-align:left;font-size:10px;letter-spacing:.04em;text-transform:uppercase;color:var(--ink2)}
table.items td{padding:7px 6px;border-bottom:1px solid var(--line2);vertical-align:top}
table.items .r{text-align:right;white-space:nowrap}
table.items .c{text-align:center}
table.items tbody tr:nth-child(even) td{background:#FCFBFA}
.sub{color:var(--muted);font-size:10px}
.sumrow{display:grid;grid-template-columns:1fr 78mm;gap:14px;margin-top:12px;align-items:start}
table.sum{width:100%;border-collapse:collapse;font-size:11.5px}
table.sum td{padding:5px 8px;border-bottom:1px solid var(--line2)}
table.sum td:last-child{text-align:right;font-weight:700;white-space:nowrap}
table.sum tr.total td{background:var(--ink);color:#fff;font-size:13px;border:0}
.words{border:1px dashed var(--line);border-radius:6px;padding:8px 10px;font-size:11px}
.words b{display:block;font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:2px}
.note{font-size:10.5px;color:var(--ink2);margin-top:8px;white-space:pre-line}
.foot{margin-top:14px;padding-top:10px;border-top:1px solid var(--line);display:grid;grid-template-columns:1.4fr 1fr;gap:14px;font-size:10.5px}
.bank{margin-bottom:4px}
.bank b{font-weight:700}
.mono{font-family:Consolas,"SFMono-Regular",Menlo,monospace;letter-spacing:.02em}
.sign{border-top:1px solid var(--ink2);padding-top:6px;text-align:center;font-size:10.5px;color:var(--ink2);margin-top:34px}
.disclaimer{margin-top:10px;padding:8px 10px;background:var(--soft);border-left:3px solid var(--accent);font-size:10.5px;color:var(--ink2)}
.stamp{display:inline-block;padding:2px 8px;border:1.5px solid var(--accent);color:var(--accent);border-radius:4px;font-weight:800;font-size:10px;letter-spacing:.1em}
.neg{color:#1C7347}
.ttl-mini{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:800}
@media screen and (max-width:820px){.tscroll{overflow-x:auto;-webkit-overflow-scrolling:touch}.tscroll table.items{min-width:600px}.page{width:auto;margin:10px;padding:16px;min-height:0}.head,.parties,.sumrow,.foot{grid-template-columns:1fr}table.items{font-size:10.5px}.hide-sm{display:none}}
@media print{@page{size:A4;margin:0}body{background:#fff}.bar{display:none!important}.page{margin:0;box-shadow:none;width:210mm;min-height:297mm}tr{page-break-inside:avoid}}
CSS;
}

/** Şirket bloğu (başlığın sol tarafı) */
function doc_company_html(): string {
    $c = doc_company();
    $loc = trim(implode(' ', array_filter([$c['district'], $c['city'] !== '' ? $c['city'] : null])), ' ');
    $lines = array_filter([
        $c['address'] !== '' ? nl2br(e($c['address'])) . ($loc !== '' ? ' ' . e($loc) : '') : ($loc !== '' ? e($loc) : ''),
        trim(($c['phone'] !== '' ? 'Tel: ' . e($c['phone']) : '') . ($c['email'] !== '' ? ' · ' . e($c['email']) : '') . ($c['web'] !== '' ? ' · ' . e(preg_replace('#^https?://#', '', $c['web'])) : ''), ' ·'),
        $c['tax_office'] !== '' || $c['tax_no'] !== '' ? 'Vergi Dairesi: <b>' . e($c['tax_office']) . '</b> · VKN: <b>' . e($c['tax_no']) . '</b>' : '',
        trim(($c['mersis'] !== '' ? 'MERSİS: ' . e($c['mersis']) : '') . ($c['sicil'] !== '' ? ' · Ticaret Sicil: ' . e($c['sicil']) : ''), ' ·'),
        $c['kep'] !== '' ? 'KEP: ' . e($c['kep']) : '',
    ]);
    return doc_brand_html() . '<div class="co-name">' . e($c['name'] ?: $c['brand']) . '</div>'
        . (site_setting('doc_tagline') !== '' ? '<div class="co-line" style="margin-bottom:4px">' . e(site_setting('doc_tagline')) . '</div>' : '')
        . '<div class="co-line">' . implode('<br>', $lines) . '</div>';
}
