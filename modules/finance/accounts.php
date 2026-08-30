<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - KASA & BANKA HESAPLARI (OTOMATİK BAKİYE EŞİTLEME)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    redirect(BASE_URL . '/modules/auth/login.php');
}
require_permission('finance.view');

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // Yeni Hesap Açma
    if ($action === 'create_account') {
        $name     = trim($_POST['account_name'] ?? '');
        $type     = $_POST['account_type'] ?? 'bank';
        $currency = $_POST['currency'] ?? 'TRY';
        $bank     = trim($_POST['bank_name'] ?? '');
        $iban     = trim($_POST['iban'] ?? '');

        if (!empty($name)) {
            $stmt = $db->prepare("INSERT INTO accounts (account_name, account_type, currency, bank_name, iban, balance, created_at) VALUES (?, ?, ?, ?, ?, 0.00, NOW())");
            $stmt->execute([$name, $type, $currency, $bank, $iban]);
            set_flash('success', "{$name} hesabı başarıyla oluşturuldu.");
            redirect(BASE_URL . '/modules/finance/accounts.php');
        }
    }

    // Manuel Para Girişi / Çıkışı
    if ($action === 'create_transaction') {
        $acc_id   = (int)$_POST['account_id'];
        $type     = $_POST['type']; // income, expense
        $category = trim($_POST['category'] ?? 'Genel');
        $amount   = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $date     = $_POST['transaction_date'] ?? date('Y-m-d');
        $desc     = trim($_POST['description'] ?? '');

        if ($acc_id > 0 && $amount > 0) {
            $db->prepare("INSERT INTO transactions (account_id, type, category, amount, transaction_date, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$acc_id, $type, $category, $amount, $date, $desc, $user['id']]);

            recalculate_account_balance($acc_id);
            set_flash('success', 'Kasa hareketi işlendi ve bakiye güncellendi.');
            redirect(BASE_URL . '/modules/finance/accounts.php');
        }
    }

    // Kasa Hareketini Silme
    if ($action === 'delete_tx') {
        $tx_id = (int)$_POST['transaction_id'];
        $tx = $db->query("SELECT * FROM transactions WHERE id = {$tx_id}")->fetch();
        if ($tx) {
            $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$tx_id]);
            if (!empty($tx['account_id'])) {
                recalculate_account_balance((int)$tx['account_id']);
            }
            if (!empty($tx['contact_id'])) {
                recalculate_contact_balance((int)$tx['contact_id']);
            }
            set_flash('success', 'Hareket silindi ve kasa bakiyesi eşitlendi.');
            redirect(BASE_URL . '/modules/finance/accounts.php');
        }
    }
}

// SAYFA AÇILDIĞINDA TÜM KASALARI AKTİF HAREKETLERLE MATEMATİKSEL EŞİTLE (HAYALET BAKİYEYİ SIFIRLAR)
recalculate_account_balance();

// 2. VERİLERİ ÇEKME
$accounts = $db->query("SELECT * FROM accounts WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$transactions = $db->query("
    SELECT t.*, a.account_name, u.full_name as user_name
    FROM transactions t
    LEFT JOIN accounts a ON t.account_id = a.id
    LEFT JOIN users u ON t.created_by = u.id
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 30
")->fetchAll();

$page_title = 'Kasa & Banka Hesapları';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ openAccountModal: false, openTxModal: false }">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Kasa & Banka Yönetimi</h1>
            <p class="text-xs text-slate-500 mt-0.5">Şirket nakit kasası, ticari banka hesapları ve anlık nakit akışı.</p>
        </div>
        <div class="flex items-center gap-2">
            <button @click="openAccountModal = true" class="inline-flex items-center gap-1.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-bold py-2.5 px-4 rounded-xl shadow-xs transition">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Yeni Hesap Tanımla</span>
            </button>
            <button @click="openTxModal = true" class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition">
                <i data-lucide="arrow-left-right" class="w-4 h-4"></i>
                <span>Manuel Hareket Girişi</span>
            </button>
        </div>
    </div>

    <!-- Kasa Kartları (Artık 0,00 ₺ olarak kusursuz) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
        <?php foreach ($accounts as $acc): ?>
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between mb-4">
                <div class="w-10 h-10 rounded-2xl <?= $acc['account_type'] === 'cash' ? 'bg-amber-50 text-amber-600' : 'bg-brand-50 text-brand-600' ?> flex items-center justify-center">
                    <i data-lucide="<?= $acc['account_type'] === 'cash' ? 'banknote' : 'building-2' ?>" class="w-5 h-5"></i>
                </div>
                <span class="text-[11px] font-mono font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600"><?= $acc['currency'] ?></span>
            </div>
            <h3 class="text-sm font-bold text-slate-900"><?= e($acc['account_name']) ?></h3>
            <p class="text-xs text-slate-400 mt-0.5"><?= !empty($acc['bank_name']) ? e($acc['bank_name']) : 'Nakit Kasa' ?></p>
            <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-500 font-medium">Bakiye:</span>
                <span class="text-xl font-black <?= (float)$acc['balance'] >= 0 ? 'text-slate-900' : 'text-rose-600' ?>">
                    <?= format_money($acc['balance'], $acc['currency']) ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Son Kasa Hareketleri Tablosu -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Son Kasa & Banka Hareketleri</h3>
            <span class="text-xs text-slate-400">Son 30 İşlem</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase">
                        <th class="py-3 px-4">Tarih</th>
                        <th class="py-3 px-4">Hesap</th>
                        <th class="py-3 px-4">Kategori / Açıklama</th>
                        <th class="py-3 px-4">İşlemi Yapan</th>
                        <th class="py-3 px-4 text-right">Tutar</th>
                        <th class="py-3 px-4 text-right">İşlem</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="6" class="py-8 text-center text-slate-400">Kasa hareketi bulunmuyor.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): 
                            $is_inc = ($tx['type'] === 'income');
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-4 text-slate-600 font-medium"><?= format_date($tx['transaction_date']) ?></td>
                            <td class="py-3 px-4 font-bold text-slate-800"><?= e($tx['account_name'] ?: 'Kasa Dışı Dekont') ?></td>
                            <td class="py-3 px-4">
                                <span class="font-semibold text-slate-800"><?= e($tx['category']) ?></span>
                                <span class="text-slate-500 text-[11px] block"><?= e($tx['description']) ?></span>
                            </td>
                            <td class="py-3 px-4 text-slate-500"><?= e($tx['user_name'] ?? 'Sistem') ?></td>
                            <td class="py-3 px-4 text-right font-black <?= $is_inc ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $is_inc ? '+' : '-' ?><?= format_money($tx['amount']) ?>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <form method="POST" action="" onsubmit="return confirm('Bu hareketi silmek istiyor musunuz?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_tx">
                                    <input type="hidden" name="transaction_id" value="<?= $tx['id'] ?>">
                                    <button type="submit" class="p-1 text-slate-300 hover:text-rose-600">✕ Sil</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL 1: HESAP AÇ -->
    <div x-show="openAccountModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openAccountModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4">Yeni Kasa / Banka Hesabı</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_account">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Hesap Adı *</label>
                    <input type="text" name="account_name" required placeholder="Örn: Garanti BBVA Şirket Hesabı" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Hesap Türü</label>
                        <select name="account_type" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="bank">Banka Hesabı</option>
                            <option value="cash">Nakit Kasa</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Para Birimi</label>
                        <select name="currency" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (CURRENCIES as $c => $n): ?>
                                <option value="<?= $c ?>"><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">IBAN Numarası</label>
                    <input type="text" name="iban" placeholder="TR..." class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openAccountModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: MANUEL HAREKET -->
    <div x-show="openTxModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openTxModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4">Manuel Para Giriş / Çıkış</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_transaction">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">İşlem Türü</label>
                        <select name="type" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="income">↗ Gelir (Para Girişi)</option>
                            <option value="expense">↘ Gider (Para Çıkışı)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Hesap Seçin *</label>
                        <select name="account_id" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= e($a['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tutar *</label>
                        <input type="number" step="0.01" name="amount" required placeholder="0.00" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Kategori</label>
                        <input type="text" name="category" placeholder="Ofis Gideri, Yemek vb." class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <input type="text" name="description" placeholder="Açıklama notu..." class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openTxModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">İşlemi Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>