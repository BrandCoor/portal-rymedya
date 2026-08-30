<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - SET ÇAĞRI KAĞIDI (CALL SHEET) A4 PDF DÖKÜM MOTORU
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    die("Yetkisiz erişim!");
}

$shoot_id = (int)($_GET['shoot_id'] ?? 0);

// Çekim ve Proje Detaylarını Getir
$stmt = $db->prepare("
    SELECT s.*, 
           p.project_name, p.project_code, p.project_type, p.description as brief,
           c.company_title as client_name, c.authorized_person as client_rep, c.phone as client_phone
    FROM shoots s
    JOIN projects p ON s.project_id = p.id
    LEFT JOIN contacts c ON p.client_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$shoot_id]);
$shoot = $stmt->fetch();

if (!$shoot) {
    die("Çekim seti kaydı bulunamadı!");
}

// Çekime Atanmış Ekip & Ekipman Listesini Çek
$cg_stmt = $db->prepare("
    SELECT scg.*, c.company_title as contact_title, c.phone as contact_phone, c.authorized_person
    FROM shoot_crew_gear scg
    LEFT JOIN contacts c ON scg.contact_id = c.id
    WHERE scg.shoot_id = ?
    ORDER BY scg.category ASC
");
$cg_stmt->execute([$shoot_id]);
$crew_items = $cg_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CallSheet_<?= e($shoot['project_code']) ?>_<?= e($shoot['title']) ?></title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .print-shadow-none { box-shadow: none !important; border: 1px solid #94a3b8 !important; }
            @page { size: A4; margin: 8mm; }
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
            <span>Call Sheet Yazdır / PDF İndir</span>
        </button>
    </div>

    <!-- A4 ÇAĞRI KAĞIDI SAYFASI -->
    <div class="bg-white max-w-4xl w-full p-8 sm:p-10 rounded-3xl shadow-xl border border-slate-200 print-shadow-none flex flex-col justify-between" style="min-height: 297mm;">
        
        <div>
            <!-- 1. BAŞLIK VE SET KİMLİĞİ -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b-2 border-slate-900 gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl bg-slate-900 flex items-center justify-center text-white shadow-md">
                        <i data-lucide="clapperboard" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-black text-slate-900 tracking-tight">RY MEDYA PRODÜKSİYON</h1>
                        <p class="text-xs font-bold text-brand-600 tracking-wide uppercase">SET ÇAĞRI KAĞIDI / CALL SHEET</p>
                    </div>
                </div>

                <div class="text-left sm:text-right">
                    <span class="inline-block px-3 py-1 bg-slate-900 text-white text-xs font-mono font-bold rounded-lg">
                        <?= e($shoot['project_code']) ?>
                    </span>
                    <p class="text-xs font-bold text-slate-900 mt-1"><?= format_date($shoot['shoot_date']) ?></p>
                </div>
            </div>

            <!-- 2. PROJE & MEKAN & SAAT BİLGİLERİ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 my-6">
                <!-- Sol: Proje & Müşteri -->
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-xs space-y-1.5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Prodüksiyon Detayları</span>
                    <p class="text-sm font-bold text-slate-900"><?= e($shoot['project_name']) ?></p>
                    <p class="text-slate-600">Tür: <strong><?= PROJECT_TYPES[$shoot['project_type']] ?? $shoot['project_type'] ?></strong></p>
                    <p class="text-slate-600">Müşteri: <strong><?= e($shoot['client_name']) ?></strong> <?= !empty($shoot['client_phone']) ? "({$shoot['client_phone']})" : '' ?></p>
                </div>

                <!-- Sağ: Mekan & Saat -->
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-xs space-y-1.5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Set & Mekan Bilgisi</span>
                    <p class="text-sm font-bold text-slate-900"><?= e($shoot['title']) ?></p>
                    <p class="text-slate-700 flex items-center gap-1 font-semibold">
                        <i data-lucide="map-pin" class="w-3.5 h-3.5 text-brand-600"></i>
                        <span><?= e($shoot['location_name']) ?></span>
                    </p>
                    <p class="text-slate-500 text-[11px]"><?= e($shoot['location_address'] ?: 'Adres belirtilmemiş.') ?></p>
                    <p class="text-slate-700">Set Saati: <strong><?= $shoot['start_time'] ? date('H:i', strtotime($shoot['start_time'])) : '08:00' ?> - <?= $shoot['end_time'] ? date('H:i', strtotime($shoot['end_time'])) : 'Set Sonu' ?></strong></p>
                </div>
            </div>

            <!-- 3. CALL SHEET / SET NOTLARI & DİREKTİFLER -->
            <?php if (!empty($shoot['call_sheet_notes'])): ?>
            <div class="mb-6 p-4 bg-amber-50/70 border border-amber-200 rounded-2xl text-xs">
                <span class="text-[10px] font-bold text-amber-800 uppercase tracking-wider block mb-1 flex items-center gap-1">
                    <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-amber-600"></i>
                    <span>Set & Servis Direktifleri / Call Sheet Notları:</span>
                </span>
                <p class="text-slate-800 leading-relaxed whitespace-pre-line"><?= e($shoot['call_sheet_notes']) ?></p>
            </div>
            <?php endif; ?>

            <!-- 4. EKİP & EKİPMAN & GÖREV LİSTESİ -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden mb-6">
                <div class="bg-slate-900 text-white px-4 py-2.5 text-xs font-bold uppercase tracking-wider flex justify-between">
                    <span>Set Ekibi & Ekipman Dağılımı</span>
                    <span><?= count($crew_items) ?> Kalem</span>
                </div>
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-2.5 px-4 w-12 text-center">#</th>
                            <th class="py-2.5 px-4">Departman / Görev</th>
                            <th class="py-2.5 px-4">Kişi / Ekipman / Açıklama</th>
                            <th class="py-2.5 px-4">Cari / Sorumlu</th>
                            <th class="py-2.5 px-4 text-right">İletişim</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php if (empty($crew_items)): ?>
                            <tr><td colspan="5" class="py-6 text-center text-slate-400">Henüz ekip ve ekipman ataması yapılmamış.</td></tr>
                        <?php else: 
                            $i = 1;
                            foreach ($crew_items as $ci):
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-2.5 px-4 text-center font-bold text-slate-400"><?= $i++ ?></td>
                            <td class="py-2.5 px-4 font-bold text-slate-900"><?= CREW_CATEGORIES[$ci['category']] ?? $ci['category'] ?></td>
                            <td class="py-2.5 px-4 text-slate-800"><?= e($ci['item_title']) ?></td>
                            <td class="py-2.5 px-4 font-medium text-slate-700"><?= e($ci['contact_title'] ?: '-') ?></td>
                            <td class="py-2.5 px-4 text-right font-mono text-slate-600"><?= e($ci['contact_phone'] ?: '-') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. ALT BİLGİ & ACİL DURUM KURALI -->
        <div class="pt-4 border-t border-slate-200 text-xs">
            <div class="grid grid-cols-2 gap-4 items-center">
                <div>
                    <p class="font-bold text-slate-800">RY MEDYA PRODÜKSİYON YÖNETİMİ</p>
                    <p class="text-[11px] text-slate-500">Lütfen çağrı saatinden en geç 15 dakika önce sette hazır bulununuz.</p>
                </div>
                <div class="text-right">
                    <p class="text-[10px] text-slate-400 uppercase font-bold">Prodüksiyon İletişim</p>
                    <p class="font-mono text-slate-700 font-bold">+90 (555) 000 00 00</p>
                </div>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>