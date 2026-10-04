<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - KASA & BANKA HESAPLARI (OTOMATİK BAKİYE EŞİTLEME)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
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
        $type     = ($_POST['account_type'] ?? 'bank') === 'cash' ? 'cash' : 'bank';
        $currency = array_key_exists($_POST['currency'] ?? '', CURRENCIES) ? $_POST['currency'] : 'TRY';
        $bank     = trim($_POST['bank_name'] ?? '');
        $iban     = trim($_POST['iban'] ?? '');

        if (!empty($name)) {
            $stmt = $db->prepare("INSERT INTO accounts (account_name, account_type, currency, bank_name, iban, balance, created_at) VALUES (?, ?, ?, ?, ?, 0.00, NOW())");
            $stmt->execute([$name, $type, $currency, $bank, $iban]);
            set_flash('success', "{$name} hesabı başarıyla oluşturuldu.");
            redirect(BASE_URL . '/modules/finance/accounts.php');
        }
    }

    // Hesap Düzenleme
    if ($action === 'edit_account') {
        $acc_id = (int)($_POST['account_id'] ?? 0);
        $name   = trim($_POST['account_name'] ?? '');
        if ($acc_id > 0 && $name !== '') {
            $db->prepare("UPDATE accounts SET account_name = ?, bank_name = ?, iban = ? WHERE id = ?")
               ->execute([$name, trim($_POST['bank_name'] ?? ''), trim($_POST['iban'] ?? ''), $acc_id]);
            set_flash('success', 'Hesap bilgileri güncellendi.');
        }
        redirect(BASE_URL . '/modules/finance/accounts.php');
    }

    // Hesabı Pasife Alma (hareketler korunur)
    if ($action === 'deactivate_account') {
        $acc_id = (int)($_POST['account_id'] ?? 0);
        recalculate_account_balance($acc_id);
        $bal = (float)$db->query("SELECT balance FROM accounts WHERE id = {$acc_id}")->fetchColumn();
        if (abs($bal) > 0.009) {
            set_flash('error', 'Bakiyesi sıfır olmayan bir hesap pasife alınamaz. Önce bakiyeyi virman ile aktarınız.');
        } else {
            $done = false;
            foreach (['inactive', 'passive', 'closed'] as $st) {
                try {
                    $up = $db->prepare("UPDATE accounts SET status = ? WHERE id = ?");
                    $up->execute([$st, $acc_id]);
                    $done = $db->query("SELECT status FROM accounts WHERE id = {$acc_id}")->fetchColumn() === $st;
                } catch (Throwable $e) {
                    $done = false;
                }
                if ($done) {
                    break;
                }
            }
            $done ? set_flash('success', 'Hesap pasife alındı. Geçmiş hareketler korunmaktadır.')
                  : set_flash('error', 'Hesap durumu güncellenemedi.');
        }
        redirect(BASE_URL . '/modules/finance/accounts.php');
    }

    // Hesaplar Arası Virman (Transfer)
    if ($action === 'transfer') {
        $from   = (int)($_POST['from_account_id'] ?? 0);
        $to     = (int)($_POST['to_account_id'] ?? 0);
        $amount = parse_money($_POST['amount'] ?? '0');
        $date   = valid_date($_POST['transaction_date'] ?? '', date('Y-m-d'));
        $desc   = trim($_POST['description'] ?? '');

        $acc_stmt = $db->prepare("SELECT id, account_name, currency FROM accounts WHERE id = ? AND status = 'active'");
        $acc_stmt->execute([$from]);
        $from_acc = $acc_stmt->fetch();
        $acc_stmt->execute([$to]);
        $to_acc = $acc_stmt->fetch();

        if (!$from_acc || !$to_acc || $from === $to || $amount <= 0) {
            set_flash('error', 'Lütfen iki farklı aktif hesap ve geçerli bir tutar seçiniz.');
        } elseif ($from_acc['currency'] !== $to_acc['currency']) {
            set_flash('error', 'Farklı para birimindeki hesaplar arasında virman yapılamaz.');
        } else {
            $ins = $db->prepare("INSERT INTO transactions (account_id, type, category, amount, transaction_date, description, created_by, created_at) VALUES (?, ?, 'Virman / Hesaplar Arası Transfer', ?, ?, ?, ?, NOW())");
            $ins->execute([$from, 'expense', $amount, $date, ($desc ?: "{$to_acc['account_name']} hesabına virman"), $user['id']]);
            $ins->execute([$to, 'income', $amount, $date, ($desc ?: "{$from_acc['account_name']} hesabından virman"), $user['id']]);
            recalculate_account_balance($from);
            recalculate_account_balance($to);
            set_flash('success', 'Virman işlemi tamamlandı.');
        }
        redirect(BASE_URL . '/modules/finance/accounts.php');
    }

    // Manuel Para Girişi / Çıkışı
    if ($action === 'create_transaction') {
        $acc_id   = (int)$_POST['account_id'];
        $type     = ($_POST['type'] ?? '') === 'income' ? 'income' : 'expense';
        $category = trim($_POST['category'] ?? '') ?: 'Genel';
        $amount   = parse_money($_POST['amount'] ?? '0');
        $date     = valid_date($_POST['transaction_date'] ?? '', date('Y-m-d'));
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
            if (!empty($tx['invoice_id'])) {
                // Faturaya bağlı tahsilat silindiyse fatura ödeme durumu da geri alınır
                sync_invoice_payment((int)$tx['invoice_id']);
            }
            set_flash('success', 'Hareket silindi; kasa, cari ve fatura durumları eşitlendi.');
            redirect(BASE_URL . '/modules/finance/accounts.php');
        }
    }
}

// SAYFA AÇILDIĞINDA TÜM KASALARI AKTİF HAREKETLERLE MATEMATİKSEL EŞİTLE (HAYALET BAKİYEYİ SIFIRLAR)
recalculate_account_balance();

// 2. VERİLERİ ÇEKME
$accounts = $db->query("SELECT * FROM accounts WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$account_filter = (int)($_GET['account'] ?? 0);
$tx_limit = min(500, max(30, (int)($_GET['limit'] ?? 30)));

$tx_sql = "
    SELECT t.*, a.account_name, u.full_name as user_name
    FROM transactions t
    LEFT JOIN accounts a ON t.account_id = a.id
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.account_id IS NOT NULL
";
$tx_params = [];
if ($account_filter > 0) {
    $tx_sql .= " AND t.account_id = ?";
    $tx_params[] = $account_filter;
}
$tx_sql .= " ORDER BY t.transaction_date DESC, t.id DESC LIMIT {$tx_limit}";
$tx_stmt = $db->prepare($tx_sql);
$tx_stmt->execute($tx_params);
$transactions = $tx_stmt->fetchAll();

$page_title = 'Kasa & Banka Hesapları';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ openAccountModal: false, openTxModal: false, openTransferModal: false, openEditAccModal: false, editAcc: {} }">
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
            <?php if (count($accounts) > 1): ?>
            <button @click="openTransferModal = true" class="inline-flex items-center gap-1.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-bold py-2.5 px-4 rounded-xl shadow-xs transition">
                <i data-lucide="repeat" class="w-4 h-4"></i>
                <span>Virman</span>
            </button>
            <?php endif; ?>
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
            <div class="flex items-start justify-between gap-2">
                <div>
                    <a href="?account=<?= (int)$acc['id'] ?>" class="text-sm font-bold text-slate-900 hover:text-brand-600"><?= e($acc['account_name']) ?></a>
                    <p class="text-xs text-slate-400 mt-0.5"><?= !empty($acc['bank_name']) ? e($acc['bank_name']) : 'Nakit Kasa' ?></p>
                    <?php if (!empty($acc['iban'])): ?><p class="text-[10px] font-mono text-slate-400 mt-0.5"><?= e($acc['iban']) ?></p><?php endif; ?>
                </div>
                <div class="flex items-center gap-1">
                    <button @click="editAcc = { id: <?= (int)$acc['id'] ?>, account_name: <?= js_val($acc['account_name']) ?>, bank_name: <?= js_val($acc['bank_name'] ?? '') ?>, iban: <?= js_val($acc['iban'] ?? '') ?> }; openEditAccModal = true" class="p-1.5 text-slate-400 hover:text-brand-600" title="Düzenle">
                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                    </button>
                    <form method="POST" action="" onsubmit="return confirm('Hesap pasife alınsın mı? (Bakiyesi sıfır olmalıdır)');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="deactivate_account">
                        <input type="hidden" name="account_id" value="<?= (int)$acc['id'] ?>">
                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600" title="Pasife Al"><i data-lucide="archive" class="w-3.5 h-3.5"></i></button>
                    </form>
                </div>
            </div>
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
            <form method="GET" action="" class="flex items-center gap-2 text-xs">
                <select name="account" onchange="this.form.submit()" class="py-1.5 px-2.5 bg-slate-50 border border-slate-200 rounded-lg">
                    <option value="0">Tüm Hesaplar</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $account_filter === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['account_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="limit" onchange="this.form.submit()" class="py-1.5 px-2.5 bg-slate-50 border border-slate-200 rounded-lg">
                    <?php foreach ([30, 100, 250, 500] as $lim): ?>
                        <option value="<?= $lim ?>" <?= $tx_limit === $lim ? 'selected' : '' ?>>Son <?= $lim ?> işlem</option>
                    <?php endforeach; ?>
                </select>
            </form>
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
                                    <button type="submit" class="p-1 text-slate-300 hover:text-rose-600">Sil</button>
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
                    <label class="block text-xs font-bold text-slate-600 mb-1">Banka Adı</label>
                    <input type="text" name="bank_name" placeholder="Örn: Garanti BBVA" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
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

    <!-- VİRMAN MODALI -->
    <div x-show="openTransferModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openTransferModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4">Hesaplar Arası Virman</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="transfer">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Çıkış Hesabı *</label>
                        <select name="from_account_id" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int)$a['id'] ?>"><?= e($a['account_name']) ?> (<?= format_money($a['balance'], $a['currency']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Giriş Hesabı *</label>
                        <select name="to_account_id" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <?php foreach (array_reverse($accounts) as $a): ?>
                                <option value="<?= (int)$a['id'] ?>"><?= e($a['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tutar *</label>
                        <input type="number" step="0.01" min="0.01" name="amount" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tarih</label>
                        <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <input type="text" name="description" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openTransferModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs">Virman Yap</button>
                </div>
            </form>
        </div>
    </div>

    <!-- HESAP DÜZENLEME MODALI -->
    <div x-show="openEditAccModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openEditAccModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4">Hesabı Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="account_id" :value="editAcc.id">
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Hesap Adı *</label>
                    <input type="text" name="account_name" required x-model="editAcc.account_name" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Banka Adı</label>
                    <input type="text" name="bank_name" x-model="editAcc.bank_name" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">IBAN</label>
                    <input type="text" name="iban" x-model="editAcc.iban" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                </div>
                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openEditAccModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>