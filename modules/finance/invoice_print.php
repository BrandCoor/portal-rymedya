<?php
/**
 * ====================================================================
 * PROFORMA FATURA / HAKEDİŞ BELGESİ (A4 · yazdır / PDF)
 * ====================================================================
 * Satış faturası → "PROFORMA FATURA": düzenleyen şirket, alıcı cari.
 * Alış faturası (freelancer hakedişi) → "HAKEDİŞ BELGESİ": düzenleyen
 * şirket, hizmet sağlayıcı (ödeme yapılacak) freelancer.
 * Şirket, banka, başlık, ödeme koşulları ve notlar Ayarlar'dan gelir.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

$invoice_id = (int)($_GET['id'] ?? 0);

// Yetki: finans yetkili personel veya faturanın sahibi olan portal kullanıcısı
$is_staff_viewer = is_logged_in() && (has_permission('finance.view') || has_permission('finance.invoices'));
$client_contact_scope = null;
if (!$is_staff_viewer) {
    if (!is_client_logged_in()) {
        http_response_code(403);
        die('Yetkisiz erişim!');
    }
    $client_contact_scope = (int)$_SESSION['client_contact_id'];
}

$stmt = $db->prepare("
    SELECT i.*, c.company_title AS client_title, c.authorized_person, c.tax_office AS client_tax_office,
           c.tax_number AS client_tax_number, c.id_number AS client_id_number, c.iban AS client_iban, c.phone AS client_phone, c.email AS client_email,
           c.address AS client_address, c.city AS client_city, c.district AS client_district,
           p.project_name, p.project_code
    FROM invoices i
    LEFT JOIN contacts c ON i.contact_id = c.id
    LEFT JOIN projects p ON i.project_id = p.id
    WHERE i.id = ?
");
$stmt->execute([$invoice_id]);
$inv = $stmt->fetch();

// Portal kullanıcısı yalnızca kendisine kesilmiş satış faturalarını görebilir
if ($inv && $client_contact_scope !== null && ((int)$inv['contact_id'] !== $client_contact_scope || $inv['invoice_type'] !== 'sales')) {
    $inv = false;
}
if (!$inv) {
    http_response_code(404);
    die('Fatura belgesi bulunamadı!');
}

$is_sales = $inv['invoice_type'] === 'sales';
$cur      = 'TRY';
$lines    = invoice_lines($inv);
$vat_rate = (float)$inv['vat_rate'];
$co       = doc_company();
$title    = $is_sales ? (site_setting('proforma_title') ?: 'PROFORMA FATURA') : 'HAKEDİŞ BELGESİ';
$status   = ['paid' => 'ÖDENDİ', 'partial' => 'KISMİ ÖDENDİ', 'unpaid' => 'ÖDENMEDİ'][$inv['payment_status']] ?? '';
$remaining = max(0, (float)$inv['grand_total'] - (float)$inv['paid_amount']);
$money = fn($v) => format_money((float)$v);
$qty   = fn($q) => rtrim(rtrim(number_format((float)$q, 2, ',', '.'), '0'), ',');

// Alıcı (satışta cari, hakedişte şirket) — hakedişte düzenleyen freelancer'dır
$party_lines = function (array $p): string {
    $out = [];
    $loc = trim(($p['district'] ?? '') . ' ' . ($p['city'] ?? ''));
    if (($p['address'] ?? '') !== '' || $loc !== '') $out[] = nl2br(e(trim(($p['address'] ?? '') . ' ' . $loc)));
    if (($p['phone'] ?? '') !== '' || ($p['email'] ?? '') !== '') $out[] = trim((($p['phone'] ?? '') !== '' ? 'Tel: ' . e($p['phone']) : '') . (($p['email'] ?? '') !== '' ? ' · ' . e($p['email']) : ''), ' ·');
    if (($p['tax_office'] ?? '') !== '' || ($p['tax_no'] ?? '') !== '') $out[] = 'Vergi Dairesi: <b>' . e($p['tax_office'] ?: '—') . '</b> · ' . (strlen((string)$p['tax_no']) === 11 ? 'TCKN' : 'VKN') . ': <b>' . e($p['tax_no'] ?: '—') . '</b>';
    if (($p['person'] ?? '') !== '') $out[] = 'Yetkili: ' . e($p['person']);
    return implode('<br>', $out);
};
$client = [
    'name' => $inv['client_title'], 'address' => $inv['client_address'], 'district' => $inv['client_district'], 'city' => $inv['client_city'],
    'phone' => $inv['client_phone'], 'email' => $inv['client_email'], 'tax_office' => $inv['client_tax_office'],
    'tax_no' => $inv['client_tax_number'] ?: $inv['client_id_number'], 'person' => $inv['authorized_person'] !== $inv['client_title'] ? $inv['authorized_person'] : '',
];
$refs = array_values(array_unique(array_filter(array_column($lines, 5))));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= e(str_replace(' ', '_', mb_convert_case($title, MB_CASE_TITLE, 'UTF-8'))) ?>_<?= e($inv['invoice_number']) ?></title>
<style><?= doc_css() ?></style>
</head>
<body>
<div class="bar">
    <div class="bar-in">
        <button type="button" onclick="history.length > 1 ? history.back() : window.close()">← Geri</button>
        <span style="font-size:12px;opacity:.8"><?= e($title) ?> · <?= e($inv['invoice_number']) ?> <span style="opacity:.6">· v<?= APP_VERSION ?></span></span>
        <button type="button" class="primary" onclick="window.print()">Yazdır / PDF</button>
    </div>
</div>

<div class="page">
    <div class="grow">
        <div class="head">
            <div>
                <?= doc_company_html() ?>
            </div>
            <div>
                <div class="title-box">
                    <h1><?= e($title) ?></h1>
                    <table class="meta">
                        <tr><td>Belge no</td><td class="mono"><?= e($inv['invoice_number']) ?></td></tr>
                        <tr><td>Düzenleme tarihi</td><td><?= format_date($inv['issue_date']) ?></td></tr>
                        <?php if (!empty($inv['due_date'])): ?><tr><td>Son ödeme (vade)</td><td><?= format_date($inv['due_date']) ?></td></tr><?php endif; ?>
                        <tr><td>Belge tipi</td><td><?= $is_sales ? 'Satış · Proforma' : 'Alış · Hakediş' ?></td></tr>
                        <tr><td>Para birimi</td><td>TRY</td></tr>
                        <?php if ($refs || !empty($inv['project_code'])): ?><tr><td>Referans</td><td class="mono"><?= e(implode(', ', $refs ?: [$inv['project_code']])) ?></td></tr><?php endif; ?>
                        <?php if ($status !== ''): ?><tr><td>Durum</td><td><span class="stamp"><?= $status ?></span></td></tr><?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="parties">
            <div class="box">
                <div class="lbl"><?= $is_sales ? 'Sayın (alıcı)' : 'Hizmet sağlayıcı (ödeme yapılacak)' ?></div>
                <div class="nm"><?= e($client['name']) ?></div>
                <div class="co-line"><?= $party_lines($client) ?><?php if (!$is_sales && !empty($inv['client_iban'])): ?><br>IBAN: <b class="mono"><?= e($inv['client_iban']) ?></b><?php endif; ?></div>
            </div>
            <div class="box">
                <div class="lbl">Hizmet bilgisi</div>
                <div class="nm" style="font-size:12px"><?= e($inv['project_name'] ?: ($is_sales ? 'Prodüksiyon hizmetleri' : 'Prodüksiyon hizmet hakedişi')) ?></div>
                <div class="co-line">
                    <?php if (!empty($inv['project_code'])): ?>Proje kodu: <b class="mono"><?= e($inv['project_code']) ?></b><br><?php endif; ?>
                    <?php if ($refs): ?>İş: <b class="mono"><?= e(implode(', ', $refs)) ?></b><br><?php endif; ?>
                    Düzenleyen: <b><?= e(site_setting('doc_issuer_name') ?: $co['name']) ?></b>
                </div>
            </div>
        </div>

        <div class="tscroll"><table class="items">
            <thead>
                <tr>
                    <th class="c" style="width:7mm">#</th>
                    <th>Mal / hizmet</th>
                    <th class="r">Miktar</th>
                    <th class="r">Birim fiyat</th>
                    <th class="c hide-sm">KDV</th>
                    <th class="r hide-sm">KDV tutarı</th>
                    <th class="r">Tutar</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $n => [$desc, $q, $unit, $price, $total, $ref]): ?>
                <tr>
                    <td class="c sub"><?= $n + 1 ?></td>
                    <td><b><?= e($desc) ?></b><?php if ($n === 0 && count($lines) === 1 && !empty($inv['notes']) && $inv['notes'] !== $desc): ?><div class="sub"><?= e($inv['notes']) ?></div><?php endif; ?></td>
                    <td class="r"><?= $qty($q) ?> <span class="sub"><?= e($unit) ?></span></td>
                    <td class="r"><?= $money($price) ?></td>
                    <td class="c hide-sm">%<?= $qty($vat_rate) ?></td>
                    <td class="r hide-sm"><?= $money(round($total * $vat_rate / 100, 2)) ?></td>
                    <td class="r"><b><?= $money($total) ?></b></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <div class="sumrow">
            <div>
                <?php if (site_setting('doc_show_amount_words') === '1'): ?>
                <div class="words"><b>Yalnız</b><?= e(tr_amount_words((float)$inv['grand_total'], $cur)) ?></div>
                <?php endif; ?>
                <?php if (!empty($inv['notes']) && count($lines) > 1): ?><p class="note"><b>Açıklama:</b> <?= e($inv['notes']) ?></p><?php endif; ?>
            </div>
            <table class="sum">
                <tr><td>Mal / hizmet toplamı</td><td><?= $money($inv['subtotal']) ?></td></tr>
                <tr><td>Hesaplanan KDV (%<?= $qty($vat_rate) ?>)</td><td><?= $money($inv['vat_amount']) ?></td></tr>
                <tr><td>Vergiler dahil toplam</td><td><?= $money((float)$inv['subtotal'] + (float)$inv['vat_amount']) ?></td></tr>
                <?php if ((float)$inv['withholding_amount'] > 0): ?><tr><td>KDV tevkifatı (<?= e($inv['withholding_rate']) ?>)</td><td>−<?= $money($inv['withholding_amount']) ?></td></tr><?php endif; ?>
                <?php if ((float)$inv['stoppage_amount'] > 0): ?><tr><td>Gelir vergisi stopajı (%<?= $qty($inv['stoppage_rate']) ?>)</td><td>−<?= $money($inv['stoppage_amount']) ?></td></tr><?php endif; ?>
                <tr class="total"><td>Ödenecek tutar</td><td><?= $money($inv['grand_total']) ?></td></tr>
                <?php if ((float)$inv['paid_amount'] > 0): ?>
                    <tr><td>Ödenen</td><td class="neg">−<?= $money($inv['paid_amount']) ?></td></tr>
                    <tr><td><b>Kalan</b></td><td><?= $money($remaining) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div>
        <div class="foot">
            <div>
                <?php if ($is_sales && site_setting('doc_show_bank') === '1' && ($banks = doc_banks())): ?>
                    <div class="ttl-mini" style="margin-bottom:4px">Banka ve ödeme bilgileri</div>
                    <?php foreach ($banks as [$bn, $recv, $iban]): ?>
                        <div class="bank"><b><?= e($bn) ?></b> · <?= e($recv) ?><br><span class="mono"><?= e($iban) ?></span></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($is_sales && site_setting('proforma_payment_terms') !== ''): ?><p class="note"><?= e(site_setting('proforma_payment_terms')) ?></p><?php endif; ?>
            </div>
            <div>
                <?php if (site_setting('doc_show_signature') === '1'): ?>
                    <div class="sign"><b><?= e($co['name']) ?></b><br><?= e(site_setting('doc_signature_label') ?: 'Yetkili İmza / Kaşe') ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($is_sales && site_setting('proforma_disclaimer') !== ''): ?><div class="disclaimer"><?= e(site_setting('proforma_disclaimer')) ?></div><?php endif; ?>
        <?php if (site_setting('invoice_footer_note') !== ''): ?><p class="note" style="text-align:center;color:var(--muted)"><?= e(site_setting('invoice_footer_note')) ?></p><?php endif; ?>
    </div>
</div>
</body>
</html>
