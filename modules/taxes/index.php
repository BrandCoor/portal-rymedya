<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - MANUEL & KADEMELİ GELİR VERGİSİ, KDV MOTORU
 * ====================================================================
 */

$page_title = 'Otomatik Vergi & Beyanname Motoru';
require_once __DIR__ . '/../../includes/header.php';
require_permission('finance.taxes');

// 1. DÖNEM, ŞİRKET TÜRÜ VE ÖZEL VERGİ ORANI SEÇİMİ
$selected_month  = (int)($_GET['month'] ?? date('m'));
$selected_year   = (int)($_GET['year'] ?? date('Y'));
$company_type    = $_GET['company_type'] ?? 'personal'; // 'personal', 'corporate', 'custom'
$custom_tax_rate = (float)($_GET['custom_tax_rate'] ?? 27); // Kullanıcının girdiği özel oran

// ====================================================================
// 2. KDV BEYANNAMESİ HESAPLAMALARI (SEÇİLİ AY)
// ====================================================================
$calc_vat_stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(subtotal), 0) as matrah, 
        COALESCE(SUM(vat_amount), 0) as vat, 
        COALESCE(SUM(withholding_amount), 0) as withh
    FROM invoices 
    WHERE invoice_type = 'sales' AND MONTH(issue_date) = ? AND YEAR(issue_date) = ?
");
$calc_vat_stmt->execute([$selected_month, $selected_year]);
$sales_tax = $calc_vat_stmt->fetch();

$deduct_vat_stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(subtotal), 0) as matrah, 
        COALESCE(SUM(vat_amount), 0) as vat, 
        COALESCE(SUM(stoppage_amount), 0) as stopaj
    FROM invoices 
    WHERE invoice_type = 'purchase' AND MONTH(issue_date) = ? AND YEAR(issue_date) = ?
");
$deduct_vat_stmt->execute([$selected_month, $selected_year]);
$purchase_tax = $deduct_vat_stmt->fetch();

// Net KDV Durumu: Hesaplanan KDV - Tevkifat - İndirilecek KDV
$net_payable_vat = (float)$sales_tax['vat'] - (float)$sales_tax['withh'] - (float)$purchase_tax['vat'];
$total_stoppage  = (float)$purchase_tax['stopaj'];

// Seçili Ayın Kâr Matrahı
$monthly_gross_profit = (float)$sales_tax['matrah'] - (float)$purchase_tax['matrah'];

// ====================================================================
// 3. GELİR VERGİSİ DİLİMLERİ MOTORU (GVK 103 - YILA GÖRE TARİFE)
// ====================================================================
function income_tax_brackets_for_year(int $year): array {
    $years = array_keys(INCOME_TAX_BRACKETS);
    // Tarifesi tanımlı olmayan yıllar için en yakın önceki (yoksa en eski) yılın tarifesi kullanılır
    $usable = array_filter($years, fn($y) => $y <= $year);
    $key = $usable ? max($usable) : min($years);
    return INCOME_TAX_BRACKETS[$key];
}

function calculate_income_tax_brackets(float $profit, int $year): array {
    $brackets = income_tax_brackets_for_year($year);
    if ($profit <= 0) {
        return ['tax' => 0.00, 'effective_rate' => 0.0, 'bracket_info' => '%' . $brackets[0][1] . ' (Kâr Yok / Zarar)', 'bracket_index' => 0];
    }

    $tax = 0.00;
    $lower = 0.0;
    $bracket_index = 0;
    foreach ($brackets as $i => [$upper, $rate]) {
        $cap = $upper === null ? $profit : min($profit, (float)$upper);
        if ($cap > $lower) {
            $tax += ($cap - $lower) * ($rate / 100);
            $bracket_index = $i;
        }
        if ($upper === null || $profit <= $upper) {
            break;
        }
        $lower = (float)$upper;
    }

    return [
        'tax'            => round($tax, 2),
        'effective_rate' => round(($tax / $profit) * 100, 1),
        'bracket_info'   => '%' . $brackets[$bracket_index][1] . ' Dilimi',
        'bracket_index'  => $bracket_index
    ];
}

// Gelir vergisi YILLIK kümülatif kâr üzerinden hesaplanır. Seçili aya düşen pay,
// "Ocak–seçili ay" vergisi ile "Ocak–önceki ay" vergisi arasındaki farktır.
$ytd_stmt = $db->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN invoice_type = 'sales' THEN subtotal ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN invoice_type = 'purchase' THEN subtotal ELSE 0 END), 0)
    FROM invoices
    WHERE YEAR(issue_date) = ? AND MONTH(issue_date) <= ?
");
$ytd_stmt->execute([$selected_year, $selected_month]);
$ytd_profit = (float)$ytd_stmt->fetchColumn();
$ytd_stmt->execute([$selected_year, $selected_month - 1]);
$ytd_profit_prev = (float)$ytd_stmt->fetchColumn();

$ytd_tax_data      = calculate_income_tax_brackets($ytd_profit, $selected_year);
$ytd_tax_prev_data = calculate_income_tax_brackets($ytd_profit_prev, $selected_year);
$active_brackets   = income_tax_brackets_for_year($selected_year);

$monthly_income_tax_data = [
    'tax'            => max(0, round($ytd_tax_data['tax'] - $ytd_tax_prev_data['tax'], 2)),
    'effective_rate' => $ytd_tax_data['effective_rate'],
    'bracket_info'   => $ytd_tax_data['bracket_info'],
    'bracket_index'  => $ytd_tax_data['bracket_index'],
];

$corporate_rate          = (float)get_setting('corporate_tax_rate', '25');
$monthly_corporate_tax   = $monthly_gross_profit > 0 ? round($monthly_gross_profit * ($corporate_rate / 100), 2) : 0.00;
$custom_calculated_tax   = $monthly_gross_profit > 0 ? round($monthly_gross_profit * ($custom_tax_rate / 100), 2) : 0.00;

// Seçili Yönteme Göre Vergi Tutarını Belirle
if ($company_type === 'custom') {
    $active_tax_amount = $custom_calculated_tax;
    $active_tax_label  = "Manuel Seçilen Vergi (%{$custom_tax_rate})";
    $active_badge      = "%{$custom_tax_rate} Sabit Dilim";
} elseif ($company_type === 'corporate') {
    $active_tax_amount = $monthly_corporate_tax;
    $active_tax_label  = "Kurumlar Vergisi (%{$corporate_rate})";
    $active_badge      = "%{$corporate_rate} Sabit";
} else {
    $active_tax_amount = $monthly_income_tax_data['tax'];
    $active_tax_label  = "Kademeli Gelir Vergisi (GVK)";
    $active_badge      = $monthly_income_tax_data['bracket_info'];
}

// Şirketin Cebine Kalan Gerçek Net Para (Tüm Vergiler Sonrası)
$actual_net_take_home = $monthly_gross_profit - ($net_payable_vat > 0 ? $net_payable_vat : 0) - $active_tax_amount - $total_stoppage;
?>

<!-- Üst Başlık & Gelişmiş Vergi Seçim Paneli -->
<div class="mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Gelir Vergisi, KDV & Beyanname Motoru</h1>
        <p class="text-xs text-slate-500 mt-0.5">Otomatik kademeli dilimler veya harici gelirlerinize göre manuel vergi oranı seçimi.</p>
    </div>
    
    <!-- Dönem & Vergi Türü / Özel Oran Seçici Form -->
    <form method="GET" action="" class="flex flex-wrap items-center gap-2 bg-white p-2.5 rounded-2xl border border-slate-200 shadow-sm" x-data="{ cType: '<?= e($company_type) ?>' }">
        
        <!-- Vergi Hesaplama Modu -->
        <select name="company_type" x-model="cType" onchange="this.form.submit()" class="py-2 px-3 bg-indigo-50 border border-indigo-200 rounded-xl text-xs font-bold text-indigo-950 cursor-pointer">
            <option value="personal">Şahıs Şirketi (Otomatik Kademeli %15-%40)</option>
            <option value="corporate">Ltd. Şti. / A.Ş. (Sabit Kurumlar %<?= e((string)$corporate_rate) ?>)</option>
            <option value="custom">Özel / Manuel Vergi Oranı Seçimi (%...)</option>
        </select>

        <!-- Manuel Oran Seçimi (Sadece Özel Seçildiğinde Açılır) -->
        <div x-show="cType === 'custom'" class="flex items-center gap-1 bg-amber-50 p-1 rounded-xl border border-amber-200">
            <select name="custom_tax_rate" onchange="this.form.submit()" class="py-1.5 px-2 bg-white border border-amber-300 rounded-lg text-xs font-bold text-amber-900 cursor-pointer">
                <option value="15" <?= (int)$custom_tax_rate === 15 ? 'selected' : '' ?>>%15 (1. Dilim)</option>
                <option value="20" <?= (int)$custom_tax_rate === 20 ? 'selected' : '' ?>>%20 (2. Dilim)</option>
                <option value="27" <?= (int)$custom_tax_rate === 27 ? 'selected' : '' ?>>%27 (3. Dilim)</option>
                <option value="35" <?= (int)$custom_tax_rate === 35 ? 'selected' : '' ?>>%35 (4. Dilim)</option>
                <option value="40" <?= (int)$custom_tax_rate === 40 ? 'selected' : '' ?>>%40 (5. Dilim)</option>
            </select>
        </div>

        <!-- Ay / Yıl Seçimi -->
        <select name="month" onchange="this.form.submit()" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 cursor-pointer">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $selected_month === $m ? 'selected' : '' ?>>
                    <?= turkish_month($m) ?> (<?= $m ?>. Ay)
                </option>
            <?php endfor; ?>
        </select>

        <select name="year" onchange="this.form.submit()" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 cursor-pointer">
            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $selected_year === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<!-- ==================================================================== -->
<!-- 1. BÜYÜK VERGİ SONUÇ KARTLARI (3'LÜ GRID) -->
<!-- ==================================================================== -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    
    <!-- 1. KDV BEYANNAMESİ -->
    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">1 Nolu KDV Beyannamesi</span>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">%20 Standart</span>
            </div>
            <?php if ($net_payable_vat > 0): ?>
                <p class="text-3xl font-black text-rose-600 mt-2"><?= format_money($net_payable_vat) ?></p>
                <span class="text-xs font-bold text-rose-700 bg-rose-50 px-2.5 py-1 rounded-xl mt-2 inline-block">
                    Maliyeye Ödenecek Net KDV
                </span>
            <?php else: ?>
                <p class="text-3xl font-black text-emerald-600 mt-2"><?= format_money(abs($net_payable_vat)) ?></p>
                <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-xl mt-2 inline-block">
                    Sonraki Aya Devreden KDV
                </span>
            <?php endif; ?>
        </div>
        <p class="text-[11px] text-slate-400 mt-4">Satış KDV'si - İndirilecek Gider KDV'si</p>
    </div>

    <!-- 2. GELİR VERGİSİ / KURUMLAR VERGİSİ (MANUEL ORAN DESTEKLİ) -->
    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                    <?= e($active_tax_label) ?>
                </span>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                    <?= e($active_badge) ?>
                </span>
            </div>
            <p class="text-3xl font-black text-indigo-600 mt-2"><?= format_money($active_tax_amount) ?></p>
            <span class="text-xs font-bold text-indigo-900 bg-indigo-50 px-2.5 py-1 rounded-xl mt-2 inline-block">
                Kâr Matrahı: <?= format_money($monthly_gross_profit) ?>
            </span>
        </div>
        <p class="text-[11px] text-slate-400 mt-4">
            <?= $company_type === 'custom' ? "Dış gelirleriniz sebebiyle belirlenen %{$custom_tax_rate} oranı uygulandı" : ($company_type === 'personal' ? 'GVK 103 Kademeli Dilim Hesaplaması' : 'Sabit Kurumlar Vergisi') ?>
        </p>
    </div>

    <!-- 3. MUHTASAR & STOPAJ YÜKÜ -->
    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Muhtasar & Stopaj Yükü</span>
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Stopaj</span>
            </div>
            <p class="text-3xl font-black text-slate-900 mt-2"><?= format_money($total_stoppage) ?></p>
            <span class="text-xs font-bold text-slate-700 bg-slate-100 px-2.5 py-1 rounded-xl mt-2 inline-block">
                Kira, SMM & Freelancer Kesintileri
            </span>
        </div>
        <p class="text-[11px] text-slate-400 mt-4">Gider pusulaları ve SMM stopajları</p>
    </div>

</div>

<!-- ==================================================================== -->
<!-- 2. NET CEBE KALAN KAZANÇ (VERGİLER SONRASI NET NAKİT) BARI -->
<!-- ==================================================================== -->
<div class="p-6 rounded-3xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white shadow-xl mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
    <div>
        <span class="px-3 py-1 bg-emerald-500/20 border border-emerald-400/30 rounded-full text-[10px] font-bold uppercase tracking-wider text-emerald-300">
            Mali Bilanço & Net Kazanç
        </span>
        <h3 class="text-xl font-black mt-2">Vergiler Düştükten Sonra Net Kazanç</h3>
        <p class="text-xs text-slate-300 mt-1">Cirodan giderler, KDV ve seçilen Gelir/Kurumlar vergisi düşüldükten sonra şirkete kalan net para.</p>
    </div>

    <div class="text-right">
        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Cepte Kalan Net Kâr</span>
        <p class="text-3xl font-black <?= $actual_net_take_home >= 0 ? 'text-emerald-400' : 'text-rose-400' ?> mt-1">
            <?= format_money($actual_net_take_home) ?>
        </p>
        <span class="text-[11px] text-slate-300 block mt-1">
            Toplam Satış: <?= format_money($sales_tax['matrah']) ?> | Gider: <?= format_money($purchase_tax['matrah']) ?>
        </span>
    </div>
</div>

<!-- ==================================================================== -->
<!-- 3. KADEMELİ GELİR VERGİSİ DİLİMLERİ TABLOSU -->
<!-- ==================================================================== -->
<div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-8">
    <div class="p-5 border-b border-slate-200 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <h3 class="text-sm font-bold text-slate-900">Gelir Vergisi Dilim Baremleri (GVK Madde 103)</h3>
            <p class="text-xs text-slate-400">Şahıs şirketlerinin yıllık kâr matrahına göre uygulanan <?= (int)$selected_year ?> yılı kademeli oranları (Ocak–<?= turkish_month($selected_month) ?> kümülatif kâr: <?= format_money($ytd_profit) ?>)</p>
        </div>
        <span class="text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 px-3 py-1 rounded-xl">
            <?= $company_type === 'custom' ? "Uygulanan Özel Dilim: %{$custom_tax_rate}" : "Efektif Oran: %{$monthly_income_tax_data['effective_rate']}" ?>
        </span>
    </div>

    <div class="p-6 grid grid-cols-1 sm:grid-cols-5 gap-3 text-xs text-center">
        <?php $lower_b = 0; foreach ($active_brackets as $bi => [$upper_b, $rate_b]):
            $is_active_b = ($company_type === 'custom' && (int)$custom_tax_rate === (int)$rate_b)
                        || ($company_type === 'personal' && $monthly_income_tax_data['bracket_index'] === $bi);
        ?>
        <div class="p-3.5 rounded-2xl border <?= $is_active_b ? 'bg-indigo-50 border-indigo-300 ring-2 ring-indigo-500' : 'bg-slate-50 border-slate-200' ?>">
            <span class="font-bold text-slate-400 block text-[10px]"><?= $bi + 1 ?>. DİLİM</span>
            <strong class="text-slate-900 text-sm block mt-1">%<?= $rate_b ?></strong>
            <span class="text-[11px] text-slate-500 block mt-1">
                <?= $upper_b === null ? number_format($lower_b, 0, ',', '.') . ' ₺ ve Üzeri' : number_format($lower_b, 0, ',', '.') . ' - ' . number_format($upper_b, 0, ',', '.') . ' ₺' ?>
            </span>
        </div>
        <?php $lower_b = $upper_b ?? $lower_b; endforeach; ?>
    </div>
</div>

<!-- ==================================================================== -->
<!-- 4. DETAYLI KDV MATRAH & BEYANNAME HESAPLAMA TABLOSU -->
<!-- ==================================================================== -->
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