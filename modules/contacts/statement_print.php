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
// Ek filtreler: hareket türü, durum, arama
$kinds = array_values(array_intersect((array)($_GET['k'] ?? []), array_keys(LEDGER_KINDS)));
if (count($kinds) === count(LEDGER_KINDS)) $kinds = [];
$fstatus = in_array($_GET['st'] ?? '', ['open', 'overdue'], true) ? $_GET['st'] : '';
$fq = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$filtered = $kinds || $fstatus !== '' || $fq !== '';
$keep = ['k' => $kinds ?: null, 'st' => $fstatus, 'q' => $fq];
$self = fn(array $q) => '?' . http_build_query(array_filter(['id' => $is_staff_viewer ? $contact_id : null] + $q + $keep, fn($v) => $v !== null && $v !== '' && $v !== []));

// ---------------- Hareketler ----------------
$ledger = contact_ledger($contact_id);
[$opening, $rows] = ledger_filter($ledger, $from, $to, $kinds, $fstatus, $fq);
[, $all_rows] = ledger_filter($ledger, $from, $to);
$period_debit = array_sum(array_column($rows, 'debit'));
$period_credit = array_sum(array_column($rows, 'credit'));
// Dönem sonu bakiyesi filtreden bağımsız, gerçek cari bakiyesidir
$closing = $all_rows ? end($all_rows)['balance'] : $opening;

// ---------------- Excel (CSV) ----------------
if (($_GET['format'] ?? '') === 'csv') {
    $fname = 'Cari_Ekstre_' . preg_replace('/[^\p{L}\p{N}]+/u', '_', $contact['company_title']) . '_' . ($from ?? 'tum') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $num = fn(float $v) => number_format($v, 2, ',', '');
    fputcsv($out, ['Tarih', 'Belge no', 'İşlem', 'Açıklama', 'Borç', 'Alacak', 'Bakiye', 'B/A'], ';');
    if ($from !== null) fputcsv($out, [$from, '', 'Devreden bakiye', '', '', '', $num(abs($opening)), $opening > 0 ? 'B' : ($opening < 0 ? 'A' : '')], ';');
    foreach ($rows as $r) {
        fputcsv($out, [$r['date'], $r['doc'], $r['type'], $r['desc'], $r['debit'] > 0 ? $num($r['debit']) : '', $r['credit'] > 0 ? $num($r['credit']) : '', $num(abs($r['balance'])), $r['balance'] > 0 ? 'B' : ($r['balance'] < 0 ? 'A' : '')], ';');
    }
    fputcsv($out, ['', '', 'Dönem toplamı', '', $num($period_debit), $num($period_credit), $num(abs($closing)), $closing > 0 ? 'B' : ($closing < 0 ? 'A' : '')], ';');
    fclose($out);
    exit;
}
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
            <?php foreach ($keep as $kk => $kv): foreach ((array)$kv as $vv): if ($vv === null || $vv === '') continue; ?><input type="hidden" name="<?= $kk === 'k' ? 'k[]' : $kk ?>" value="<?= e($vv) ?>"><?php endforeach; endforeach; ?>
            <button type="submit">Aralık</button>
            <button type="button" class="primary" onclick="window.print()">Yazdır / PDF</button>
            <a href="<?= e($self(['p' => $p, 'd' => $p === 'custom' || $p === 'all' ? null : $ref, 'from' => $p === 'custom' ? $from : null, 'to' => $p === 'custom' ? $to : null, 'format' => 'csv'])) ?>">Excel</a>
            <span style="font-size:11px;opacity:.5">v<?= APP_VERSION ?></span>
        </form>
    </div>
    <form class="bar-in" method="GET" action="">
        <?php if ($is_staff_viewer): ?><input type="hidden" name="id" value="<?= (int)$contact_id ?>"><?php endif; ?>
        <input type="hidden" name="p" value="<?= e($p) ?>">
        <?php if ($p === 'custom'): ?><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><?php elseif ($p !== 'all'): ?><input type="hidden" name="d" value="<?= e($ref) ?>"><?php endif; ?>
        <div class="grp">
            <?php foreach (LEDGER_KINDS as $kk => $kl): ?>
            <label style="font-size:12px;display:inline-flex;gap:4px;align-items:center;cursor:pointer"><input type="checkbox" name="k[]" value="<?= $kk ?>" <?= !$kinds || in_array($kk, $kinds, true) ? 'checked' : '' ?>> <?= e($kl) ?></label>
            <?php endforeach; ?>
        </div>
        <div class="grp">
            <select name="st" aria-label="Durum">
                <option value="">Tüm durumlar</option>
                <option value="open" <?= $fstatus === 'open' ? 'selected' : '' ?>>Ödenmemiş faturalar</option>
                <option value="overdue" <?= $fstatus === 'overdue' ? 'selected' : '' ?>>Vadesi geçmiş</option>
            </select>
            <input type="search" name="q" value="<?= e($fq) ?>" placeholder="Belge no / açıklama ara" aria-label="Ara">
            <button type="submit">Filtrele</button>
            <?php if ($filtered): ?><a href="<?= e('?' . http_build_query(array_filter(['id' => $is_staff_viewer ? $contact_id : null, 'p' => $p, 'd' => $p === 'custom' || $p === 'all' ? null : $ref, 'from' => $p === 'custom' ? $from : null, 'to' => $p === 'custom' ? $to : null]))) ?>">Filtreyi temizle</a><?php endif; ?>
        </div>
    </form>
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
                        <?php if ($filtered): ?><tr><td>Filtre</td><td><?= e(implode(', ', array_filter([$kinds ? implode(' + ', array_map(fn($k) => LEDGER_KINDS[$k], $kinds)) : '', $fstatus === 'open' ? 'Ödenmemiş faturalar' : ($fstatus === 'overdue' ? 'Vadesi geçmiş' : ''), $fq !== '' ? '"' . $fq . '"' : '']))) ?></td></tr><?php endif; ?>
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
                <?php foreach ($rows as $r): $run = $r['balance']; ?>
                <tr>
                    <td style="white-space:nowrap"><?= format_date($r['date']) ?></td>
                    <td class="mono" style="white-space:nowrap;font-size:10px"><?= e($r['doc']) ?></td>
                    <td><b><?= e($r['type']) ?></b><?php if ($r['kind'] === 'invoice' && $r['status'] !== 'paid'): ?> <span class="sub">· <?= $r['overdue'] ? 'vadesi geçti' : 'açık' ?>, kalan <?= $money($r['remaining']) ?></span><?php endif; ?><div class="sub"><?= e(mb_strimwidth((string)$r['desc'], 0, 140, '…')) ?></div></td>
                    <td class="r"><?= $r['debit'] > 0 ? $money($r['debit']) : '—' ?></td>
                    <td class="r neg"><?= $r['credit'] > 0 ? $money($r['credit']) : '—' ?></td>
                    <td class="r"><b><?= $money(abs($run)) . $bal_label($run) ?></b></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b><?= $filtered ? 'Filtrelenen hareketler toplamı' : 'Dönem toplamı' ?></b> <span class="sub">(<?= count($rows) ?> hareket)</span></td>
                    <td class="r" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b><?= $money($period_debit) ?></b></td>
                    <td class="r neg" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b><?= $money($period_credit) ?></b></td>
                    <td class="r" style="border-top:1.5px solid var(--ink);padding:7px 6px"><b><?= $money(abs($closing)) . $bal_label($closing) ?></b></td>
                </tr>
            </tfoot>
        </table></div>
        <p class="sub" style="margin-top:6px"><?php if ($filtered): ?>Bakiye sütunu filtreden bağımsız olarak, ilgili hareketten sonraki gerçek cari bakiyeyi gösterir. <?php endif; ?>(B) borç bakiyesi: cari hesap sahibinin <?= e($co['name'] ?: 'şirketimize') ?> borcu · (A) alacak bakiyesi: cari hesap sahibinin alacağı.</p>
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
