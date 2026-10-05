<?php
/**
 * ====================================================================
 * CARİ HESAP EKSTRESİ (A4 · yazdır / PDF)
 * ====================================================================
 * Dönem: haftalık, aylık, yıllık, tüm hareketler veya özel tarih aralığı.
 * Dönem öncesi hareketler "devreden bakiye" olarak tek satırda gelir;
 * dönem sonu bakiyesi devreden + dönem hareketleridir.
 * Personel (cari görme yetkisi) her cariyi, portal kullanıcısı (müşteri,
 * ajans, freelancer) yalnızca kendi cari hesabını görür.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

$contact_id = (int)($_GET['id'] ?? 0);
$is_staff_viewer = is_logged_in() && has_permission('contacts.view');
if (!$is_staff_viewer) {
    if (!is_client_logged_in()) {
        http_response_code(403);
        die('Yetkisiz erişim!');
    }
    $contact_id = (int)$_SESSION['client_contact_id'];   // yalnızca kendi ekstresi
}

$st = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$st->execute([$contact_id]);
$contact = $st->fetch();
if (!$contact) {
    http_response_code(404);
    die('Cari hesap bulunamadı!');
}

// ---------------- Dönem ----------------
$p = in_array($_GET['p'] ?? '', ['week', 'month', 'year', 'all', 'custom'], true) ? $_GET['p'] : (site_setting('statement_default_period') ?: 'month');
[$from, $to, $period_label, $prev, $next] = statement_period($p, $_GET['d'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
$self = fn(array $q) => '?' . http_build_query(array_filter(['id' => $is_staff_viewer ? $contact_id : null] + $q, fn($v) => $v !== null && $v !== ''));

// ---------------- Hareketler ----------------
$ledger = [];
$inv = $db->prepare("SELECT id, invoice_number, issue_date, invoice_type, grand_total, notes, created_at FROM invoices WHERE contact_id = ?");
$inv->execute([$contact_id]);
foreach ($inv->fetchAll() as $r) {
    $sales = $r['invoice_type'] === 'sales';
    $ledger[] = [
        'date' => $r['issue_date'], 'doc' => $r['invoice_number'], 'sort' => $r['created_at'],
        'type' => $sales ? 'Satış faturası' : 'Alış faturası / hakediş',
        'desc' => $r['notes'] ?: 'Fatura kaydı',
        'debit' => $sales ? (float)$r['grand_total'] : 0.0, 'credit' => $sales ? 0.0 : (float)$r['grand_total'],
    ];
}
$tx = $db->prepare("SELECT id, transaction_date, type, category, amount, description, created_at FROM transactions WHERE contact_id = ?");
$tx->execute([$contact_id]);
foreach ($tx->fetchAll() as $r) {
    $in = $r['type'] === 'income';
    $ledger[] = [
        'date' => $r['transaction_date'], 'doc' => 'DKN-' . $r['id'], 'sort' => $r['created_at'],
        'type' => $in ? 'Tahsilat' : 'Ödeme / borç dekontu',
        'desc' => $r['description'] ?: (string)$r['category'],
        'debit' => $in ? 0.0 : (float)$r['amount'], 'credit' => $in ? (float)$r['amount'] : 0.0,
    ];
}
usort($ledger, fn($a, $b) => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);

$opening = 0.0;
$rows = [];
foreach ($ledger as $l) {
    if ($from !== null && $l['date'] < $from) { $opening += $l['debit'] - $l['credit']; continue; }
    if ($to !== null && $l['date'] > $to) continue;
    $rows[] = $l;
}
$period_debit = array_sum(array_column($rows, 'debit'));
$period_credit = array_sum(array_column($rows, 'credit'));
$closing = $opening + $period_debit - $period_credit;
$money = fn($v) => format_money((float)$v);
$bal_label = fn(float $v) => abs($v) < 0.005 ? '' : ($v > 0 ? ' (B)' : ' (A)');
$title = site_setting('statement_title') ?: 'CARİ HESAP EKSTRESİ';
$co = doc_company();
$tabs = ['week' => 'Haftalık', 'month' => 'Aylık', 'year' => 'Yıllık', 'all' => 'Tümü'];
$ref = $_GET['d'] ?? date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Cari_Ekstre_<?= e(preg_replace('/\s+/', '_', $contact['company_title'])) ?>_<?= e($from ?? 'tum') ?></title>
<style><?= doc_css() ?></style>
</head>
<body>
<div class="bar">
    <div class="bar-in">
        <div class="grp">
            <button type="button" onclick="history.length > 1 ? history.back() : window.close()">← Geri</button>
            <?php foreach ($tabs as $k => $l): ?><a class="<?= $p === $k ? 'on' : '' ?>" href="<?= e($self(['p' => $k, 'd' => $k === 'all' ? null : $ref])) ?>"><?= $l ?></a><?php endforeach; ?>
        </div>
        <div class="grp">
            <?php if ($prev): ?><a href="<?= e($self(['p' => $p, 'd' => $prev])) ?>" title="Önceki dönem">‹</a><?php endif; ?>
            <span style="font-size:12px;font-weight:600;min-width:120px;text-align:center"><?= e($period_label) ?></span>
            <?php if ($next): ?><a href="<?= e($self(['p' => $p, 'd' => $next])) ?>" title="Sonraki dönem">›</a><?php endif; ?>
        </div>
        <form class="grp" method="GET" action="">
            <?php if ($is_staff_viewer): ?><input type="hidden" name="id" value="<?= (int)$contact_id ?>"><?php endif; ?>
            <input type="hidden" name="p" value="custom">
            <input type="date" name="from" value="<?= e($p === 'custom' ? $from : ($from ?? '')) ?>" aria-label="Başlangıç">
            <input type="date" name="to" value="<?= e($p === 'custom' ? $to : ($to ?? '')) ?>" aria-label="Bitiş">
            <button type="submit">Aralık</button>
            <button type="button" class="primary" onclick="window.print()">Yazdır / PDF</button>
            <span style="font-size:11px;opacity:.5">v<?= APP_VERSION ?></span>
        </form>
    </div>
</div>

<div class="page">
    <div class="grow">
        <div class="head">
            <div><?= doc_company_html() ?></div>
            <div>
                <div class="title-box">
                    <h1><?= e($title) ?></h1>
                    <table class="meta">
                        <tr><td>Dönem</td><td><?= e($period_label) ?></td></tr>
                        <?php if ($from): ?><tr><td>Tarih aralığı</td><td><?= format_date($from) ?> – <?= format_date($to) ?></td></tr><?php endif; ?>
                        <tr><td>Düzenleme tarihi</td><td><?= format_date(date('Y-m-d')) ?></td></tr>
                        <tr><td>Cari kodu</td><td class="mono">C-<?= str_pad((string)$contact_id, 5, '0', STR_PAD_LEFT) ?></td></tr>
                        <tr><td>Dönem sonu bakiye</td><td><?= $money(abs($closing)) . $bal_label($closing) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="parties">
            <div class="box">
                <div class="lbl">Sayın (hesap sahibi)</div>
                <div class="nm"><?= e($contact['company_title']) ?></div>
                <div class="co-line">
                    <?php $loc = trim(($contact['district'] ?? '') . ' ' . ($contact['city'] ?? '')); ?>
                    <?php if (!empty($contact['address']) || $loc !== ''): ?><?= nl2br(e(trim(($contact['address'] ?? '') . ' ' . $loc))) ?><br><?php endif; ?>
                    <?php if (!empty($contact['tax_number']) || !empty($contact['tax_office'])): ?>Vergi Dairesi: <b><?= e($contact['tax_office'] ?: '—') ?></b> · VKN/TCKN: <b><?= e($contact['tax_number'] ?: '—') ?></b><br><?php endif; ?>
                    <?php if (!empty($contact['authorized_person']) && $contact['authorized_person'] !== $contact['company_title']): ?>Yetkili: <?= e($contact['authorized_person']) ?><br><?php endif; ?>
                    <?= e(trim(($contact['phone'] ?? '') . (!empty($contact['email']) ? ' · ' . $contact['email'] : ''), ' ·')) ?>
                </div>
            </div>
            <div class="box">
                <div class="lbl">Dönem özeti</div>
                <table class="sum" style="font-size:11px">
                    <tr><td>Devreden bakiye</td><td><?= $money(abs($opening)) . $bal_label($opening) ?></td></tr>
                    <tr><td>Dönem borç</td><td><?= $money($period_debit) ?></td></tr>
                    <tr><td>Dönem alacak</td><td class="neg"><?= $money($period_credit) ?></td></tr>
                    <tr class="total"><td>Dönem sonu bakiye</td><td><?= $money(abs($closing)) . $bal_label($closing) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="tscroll"><table class="items">
            <thead>
                <tr>
                    <th style="width:24mm">Tarih</th>
                    <th style="width:28mm">Belge no</th>
                    <th>İşlem / açıklama</th>
                    <th class="r">Borç</th>
                    <th class="r">Alacak</th>
                    <th class="r">Bakiye</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($from !== null): ?>
                <tr>
                    <td style="white-space:nowrap"><?= format_date($from) ?></td><td class="sub">—</td>
                    <td><b>Devreden bakiye</b><div class="sub"><?= format_date($from) ?> öncesi hareketler</div></td>
                    <td class="r"><?= $opening > 0 ? $money($opening) : '—' ?></td>
                    <td class="r neg"><?= $opening < 0 ? $money(-$opening) : '—' ?></td>
                    <td class="r"><b><?= $money(abs($opening)) . $bal_label($opening) ?></b></td>
                </tr>
                <?php endif; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="6" class="c sub" style="padding:18px">Bu dönemde hareket yok.</td></tr>
                <?php endif; ?>
                <?php $run = $opening; foreach ($rows as $r): $run += $r['debit'] - $r['credit']; ?>
                <tr>
                    <td style="white-space:nowrap"><?= format_date($r['date']) ?></td>
                    <td class="mono" style="white-space:nowrap;font-size:10px"><?= e($r['doc']) ?></td>
                    <td><b><?= e($r['type']) ?></b><div class="sub"><?= e(mb_strimwidth((string)$r['desc'], 0, 140, '…')) ?></div></td>
                    <td class="r"><?= $r['debit'] > 0 ? $money($r['debit']) : '—' ?></td>
                    <td class="r neg"><?= $r['credit'] > 0 ? $money($r['credit']) : '—' ?></td>
                    <td class="r"><b><?= $money(abs($run)) . $bal_label($run) ?></b></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b>Dönem toplamı</b> <span class="sub">(<?= count($rows) ?> hareket)</span></td>
                    <td class="r" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b><?= $money($period_debit) ?></b></td>
                    <td class="r neg" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b><?= $money($period_credit) ?></b></td>
                    <td class="r" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b><?= $money(abs($closing)) . $bal_label($closing) ?></b></td>
                </tr>
            </tfoot>
        </table></div>
        <p class="sub" style="margin-top:6px">(B) borç bakiyesi: cari hesap sahibinin <?= e($co['name'] ?: 'şirketimize') ?> borcu · (A) alacak bakiyesi: cari hesap sahibinin alacağı.</p>
    </div>

    <div>
        <?php if (site_setting('statement_reconcile_text') !== ''): ?><div class="disclaimer"><?= e(site_setting('statement_reconcile_text')) ?></div><?php endif; ?>
        <?php if (site_setting('doc_show_signature') === '1'): ?>
        <div class="foot" style="grid-template-columns:1fr 1fr;border:0">
            <div class="sign"><b><?= e($co['name']) ?></b><br><?= e(site_setting('doc_signature_label') ?: 'Yetkili İmza / Kaşe') ?></div>
            <div class="sign"><b><?= e($contact['company_title']) ?></b><br>Mutabakat onayı / İmza</div>
        </div>
        <?php endif; ?>
        <?php if (site_setting('statement_footer_note') !== ''): ?><p class="note" style="text-align:center;color:var(--muted)"><?= e(site_setting('statement_footer_note')) ?></p><?php endif; ?>
    </div>
</div>
</body>
</html>
