<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - RESMİ A4 FİYAT TEKLİFİ & SUNUM (PDF ÇIKTI MOTORU)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

$proposal_id = (int)($_GET['id'] ?? 0);

$is_staff_viewer = is_logged_in() && can_access_module('proposals.manage');
if (!$is_staff_viewer && !is_client_logged_in()) {
    http_response_code(403);
    die("Yetkisiz erişim!");
}

$stmt = $db->prepare("
    SELECT pr.*, 
           c.company_title as client_title, c.authorized_person, c.phone as client_phone, c.email as client_email, c.address as client_address
    FROM proposals pr
    JOIN contacts c ON pr.client_id = c.id
    WHERE pr.id = ?
");
$stmt->execute([$proposal_id]);
$proposal = $stmt->fetch();

// Müşteri yalnızca kendisine hazırlanmış ve taslak olmayan teklifleri görebilir
if ($proposal && !$is_staff_viewer && ((int)$proposal['client_id'] !== (int)$_SESSION['client_contact_id'] || $proposal['status'] === 'draft')) {
    $proposal = false;
}

if (!$proposal) {
    die("Teklif belgesi bulunamadı!");
}

// Teklif kalemleri
$items = [];
try {
    $it = $db->prepare("SELECT * FROM proposal_items WHERE proposal_id = ? ORDER BY sort_order, id");
    $it->execute([$proposal_id]);
    $items = $it->fetchAll();
} catch (Throwable $e) {
}

// Şirket Ayarlarını Çek
$settings_raw = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$company_name = site_setting('company_name');
$company_email = $settings_raw['company_email'] ?? 'info@rymedya.com.tr';
$company_phone = $settings_raw['company_phone'] ?? '+90 (212) 000 00 00';
$company_address = $settings_raw['company_address'] ?? 'İstanbul / Türkiye';
$bank_iban = $settings_raw['bank_primary_iban'] ?? 'TR00 0000 0000 0000 0000 0000 00';
$bank_name = $settings_raw['bank_primary_name'] ?? 'Garanti BBVA';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiyat_Teklifi_<?= e($proposal['proposal_code']) ?></title>
    <!-- Tailwind CSS -->
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
    <div class="max-w-4xl w-full mb-4 flex items-center justify-between no-print">
        <button onclick="window.history.back()" class="inline-flex items-center gap-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-bold py-2.5 px-4 rounded-xl shadow-xs transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>Geri Dön</span>
        </button>

        <button onclick="window.print()" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-2.5 px-5 rounded-xl shadow-md transition cursor-pointer">
            <i data-lucide="printer" class="w-4 h-4"></i>
            <span>Teklifi Yazdır / PDF İndir</span>
        </button>
    </div>

    <!-- A4 TEKLİF SAYFASI -->
    <div class="bg-white max-w-4xl w-full p-8 sm:p-12 rounded-3xl shadow-xl border border-slate-200 print-shadow-none flex flex-col justify-between" style="min-height: 297mm;">
        
        <div>
            <!-- 1. BAŞLIK VE AJANS LOGO -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b-2 border-slate-900 gap-4">
                <div class="flex items-center gap-3">
                    <?= doc_logo_box('video', 'bg-indigo-600') ?>
                    <div>
                        <h1 class="text-xl font-black text-slate-900 tracking-tight"><?= e($company_name) ?></h1>
                        <p class="text-[11px] text-slate-500 font-medium"><?= e(site_setting('doc_tagline')) ?></p>
                    </div>
                </div>

                <div class="text-left sm:text-right">
                    <span class="inline-block px-3 py-1 bg-indigo-600 text-white text-xs font-bold uppercase tracking-widest rounded-lg">
                        FİYAT TEKLİFİ
                    </span>
                    <p class="text-xs font-mono font-bold text-slate-900 mt-1">Teklif No: <?= e($proposal['proposal_code']) ?></p>
                    <p class="text-xs text-slate-500">Tarih: <strong><?= format_date($proposal['created_at']) ?></strong></p>
                </div>
            </div>

            <!-- 2. MÜŞTERİ VE TEKLİF ÖZETİ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 my-6 p-5 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                <div class="space-y-1.5">
                    <span class="text-[10px] font-bold uppercase text-slate-400 block mb-1">Teklif Sunulan (Müşteri)</span>
                    <p class="text-sm font-bold text-slate-900"><?= e($proposal['client_title']) ?></p>
                    <?php if (!empty($proposal['authorized_person'])): ?>
                        <p class="text-slate-600">Yetkili: <strong><?= e($proposal['authorized_person']) ?></strong></p>
                    <?php endif; ?>
                    <p class="text-slate-600">İletişim: <?= e($proposal['client_phone'] ?: '-') ?> | <?= e($proposal['client_email'] ?: '-') ?></p>
                </div>

                <div class="text-left sm:text-right space-y-1.5">
                    <span class="text-[10px] font-bold uppercase text-slate-400 block mb-1">Teklif Detayı</span>
                    <p class="text-xs font-bold text-slate-900"><?= e($proposal['title']) ?></p>
                    <p class="text-slate-600">Hizmet Türü: <strong><?= get_project_type_name($proposal['project_type']) ?></strong></p>
                    <p class="text-indigo-600 font-bold">Son Geçerlilik: <?= format_date($proposal['valid_until']) ?></p>
                </div>
            </div>

            <!-- 3. HİZMET KAPSAMI VE KALEMLERİ -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden mb-6">
                <div class="bg-slate-900 text-white px-4 py-2.5 text-xs font-bold uppercase tracking-wider">
                    Teklif Kapsamı & Prodüksiyon Hizmet Detayları
                </div>
                <div class="p-5 text-xs text-slate-800 leading-relaxed whitespace-pre-line bg-white">
                    <?= !empty($proposal['scope_items']) ? e($proposal['scope_items']) : 'Prodüksiyon çekim, kurgu, renk ve ses tasarımı teslim paketi.' ?>
                </div>
            </div>

            <?php if (!empty($items)): ?>
            <!-- 3b. FİYAT KALEMLERİ -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden mb-6">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-slate-900 text-white text-[10px] uppercase tracking-wider">
                            <th class="py-2.5 px-3 text-left w-8">#</th>
                            <th class="py-2.5 px-3 text-left">Hizmet / Kalem</th>
                            <th class="py-2.5 px-3 text-right">Miktar</th>
                            <th class="py-2.5 px-3 text-right">Birim Fiyat</th>
                            <th class="py-2.5 px-3 text-right">Tutar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td class="py-2 px-3 text-slate-400"><?= $i + 1 ?></td>
                            <td class="py-2 px-3 font-semibold text-slate-800"><?= e($item['description']) ?></td>
                            <td class="py-2 px-3 text-right text-slate-600"><?= rtrim(rtrim(number_format((float)$item['quantity'], 2, ',', '.'), '0'), ',') ?> <?= e($item['unit']) ?></td>
                            <td class="py-2 px-3 text-right text-slate-600"><?= format_money($item['unit_price'], $proposal['currency']) ?></td>
                            <td class="py-2 px-3 text-right font-bold text-slate-900"><?= format_money($item['line_total'], $proposal['currency']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- 4. ŞARTLAR & ÖDEME KOŞULLARI -->
            <?php if (!empty($proposal['terms'])): ?>
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-xs mb-6">
                <span class="font-bold text-slate-700 block mb-1">Ödeme ve Çalışma Şartları:</span>
                <p class="text-slate-600 leading-relaxed whitespace-pre-line"><?= e($proposal['terms']) ?></p>
            </div>
            <?php endif; ?>

            <!-- 5. FİYAT VE VERGİ DÖKÜMÜ -->
            <div class="flex flex-col sm:flex-row justify-end mb-8">
                <div class="w-full sm:w-80 bg-slate-50 p-5 rounded-2xl border border-slate-200 space-y-2 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Hizmet Bedeli (Matrah):</span>
                        <strong class="text-slate-900"><?= format_money($proposal['subtotal'], $proposal['currency']) ?></strong>
                    </div>

                    <div class="flex justify-between text-slate-600">
                        <span>KDV (%<?= (int)$proposal['vat_rate'] ?>):</span>
                        <strong class="text-slate-900">+<?= format_money((float)$proposal['grand_total'] - (float)$proposal['subtotal'], $proposal['currency']) ?></strong>
                    </div>

                    <div class="pt-3 border-t-2 border-slate-200 flex justify-between text-sm font-bold text-slate-900">
                        <span>Teklif Toplamı:</span>
                        <span class="text-indigo-600 text-base font-black"><?= format_money($proposal['grand_total'], $proposal['currency']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 6. İMZA VE ONAY ALANI -->
        <div class="pt-6 border-t border-slate-200 text-xs">
            <div class="grid grid-cols-2 gap-8 pt-4">
                <div class="border-t border-slate-300 pt-2 text-center">
                    <p class="font-bold text-slate-800"><?= e($company_name) ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5">Teklif Veren / Kaşe & İmza</p>
                </div>

                <div class="border-t border-slate-300 pt-2 text-center">
                    <p class="font-bold text-slate-800"><?= e($proposal['client_title']) ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5">Teklif Onayı / Kaşe & İmza</p>
                </div>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>