<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - CARİ DETAY, TAHSİLAT, FATURA & DEKONT YÖNETİMİ
 * ====================================================================
 */

$contact_id = (int)($_GET['id'] ?? 0);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

// 1. TÜM FORM VE MUHASEBE İŞLEMLERİ (POST HANDLER)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // A. CARİ KÜNYE BİLGİLERİNİ DÜZENLEME
    if ($action === 'edit_contact_info') {
        require_permission('contacts.edit');
        $up = $db->prepare("UPDATE contacts SET type = ?, company_title = ?, authorized_person = ?, phone = ?, email = ?, tax_office = ?, tax_number = ?, id_number = ?, iban = ?, city = ?, district = ?, address = ? WHERE id = ?");
        $up->execute([$_POST['type'], $_POST['company_title'], $_POST['authorized_person'], $_POST['phone'], $_POST['email'], $_POST['tax_office'], $_POST['tax_number'], $_POST['id_number'], $_POST['iban'], $_POST['city'], $_POST['district'], $_POST['address'], $contact_id]);
        set_flash('success', 'Cari bilgileri güncellendi.');
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}");
    }

    // B. FATURAYA İSTİNADEN TAHSİLAT ALMA
    if ($action === 'pay_invoice_from_contact') {
        $invoice_id = (int)$_POST['invoice_id'];
        $account_id = (int)$_POST['account_id'];
        $pay_amount = (float)str_replace(['.', ','], ['', '.'], $_POST['pay_amount'] ?? '0');
        $pay_date   = $_POST['pay_date'] ?? date('Y-m-d');
        $inv = $db->query("SELECT * FROM invoices WHERE id = {$invoice_id}")->fetch();

        if ($inv && $account_id > 0 && $pay_amount > 0) {
            $new_paid = (float)$inv['paid_amount'] + $pay_amount;
            $new_status = ($new_paid >= (float)$inv['grand_total']) ? 'paid' : 'partial';

            $db->prepare("UPDATE invoices SET paid_amount = ?, payment_status = ? WHERE id = ?")->execute([$new_paid, $new_status, $invoice_id]);
            $tx_type = ($inv['invoice_type'] === 'sales') ? 'income' : 'expense';
            $acc_modifier = ($tx_type === 'income') ? $pay_amount : -$pay_amount;

            $db->prepare("INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'Fatura Tahsilatı', ?, ?, ?, ?, NOW())")
               ->execute([$account_id, $contact_id, $invoice_id, $inv['project_id'], $tx_type, $pay_amount, $pay_date, "Fatura No: {$inv['invoice_number']} ödemesi", $user['id']]);

            $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$acc_modifier, $account_id]);

            recalculate_contact_balance($contact_id);
            set_flash('success', "Tahsilat alındı ve bakiye eşitlendi.");
            redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=invoices");
        }
    }

    // C. MANUEL BORÇ / ALACAK DEKONTU EKLEME
    if ($action === 'add_manual_adjustment') {
        $type        = $_POST['adj_type'];
        $amount      = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $date        = $_POST['transaction_date'] ?? date('Y-m-d');
        $category    = trim($_POST['category'] ?? 'Manuel Cari Dekontu');
        $description = trim($_POST['description'] ?? '');
        $account_id  = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

        if ($amount > 0) {
            $tx_type = ($type === 'credit') ? 'income' : 'expense';
            if ($account_id) {
                $acc_modifier = ($tx_type === 'income') ? $amount : -$amount;
                $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$acc_modifier, $account_id]);
            }

            $db->prepare("INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at) VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$account_id, $contact_id, $tx_type, $category, $amount, $date, ($description ?: ($type === 'debit' ? 'Manuel Borç Dekontu' : 'Manuel Alacak Dekontu')), $user['id']]);

            recalculate_contact_balance($contact_id);
            set_flash('success', "Manuel dekont işlendi ve bakiye eşitlendi.");
            redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=ledger");
        }
    }

    // D. MANUEL DEKONTU DÜZENLEME
    if ($action === 'edit_manual_transaction') {
        $tx_id       = (int)$_POST['transaction_id'];
        $type        = $_POST['adj_type'];
        $amount      = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $date        = $_POST['transaction_date'] ?? date('Y-m-d');
        $category    = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $old_tx = $db->query("SELECT * FROM transactions WHERE id = {$tx_id} AND contact_id = {$contact_id}")->fetch();

        if ($old_tx && $amount > 0) {
            if (!empty($old_tx['account_id'])) {
                $acc_reversal = ($old_tx['type'] === 'income') ? -$old_tx['amount'] : $old_tx['amount'];
                $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$acc_reversal, $old_tx['account_id']]);
                
                $new_tx_type = ($type === 'credit') ? 'income' : 'expense';
                $new_acc_mod = ($new_tx_type === 'income') ? $amount : -$amount;
                $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$new_acc_mod, $old_tx['account_id']]);
            } else {
                $new_tx_type = ($type === 'credit') ? 'income' : 'expense';
            }

            $db->prepare("UPDATE transactions SET type = ?, category = ?, amount = ?, transaction_date = ?, description = ? WHERE id = ?")
               ->execute([$new_tx_type, $category, $amount, $date, $description, $tx_id]);

            recalculate_contact_balance($contact_id);
            set_flash('success', 'Dekont güncellendi.');
            redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=ledger");
        }
    }

    // E. MANUEL DEKONTU SİLME
    if ($action === 'delete_manual_transaction') {
        $tx_id = (int)$_POST['transaction_id'];
        $tx = $db->query("SELECT * FROM transactions WHERE id = {$tx_id} AND contact_id = {$contact_id}")->fetch();

        if ($tx) {
            if (!empty($tx['account_id'])) {
                $acc_reversal = ($tx['type'] === 'income') ? -$tx['amount'] : $tx['amount'];
                $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$acc_reversal, $tx['account_id']]);
            }
            $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$tx_id]);
            recalculate_contact_balance($contact_id);
            set_flash('success', 'Dekont silindi.');
            redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=ledger");
        }
    }

    // F. RESMİ FATURAYI DÜZENLEME
    if ($action === 'edit_invoice_from_contact') {
        $invoice_id       = (int)$_POST['invoice_id'];
        $invoice_type     = $_POST['invoice_type'] ?? 'sales';
        $invoice_number   = trim($_POST['invoice_number'] ?? '');
        $subtotal         = (float)str_replace(['.', ','], ['', '.'], $_POST['subtotal'] ?? '0');
        $vat_rate         = (float)($_POST['vat_rate'] ?? 20);
        $issue_date       = $_POST['issue_date'] ?? date('Y-m-d');
        $notes            = trim($_POST['notes'] ?? '');

        if (!empty($invoice_number) && $subtotal > 0) {
            $tax = calculate_tax_breakdown($subtotal, $vat_rate, '0/10', 0);
            $db->prepare("UPDATE invoices SET invoice_type = ?, invoice_number = ?, subtotal = ?, vat_rate = ?, vat_amount = ?, grand_total = ?, issue_date = ?, notes = ? WHERE id = ?")
               ->execute([$invoice_type, $invoice_number, $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'], $issue_date, $notes, $invoice_id]);

            recalculate_contact_balance($contact_id);
            set_flash('success', "Fatura güncellendi.");
            redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=invoices");
        }
    }

    // G. RESMİ FATURAYI SİLME
    if ($action === 'delete_invoice_from_contact') {
        $inv_id = (int)$_POST['invoice_id'];
        $db->prepare("DELETE FROM transactions WHERE invoice_id = ?")->execute([$inv_id]);
        $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$inv_id]);

        recalculate_contact_balance($contact_id);
        set_flash('success', 'Fatura silindi ve bakiye eşitlendi.');
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=invoices");
    }

    // H. CARİYİ KALICI SİLME
    if ($action === 'delete_contact_permanent') {
        require_permission('contacts.delete');
        $db->prepare("DELETE FROM users WHERE contact_id = ?")->execute([$contact_id]);
        $db->prepare("DELETE FROM contact_change_logs WHERE contact_id = ?")->execute([$contact_id]);
        $db->prepare("DELETE FROM transactions WHERE contact_id = ?")->execute([$contact_id]);
        $db->prepare("DELETE FROM invoices WHERE contact_id = ?")->execute([$contact_id]);
        $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$contact_id]);

        set_flash('success', "Cari kartı tamamen silindi.");
        redirect(BASE_URL . '/modules/contacts/index.php');
    }

    // I. MÜŞTERİ PORTALI GİRİŞİ
    if ($action === 'create_client_user') {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $name     = trim($_POST['full_name'] ?? $contact['company_title']);

        if (!empty($email) && !empty($password)) {
            $role_stmt = $db->query("SELECT id FROM roles WHERE role_slug = 'client' LIMIT 1");
            $client_role = $role_stmt->fetch();
            $client_role_id = $client_role ? (int)$client_role['id'] : 6;
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            $existing = $chk->fetch();

            if ($existing) {
                $db->prepare("UPDATE users SET password = ?, role_id = ?, contact_id = ?, status = 'active', full_name = ? WHERE id = ?")->execute([$hashed, $client_role_id, $contact_id, $name, $existing['id']]);
            } else {
                $db->prepare("INSERT INTO users (role_id, contact_id, full_name, email, password, phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())")->execute([$client_role_id, $contact_id, $name, $email, $hashed, $contact['phone']]);
            }
            set_flash('success', "Müşteri portalı girişi güncellendi!");
            redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}");
        }
    }
}

// CARİ BAKİYESİNİ HER SAYFA AÇILIŞINDA OTOMATİK EŞİTLE
$accurate_balance = recalculate_contact_balance($contact_id);

$stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$stmt->execute([$contact_id]);
$contact = $stmt->fetch();

$client_user_stmt = $db->prepare("SELECT * FROM users WHERE contact_id = ? LIMIT 1");
$client_user_stmt->execute([$contact_id]);
$client_user = $client_user_stmt->fetch();

$projects = $db->query("SELECT * FROM projects WHERE client_id = {$contact_id} ORDER BY id DESC")->fetchAll();
$invoices = $db->query("SELECT * FROM invoices WHERE contact_id = {$contact_id} ORDER BY issue_date DESC")->fetchAll();
$transactions = $db->query("SELECT t.*, a.account_name FROM transactions t LEFT JOIN accounts a ON t.account_id = a.id WHERE t.contact_id = {$contact_id} ORDER BY t.transaction_date DESC, t.id DESC")->fetchAll();
$change_logs = $db->query("SELECT * FROM contact_change_logs WHERE contact_id = {$contact_id} ORDER BY id DESC LIMIT 50")->fetchAll();
$accounts = $db->query("SELECT id, account_name, currency, balance FROM accounts WHERE status = 'active'")->fetchAll();

$active_tab = $_GET['tab'] ?? 'ledger';

$page_title = e($contact['company_title']) . ' | Cari Masası';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ 
    openPortalModal: false, 
    openManualAdjModal: false, 
    openEditContactModal: false,
    openEditTxModal: false,
    editTxData: {},
    openEditInvModal: false,
    editInvData: {},
    openPayInvModal: false,
    payInvData: { id: '', invoice_number: '', remaining: '' },
    tab: '<?= e($active_tab) ?>' 
}">
    <!-- Üst Başlık & Butonlar -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="<?= BASE_URL ?>/modules/contacts/index.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800 bg-white border border-slate-200 py-2 px-3.5 rounded-xl transition">
                ← Carilere Dön
            </a>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-black text-slate-900 tracking-tight"><?= e($contact['company_title']) ?></h1>
                <button @click="openEditContactModal = true" class="p-1.5 bg-slate-100 hover:bg-brand-50 text-slate-500 hover:text-brand-600 rounded-xl transition" title="Cari Bilgilerini Düzenle">
                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                </button>
            </div>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-brand-50 text-brand-700 border border-brand-200">
                <?= CONTACT_TYPES[$contact['type']] ?? $contact['type'] ?>
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button @click="openManualAdjModal = true" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md shadow-emerald-600/20 transition cursor-pointer">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                <span>+ Faturasız Borç / Alacak Dekontu</span>
            </button>

            <button @click="openPortalModal = true" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition cursor-pointer">
                <i data-lucide="key-round" class="w-4 h-4"></i>
                <span><?= $client_user ? 'Portal Girişini Yönet' : 'Müşteriye Portal Aç' ?></span>
            </button>

            <a href="<?= BASE_URL ?>/modules/contacts/statement_print.php?id=<?= $contact['id'] ?>" target="_blank"
               class="inline-flex items-center justify-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition cursor-pointer">
                <i data-lucide="printer" class="w-4 h-4"></i>
                <span>Cari Ekstre Yazdır</span>
            </a>

            <?php if (has_permission('contacts.delete')): ?>
            <form method="POST" action="" onsubmit="return confirm('DİKKAT: Bu cariyi kalıcı olarak silmek istediğinize emin misiniz?');" class="inline-block">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_contact_permanent">
                <button type="submit" class="p-2.5 bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 rounded-xl transition" title="Cariyi Kalıcı Olarak Sil">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cari Bilgi Kartları -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">İletişim Bilgileri</h3>
                <button @click="openEditContactModal = true" class="text-[11px] font-bold text-brand-600 hover:underline">Düzenle</button>
            </div>
            <p class="text-xs text-slate-700"><strong>Yetkili:</strong> <?= e($contact['authorized_person'] ?? '-') ?></p>
            <p class="text-xs text-slate-700"><strong>Telefon:</strong> <?= e($contact['phone'] ?? '-') ?></p>
            <p class="text-xs text-slate-700"><strong>E-Posta:</strong> <?= e($contact['email'] ?? '-') ?></p>
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                <span class="text-slate-500">Müşteri Portalı:</span>
                <?php if ($client_user): ?>
                    <span class="text-emerald-600 font-bold flex items-center gap-1">✓ Aktif (<?= e($client_user['email']) ?>)</span>
                <?php else: ?>
                    <span class="text-slate-400">Tanımlanmadı</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-3">
            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Fatura & Vergi Bilgileri</h3>
            <p class="text-xs text-slate-700"><strong>Vergi Dairesi:</strong> <?= e($contact['tax_office'] ?? '-') ?></p>
            <p class="text-xs text-slate-700"><strong>Vergi No:</strong> <?= e($contact['tax_number'] ?? '-') ?></p>
            <p class="text-xs text-slate-700 font-mono"><strong>IBAN:</strong> <?= e($contact['iban'] ?? '-') ?></p>
        </div>

        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <div>
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Güncel Net Cari Bakiye</h3>
                <p class="text-3xl font-black <?= $accurate_balance > 0 ? 'text-rose-600' : 'text-slate-900' ?> mt-2">
                    <?= format_money($accurate_balance) ?>
                </p>
                <span class="text-xs font-bold text-slate-500 mt-1 block">
                    <?= $accurate_balance > 0 ? 'Müşteri Borçlu (Alacağımız)' : ($accurate_balance < 0 ? 'Müşteri Alacaklı' : 'Hesap Dengede (0,00 ₺)') ?>
                </span>
            </div>
            <a href="<?= BASE_URL ?>/modules/contacts/statement_print.php?id=<?= $contact['id'] ?>" target="_blank" class="mt-4 text-xs font-bold text-brand-600 hover:text-brand-800 flex items-center gap-1">
                <i data-lucide="file-text" class="w-4 h-4"></i>
                <span>Resmi Ekstre Dökümünü Gör →</span>
            </a>
        </div>
    </div>

    <!-- SEKMELER -->
    <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm mb-8">
        <div class="flex flex-wrap border-b border-slate-200 mb-6 gap-6">
            <button @click="tab = 'ledger'" :class="tab === 'ledger' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="arrow-left-right" class="w-4 h-4 text-emerald-600"></i>
                <span>Faturasız Dekont & Kasa Hareketleri (<?= count($transactions) ?>)</span>
            </button>

            <button @click="tab = 'invoices'" :class="tab === 'invoices' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="receipt" class="w-4 h-4 text-indigo-600"></i>
                <span>Resmi Faturalar & Tahsilatlar (<?= count($invoices) ?>)</span>
            </button>

            <button @click="tab = 'projects'" :class="tab === 'projects' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="film" class="w-4 h-4"></i>
                <span>Projeler (<?= count($projects) ?>)</span>
            </button>

            <button @click="tab = 'logs'" :class="tab === 'logs' ? 'border-brand-600 text-brand-600 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="pb-3 text-xs border-b-2 transition flex items-center gap-2">
                <i data-lucide="history" class="w-4 h-4 text-amber-600"></i>
                <span>Müşteri Değişiklik Tarihçesi (<?= count($change_logs) ?>)</span>
            </button>
        </div>

        <!-- TAB 1: DEKONTLAR -->
        <div x-show="tab === 'ledger'">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-slate-800">Faturasız Manuel Dekontlar ve Kasa Hareketleri</h3>
                <button @click="openManualAdjModal = true" class="text-xs font-bold text-emerald-600 hover:underline">+ Yeni Dekont Ekle</button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-3 px-4">Tarih</th>
                            <th class="py-3 px-4">Kategori</th>
                            <th class="py-3 px-4">Kasa/Banka</th>
                            <th class="py-3 px-4">Açıklama</th>
                            <th class="py-3 px-4 text-right">Tutar & Etki</th>
                            <th class="py-3 px-4 text-right">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="6" class="py-6 text-center text-slate-400">Henüz manuel hareket kaydı bulunmuyor.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): 
                                $is_inc = ($tx['type'] === 'income');
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-3 px-4 text-slate-600 font-medium"><?= format_date($tx['transaction_date']) ?></td>
                                <td class="py-3 px-4 font-bold text-slate-900"><?= e($tx['category']) ?></td>
                                <td class="py-3 px-4 text-slate-500"><?= e($tx['account_name'] ?: 'Kasa Dışı (Dekont)') ?></td>
                                <td class="py-3 px-4 text-slate-600"><?= e($tx['description']) ?></td>
                                <td class="py-3 px-4 text-right font-black <?= $is_inc ? 'text-emerald-600' : 'text-rose-600' ?>">
                                    <?= $is_inc ? '-' : '+' ?><?= format_money($tx['amount']) ?>
                                    <span class="text-[10px] block font-normal text-slate-400">
                                        <?= $is_inc ? '(Alacaklandırıldı)' : '(Borçlandırıldı)' ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button @click="editTxData = {
                                                    id: '<?= $tx['id'] ?>',
                                                    adj_type: '<?= $is_inc ? 'credit' : 'debit' ?>',
                                                    amount: '<?= (float)$tx['amount'] ?>',
                                                    transaction_date: '<?= $tx['transaction_date'] ?>',
                                                    category: '<?= e(addslashes($tx['category'])) ?>',
                                                    description: '<?= e(addslashes($tx['description'] ?? '')) ?>'
                                                }; openEditTxModal = true"
                                                class="p-1.5 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-500 rounded-lg transition" title="Düzenle">
                                            <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                        </button>

                                        <form method="POST" action="" onsubmit="return confirm('Bu dekontu silmek istiyor musunuz?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_manual_transaction">
                                            <input type="hidden" name="transaction_id" value="<?= $tx['id'] ?>">
                                            <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Sil">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                            </button>
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

        <!-- TAB 2: FATURALAR -->
        <div x-show="tab === 'invoices'" x-cloak>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-3 px-4">Fatura No & Tür</th>
                            <th class="py-3 px-4">Tarih</th>
                            <th class="py-3 px-4 text-right">Tutar</th>
                            <th class="py-3 px-4 text-center">Durum</th>
                            <th class="py-3 px-4 text-right">İşlemler & Tahsilat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($invoices)): ?>
                            <tr><td colspan="5" class="py-6 text-center text-slate-400">Henüz fatura kaydı bulunmuyor.</td></tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $inv): 
                                $rem = (float)$inv['grand_total'] - (float)$inv['paid_amount'];
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-3 px-4">
                                    <span class="font-mono font-bold text-slate-900 block"><?= e($inv['invoice_number']) ?></span>
                                    <span class="text-[10px] font-bold <?= $inv['invoice_type'] === 'sales' ? 'text-emerald-700' : 'text-rose-700' ?>">
                                        <?= $inv['invoice_type'] === 'sales' ? '↗ Satış Faturası' : '↘ Alış Faturası' ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-slate-600"><?= format_date($inv['issue_date']) ?></td>
                                <td class="py-3 px-4 text-right font-black text-slate-900"><?= format_money($inv['grand_total']) ?></td>
                                <td class="py-3 px-4 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $inv['payment_status'] === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <?= $inv['payment_status'] === 'paid' ? 'ÖDENDİ' : 'ÖDENMEDİ' ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <?php if ($inv['payment_status'] !== 'paid'): ?>
                                        <button @click="payInvData = { id: '<?= $inv['id'] ?>', invoice_number: '<?= e($inv['invoice_number']) ?>', remaining: '<?= $rem ?>' }; openPayInvModal = true"
                                                class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 font-bold rounded-lg border border-emerald-200 transition text-[11px] flex items-center gap-1">
                                            <i data-lucide="hand-coins" class="w-3.5 h-3.5"></i>
                                            <span>Tahsilat Al</span>
                                        </button>
                                        <?php endif; ?>

                                        <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= $inv['id'] ?>" target="_blank" class="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg" title="PDF Yazdır">
                                            <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                                        </a>

                                        <button @click="editInvData = {
                                                    id: '<?= $inv['id'] ?>',
                                                    invoice_type: '<?= $inv['invoice_type'] ?>',
                                                    invoice_number: '<?= e(addslashes($inv['invoice_number'])) ?>',
                                                    subtotal: '<?= (float)$inv['subtotal'] ?>',
                                                    vat_rate: '<?= (float)$inv['vat_rate'] ?>',
                                                    issue_date: '<?= $inv['issue_date'] ?>',
                                                    notes: '<?= e(addslashes($inv['notes'] ?? '')) ?>'
                                                }; openEditInvModal = true"
                                                class="p-1.5 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-500 rounded-lg transition" title="Düzenle">
                                            <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                        </button>

                                        <form method="POST" action="" onsubmit="return confirm('Bu faturayı silmek istiyor musunuz?');" class="inline-block">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_invoice_from_contact">
                                            <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                            <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Sil">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                            </button>
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

        <!-- TAB 3: PROJELER -->
        <div x-show="tab === 'projects'" x-cloak>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-3 px-4">Proje</th>
                            <th class="py-3 px-4">Tür</th>
                            <th class="py-3 px-4">Durum</th>
                            <th class="py-3 px-4 text-right">Bütçe</th>
                            <th class="py-3 px-4 text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($projects as $p): 
                            $st = PROJECT_STATUSES[$p['status']] ?? ['label' => $p['status'], 'color' => 'bg-slate-100 text-slate-700'];
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-4 font-bold text-slate-900"><?= e($p['project_name']) ?></td>
                            <td class="py-3 px-4 text-slate-600"><?= get_project_type_name($p['project_type']) ?></td>
                            <td class="py-3 px-4"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $st['color'] ?>"><?= $st['label'] ?></span></td>
                            <td class="py-3 px-4 text-right font-bold text-slate-900"><?= format_money($p['agreed_budget'], $p['currency']) ?></td>
                            <td class="py-3 px-4 text-right"><a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= $p['id'] ?>" class="text-brand-600 font-bold hover:underline">Görüntüle →</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 4: AUDIT LOG -->
        <div x-show="tab === 'logs'" x-cloak>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-3 px-4">Tarih</th>
                            <th class="py-3 px-4">Kullanıcı</th>
                            <th class="py-3 px-4">Alan</th>
                            <th class="py-3 px-4">Eski Değer</th>
                            <th class="py-3 px-4">Yeni Değer</th>
                            <th class="py-3 px-4 text-right">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($change_logs as $log): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-4 text-slate-600"><?= format_date($log['created_at'], true) ?></td>
                            <td class="py-3 px-4 font-bold text-slate-900"><?= e($log['user_name']) ?></td>
                            <td class="py-3 px-4 font-semibold text-indigo-700"><?= e($log['field_label']) ?></td>
                            <td class="py-3 px-4 text-rose-600 line-through"><?= e($log['old_value'] ?: '(Boş)') ?></td>
                            <td class="py-3 px-4 text-emerald-700 font-bold"><?= e($log['new_value']) ?></td>
                            <td class="py-3 px-4 text-right font-mono text-slate-400"><?= e($log['ip_address']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL 1: CARİ KÜNYE DÜZENLE -->
    <div x-show="openEditContactModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openEditContactModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Cari Bilgilerini Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_contact_info">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Cari Türü *</label>
                        <select name="type" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (CONTACT_TYPES as $ck => $cv): ?>
                                <option value="<?= $ck ?>" <?= $contact['type'] === $ck ? 'selected' : '' ?>><?= $cv ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Şirket Ünvanı / Kişi *</label>
                        <input type="text" name="company_title" required value="<?= e($contact['company_title']) ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Yetkili Kişi</label>
                        <input type="text" name="authorized_person" value="<?= e($contact['authorized_person'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Telefon</label>
                        <input type="text" name="phone" value="<?= e($contact['phone'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">E-Posta</label>
                        <input type="email" name="email" value="<?= e($contact['email'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Vergi Dairesi</label>
                        <input type="text" name="tax_office" value="<?= e($contact['tax_office'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Vergi No (VKN)</label>
                        <input type="text" name="tax_number" value="<?= e($contact['tax_number'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">TC Kimlik No</label>
                        <input type="text" name="id_number" value="<?= e($contact['id_number'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">IBAN</label>
                    <input type="text" name="iban" value="<?= e($contact['iban'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açık Adres</label>
                    <textarea name="address" rows="2" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"><?= e($contact['address'] ?? '') ?></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditContactModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: TAHSİLAT AL -->
    <div x-show="openPayInvModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openPayInvModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-1" x-text="payInvData.invoice_number + ' Tahsilatı Al'"></h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_invoice_from_contact">
                <input type="hidden" name="invoice_id" :value="payInvData.id">

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Kasa / Banka *</label>
                    <select name="account_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= e($acc['account_name']) ?> (<?= format_money($acc['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Tutar *</label>
                    <input type="number" step="0.01" name="pay_amount" required :value="payInvData.remaining" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Tarih</label>
                    <input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openPayInvModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs shadow-md">
                        ✓ Onayla
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: MANUEL DEKONT DÜZENLE -->
    <div x-show="openEditTxModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openEditTxModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Manuel Dekontu Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_manual_transaction">
                <input type="hidden" name="transaction_id" :value="editTxData.id">

                <div class="grid grid-cols-2 gap-2 text-xs">
                    <label class="flex items-center gap-2 p-2 bg-slate-50 rounded-xl border cursor-pointer">
                        <input type="radio" name="adj_type" value="debit" :checked="editTxData.adj_type === 'debit'" class="text-rose-600">
                        <span class="font-bold text-rose-700">BORÇLANDIR (+)</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 bg-slate-50 rounded-xl border cursor-pointer">
                        <input type="radio" name="adj_type" value="credit" :checked="editTxData.adj_type === 'credit'" class="text-emerald-600">
                        <span class="font-bold text-emerald-700">ALACAKLANDIR (-)</span>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tutar *</label>
                        <input type="number" step="0.01" name="amount" required x-model="editTxData.amount" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tarih</label>
                        <input type="date" name="transaction_date" required x-model="editTxData.transaction_date" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Kategori</label>
                    <input type="text" name="category" x-model="editTxData.category" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <input type="text" name="description" x-model="editTxData.description" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditTxModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: FATURA DÜZENLE -->
    <div x-show="openEditInvModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200" @click.away="openEditInvModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Faturayı Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_invoice_from_contact">
                <input type="hidden" name="invoice_id" :value="editInvData.id">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Fatura Türü</label>
                        <select name="invoice_type" x-model="editInvData.invoice_type" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="sales">↗ Satış Faturası (Gelir)</option>
                            <option value="purchase">↘ Alış Faturası (Gider)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Fatura No *</label>
                        <input type="text" name="invoice_number" required x-model="editInvData.invoice_number" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Matrah *</label>
                        <input type="number" step="0.01" name="subtotal" required x-model="editInvData.subtotal" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">KDV Oranı</label>
                        <select name="vat_rate" x-model="editInvData.vat_rate" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (VAT_RATES as $vr): ?>
                                <option value="<?= $vr ?>">%<?= $vr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Tarih</label>
                    <input type="date" name="issue_date" required x-model="editInvData.issue_date" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <textarea name="notes" rows="2" x-model="editInvData.notes" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditInvModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 5: YENİ MANUEL DEKONT -->
    <div x-show="openManualAdjModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openManualAdjModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Faturasız Cari Borç / Alacak Dekontu</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_manual_adjustment">

                <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200">
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-2">İşlem Türü *</label>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <label class="flex items-center gap-2 p-2.5 bg-white rounded-xl border border-rose-200 cursor-pointer">
                            <input type="radio" name="adj_type" value="debit" checked class="text-rose-600">
                            <span class="font-bold text-rose-700 text-[11px]">BORÇLANDIR (+)</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 bg-white rounded-xl border border-emerald-200 cursor-pointer">
                            <input type="radio" name="adj_type" value="credit" class="text-emerald-600">
                            <span class="font-bold text-emerald-700 text-[11px]">ALACAKLANDIR (-)</span>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tutar *</label>
                        <input type="number" step="0.01" name="amount" required placeholder="0.00" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tarih</label>
                        <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Kategori</label>
                        <select name="category" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="Devir / Açılış Bakiyesi">Devir / Açılış Bakiyesi</option>
                            <option value="Faturasız Avans / Kapora">Faturasız Avans / Kapora</option>
                            <option value="İskonto / Bakiye Düzeltme">İskonto / Bakiye Düzeltme</option>
                            <option value="Barter / Takas">Barter / Takas</option>
                            <option value="Diğer">Diğer</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Kasa/Banka Etkisi</label>
                        <select name="account_id" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            <option value="">-- Kasa Dışı (Dekont) --</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= e($a['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <input type="text" name="description" placeholder="Örn: Devir bakiyesi veya indirim" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openManualAdjModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs shadow-md transition">
                        ✓ Dekontu Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 6: PORTAL GİRİŞİ -->
    <div x-show="openPortalModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openPortalModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Müşteri Portalı Giriş Yetkisi</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_client_user">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Yetkili Adı</label>
                    <input type="text" name="full_name" value="<?= e($client_user['full_name'] ?? $contact['authorized_person'] ?: $contact['company_title']) ?>" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Giriş E-Postası *</label>
                    <input type="email" name="email" value="<?= e($client_user['email'] ?? $contact['email']) ?>" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Portal Şifresi *</label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" @click="openPortalModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>