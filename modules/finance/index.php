<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - OTOMATİK VERGİ HESAPLAMA & BEYANNAME MOTORU
 * ====================================================================
 */

$page_title = 'Otomatik Vergi Paneli';
require_once __DIR__ . '/../../includes/header.php';
require_permission('finance.taxes');

// Dönem Seçimi (Ay ve Yıl)
$selected_month = (int)($_GET['month'] ?? date('m'));
$selected_year  = (int)($_GET['year'] ?? date('Y'));

// 1. KDV HESAPLAMA SORGULARI
// Hesaplanan KDV (Satış Faturaları)
$calc_vat_stmt = $db->prepare("
    SELECT COALESCE(SUM(subtotal), 0) as matrah, COALESCE(SUM(vat_amount), 0) as vat, COALESCE(SUM(withholding_amount), 0) as withh
    FROM invoices 
    WHERE invoice_type = 'sales' AND MONTH(issue_date) = ? AND YEAR(issue_date) = ?
");
$calc_vat_stmt->execute([$selected_month, $selected_year]);
$sales_tax = $calc_vat_stmt->fetch();

// İndirilecek KDV (Alış Faturaları)
$deduct_vat_stmt = $db->prepare("
    SELECT COALESCE(SUM(subtotal), 0) as matrah, COALESCE(SUM(vat_amount), 0) as vat, COALESCE(SUM(stoppage_amount), 0) as stopaj
    FROM invoices 
    WHERE invoice_type = 'purchase' AND MONTH(issue_date) = ? AND YEAR(issue_date) = ?
");
$deduct_vat_stmt->execute([$selected_month, $selected_year]);
$purchase_tax = $deduct_vat_stmt->fetch();

// Net KDV Durumu: Hesaplanan KDV - Tevkifat - İndirilecek KDV
$net_payable_vat = (float)$sales_tax['vat'] - (float)$sales_tax['withh'] - (float)$purchase_tax['vat'];

// Stopaj / Muhtasar Yükü
$total_stoppage = (float)$purchase_tax['stopaj'];

// Gelir - Gider Dengesi & Kurumlar/Geçici Vergi Tahmini (%25 Oranında)
$gross_profit = (float)$sales_tax['matrah'] - (float)$purchase_tax['matrah'];
$estimated_corporate_tax = $gross_profit > 0 ? $gross_profit * 0.25 : 0.00;
?>

<!-- Başlık & Dönem Seçici -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Otomatik Vergi & Beyanname Motoru</h1>
        <p class="text-xs text-slate-500 mt-1">Giren/çıkan faturalar ve tevkifatlardan hesaplanan resmi vergi yükü projeksiyonu.</p>
    </div>
    
    <!-- Ay / Yıl Seçici Form -->
    <form method="GET" action="" class="flex items-center gap-2 bg-white p-1.5 rounded-2xl border border-slate-200 shadow-sm">
        <select name="month" onchange="this.form.submit()" class="py-1.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $selected_month === $m ? 'selected' : '' ?>>
                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?> (<?= $m ?>. Ay)
                </option>
            <?php endfor; ?>
        </select>
        <select name="year" onchange="this.form.submit()" class="py-1.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800">
            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $selected_year === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<!-- Ana Vergi Sonuç Kartları -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
    <!-- 1. KDV KARTI -->
    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">1 Nolu KDV Beyannamesi</span>
            <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold text-xs">%20</span>
        </div>
        <?php if ($net_payable_vat > 0): ?>
            <p class="text-3xl font-bold text-rose-600 mt-2"><?= format_money($net_payable_vat) ?></p>
            <span class="text-xs font-semibold text-rose-700 bg-rose-50 px-2.5 py-1 rounded-lg mt-3 inline-block">
                Bu Ay Maliyeye Ödenecek KDV
            </span>
        <?php else: ?>
            <p class="text-3xl font-bold text-emerald-600 mt-2"><?= format_money(abs($net_payable_vat)) ?></p>
            <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-lg mt-3 inline-block">
                Sonraki Aya Devreden KDV
            </span>
        <?php endif; ?>
    </div>

    <!-- 2. STOPAJ KARTI -->
    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Muhtasar & Stopaj Yükü</span>
            <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-xs">%20</span>
        </div>
        <p class="text-3xl font-bold text-slate-900 mt-2"><?= format_money($total_stoppage) ?></p>
        <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-lg mt-3 inline-block">
            Kira, SMM & Freelancer Kesintileri
        </span>
    </div>

    <!-- 3. GEÇİCİ VERGİ KARTI -->
    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Tahmini Kurumlar / Gelir Vergisi</span>
            <span class="w-8 h-8 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center font-bold text-xs">%25</span>
        </div>
        <p class="text-3xl font-bold text-slate-900 mt-2"><?= format_money($estimated_corporate_tax) ?></p>
        <span class="text-xs font-semibold text-cyan-700 bg-cyan-50 px-2.5 py-1 rounded-lg mt-3 inline-block">
            Net Kâr Matrahı: <?= format_money($gross_profit) ?>
        </span>
    </div>
</div>

<!-- Detaylı KDV Matrah & Dağılım Tablosu -->
<div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-8">
    <div class="p-5 border-b border-slate-200 bg-slate-50/50">
        <h3 class="text-sm font-bold text-slate-900">KDV Beyannamesi Hesaplama Detayları (<?= $selected_month ?>/<?= $selected_year ?>)</h3>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Satış (Gelir) Tarafı -->
            <div class="space-y-4">
                <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider pb-2 border-b border-slate-100">1. Kesilen Faturalar (Gelir / Satış)</h4>
                <div class="flex justify-between text-xs py-1">
                    <span class="text-slate-600">Toplam Satış Matrahı:</span>
                    <strong class="text-slate-900"><?= format_money($sales_tax['matrah']) ?></strong>
                </div>
                <div class="flex justify-between text-xs py-1">
                    <span class="text-slate-600">Hesaplanan Toplam KDV (+):</span>
                    <strong class="text-slate-900"><?= format_money($sales_tax['vat']) ?></strong>
                </div>
                <div class="flex justify-between text-xs py-1 text-purple-700">
                    <span>Müşteri Tarafından Kesilen Tevkifat (-):</span>
                    <strong><?= format_money($sales_tax['withh']) ?></strong>
                </div>
                <div class="pt-2 border-t border-slate-100 flex justify-between text-xs font-bold text-slate-900">
                    <span>Tahsil Edilen Net KDV:</span>
                    <span><?= format_money((float)$sales_tax['vat'] - (float)$sales_tax['withh']) ?></span>
                </div>
            </div>

            <!-- Alış (Gider) Tarafı -->
            <div class="space-y-4">
                <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider pb-2 border-b border-slate-100">2. Gelen Faturalar (Gider / Alış)</h4>
                <div class="flex justify-between text-xs py-1">
                    <span class="text-slate-600">Toplam Gider Matrahı:</span>
                    <strong class="text-slate-900"><?= format_money($purchase_tax['matrah']) ?></strong>
                </div>
                <div class="flex justify-between text-xs py-1">
                    <span class="text-slate-600">İndirilecek KDV (-):</span>
                    <strong class="text-slate-900"><?= format_money($purchase_tax['vat']) ?></strong>
                </div>
                <div class="flex justify-between text-xs py-1 text-rose-600">
                    <span>Doğan Stopaj Yükü:</span>
                    <strong><?= format_money($purchase_tax['stopaj']) ?></strong>
                </div>
                <div class="pt-2 border-t border-slate-100 flex justify-between text-xs font-bold text-slate-900">
                    <span>Gider KDV Toplamı:</span>
                    <span><?= format_money($purchase_tax['vat']) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>