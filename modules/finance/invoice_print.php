<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - PROFORMA FATURA & HİZMET DÖKÜMÜ (A4 PDF / YAZDIR)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

$invoice_id = (int)($_GET['id'] ?? 0);

// Yetki: Finans yetkili personel veya faturanın sahibi olan müşteri
$is_staff_viewer = is_logged_in() && (has_permission('finance.view') || has_permission('finance.invoices'));
$client_contact_scope = null;
if (!$is_staff_viewer) {
    if (!is_client_logged_in()) {
        http_response_code(403);
        die("Yetkisiz erişim!");
    }
    $client_contact_scope = (int)$_SESSION['client_contact_id'];
}

// Faturayı, Cariyi ve Projeyi Getir
$stmt = $db->prepare("
    SELECT i.*, 
           c.company_title as client_title, c.authorized_person, c.tax_office as client_tax_office, 
           c.tax_number as client_tax_number, c.phone as client_phone, c.email as client_email, 
           c.address as client_address, c.city as client_city,
           p.project_name, p.project_code, p.project_type
    FROM invoices i
    LEFT JOIN contacts c ON i.contact_id = c.id
    LEFT JOIN projects p ON i.project_id = p.id
    WHERE i.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

// Müşteri yalnızca kendisine kesilmiş satış faturalarını görebilir
if ($invoice && $client_contact_scope !== null && ((int)$invoice['contact_id'] !== $client_contact_scope || $invoice['invoice_type'] !== 'sales')) {
    $invoice = false;
}

if (!$invoice) {
    die("Fatura belgesi bulunamadı!");
}

// Şirket Banka Bilgilerini Çek
// Ayarlarda birincil IBAN tanımlıysa o kullanılır, yoksa ilk banka hesabı
$bank = false;
if (get_setting('bank_primary_iban') === '') {
    $bank = $db->query("SELECT * FROM accounts WHERE account_type = 'bank' AND iban IS NOT NULL AND iban != '' AND status = 'active' ORDER BY id ASC LIMIT 1")->fetch();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proforma_Fatura_<?= e($invoice['invoice_number']) ?></title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @media print {
            body {
                background: white !important;
                color: black !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .print-shadow-none {
                box-shadow: none !important;
                border: 1px solid #e2e8f0 !important;
            }
            @page {
                size: A4;
                margin: 12mm;
            }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen py-8 px-4 font-sans text-slate-800 antialiased flex flex-col items-center">

    <!-- ÜST BUTON ÇUBUĞU (Yazdırırken Gizlenir) -->
    <div class="max-w-4xl w-full mb-6 flex items-center justify-between no-print">
        <button onclick="window.history.back()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-bold py-2.5 px-4 rounded-xl shadow-xs transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>Geri Dön</span>
        </button>

        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2.5 px-5 rounded-xl shadow-md shadow-brand-600/30 transition cursor-pointer">
                <i data-lucide="printer" class="w-4 h-4"></i>
                <span>Yazdır / PDF Olarak Kaydet</span>
            </button>
        </div>
    </div>

    <!-- A4 FATURA / PROFORMA KAĞIDI -->
    <div class="bg-white max-w-4xl w-full p-8 sm:p-12 rounded-3xl shadow-xl border border-slate-200 print-shadow-none flex flex-col justify-between" style="min-height: 297mm;">
        
        <div>
            <!-- 1. BAŞLIK VE AJANS LOGO ALANI -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-8 border-b-2 border-slate-100 gap-6">
                <div class="flex items-center gap-3">
                    <?= doc_logo_box('video', 'bg-slate-900') ?>
                    <div>
                        <h1 class="text-xl font-black text-slate-900 tracking-tight"><?= e(mb_strtoupper(site_setting('company_brand_name') ?: site_setting('company_name'), 'UTF-8')) ?></h1>
                        <p class="text-[11px] text-slate-500 font-medium tracking-wide"><?= e(site_setting('doc_tagline')) ?></p>
                    </div>
                </div>

                <div class="text-left sm:text-right">
                    <span class="inline-block px-3 py-1 bg-slate-100 text-slate-700 text-[11px] font-bold uppercase tracking-widest rounded-lg border border-slate-200">
                        PROFORMA FATURA / İCMAL
                    </span>
                    <p class="text-xs text-slate-400 mt-1 font-mono">Belge No: <strong class="text-slate-800 font-bold"><?= e($invoice['invoice_number']) ?></strong></p>
                    <p class="text-xs text-slate-500">Tarih: <strong class="text-slate-800"><?= format_date($invoice['issue_date']) ?></strong></p>
                </div>
            </div>

            <!-- 2. MÜŞTERİ VE AJANS BİLGİLERİ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 my-8">
                <!-- Müşteri (Sayın / Firma) -->
                <div class="bg-slate-50/75 p-5 rounded-2xl border border-slate-200">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-2">Hizmet Verilen (Müşteri)</span>
                    <h3 class="text-sm font-bold text-slate-900"><?= e($invoice['client_title']) ?></h3>
                    <?php if (!empty($invoice['authorized_person'])): ?>
                        <p class="text-xs text-slate-600 mt-0.5">Yetkili: <?= e($invoice['authorized_person']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['client_tax_number'])): ?>
                        <p class="text-xs text-slate-600 mt-1">Vergi Dairesi & No: <strong><?= e($invoice['client_tax_office']) ?> V.D. - <?= e($invoice['client_tax_number']) ?></strong></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['client_address'])): ?>
                        <p class="text-xs text-slate-500 mt-1 leading-relaxed"><?= e($invoice['client_address']) ?> <?= !empty($invoice['client_city']) ? ' / ' . e($invoice['client_city']) : '' ?></p>
                    <?php endif; ?>
                </div>

                <!-- Proje Detayı & Ajans İletişim -->
                <div class="bg-slate-50/75 p-5 rounded-2xl border border-slate-200 flex flex-col justify-between">
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-2">Proje & Prodüksiyon Bilgisi</span>
                        <h3 class="text-sm font-bold text-slate-900"><?= e($invoice['project_name'] ?? 'Genel Prodüksiyon Hizmeti') ?></h3>
                        <?php if (!empty($invoice['project_code'])): ?>
                            <p class="text-xs text-slate-600 font-mono mt-0.5">Proje Kodu: <?= e($invoice['project_code']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="pt-2 border-t border-slate-200 mt-3 text-[11px] text-slate-500">
                        <span>Düzenleyen: <strong><?= e(site_setting('doc_issuer_name') ?: site_setting('company_name')) ?></strong></span>
                    </div>
                </div>
            </div>

            <!-- 3. HİZMET VE KALEM DETAYI TABLOSU -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden mb-8">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-900 text-white text-[11px] font-bold uppercase tracking-wider">
                            <th class="py-3.5 px-4 w-12 text-center">#</th>
                            <th class="py-3.5 px-4">Hizmet / Açıklama</th>
                            <th class="py-3.5 px-4 text-center">KDV</th>
                            <th class="py-3.5 px-4 text-right">Tutar (KDV Hariç)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-xs">
                        <tr>
                            <td class="py-4 px-4 text-center font-bold text-slate-400">1</td>
                            <td class="py-4 px-4">
                                <span class="font-bold text-slate-900 block text-sm"><?= e($invoice['project_name'] ?? 'Video Prodüksiyon Hizmeti') ?></span>
                                <span class="text-slate-500 mt-0.5 block leading-relaxed">
                                    <?= !empty($invoice['notes']) ? e($invoice['notes']) : 'Çekim, kurgu, ses tasarımı, renk düzenleme (Color Grading) ve teslimat hizmet bedeli.' ?>
                                </span>
                            </td>
                            <td class="py-4 px-4 text-center font-bold text-slate-700">
                                %<?= (int)$invoice['vat_rate'] ?>
                            </td>
                            <td class="py-4 px-4 text-right font-bold text-slate-900 text-sm">
                                <?= format_money($invoice['subtotal']) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 4. MATRAH & VERGİ HESAPLAMA DÖKÜMÜ -->
            <div class="flex flex-col sm:flex-row justify-end mb-8">
                <div class="w-full sm:w-80 space-y-2 text-xs bg-slate-50 p-5 rounded-2xl border border-slate-200">
                    <div class="flex justify-between text-slate-600">
                        <span>Ara Toplam (Matrah):</span>
                        <strong class="text-slate-900"><?= format_money($invoice['subtotal']) ?></strong>
                    </div>

                    <div class="flex justify-between text-slate-600">
                        <span>Hesaplanan KDV (%<?= (int)$invoice['vat_rate'] ?>):</span>
                        <strong class="text-slate-900">+<?= format_money($invoice['vat_amount']) ?></strong>
                    </div>

                    <?php if ((float)$invoice['withholding_amount'] > 0): ?>
                    <div class="flex justify-between text-purple-700 font-medium">
                        <span>Tevkifat (<?= $invoice['withholding_rate'] ?>):</span>
                        <strong>-<?= format_money($invoice['withholding_amount']) ?></strong>
                    </div>
                    <?php endif; ?>

                    <div class="pt-3 border-t-2 border-slate-200 flex justify-between text-sm font-bold text-slate-900">
                        <span>Ödenecek Genel Toplam:</span>
                        <span class="text-brand-600 text-base"><?= format_money($invoice['grand_total']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. ALT BİLGİ & BANKA HESAPLARI (A4'ün En Altında) -->
        <div class="pt-6 border-t border-slate-200 text-xs">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                <div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Banka & Ödeme Bilgileri</span>
                    <?php if ($bank): ?>
                        <p class="font-bold text-slate-800"><?= e($bank['account_name']) ?> (<?= e($bank['bank_name'] ?? 'Banka') ?>)</p>
                        <p class="font-mono text-slate-600 mt-0.5 font-medium"><?= e($bank['iban']) ?></p>
                    <?php else: ?>
                        <p class="font-bold text-slate-800"><?= e(site_setting('bank_primary_receiver') ?: site_setting('company_name')) ?> (<?= e(get_setting('bank_primary_name', 'Banka')) ?>)</p>
                        <p class="font-mono text-slate-600 mt-0.5"><?= e(get_setting('bank_primary_iban', 'TR00 0000 0000 0000 0000 0000 00')) ?></p>
                    <?php endif; ?>
                </div>

                <div class="text-left sm:text-right">
                    <p class="text-[10px] text-slate-400 uppercase font-semibold">Resmi Bilgilendirme Notu</p>
                    <p class="text-[11px] text-slate-500 italic mt-0.5">
                        * Bu belge proforma / icmal niteliğinde olup resmi Vergi Usul Kanunu faturası yerine geçmez. Bilgi ve ödeme dökümü amaçlıdır.
                    </p>
                </div>
            </div>
        </div>

        <?php if (site_setting('invoice_footer_note') !== ''): ?>
        <p class="text-[11px] text-slate-500 text-center whitespace-pre-line" style="margin-top:16px"><?= e(site_setting('invoice_footer_note')) ?></p>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>