<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - RESMİ MAAŞ BORDROSU & TEDİYE MAKBUZU (A4 PDF)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in() || !has_permission('personnel.manage')) {
    http_response_code(403);
    die("Yetkisiz erişim!");
}

$payroll_id = (int)($_GET['id'] ?? 0);

// Bordro ve Personel Bilgilerini Getir
$stmt = $db->prepare("
    SELECT pr.*, 
           p.first_name, p.last_name, p.identity_number, p.department, p.job_title, p.iban, p.start_date
    FROM payrolls pr
    JOIN personnel p ON pr.personnel_id = p.id
    WHERE pr.id = ?
");
$stmt->execute([$payroll_id]);
$payroll = $stmt->fetch();

if (!$payroll) {
    die("Maaş bordrosu bulunamadı!");
}

// Şirket Ayarlarını Çek
$settings_raw = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$company_name = $settings_raw['company_name'] ?? 'RY MEDYA PRODÜKSİYON A.Ş.';
$company_address = $settings_raw['company_address'] ?? 'İstanbul / Türkiye';
$company_tax = ($settings_raw['company_tax_office'] ?? 'Kadıköy') . ' V.D. - ' . ($settings_raw['company_tax_number'] ?? '1234567890');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maas_Bordrosu_<?= e($payroll['first_name'] . '_' . $payroll['last_name']) ?>_<?= $payroll['period_month'] ?>_<?= $payroll['period_year'] ?></title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .print-shadow-none { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
            @page { size: A4; margin: 12mm; }
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

        <button onclick="window.print()" class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-5 rounded-xl shadow-md transition cursor-pointer">
            <i data-lucide="printer" class="w-4 h-4"></i>
            <span>Bordro Yazdır / PDF İndir</span>
        </button>
    </div>

    <!-- A4 BORDRO SAYFASI -->
    <div class="bg-white max-w-4xl w-full p-8 sm:p-12 rounded-3xl shadow-xl border border-slate-200 print-shadow-none flex flex-col justify-between" style="min-height: 297mm;">
        
        <div>
            <!-- 1. BAŞLIK VE ŞİRKET KÜNYESİ -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b-2 border-slate-900 gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl bg-slate-900 flex items-center justify-center text-white shadow-md">
                        <i data-lucide="video" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-black text-slate-900 tracking-tight"><?= e($company_name) ?></h1>
                        <p class="text-[11px] text-slate-500 font-medium">Personel Ücret Hesap Pusulası & Maaş Bordrosu</p>
                    </div>
                </div>

                <div class="text-left sm:text-right">
                    <span class="inline-block px-3 py-1 bg-slate-900 text-white text-xs font-bold uppercase tracking-widest rounded-lg">
                        MAAŞ BORDROSU
                    </span>
                    <p class="text-xs font-bold text-slate-900 mt-1">Dönem: <span class="font-mono text-brand-600"><?= str_pad((string)$payroll['period_month'], 2, '0', STR_PAD_LEFT) ?> / <?= $payroll['period_year'] ?></span></p>
                    <p class="text-xs text-slate-500">Ödeme Tarihi: <strong><?= format_date($payroll['payment_date']) ?></strong></p>
                </div>
            </div>

            <!-- 2. PERSONEL VE ŞİRKET BİLGİLERİ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 my-6 p-5 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                <div class="space-y-1.5">
                    <span class="text-[10px] font-bold uppercase text-slate-400 block mb-1">Personel Bilgileri</span>
                    <p class="text-sm font-bold text-slate-900"><?= e($payroll['first_name'] . ' ' . $payroll['last_name']) ?></p>
                    <p class="text-slate-600">Departman / Görev: <strong><?= e($payroll['department']) ?> / <?= e($payroll['job_title']) ?></strong></p>
                    <p class="text-slate-600">T.C. Kimlik No: <strong class="font-mono"><?= e($payroll['identity_number'] ?: '-') ?></strong></p>
                    <p class="text-slate-600">İşe Başlama Tarihi: <strong><?= format_date($payroll['start_date']) ?></strong></p>
                </div>

                <div class="text-left sm:text-right space-y-1.5">
                    <span class="text-[10px] font-bold uppercase text-slate-400 block mb-1">Banka & Ödeme Detayı</span>
                    <p class="text-slate-700">Maaş IBAN:</p>
                    <p class="font-mono font-bold text-slate-900 text-[11px]"><?= e($payroll['iban'] ?: 'Elden / Nakit Tediye') ?></p>
                    <p class="text-slate-500 pt-2"><?= e($company_tax) ?></p>
                    <p class="text-slate-400 text-[10px]"><?= e($company_address) ?></p>
                </div>
            </div>

            <!-- 3. HAKEDİŞ VE KESİNTİLER TABLOSU -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden mb-6">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-900 text-white text-[11px] font-bold uppercase tracking-wider">
                            <th class="py-3 px-4 w-12 text-center">#</th>
                            <th class="py-3 px-4">Gelir / Hakediş Kalemi</th>
                            <th class="py-3 px-4 text-right">Tutar (₺)</th>
                            <th class="py-3 px-4">Kesinti / Mahsup Kalemi</th>
                            <th class="py-3 px-4 text-right">Tutar (₺)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr>
                            <td class="py-3.5 px-4 text-center font-bold text-slate-400">1</td>
                            <td class="py-3.5 px-4 font-semibold text-slate-800">Aylık Taban Maaş</td>
                            <td class="py-3.5 px-4 text-right font-bold text-slate-900"><?= format_money($payroll['base_salary']) ?></td>
                            <td class="py-3.5 px-4 text-amber-700 font-semibold">Dönem İçi Alınan Avans Mahsubu</td>
                            <td class="py-3.5 px-4 text-right font-bold text-amber-700">-<?= format_money($payroll['advance_deductions']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-3.5 px-4 text-center font-bold text-slate-400">2</td>
                            <td class="py-3.5 px-4 font-semibold text-emerald-700">Ek Prim / Fazla Mesai / Bonus</td>
                            <td class="py-3.5 px-4 text-right font-bold text-emerald-700">+<?= format_money($payroll['bonus']) ?></td>
                            <td class="py-3.5 px-4 text-slate-600">Diğer Yasal / Özel Kesintiler</td>
                            <td class="py-3.5 px-4 text-right font-bold text-slate-700">-<?= format_money($payroll['deductions']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 4. NET ÖDENEN TUTAR KUTUSU -->
            <div class="flex flex-col sm:flex-row justify-end mb-8">
                <div class="w-full sm:w-80 bg-slate-50 p-5 rounded-2xl border border-slate-200 space-y-2 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Toplam Hakediş (Brüt):</span>
                        <strong class="text-slate-900"><?= format_money((float)$payroll['base_salary'] + (float)$payroll['bonus']) ?></strong>
                    </div>

                    <div class="flex justify-between text-rose-600">
                        <span>Toplam Kesintiler / Avans:</span>
                        <strong>-<?= format_money((float)$payroll['advance_deductions'] + (float)$payroll['deductions']) ?></strong>
                    </div>

                    <div class="pt-3 border-t-2 border-slate-200 flex justify-between text-sm font-bold text-slate-900">
                        <span>Ödenen Net Maaş:</span>
                        <span class="text-emerald-600 text-base font-black"><?= format_money($payroll['net_paid']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. İMZA VE TEDİYE ONAY ALANI -->
        <div class="pt-6 border-t border-slate-200 text-xs">
            <div class="grid grid-cols-2 gap-8 pt-4">
                <div class="border-t border-slate-300 pt-2 text-center">
                    <p class="font-bold text-slate-800"><?= e($company_name) ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5">İşveren / Yetkili İmza & Kaşe</p>
                </div>

                <div class="border-t border-slate-300 pt-2 text-center">
                    <p class="font-bold text-slate-800"><?= e($payroll['first_name'] . ' ' . $payroll['last_name']) ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5">Personel İmzası</p>
                    <p class="text-[9px] text-slate-400 italic mt-1">"Yukarıda belirtilen net maaşımı eksiksiz olarak teslim aldım."</p>
                </div>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>