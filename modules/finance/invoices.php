<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - FATURALAR & ZİNCİRLEME TAHSİLAT SİLME MOTORU
 * ====================================================================
 */

$page_title = 'Faturalar (Gelen / Giden)';
require_once __DIR__ . '/../../includes/header.php';
require_permission('finance.invoices');

// 1. FATURA İŞLEMLERİ (POST HANDLER)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // Ortak form okuma & doğrulama
    $read_invoice_form = function () {
        return [
            'invoice_type'     => ($_POST['invoice_type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales',
            'invoice_number'   => trim($_POST['invoice_number'] ?? ''),
            'contact_id'       => (int)($_POST['contact_id'] ?? 0),
            'project_id'       => !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null,
            'issue_date'       => valid_date($_POST['issue_date'] ?? '', date('Y-m-d')),
            'due_date'         => valid_date($_POST['due_date'] ?? ''),
            'subtotal'         => parse_money($_POST['subtotal'] ?? '0'),
            'vat_rate'         => (float)($_POST['vat_rate'] ?? 20),
            'withholding_rate' => array_key_exists($_POST['withholding_rate'] ?? '', WITHHOLDING_RATES) ? $_POST['withholding_rate'] : '0/10',
            'stoppage_rate'    => max(0, min(100, (float)($_POST['stoppage_rate'] ?? 0))),
            'notes'            => trim($_POST['notes'] ?? ''),
        ];
    };

    // A. YENİ FATURA
    if ($action === 'create_invoice') {
        $f = $read_invoice_form();
        if ($f['invoice_number'] === '') {
            $f['invoice_number'] = generate_invoice_number($f['invoice_type']);
        }

        if ($f['contact_id'] <= 0 || $f['subtotal'] <= 0) {
            set_flash('error', 'Lütfen cari seçiniz ve geçerli bir tutar giriniz.');
        } elseif (invoice_number_exists($f['invoice_number'], 0, $f['invoice_type'])) {
            set_flash('error', "{$f['invoice_number']} numaralı fatura zaten kayıtlı.");
        } else {
            $tax = calculate_tax_breakdown($f['subtotal'], $f['vat_rate'], $f['withholding_rate'], $f['stoppage_rate']);

            $db->prepare("
                INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, due_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, NOW())
            ")->execute([
                $f['invoice_type'], $f['invoice_number'], $f['contact_id'], $f['project_id'], $f['issue_date'], $f['due_date'],
                $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'],
                $tax['withholding_rate'], $tax['withholding_amount'],
                $tax['stoppage_rate'], $tax['stoppage_amount'],
                $tax['grand_total'], $f['notes']
            ]);

            recalculate_contact_balance($f['contact_id']);
            set_flash('success', "{$f['invoice_number']} numaralı fatura başarıyla kaydedildi.");
        }
        redirect(BASE_URL . '/modules/finance/invoices.php');
    }

    // B. FATURA DÜZENLEME
    if ($action === 'edit_invoice') {
        $invoice_id = (int)$_POST['invoice_id'];
        $f = $read_invoice_form();

        $old_stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
        $old_stmt->execute([$invoice_id]);
        $old_inv = $old_stmt->fetch();

        if (!$old_inv) {
            set_flash('error', 'Fatura bulunamadı.');
        } elseif ($f['invoice_number'] === '' || $f['contact_id'] <= 0 || $f['subtotal'] <= 0) {
            set_flash('error', 'Fatura no, cari ve tutar zorunludur.');
        } elseif (invoice_number_exists($f['invoice_number'], $invoice_id, $f['invoice_type'])) {
            set_flash('error', "{$f['invoice_number']} numaralı başka bir fatura zaten kayıtlı.");
        } else {
            $tax = calculate_tax_breakdown($f['subtotal'], $f['vat_rate'], $f['withholding_rate'], $f['stoppage_rate']);

            $db->prepare("
                UPDATE invoices 
                SET invoice_type = ?, invoice_number = ?, contact_id = ?, project_id = ?, issue_date = ?, due_date = ?,
                    subtotal = ?, vat_rate = ?, vat_amount = ?, withholding_rate = ?, withholding_amount = ?,
                    stoppage_rate = ?, stoppage_amount = ?, grand_total = ?, notes = ?
                WHERE id = ?
            ")->execute([
                $f['invoice_type'], $f['invoice_number'], $f['contact_id'], $f['project_id'], $f['issue_date'], $f['due_date'],
                $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'],
                $tax['withholding_rate'], $tax['withholding_amount'],
                $tax['stoppage_rate'], $tax['stoppage_amount'],
                $tax['grand_total'], $f['notes'], $invoice_id
            ]);

            // Faturaya bağlı tahsilatlar yeni cari/tür ile uyumlu hale getirilir
            $db->prepare("UPDATE transactions SET contact_id = ?, project_id = ?, type = ? WHERE invoice_id = ?")
               ->execute([$f['contact_id'], $f['project_id'], $f['invoice_type'] === 'sales' ? 'income' : 'expense', $invoice_id]);

            sync_invoice_payment($invoice_id);
            recalculate_contact_balance($f['contact_id']);
            if ((int)$old_inv['contact_id'] !== $f['contact_id'] && !empty($old_inv['contact_id'])) {
                recalculate_contact_balance((int)$old_inv['contact_id']);
            }
            recalculate_account_balance();
            set_flash('success', "{$f['invoice_number']} numaralı fatura güncellendi.");
        }
        redirect(BASE_URL . '/modules/finance/invoices.php');
    }

    // C. ZİNCİRLEME FATURA SİLME (TAHSİLATLAR VE KASA DAHİL SİLİNİR)
    if ($action === 'delete_invoice') {
        $del_id = (int)$_POST['invoice_id'];
        delete_invoice_cascade($del_id);
        set_flash('success', "Fatura ve faturaya ait tüm tahsilatlar silindi, kasa ve cari bakiyeleri otomatik eşitlendi.");
        redirect(BASE_URL . '/modules/finance/invoices.php');
    }

    // D. TAHSİLAT / ÖDEME
    if ($action === 'pay_invoice') {
        $err = record_invoice_payment(
            (int)$_POST['invoice_id'],
            (int)($_POST['account_id'] ?? 0),
            parse_money($_POST['pay_amount'] ?? '0'),
            valid_date($_POST['pay_date'] ?? '', date('Y-m-d')),
            (int)$user['id']
        );
        $err ? set_flash('error', $err) : set_flash('success', 'Ödeme başarıyla işlendi; kasa, cari ve fatura durumu güncellendi.');
        redirect(BASE_URL . '/modules/finance/invoices.php');
    }
}

$auto_sales_number    = generate_invoice_number('sales');
$auto_purchase_number = generate_invoice_number('purchase');

// 2. SORGULAR
$type_filter   = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');
$date_from     = valid_date($_GET['from'] ?? '');
$date_to       = valid_date($_GET['to'] ?? '');

$sql = "
    SELECT i.*, c.company_title as contact_title, p.project_name, p.project_code
    FROM invoices i
    LEFT JOIN contacts c ON i.contact_id = c.id
    LEFT JOIN projects p ON i.project_id = p.id
    WHERE 1=1
";
$params = [];

if (!empty($type_filter)) {
    $sql .= " AND i.invoice_type = ?";
    $params[] = $type_filter;
}
if ($status_filter === 'overdue') {
    $sql .= " AND i.payment_status != 'paid' AND i.due_date IS NOT NULL AND i.due_date < CURRENT_DATE()";
} elseif (!empty($status_filter)) {
    $sql .= " AND i.payment_status = ?";
    $params[] = $status_filter;
}
if ($search !== '') {
    $sql .= " AND (i.invoice_number LIKE ? OR c.company_title LIKE ? OR p.project_name LIKE ? OR i.notes LIKE ?)";
    $term = "%{$search}%";
    array_push($params, $term, $term, $term, $term);
}
if ($date_from) {
    $sql .= " AND i.issue_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $sql .= " AND i.issue_date <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY i.issue_date DESC, i.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$contacts = $db->query("SELECT id, company_title, type FROM contacts ORDER BY company_title ASC")->fetchAll();
$projects = $db->query("SELECT id, project_name, project_code FROM projects ORDER BY id DESC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, currency, balance FROM accounts WHERE status = 'active'")->fetchAll();

$total_sales = $db->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE invoice_type = 'sales'")->fetchColumn();
$total_purchases = $db->query("SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE invoice_type = 'purchase'")->fetchColumn();
$total_uncollected = $db->query("SELECT COALESCE(SUM(grand_total - paid_amount), 0) FROM invoices WHERE invoice_type = 'sales' AND payment_status != 'paid'")->fetchColumn();
?>

<div x-data="{ 
    openInvoiceModal: false, 
    openEditInvoiceModal: false,
    invoiceType: 'sales',
    invoiceNumber: '<?= $auto_sales_number ?>',
    salesAutoNo: '<?= $auto_sales_number ?>',
    purchaseAutoNo: '<?= $auto_purchase_number ?>',
    updateAutoNo() {
        this.invoiceNumber = (this.invoiceType === 'sales') ? this.salesAutoNo : this.purchaseAutoNo;
    },
    editInvData: {},
    openPayModal: false, 
    selectedInvoice: null, 
    subtotal: 0, 
    vatRate: 20, 
    withholdingRate: '0/10', 
    stoppageRate: 0,
    calcTax(sub, vatR, withhR, stopR) {
        let s = parseFloat(sub) || 0;
        let v = s * (parseFloat(vatR) / 100);
        let w = 0;
        if (withhR !== '0/10' && withhR && withhR.includes('/')) {
            let parts = withhR.split('/');
            w = v * (parseFloat(parts[0]) / parseFloat(parts[1]));
        }
        let st = s * (parseFloat(stopR) / 100);
        let g = (s + v) - w - st;
        return { vat: v.toFixed(2), withholding: w.toFixed(2), stoppage: st.toFixed(2), grand: g.toFixed(2) };
    }
}">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Fatura Yönetimi (Gelir / Gider)</h1>
            <p class="text-xs text-slate-500 mt-1">Kesilen faturalar, tahsilatlar ve zincirleme muhasebe denetimi.</p>
        </div>
        <button @click="openInvoiceModal = true" class="inline-flex items-center justify-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold py-2.5 px-4 rounded-xl shadow-md transition cursor-pointer">
            <i data-lucide="file-plus" class="w-4 h-4"></i>
            <span>Yeni Fatura Girişi</span>
        </button>
    </div>

    <!-- İstatistik Kartları -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Toplam Satış Faturası</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1"><?= format_money($total_sales) ?></p>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Toplam Gider / Alış Faturası</p>
            <p class="text-2xl font-bold text-rose-600 mt-1"><?= format_money($total_purchases) ?></p>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Bekleyen Müşteri Tahsilatları</p>
            <p class="text-2xl font-bold text-amber-600 mt-1"><?= format_money($total_uncollected) ?></p>
        </div>
    </div>

    <!-- Filtreler -->
    <form method="GET" action="" class="mb-4 flex flex-wrap items-end gap-2 bg-white p-3 rounded-2xl border border-slate-200 shadow-sm text-xs">
        <div>
            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Ara</label>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Fatura no, cari, proje..." class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl w-52">
        </div>
        <div>
            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Tür</label>
            <select name="type" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl">
                <option value="">Tümü</option>
                <option value="sales" <?= $type_filter === 'sales' ? 'selected' : '' ?>>Satış</option>
                <option value="purchase" <?= $type_filter === 'purchase' ? 'selected' : '' ?>>Alış / Gider</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Ödeme Durumu</label>
            <select name="status" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl">
                <option value="">Tümü</option>
                <option value="unpaid" <?= $status_filter === 'unpaid' ? 'selected' : '' ?>>Ödenmedi</option>
                <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Kısmi Ödendi</option>
                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Ödendi</option>
                <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Vadesi Geçmiş</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Başlangıç</label>
            <input type="date" name="from" value="<?= e($date_from) ?>" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl">
        </div>
        <div>
            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Bitiş</label>
            <input type="date" name="to" value="<?= e($date_to) ?>" class="py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl">
        </div>
        <button type="submit" class="py-2 px-4 bg-slate-900 text-white font-bold rounded-xl">Filtrele</button>
        <a href="<?= BASE_URL ?>/modules/finance/invoices.php" class="py-2 px-3 text-slate-500 font-semibold">Temizle</a>
    </form>

    <!-- Faturalar Tablosu -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/75 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                        <th class="py-3.5 px-4">Fatura No & Tür</th>
                        <th class="py-3.5 px-4">Cari Ünvan</th>
                        <th class="py-3.5 px-4">Tarih</th>
                        <th class="py-3.5 px-4 text-right">Matrah</th>
                        <th class="py-3.5 px-4 text-right">KDV / Tevkifat</th>
                        <th class="py-3.5 px-4 text-right">Genel Toplam</th>
                        <th class="py-3.5 px-4 text-center">Durum</th>
                        <th class="py-3.5 px-4 text-right">İşlemler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs">
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="8" class="py-12 text-center text-slate-400">Kayıtlı fatura bulunamadı.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): 
                            $is_sales = ($inv['invoice_type'] === 'sales');
                            $rem = (float)$inv['grand_total'] - (float)$inv['paid_amount'];
                        ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="py-3.5 px-4">
                                <div class="font-bold font-mono text-slate-900"><?= e($inv['invoice_number']) ?></div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold <?= $is_sales ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' ?>">
                                    <?= $is_sales ? '↗ SATIŞ' : '↘ GİDER' ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-4 font-bold text-slate-800"><?= e($inv['contact_title']) ?></td>
                            <td class="py-3.5 px-4 text-slate-600"><?= format_date($inv['issue_date']) ?></td>
                            <td class="py-3.5 px-4 text-right font-medium text-slate-700"><?= format_money($inv['subtotal']) ?></td>
                            <td class="py-3.5 px-4 text-right">
                                <div>+<?= format_money($inv['vat_amount']) ?></div>
                                <?php if ((float)$inv['withholding_amount'] > 0): ?>
                                    <span class="text-[10px] text-purple-600 font-bold block">-<?= format_money($inv['withholding_amount']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-4 text-right font-black text-slate-900"><?= format_money($inv['grand_total']) ?></td>
                            <td class="py-3.5 px-4 text-center">
                                <?php
                                    $ps_map = [
                                        'paid'    => ['ÖDENDİ', 'bg-emerald-100 text-emerald-800'],
                                        'partial' => ['KISMİ', 'bg-amber-100 text-amber-800'],
                                        'unpaid'  => ['ÖDENMEDİ', 'bg-rose-100 text-rose-800'],
                                    ];
                                    $ps = $ps_map[$inv['payment_status']] ?? $ps_map['unpaid'];
                                    $is_overdue = $inv['payment_status'] !== 'paid' && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d');
                                ?>
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= $ps[1] ?>"><?= $ps[0] ?></span>
                                <?php if ($is_overdue): ?>
                                    <span class="block mt-1 text-[9px] font-bold text-rose-600">VADE GEÇTİ (<?= format_date($inv['due_date']) ?>)</span>
                                <?php elseif ($inv['payment_status'] === 'partial'): ?>
                                    <span class="block mt-1 text-[9px] text-slate-500">Kalan: <?= format_money($rem) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= $inv['id'] ?>" target="_blank" class="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg">
                                        <i data-lucide="printer" class="w-4 h-4"></i>
                                    </a>

                                    <button @click="editInvData = {
                                                id: '<?= $inv['id'] ?>',
                                                invoice_type: '<?= $inv['invoice_type'] ?>',
                                                invoice_number: <?= js_val($inv['invoice_number']) ?>,
                                                contact_id: '<?= $inv['contact_id'] ?>',
                                                project_id: '<?= $inv['project_id'] ?? '' ?>',
                                                issue_date: '<?= $inv['issue_date'] ?>',
                                                due_date: '<?= $inv['due_date'] ?? '' ?>',
                                                subtotal: '<?= (float)$inv['subtotal'] ?>',
                                                vat_rate: '<?= (float)$inv['vat_rate'] ?>',
                                                withholding_rate: '<?= $inv['withholding_rate'] ?? '0/10' ?>',
                                                stoppage_rate: '<?= (float)$inv['stoppage_rate'] ?>',
                                                notes: <?= js_val($inv['notes'] ?? '') ?>
                                            }; openEditInvoiceModal = true"
                                            class="p-1.5 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-600 rounded-lg transition" title="Düzenle">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>

                                    <?php if ($inv['payment_status'] !== 'paid'): ?>
                                    <button @click="selectedInvoice = { id: <?= $inv['id'] ?>, number: <?= js_val($inv['invoice_number']) ?>, remaining: '<?= $rem ?>' }; openPayModal = true"
                                            class="bg-brand-50 hover:bg-brand-600 hover:text-white text-brand-700 font-bold py-1 px-2.5 rounded-lg text-[11px] transition">
                                        Tahsilat
                                    </button>
                                    <?php endif; ?>

                                    <!-- ZİNCİRLEME SİLME BUTONU -->
                                    <form method="POST" action="" onsubmit="return confirm('DİKKAT: Bu faturayı sildiğinizde faturaya ait tüm tahsilatlar da otomatik silinecek ve kasa bakiyesi düzeltilecektir. Onaylıyor musunuz?');" class="inline-block">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_invoice">
                                        <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 transition" title="Faturayı ve Tahsilatları Sil">✕</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL 1: YENİ FATURA -->
    <div x-show="openInvoiceModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-3xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden" @click.away="openInvoiceModal = false">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between flex-shrink-0">
                <h3 class="text-base font-bold text-slate-900">Yeni Fatura Girişi</h3>
                <button type="button" @click="openInvoiceModal = false" class="text-slate-400 hover:text-slate-800">✕</button>
            </div>
            <form method="POST" action="" class="flex-1 overflow-y-auto p-6 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_invoice">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Tür *</label>
                        <select name="invoice_type" x-model="invoiceType" @change="updateAutoNo()" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="sales">↗ Satış Faturası (Gelir)</option>
                            <option value="purchase">↘ Alış Faturası (Gider)</option>
                        </select>
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-bold uppercase text-slate-600">Fatura No *</label>
                            <button type="button" @click="updateAutoNo()" class="text-[10px] text-brand-600 font-bold hover:underline">Otomatik Yenile</button>
                        </div>
                        <input type="text" name="invoice_number" required x-model="invoiceNumber" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Tarih *</label>
                        <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Cari Seçin *</label>
                        <select name="contact_id" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                            <option value="">-- Cari Seçin --</option>
                            <?php foreach ($contacts as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['company_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Proje (Opsiyonel)</label>
                        <select name="project_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="">-- Projesiz / Genel --</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['project_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Matrah *</label>
                            <input type="number" step="0.01" name="subtotal" x-model="subtotal" required placeholder="0.00" class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl text-xs font-bold">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">KDV Oranı</label>
                            <select name="vat_rate" x-model="vatRate" class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl text-xs">
                                <?php foreach (VAT_RATES as $vr): ?>
                                    <option value="<?= $vr ?>">%<?= $vr ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Tevkifat</label>
                            <select name="withholding_rate" x-model="withholdingRate" class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl text-xs">
                                <?php foreach (WITHHOLDING_RATES as $wrk => $wrn): ?>
                                    <option value="<?= $wrk ?>"><?= $wrn ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="p-3 bg-white rounded-xl border border-slate-200 flex justify-between text-xs font-bold">
                        <span>Genel Toplam:</span>
                        <span class="text-brand-600 text-sm" x-text="calcTax(subtotal, vatRate, withholdingRate, stoppageRate).grand + ' ₺'"></span>
                    </div>
                </div>
                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openInvoiceModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-semibold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: FATURA DÜZENLE -->
    <div x-show="openEditInvoiceModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-3xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden" @click.away="openEditInvoiceModal = false">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between flex-shrink-0">
                <h3 class="text-base font-bold text-slate-900">Faturayı Düzenle</h3>
                <button type="button" @click="openEditInvoiceModal = false" class="text-slate-400 hover:text-slate-800">✕</button>
            </div>
            <form method="POST" action="" class="flex-1 overflow-y-auto p-6 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_invoice">
                <input type="hidden" name="invoice_id" :value="editInvData.id">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Fatura Türü</label>
                        <select name="invoice_type" x-model="editInvData.invoice_type" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="sales">↗ Satış Faturası (Gelir)</option>
                            <option value="purchase">↘ Alış Faturası (Gider)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Fatura No *</label>
                        <input type="text" name="invoice_number" required x-model="editInvData.invoice_number" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Tarih *</label>
                        <input type="date" name="issue_date" required x-model="editInvData.issue_date" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Cari Hesap</label>
                        <select name="contact_id" x-model="editInvData.contact_id" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                            <?php foreach ($contacts as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['company_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Proje</label>
                        <select name="project_id" x-model="editInvData.project_id" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="">-- Projesiz / Genel --</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['project_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Matrah *</label>
                            <input type="number" step="0.01" name="subtotal" x-model="editInvData.subtotal" required class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl text-xs font-bold">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">KDV Oranı</label>
                            <select name="vat_rate" x-model="editInvData.vat_rate" class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl text-xs">
                                <?php foreach (VAT_RATES as $vr): ?>
                                    <option value="<?= $vr ?>">%<?= $vr ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Tevkifat</label>
                            <select name="withholding_rate" x-model="editInvData.withholding_rate" class="w-full py-2 px-3 bg-white border border-slate-300 rounded-xl text-xs">
                                <?php foreach (WITHHOLDING_RATES as $wrk => $wrn): ?>
                                    <option value="<?= $wrk ?>"><?= $wrn ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="p-3 bg-white rounded-xl border border-slate-200 flex justify-between text-xs font-bold">
                        <span>Yeni Toplam:</span>
                        <span class="text-brand-600 text-sm" x-text="calcTax(editInvData.subtotal, editInvData.vat_rate, editInvData.withholding_rate, editInvData.stoppage_rate).grand + ' ₺'"></span>
                    </div>
                </div>
                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditInvoiceModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: TAHSİLAT -->
    <div x-show="openPayModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openPayModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4">Ödeme / Tahsilat Al</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_invoice">
                <input type="hidden" name="invoice_id" :value="selectedInvoice ? selectedInvoice.id : 0">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Hesap Seçin *</label>
                    <select name="account_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (<?= format_money($acc['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Tutar *</label>
                    <input type="number" step="0.01" name="pay_amount" required :value="selectedInvoice ? selectedInvoice.remaining : 0" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Tarih</label>
                    <input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openPayModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 text-white font-bold rounded-xl text-xs shadow-md">Onayla</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>