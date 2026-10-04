<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - CARİ HESAP EKSTRESİ (A4 PDF / YAZDIRMA ŞABLONU)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

// Hem yetkili personel hem de giriş yapmış müşteri erişebilsin
$contact_id = (int)($_GET['id'] ?? 0);
$is_staff_viewer = is_logged_in() && has_permission('contacts.view');

if (!$is_staff_viewer) {
    if (!is_client_logged_in()) {
        http_response_code(403);
        die("Yetkisiz erişim!");
    }
    // Müşteri sadece kendi ekstresini görebilir (Güvenlik Kalkanı)
    $contact_id = (int)$_SESSION['client_contact_id'];
}

// Cariyi Getir
$stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$stmt->execute([$contact_id]);
$contact = $stmt->fetch();

if (!$contact) {
    die("Cari hesap bulunamadı!");
}

// 1. Faturaları Çek
$invoices_data = $db->query("SELECT id, invoice_number as doc_no, issue_date as move_date, invoice_type, grand_total, notes as description, created_at FROM invoices WHERE contact_id = {$contact_id}")->fetchAll();

// 2. Manuel Hareketleri ve Kasa Tahsilatlarını Çek
$tx_data = $db->query("SELECT id, 'Tahsilat/Dekont' as doc_no, transaction_date as move_date, type as tx_type, category, amount, description, created_at FROM transactions WHERE contact_id = {$contact_id}")->fetchAll();

$ledger = [];

foreach ($invoices_data as $inv) {
    $is_sales = ($inv['invoice_type'] === 'sales');
    $ledger[] = [
        'date'        => $inv['move_date'],
        'doc_no'      => $inv['doc_no'],
        'type'        => $is_sales ? 'Satış Faturası' : 'Alış/Gider Faturası',
        'desc'        => $inv['description'] ?: 'Prodüksiyon Fatura Kaydı',
        'debit'       => $is_sales ? (float)$inv['grand_total'] : 0.00,
        'credit'      => !$is_sales ? (float)$inv['grand_total'] : 0.00,
        'created_at'  => $inv['created_at']
    ];
}

foreach ($tx_data as $tx) {
    $is_income = ($tx['tx_type'] === 'income');
    $ledger[] = [
        'date'        => $tx['move_date'],
        'doc_no'      => 'DKN-' . $tx['id'],
        'type'        => $is_income ? 'Tahsilat / Ödeme Makbuzu' : 'Manuel Borç Dekontu',
        'desc'        => $tx['description'] ?: $tx['category'],
        'debit'       => !$is_income ? (float)$tx['amount'] : 0.00,
        'credit'      => $is_income ? (float)$tx['amount'] : 0.00,
        'created_at'  => $tx['created_at']
    ];
}

usort($ledger, function($a, $b) {
    return strtotime($a['date']) <=> strtotime($b['date']);
});

$total_debit = 0;
$total_credit = 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari_Ekstre_<?= e($contact['company_title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .print-shadow-none { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen py-8 px-4 font-sans text-slate-800 antialiased flex flex-col items-center">

    <!-- ÜST BUTON ÇUBUĞU -->
    <div class="max-w-4xl w-full mb-6 flex items-center justify-between no-print">
        <button onclick="window.history.back()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-bold py-2.5 px-4 rounded-xl shadow-xs transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>Geri Dön</span>
        </button>

        <button onclick="window.print()" class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-5 rounded-xl shadow-md transition cursor-pointer">
            <i data-lucide="printer" class="w-4 h-4"></i>
            <span>Ekstre Yazdır / PDF İndir</span>
        </button>
    </div>

    <!-- A4 CARİ EKSTRE SAYFASI -->
    <div class="bg-white max-w-4xl w-full p-8 sm:p-12 rounded-3xl shadow-xl border border-slate-200 print-shadow-none flex flex-col justify-between" style="min-height: 297mm;">
        <div>
            <!-- BAŞLIK -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b-2 border-slate-900 gap-4">
                <div class="flex items-center gap-3">
                    <?= doc_logo_box('video', 'bg-slate-900') ?>
                    <div>
                        <h1 class="text-xl font-black text-slate-900 tracking-tight"><?= e(mb_strtoupper(site_setting('company_brand_name') ?: site_setting('company_name'), 'UTF-8')) ?></h1>
                        <p class="text-[11px] text-slate-500 font-medium">Cari Hesap ve Bakiye Hareket Ekstresi</p>
                    </div>
                </div>

                <div class="text-left sm:text-right">
                    <span class="inline-block px-3 py-1 bg-slate-900 text-white text-[11px] font-bold uppercase tracking-widest rounded-lg">
                        CARİ HESAP EKSTRESİ
                    </span>
                    <p class="text-xs text-slate-500 mt-1">Döküm Tarihi: <strong class="text-slate-800 font-bold"><?= format_date(date('Y-m-d')) ?></strong></p>
                </div>
            </div>

            <!-- CARİ KART BİLGİLERİ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 my-6 p-5 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                <div>
                    <span class="text-[10px] font-bold uppercase text-slate-400 block mb-1">Hesap Sahibi (Cari Ünvan)</span>
                    <h2 class="text-sm font-bold text-slate-900"><?= e($contact['company_title']) ?></h2>
                    <?php if (!empty($contact['authorized_person'])): ?>
                        <p class="text-slate-600 mt-0.5">Yetkili: <strong><?= e($contact['authorized_person']) ?></strong></p>
                    <?php endif; ?>
                    <p class="text-slate-600 mt-0.5">Telefon: <?= e($contact['phone'] ?? '-') ?> | E-Posta: <?= e($contact['email'] ?? '-') ?></p>
                </div>

                <div class="text-left sm:text-right">
                    <span class="text-[10px] font-bold uppercase text-slate-400 block mb-1">Vergi & Lokasyon Bilgisi</span>
                    <?php if (!empty($contact['tax_number'])): ?>
                        <p class="text-slate-700">Vergi Dairesi: <strong><?= e($contact['tax_office']) ?> V.D.</strong></p>
                        <p class="text-slate-700 font-mono">VKN/TCKN: <strong><?= e($contact['tax_number']) ?></strong></p>
                    <?php endif; ?>
                    <p class="text-slate-500 mt-0.5"><?= e($contact['address'] ?? '') ?> <?= !empty($contact['city']) ? ' / ' . e($contact['city']) : '' ?></p>
                </div>
            </div>

            <!-- HAREKETLER TABLOSU -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden mb-6">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-900 text-white text-[11px] font-bold uppercase tracking-wider">
                            <th class="py-3 px-3">Tarih</th>
                            <th class="py-3 px-3">Belge No</th>
                            <th class="py-3 px-3">İşlem Türü & Açıklama</th>
                            <th class="py-3 px-3 text-right">Borç (₺)</th>
                            <th class="py-3 px-3 text-right">Alacak (₺)</th>
                            <th class="py-3 px-3 text-right">Bakiye (₺)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php if (empty($ledger)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-400">Bu hesaba ait herhangi bir hareket kaydı bulunmamaktadır.</td>
                        </tr>
                        <?php else: 
                            $running_balance = 0.00;
                            foreach ($ledger as $row):
                                $total_debit += $row['debit'];
                                $total_credit += $row['credit'];
                                $running_balance += ($row['debit'] - $row['credit']);
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-3 text-slate-600 font-medium"><?= format_date($row['date']) ?></td>
                            <td class="py-3 px-3 font-mono font-semibold text-slate-800"><?= e($row['doc_no']) ?></td>
                            <td class="py-3 px-3">
                                <span class="font-bold text-slate-900 block"><?= e($row['type']) ?></span>
                                <span class="text-[11px] text-slate-500"><?= e($row['desc']) ?></span>
                            </td>
                            <td class="py-3 px-3 text-right font-semibold text-slate-800">
                                <?= $row['debit'] > 0 ? format_money($row['debit']) : '-' ?>
                            </td>
                            <td class="py-3 px-3 text-right font-semibold text-emerald-700">
                                <?= $row['credit'] > 0 ? format_money($row['credit']) : '-' ?>
                            </td>
                            <td class="py-3 px-3 text-right font-bold <?= $running_balance >= 0 ? 'text-slate-900' : 'text-purple-700' ?>">
                                <?= format_money($running_balance) ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- BAKİYE ÖZETİ -->
            <div class="flex flex-col sm:flex-row justify-end mb-8">
                <div class="w-full sm:w-80 bg-slate-50 p-5 rounded-2xl border border-slate-200 space-y-2 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Toplam Borç:</span>
                        <strong class="text-slate-900"><?= format_money($total_debit) ?></strong>
                    </div>

                    <div class="flex justify-between text-slate-600">
                        <span>Toplam Alacak / Tahsilat:</span>
                        <strong class="text-emerald-700"><?= format_money($total_credit) ?></strong>
                    </div>

                    <div class="pt-3 border-t-2 border-slate-200 flex justify-between text-sm font-bold text-slate-900">
                        <span>Güncel Net Bakiye:</span>
                        <span class="<?= (float)$contact['balance'] > 0 ? 'text-rose-600' : 'text-emerald-600' ?> text-base">
                            <?= format_money($contact['balance']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- İMZA ALANI -->
        <div class="pt-6 border-t border-slate-200 text-xs">
            <div class="grid grid-cols-2 gap-8 pt-4">
                <div class="border-t border-slate-300 pt-2 text-center">
                    <p class="font-bold text-slate-800"><?= e(site_setting('company_name')) ?></p>
                    <p class="text-[10px] text-slate-400">Yetkili İmza / Kaşe</p>
                </div>
                <div class="border-t border-slate-300 pt-2 text-center">
                    <p class="font-bold text-slate-800"><?= e($contact['company_title']) ?></p>
                    <p class="text-[10px] text-slate-400">Mutabakat Onayı / İmza</p>
                </div>
            </div>
        </div>
        <?php if (site_setting('statement_footer_note') !== ''): ?>
        <p class="text-[11px] text-slate-500 text-center whitespace-pre-line" style="margin-top:16px"><?= e(site_setting('statement_footer_note')) ?></p>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>