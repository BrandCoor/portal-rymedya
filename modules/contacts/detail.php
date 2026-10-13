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

// Giriş ve yetki kontrolü (POST işlemlerinden ÖNCE çalışmalıdır)
require_staff_login();
require_permission('contacts.view');

// Cariyi en başta yükle (POST işlemleri de cari bilgisine ihtiyaç duyar)
$stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$stmt->execute([$contact_id]);
$contact = $stmt->fetch();

if (!$contact) {
    set_flash('error', 'Aradığınız cari kart bulunamadı.');
    redirect(BASE_URL . '/modules/contacts/index.php');
}

// 1. TÜM FORM VE MUHASEBE İŞLEMLERİ (POST HANDLER)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // A. CARİ KÜNYE BİLGİLERİNİ DÜZENLEME
    if ($action === 'edit_contact_info') {
        require_permission('contacts.edit');
        $company_title = trim($_POST['company_title'] ?? '');
        $type = array_key_exists($_POST['type'] ?? '', CONTACT_TYPES) ? $_POST['type'] : $contact['type'];

        if ($company_title === '') {
            set_flash('error', 'Firma / kişi ünvanı boş bırakılamaz.');
            redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}");
        }

        $fields = ['authorized_person', 'phone', 'email', 'tax_office', 'tax_number', 'id_number', 'iban', 'city', 'district', 'address'];
        $values = [];
        foreach ($fields as $f) {
            $values[$f] = trim($_POST[$f] ?? '');
        }
        $values['city'] = normalize_city($values['city']);

        $up = $db->prepare("UPDATE contacts SET type = ?, company_title = ?, authorized_person = ?, phone = ?, email = ?, tax_office = ?, tax_number = ?, id_number = ?, iban = ?, city = ?, district = ?, address = ? WHERE id = ?");
        $up->execute([$type, $company_title, $values['authorized_person'], $values['phone'], $values['email'], $values['tax_office'], $values['tax_number'], $values['id_number'], $values['iban'], $values['city'], $values['district'], $values['address'], $contact_id]);
        set_flash('success', 'Cari bilgileri güncellendi.');
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}");
    }

    // B. FATURAYA İSTİNADEN TAHSİLAT ALMA
    if ($action === 'pay_invoice_from_contact') {
        require_permission('finance.view');
        $invoice_id = (int)$_POST['invoice_id'];
        $account_id = (int)$_POST['account_id'];
        $pay_amount = parse_money($_POST['pay_amount'] ?? '0');
        $pay_date   = valid_date($_POST['pay_date'] ?? '', date('Y-m-d'));

        $own = $db->prepare("SELECT id FROM invoices WHERE id = ? AND contact_id = ?");
        $own->execute([$invoice_id, $contact_id]);

        $err = $own->fetch()
            ? record_invoice_payment($invoice_id, $account_id, $pay_amount, $pay_date, (int)$user['id'])
            : 'Fatura bu cariye ait değil.';

        $err ? set_flash('error', $err) : set_flash('success', 'Tahsilat/ödeme kaydedildi; kasa, cari ve fatura durumu eşitlendi.');
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=invoices");
    }

    // C. MANUEL BORÇ / ALACAK DEKONTU EKLEME
    if ($action === 'add_manual_adjustment') {
        require_permission('contacts.edit');
        $type        = ($_POST['adj_type'] ?? '') === 'credit' ? 'credit' : 'debit';
        $amount      = parse_money($_POST['amount'] ?? '0');
        $date        = valid_date($_POST['transaction_date'] ?? '', date('Y-m-d'));
        $category    = trim($_POST['category'] ?? '') ?: 'Manuel Cari Dekontu';
        $description = trim($_POST['description'] ?? '');
        $account_id  = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

        if ($amount > 0) {
            $tx_type = ($type === 'credit') ? 'income' : 'expense';

            $db->prepare("INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at) VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$account_id, $contact_id, $tx_type, $category, $amount, $date, ($description ?: ($type === 'debit' ? 'Manuel Borç Dekontu' : 'Manuel Alacak Dekontu')), $user['id']]);

            if ($account_id) {
                recalculate_account_balance($account_id);
            }
            recalculate_contact_balance($contact_id);
            set_flash('success', "Manuel dekont işlendi ve bakiye eşitlendi.");
        } else {
            set_flash('error', 'Lütfen geçerli bir tutar giriniz.');
        }
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=ledger");
    }

    // D. MANUEL DEKONTU DÜZENLEME
    if ($action === 'edit_manual_transaction') {
        require_permission('contacts.edit');
        $tx_id       = (int)$_POST['transaction_id'];
        $type        = ($_POST['adj_type'] ?? '') === 'credit' ? 'credit' : 'debit';
        $amount      = parse_money($_POST['amount'] ?? '0');
        $date        = valid_date($_POST['transaction_date'] ?? '', date('Y-m-d'));
        $category    = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $tx_stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND contact_id = ?");
        $tx_stmt->execute([$tx_id, $contact_id]);
        $old_tx = $tx_stmt->fetch();

        if ($old_tx && $amount > 0) {
            $new_tx_type = ($type === 'credit') ? 'income' : 'expense';

            $db->prepare("UPDATE transactions SET type = ?, category = ?, amount = ?, transaction_date = ?, description = ? WHERE id = ?")
               ->execute([$new_tx_type, $category, $amount, $date, $description, $tx_id]);

            if (!empty($old_tx['account_id'])) {
                recalculate_account_balance((int)$old_tx['account_id']);
            }
            if (!empty($old_tx['invoice_id'])) {
                sync_invoice_payment((int)$old_tx['invoice_id']);
            }
            recalculate_contact_balance($contact_id);
            set_flash('success', 'Dekont güncellendi.');
        }
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=ledger");
    }

    // E. MANUEL DEKONTU SİLME
    if ($action === 'delete_manual_transaction') {
        require_permission('contacts.edit');
        $tx_id = (int)$_POST['transaction_id'];
        $tx_stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND contact_id = ?");
        $tx_stmt->execute([$tx_id, $contact_id]);
        $tx = $tx_stmt->fetch();

        if ($tx) {
            $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$tx_id]);
            if (!empty($tx['account_id'])) {
                recalculate_account_balance((int)$tx['account_id']);
            }
            if (!empty($tx['invoice_id'])) {
                sync_invoice_payment((int)$tx['invoice_id']);
            }
            recalculate_contact_balance($contact_id);
            set_flash('success', 'Dekont silindi.');
        }
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=ledger");
    }

    // F. RESMİ FATURAYI DÜZENLEME
    if ($action === 'edit_invoice_from_contact') {
        require_permission('finance.invoices');
        $invoice_id       = (int)$_POST['invoice_id'];
        $invoice_type     = ($_POST['invoice_type'] ?? 'sales') === 'purchase' ? 'purchase' : 'sales';
        $invoice_number   = trim($_POST['invoice_number'] ?? '');
        $subtotal         = parse_money($_POST['subtotal'] ?? '0');
        $vat_rate         = (float)($_POST['vat_rate'] ?? 20);
        $issue_date       = valid_date($_POST['issue_date'] ?? '', date('Y-m-d'));
        $notes            = trim($_POST['notes'] ?? '');

        $inv_stmt = $db->prepare("SELECT * FROM invoices WHERE id = ? AND contact_id = ?");
        $inv_stmt->execute([$invoice_id, $contact_id]);
        $old_inv = $inv_stmt->fetch();

        if (!$old_inv) {
            set_flash('error', 'Fatura bu cariye ait değil.');
        } elseif ($invoice_number === '' || $subtotal <= 0) {
            set_flash('error', 'Fatura numarası ve tutar zorunludur.');
        } elseif (invoice_number_exists($invoice_number, $invoice_id, $invoice_type)) {
            set_flash('error', "{$invoice_number} numaralı başka bir fatura zaten mevcut.");
        } else {
            // Mevcut tevkifat ve stopaj oranları korunarak yeniden hesaplanır
            $tax = calculate_tax_breakdown($subtotal, $vat_rate, $old_inv['withholding_rate'] ?: '0/10', (float)$old_inv['stoppage_rate']);
            $db->prepare("UPDATE invoices SET invoice_type = ?, invoice_number = ?, subtotal = ?, vat_rate = ?, vat_amount = ?, withholding_amount = ?, stoppage_amount = ?, grand_total = ?, issue_date = ?, notes = ? WHERE id = ?")
               ->execute([$invoice_type, $invoice_number, $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['withholding_amount'], $tax['stoppage_amount'], $tax['grand_total'], $issue_date, $notes, $invoice_id]);

            sync_invoice_payment($invoice_id);
            recalculate_contact_balance($contact_id);
            set_flash('success', "Fatura güncellendi.");
        }
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=invoices");
    }

    // G. RESMİ FATURAYI SİLME (Tahsilatlar dahil zincirleme)
    if ($action === 'delete_invoice_from_contact') {
        require_permission('finance.invoices');
        $inv_id = (int)$_POST['invoice_id'];
        $own = $db->prepare("SELECT id FROM invoices WHERE id = ? AND contact_id = ?");
        $own->execute([$inv_id, $contact_id]);
        if ($own->fetch()) {
            delete_invoice_cascade($inv_id);
            set_flash('success', 'Fatura ve bağlı tahsilatları silindi, kasa ve cari bakiyeleri eşitlendi.');
        }
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}&tab=invoices");
    }

    // H. CARİYİ KALICI SİLME
    if ($action === 'delete_contact_permanent') {
        require_permission('contacts.delete');
        delete_contact_cascade($contact_id);
        set_flash('success', "Cari kartı ve bağlı tüm kayıtları silindi, kasa bakiyeleri eşitlendi.");
        redirect(BASE_URL . '/modules/contacts/index.php');
    }

    // I. MÜŞTERİ PORTALI GİRİŞİ
    if ($action === 'create_client_user') {
        require_permission('contacts.edit');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $name     = trim($_POST['full_name'] ?? '') ?: $contact['company_title'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Lütfen geçerli bir e-posta adresi giriniz.');
        } elseif (strlen($password) < 8) {
            set_flash('error', 'Portal şifresi en az 8 karakter olmalıdır.');
        } else {
            $client_role_id = get_client_role_id();
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $chk = $db->prepare("SELECT u.id, u.role_id, u.contact_id, r.role_slug FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.email = ?");
            $chk->execute([$email]);
            $existing = $chk->fetch();

            if ($existing && ((int)$existing['contact_id'] !== $contact_id || (int)$existing['role_id'] === 1)) {
                // Personel veya başka bir cariye ait hesap asla müşteri hesabına dönüştürülmez
                set_flash('error', 'Bu e-posta adresi başka bir kullanıcı (personel veya farklı cari) tarafından kullanılıyor.');
            } else {
                if ($existing) {
                    $db->prepare("UPDATE users SET password = ?, role_id = ?, contact_id = ?, status = 'active', full_name = ? WHERE id = ?")->execute([$hashed, $client_role_id, $contact_id, $name, $existing['id']]);
                } else {
                    // Cariye bağlı eski portal hesabı varsa onu güncelle (tek hesap)
                    $old = $db->prepare("SELECT id FROM users WHERE contact_id = ? AND role_id != 1 LIMIT 1");
                    $old->execute([$contact_id]);
                    $old_id = $old->fetchColumn();
                    if ($old_id) {
                        $db->prepare("UPDATE users SET email = ?, password = ?, role_id = ?, status = 'active', full_name = ? WHERE id = ?")->execute([$email, $hashed, $client_role_id, $name, $old_id]);
                    } else {
                        $db->prepare("INSERT INTO users (role_id, contact_id, full_name, email, password, phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())")->execute([$client_role_id, $contact_id, $name, $email, $hashed, $contact['phone']]);
                    }
                }
                set_flash('success', "Müşteri portalı girişi güncellendi!");
            }
        }
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}");
    }

    // J. MÜŞTERİ PORTALI ERİŞİMİNİ KAPATMA / AÇMA
    if ($action === 'toggle_client_user') {
        require_permission('contacts.edit');
        $db->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE contact_id = ? AND role_id != 1")->execute([$contact_id]);
        set_flash('success', 'Müşteri portalı erişim durumu değiştirildi.');
        redirect(BASE_URL . "/modules/contacts/detail.php?id={$contact_id}");
    }
}

// CARİ BAKİYESİNİ HER SAYFA AÇILIŞINDA OTOMATİK EŞİTLE
$accurate_balance = recalculate_contact_balance($contact_id);

// Güncel cari bilgisini yeniden oku
$stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$stmt->execute([$contact_id]);
$contact = $stmt->fetch();

$client_user_stmt = $db->prepare("SELECT * FROM users WHERE contact_id = ? LIMIT 1");
$client_user_stmt->execute([$contact_id]);
$client_user = $client_user_stmt->fetch();

$projects = $db->query("SELECT * FROM projects WHERE client_id = {$contact_id} ORDER BY id DESC")->fetchAll();
$invoices = $db->query("SELECT * FROM invoices WHERE contact_id = {$contact_id} ORDER BY issue_date DESC")->fetchAll();
$transactions = $db->query("SELECT t.*, a.account_name FROM transactions t LEFT JOIN accounts a ON t.account_id = a.id WHERE t.contact_id = {$contact_id} ORDER BY t.transaction_date DESC, t.id DESC")->fetchAll();
ensure_contact_change_logs_table();
$change_logs = $db->query("SELECT * FROM contact_change_logs WHERE contact_id = {$contact_id} ORDER BY id DESC LIMIT 50")->fetchAll();
$accounts = $db->query("SELECT id, account_name, currency, balance FROM accounts WHERE status = 'active'")->fetchAll();

// Sekmeler (eski bağlantılar da çalışsın: ledger → payments, logs → info)
$tab_map = ['ledger' => 'payments', 'logs' => 'info'];
$active_tab = $tab_map[$_GET['tab'] ?? ''] ?? ($_GET['tab'] ?? 'summary');
if (!in_array($active_tab, ['summary', 'statement', 'invoices', 'payments', 'projects', 'info'], true)) $active_tab = 'summary';

// ---------------- Özet rakamlar ----------------
$supplier = contact_is_supplier($contact);
$ledger = contact_ledger($contact_id);
$sum = ['sales' => 0.0, 'purchase' => 0.0, 'in' => 0.0, 'out' => 0.0, 'open' => 0.0, 'open_n' => 0, 'overdue' => 0.0, 'overdue_n' => 0];
foreach ($ledger as $l) {
    if ($l['kind'] === 'invoice') {
        $l['debit'] > 0 ? $sum['sales'] += $l['debit'] : $sum['purchase'] += $l['credit'];
        if ($l['status'] !== 'paid') { $sum['open'] += $l['remaining']; $sum['open_n']++; }
        if ($l['overdue']) { $sum['overdue'] += $l['remaining']; $sum['overdue_n']++; }
    } elseif ($l['kind'] === 'payment') {
        $sum['in'] += $l['credit'];
        $sum['out'] += $l['debit'];
    }
}
$last_move = $ledger ? end($ledger)['date'] : null;
$upcoming = array_values(array_filter($invoices, fn($i) => $i['payment_status'] !== 'paid'));
usort($upcoming, fn($a, $b) => [$a['due_date'] ?: '9999-12-31', $a['issue_date']] <=> [$b['due_date'] ?: '9999-12-31', $b['issue_date']]);
$recent = array_slice(array_reverse($ledger), 0, 6);
$manual_tx = array_values(array_filter($transactions, fn($t) => empty($t['invoice_id'])));
$invoice_tx = array_values(array_filter($transactions, fn($t) => !empty($t['invoice_id'])));
$inv_no = array_column($invoices, 'invoice_number', 'id');
$pay_word = fn(array $inv) => $inv['invoice_type'] === 'sales' ? 'Tahsilat al' : 'Ödeme yap';
$status_badge = function (array $inv): string {
    $over = $inv['payment_status'] !== 'paid' && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d');
    [$cls, $txt] = match (true) {
        $inv['payment_status'] === 'paid'    => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'Ödendi'],
        $over                                => ['bg-rose-50 text-rose-700 border-rose-200', 'Vadesi geçti'],
        $inv['payment_status'] === 'partial' => ['bg-amber-50 text-amber-700 border-amber-200', 'Kısmi ödendi'],
        default                              => ['bg-slate-50 text-slate-600 border-slate-200', 'Açık'],
    };
    return '<span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold border ' . $cls . '">' . $txt . '</span>';
};
$ledger_js = array_map(fn($l) => [
    'k' => $l['kind'], 'id' => $l['id'], 'd' => $l['date'], 'df' => format_date($l['date']), 'doc' => $l['doc'], 't' => $l['type'],
    'x' => mb_strimwidth((string)$l['desc'], 0, 160, '…'), 'acc' => $l['account'] ?? '',
    'dr' => $l['debit'], 'cr' => $l['credit'], 'b' => $l['balance'], 'st' => $l['status'], 'od' => $l['overdue'], 'rem' => $l['remaining'],
], $ledger);
$bal_tone = abs($accurate_balance) < 0.005 ? 'text-slate-900' : ($accurate_balance > 0 ? 'text-emerald-700' : 'text-rose-600');

$page_title = e($contact['company_title']) . ' | Cari Masası';
require_once __DIR__ . '/../../includes/header.php';
?>
<script>
window.CARI_LEDGER = <?= json_encode($ledger_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
function cariLedger() {
    const pad = n => String(n).padStart(2, '0');
    const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const money = v => new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v) + ' ₺';
    return {
        all: window.CARI_LEDGER || [],
        period: 'all', from: '', to: '', kinds: ['invoice', 'payment', 'adjustment'], status: '', q: '',
        money,
        ba(v) { return Math.abs(v) < 0.005 ? '' : (v > 0 ? ' (B)' : ' (A)'); },
        range() {
            const t = new Date(); let f = null, l = null;
            if (this.period === 'week') { const day = (t.getDay() + 6) % 7; f = new Date(t); f.setDate(t.getDate() - day); l = new Date(f); l.setDate(f.getDate() + 6); }
            else if (this.period === 'month') { f = new Date(t.getFullYear(), t.getMonth(), 1); l = new Date(t.getFullYear(), t.getMonth() + 1, 0); }
            else if (this.period === 'lastmonth') { f = new Date(t.getFullYear(), t.getMonth() - 1, 1); l = new Date(t.getFullYear(), t.getMonth(), 0); }
            else if (this.period === 'year') { f = new Date(t.getFullYear(), 0, 1); l = new Date(t.getFullYear(), 11, 31); }
            else if (this.period === 'custom') { return [this.from || null, this.to || null]; }
            return [f ? iso(f) : null, l ? iso(l) : null];
        },
        get view() {
            const [f, l] = this.range(), q = this.q.trim().toLocaleLowerCase('tr');
            let opening = 0, closing = null; const rows = [];
            for (const r of this.all) {
                if (f && r.d < f) { opening = r.b; continue; }
                if (l && r.d > l) continue;
                closing = r.b;
                if (!this.kinds.includes(r.k)) continue;
                if (this.status === 'open' && !(r.k === 'invoice' && r.st !== 'paid')) continue;
                if (this.status === 'overdue' && !r.od) continue;
                if (q && !(r.doc + ' ' + r.t + ' ' + r.x).toLocaleLowerCase('tr').includes(q)) continue;
                rows.push(r);
            }
            const dr = rows.reduce((s, r) => s + r.dr, 0), cr = rows.reduce((s, r) => s + r.cr, 0);
            return { f, l, opening, rows, dr, cr, closing: closing === null ? opening : closing };
        },
        get filtered() { return this.kinds.length < 3 || this.status !== '' || this.q.trim() !== ''; },
        reset() { this.kinds = ['invoice', 'payment', 'adjustment']; this.status = ''; this.q = ''; },
        link(fmt) {
            const p = new URLSearchParams({ id: '<?= (int)$contact_id ?>' }), t = new Date();
            if (this.period === 'custom') { p.set('p', 'custom'); if (this.from) p.set('from', this.from); if (this.to) p.set('to', this.to); }
            else if (this.period === 'lastmonth') { p.set('p', 'month'); p.set('d', iso(new Date(t.getFullYear(), t.getMonth() - 1, 1))); }
            else { p.set('p', this.period); if (this.period !== 'all') p.set('d', iso(t)); }
            if (this.kinds.length < 3) this.kinds.forEach(k => p.append('k[]', k));
            if (this.status) p.set('st', this.status);
            if (this.q.trim()) p.set('q', this.q.trim());
            if (fmt) p.set('format', fmt);
            return '<?= BASE_URL ?>/modules/contacts/statement_print.php?' + p.toString();
        },
    };
}
</script>

<div x-data="{ 
    openPortalModal: false, 
    openManualAdjModal: false, 
    openEditContactModal: false,
    openEditTxModal: false,
    editTxData: {},
    openEditInvModal: false,
    editInvData: {},
    openPayInvModal: false,
    payInvData: { id: '', invoice_number: '', remaining: '', word: '' },
    tab: '<?= e($active_tab) ?>' 
}">
    <!-- Üst Başlık & Butonlar -->
    <div class="mb-6 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="min-w-0">
            <a href="<?= BASE_URL ?>/modules/contacts/index.php" class="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-slate-800 mb-2">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Cariler
            </a>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-black text-slate-900 tracking-tight break-words"><?= e($contact['company_title']) ?></h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $supplier ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-brand-50 text-brand-700 border-brand-200' ?> border">
                    <?= CONTACT_TYPES[$contact['type']] ?? $contact['type'] ?>
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-1">
                Cari kodu <span class="font-mono">C-<?= str_pad((string)$contact_id, 5, '0', STR_PAD_LEFT) ?></span>
                <?php if (!empty($contact['authorized_person'])): ?> · <?= e($contact['authorized_person']) ?><?php endif; ?>
                <?php if (!empty($contact['phone'])): ?> · <?= e($contact['phone']) ?><?php endif; ?>
                <?php if (!empty($contact['city'])): ?> · <?= e($contact['city']) ?><?php endif; ?>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <?php if (has_permission('contacts.edit')): ?>
            <button @click="openManualAdjModal = true" class="inline-flex items-center gap-2 bg-white hover:bg-slate-50 text-slate-800 border border-slate-200 text-xs font-bold py-2.5 px-4 rounded-xl transition">
                <i data-lucide="plus-circle" class="w-4 h-4 text-emerald-600"></i>
                <span>Dekont ekle</span>
            </button>
            <?php endif; ?>
            <button @click="openPortalModal = true" class="inline-flex items-center gap-2 bg-white hover:bg-slate-50 text-slate-800 border border-slate-200 text-xs font-bold py-2.5 px-4 rounded-xl transition">
                <i data-lucide="key-round" class="w-4 h-4 text-indigo-600"></i>
                <span><?= $client_user ? 'Portal girişi' : 'Portal aç' ?></span>
            </button>
            <button @click="tab = 'statement'" class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition">
                <i data-lucide="file-text" class="w-4 h-4"></i>
                <span>Ekstre</span>
            </button>
            <button @click="openEditContactModal = true" class="p-2.5 bg-white hover:bg-brand-50 text-slate-500 hover:text-brand-600 border border-slate-200 rounded-xl transition" title="Cari bilgilerini düzenle">
                <i data-lucide="edit-3" class="w-4 h-4"></i>
            </button>
            <?php if (has_permission('contacts.delete')): ?>
            <form method="POST" action="" onsubmit="return confirm('DİKKAT: Bu cariyi kalıcı olarak silmek istediğinize emin misiniz?');" class="inline-block">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_contact_permanent">
                <button type="submit" class="p-2.5 bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 rounded-xl transition" title="Cariyi kalıcı olarak sil">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Özet kartları -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        <div class="col-span-2 lg:col-span-1 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Net bakiye</p>
            <p class="text-2xl font-black <?= $bal_tone ?> mt-1"><?= format_money(abs($accurate_balance)) ?></p>
            <p class="text-[11px] font-semibold text-slate-500 mt-0.5"><?= e(contact_balance_text($accurate_balance, $supplier)) ?></p>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider"><?= $supplier ? 'Alış faturaları' : 'Satış faturaları' ?></p>
            <p class="text-lg font-black text-slate-900 mt-1"><?= format_money($supplier ? $sum['purchase'] : $sum['sales']) ?></p>
            <?php if (($supplier ? $sum['sales'] : $sum['purchase']) > 0): ?>
            <p class="text-[11px] text-slate-500 mt-0.5"><?= $supplier ? 'Satış' : 'Alış' ?>: <?= format_money($supplier ? $sum['sales'] : $sum['purchase']) ?></p>
            <?php endif; ?>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider"><?= $supplier ? 'Ödediğimiz' : 'Tahsil edilen' ?></p>
            <p class="text-lg font-black text-slate-900 mt-1"><?= format_money($supplier ? $sum['out'] : $sum['in']) ?></p>
            <?php if (($supplier ? $sum['in'] : $sum['out']) > 0): ?>
            <p class="text-[11px] text-slate-500 mt-0.5"><?= $supplier ? 'Tahsilat' : 'Ödeme' ?>: <?= format_money($supplier ? $sum['in'] : $sum['out']) ?></p>
            <?php endif; ?>
        </div>
        <button type="button" @click="tab = 'invoices'" class="text-left bg-white p-4 rounded-2xl border <?= $sum['overdue_n'] ? 'border-rose-200' : 'border-slate-200' ?> shadow-sm hover:border-slate-300 transition">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Açık faturalar</p>
            <p class="text-lg font-black text-slate-900 mt-1"><?= format_money($sum['open']) ?></p>
            <p class="text-[11px] mt-0.5 <?= $sum['overdue_n'] ? 'text-rose-600 font-bold' : 'text-slate-500' ?>">
                <?= $sum['open_n'] ?> fatura<?= $sum['overdue_n'] ? ' · ' . $sum['overdue_n'] . ' vadesi geçti (' . format_money($sum['overdue']) . ')' : '' ?>
            </p>
        </button>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Son hareket</p>
            <p class="text-lg font-black text-slate-900 mt-1"><?= $last_move ? format_date($last_move) : '—' ?></p>
            <p class="text-[11px] text-slate-500 mt-0.5"><?= count($ledger) ?> hareket kaydı</p>
        </div>
    </div>

    <!-- SEKMELER -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm mb-8">
        <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 sm:px-5">
            <?php foreach ([
                'summary'   => ['layout-dashboard', 'Özet', null],
                'statement' => ['file-text', 'Ekstre', count($ledger)],
                'invoices'  => ['receipt', 'Faturalar', count($invoices)],
                'payments'  => ['arrow-left-right', $supplier ? 'Ödemeler & dekontlar' : 'Tahsilatlar & dekontlar', count($transactions)],
                'projects'  => ['film', 'Projeler', count($projects)],
                'info'      => ['id-card', 'Bilgiler & geçmiş', null],
            ] as $tk => [$ic, $tl, $cnt]): ?>
            <button type="button" @click="tab = '<?= $tk ?>'" :class="tab === '<?= $tk ?>' ? 'border-brand-600 text-brand-700 font-bold' : 'border-transparent text-slate-500 hover:text-slate-800'" class="shrink-0 py-3.5 px-3 text-xs border-b-2 -mb-px transition flex items-center gap-1.5 whitespace-nowrap">
                <i data-lucide="<?= $ic ?>" class="w-4 h-4"></i>
                <span><?= e($tl) ?></span>
                <?php if ($cnt !== null): ?><span class="text-[10px] font-bold bg-slate-100 text-slate-500 rounded-full px-1.5 py-0.5"><?= $cnt ?></span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="p-4 sm:p-6">
        <!-- ÖZET -->
        <div x-show="tab === 'summary'">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-bold text-slate-800">Açık faturalar</h3>
                        <button type="button" @click="tab = 'invoices'" class="text-xs font-bold text-brand-600 hover:underline">Tümü</button>
                    </div>
                    <?php if (!$upcoming): ?>
                        <div class="rounded-2xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-400">Ödenmemiş fatura yok.</div>
                    <?php else: ?>
                    <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200">
                        <?php foreach (array_slice($upcoming, 0, 6) as $inv): $rem = (float)$inv['grand_total'] - (float)$inv['paid_amount']; ?>
                        <div class="flex items-center justify-between gap-3 p-3">
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-900 font-mono"><?= e($inv['invoice_number']) ?> <span class="font-sans font-semibold <?= $inv['invoice_type'] === 'sales' ? 'text-emerald-700' : 'text-amber-700' ?>">· <?= $inv['invoice_type'] === 'sales' ? 'Satış' : 'Alış' ?></span></p>
                                <p class="text-[11px] text-slate-500"><?= format_date($inv['issue_date']) ?><?= $inv['due_date'] ? ' · vade ' . format_date($inv['due_date']) : '' ?></p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <?= $status_badge($inv) ?>
                                <span class="text-xs font-black text-slate-900"><?= format_money($rem) ?></span>
                                <button type="button" @click="payInvData = { id: '<?= $inv['id'] ?>', invoice_number: <?= js_val($inv['invoice_number']) ?>, remaining: '<?= $rem ?>', word: '<?= $pay_word($inv) ?>' }; openPayInvModal = true" class="px-2 py-1 bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 font-bold rounded-lg border border-emerald-200 transition text-[11px]"><?= $pay_word($inv) ?></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-bold text-slate-800">Son hareketler</h3>
                        <button type="button" @click="tab = 'statement'" class="text-xs font-bold text-brand-600 hover:underline">Ekstreye git</button>
                    </div>
                    <?php if (!$recent): ?>
                        <div class="rounded-2xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-400">Henüz hareket yok.</div>
                    <?php else: ?>
                    <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200">
                        <?php foreach ($recent as $r): ?>
                        <div class="flex items-center justify-between gap-3 p-3">
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-900"><?= e($r['type']) ?> <span class="font-mono font-normal text-slate-400"><?= e($r['doc']) ?></span></p>
                                <p class="text-[11px] text-slate-500 truncate"><?= format_date($r['date']) ?> · <?= e($r['desc']) ?></p>
                            </div>
                            <span class="text-xs font-black shrink-0 <?= $r['debit'] > 0 ? 'text-slate-900' : 'text-emerald-700' ?>"><?= $r['debit'] > 0 ? 'B ' . format_money($r['debit']) : 'A ' . format_money($r['credit']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- EKSTRE (sayfa içinde filtrelenebilir) -->
        <div x-show="tab === 'statement'" x-cloak x-data="cariLedger()">
            <div class="flex flex-col gap-3 mb-4">
                <div class="flex flex-wrap items-center gap-1.5">
                    <?php foreach (['week' => 'Bu hafta', 'month' => 'Bu ay', 'lastmonth' => 'Geçen ay', 'year' => 'Bu yıl', 'all' => 'Tümü', 'custom' => 'Tarih aralığı'] as $pk => $pl): ?>
                    <button type="button" @click="period = '<?= $pk ?>'" :class="period === '<?= $pk ?>' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-300'" class="px-3 py-1.5 rounded-lg border text-xs font-bold transition"><?= $pl ?></button>
                    <?php endforeach; ?>
                    <template x-if="period === 'custom'">
                        <div class="flex items-center gap-1.5">
                            <input type="date" x-model="from" class="py-1.5 px-2 bg-slate-50 border border-slate-200 rounded-lg text-xs" aria-label="Başlangıç">
                            <span class="text-slate-400 text-xs">–</span>
                            <input type="date" x-model="to" class="py-1.5 px-2 bg-slate-50 border border-slate-200 rounded-lg text-xs" aria-label="Bitiş">
                        </div>
                    </template>
                </div>
                <div class="flex flex-col lg:flex-row lg:items-center gap-2 lg:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <?php foreach (LEDGER_KINDS as $kk => $kl): ?>
                        <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-700 cursor-pointer">
                            <input type="checkbox" value="<?= $kk ?>" x-model="kinds" class="rounded text-brand-600"> <?= e($kl) ?>
                        </label>
                        <?php endforeach; ?>
                        <select x-model="status" class="py-1.5 px-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold" aria-label="Durum">
                            <option value="">Tüm durumlar</option>
                            <option value="open">Ödenmemiş faturalar</option>
                            <option value="overdue">Vadesi geçmiş</option>
                        </select>
                        <div class="relative">
                            <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
                            <input type="search" x-model.debounce.200ms="q" placeholder="Belge no / açıklama" class="py-1.5 pl-8 pr-2 w-48 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                        </div>
                        <button type="button" x-show="filtered" @click="reset()" class="text-xs font-bold text-slate-500 hover:text-slate-800 underline">Filtreyi temizle</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <a :href="link('')" target="_blank" class="inline-flex items-center gap-1.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2 px-3 rounded-lg transition"><i data-lucide="printer" class="w-3.5 h-3.5"></i> Yazdır / PDF</a>
                        <a :href="link('csv')" class="inline-flex items-center gap-1.5 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-bold py-2 px-3 rounded-lg transition"><i data-lucide="sheet" class="w-3.5 h-3.5"></i> Excel</a>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3"><p class="text-[10px] font-bold text-slate-400 uppercase">Devreden</p><p class="text-sm font-black text-slate-900" x-text="money(Math.abs(view.opening)) + ba(view.opening)"></p></div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3"><p class="text-[10px] font-bold text-slate-400 uppercase">Borç toplamı</p><p class="text-sm font-black text-slate-900" x-text="money(view.dr)"></p></div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3"><p class="text-[10px] font-bold text-slate-400 uppercase">Alacak toplamı</p><p class="text-sm font-black text-emerald-700" x-text="money(view.cr)"></p></div>
                <div class="rounded-xl bg-slate-900 p-3"><p class="text-[10px] font-bold text-slate-400 uppercase">Dönem sonu bakiye</p><p class="text-sm font-black text-white" x-text="money(Math.abs(view.closing)) + ba(view.closing)"></p></div>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="w-full text-left border-collapse text-xs min-w-[720px]">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-2.5 px-3">Tarih</th>
                            <th class="py-2.5 px-3">Belge</th>
                            <th class="py-2.5 px-3">İşlem / açıklama</th>
                            <th class="py-2.5 px-3 text-right">Borç</th>
                            <th class="py-2.5 px-3 text-right">Alacak</th>
                            <th class="py-2.5 px-3 text-right">Bakiye</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr x-show="view.f" class="bg-slate-50/60">
                            <td class="py-2.5 px-3 text-slate-500" x-text="view.f ? view.f.split('-').reverse().join('.') : ''"></td>
                            <td class="py-2.5 px-3 text-slate-400">—</td>
                            <td class="py-2.5 px-3 font-bold text-slate-700">Devreden bakiye</td>
                            <td class="py-2.5 px-3"></td><td class="py-2.5 px-3"></td>
                            <td class="py-2.5 px-3 text-right font-bold text-slate-900" x-text="money(Math.abs(view.opening)) + ba(view.opening)"></td>
                        </tr>
                        <template x-for="r in view.rows" :key="r.k + r.id">
                            <tr class="hover:bg-slate-50">
                                <td class="py-2.5 px-3 text-slate-600 whitespace-nowrap" x-text="r.df"></td>
                                <td class="py-2.5 px-3 font-mono text-[11px] text-slate-500 whitespace-nowrap" x-text="r.doc"></td>
                                <td class="py-2.5 px-3">
                                    <span class="font-bold text-slate-900" x-text="r.t"></span>
                                    <template x-if="r.k === 'invoice' && r.st !== 'paid'">
                                        <span class="ml-1 inline-flex px-1.5 py-0.5 rounded-full text-[10px] font-bold border" :class="r.od ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200'" x-text="(r.od ? 'Vadesi geçti' : 'Açık') + ' · kalan ' + money(r.rem)"></span>
                                    </template>
                                    <div class="text-[11px] text-slate-500" x-text="r.x + (r.acc ? ' · ' + r.acc : '')"></div>
                                </td>
                                <td class="py-2.5 px-3 text-right font-semibold text-slate-900 whitespace-nowrap" x-text="r.dr > 0 ? money(r.dr) : '—'"></td>
                                <td class="py-2.5 px-3 text-right font-semibold text-emerald-700 whitespace-nowrap" x-text="r.cr > 0 ? money(r.cr) : '—'"></td>
                                <td class="py-2.5 px-3 text-right font-black text-slate-900 whitespace-nowrap" x-text="money(Math.abs(r.b)) + ba(r.b)"></td>
                            </tr>
                        </template>
                        <tr x-show="view.rows.length === 0"><td colspan="6" class="py-8 text-center text-slate-400">Seçilen dönem ve filtrelerde hareket yok.</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-[11px] text-slate-400 mt-2">
                <b>B</b> borç bakiyesi: cari bize borçlu · <b>A</b> alacak bakiyesi: biz cariye borçluyuz.
                <span x-show="filtered">Bakiye sütunu filtreden bağımsız olarak o hareketten sonraki gerçek bakiyeyi gösterir.</span>
            </p>
        </div>

        <!-- FATURALAR -->
        <div x-show="tab === 'invoices'" x-cloak>
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="w-full text-left border-collapse text-xs min-w-[760px]">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-2.5 px-3">Fatura</th>
                            <th class="py-2.5 px-3">Tarih / vade</th>
                            <th class="py-2.5 px-3 text-right">Tutar</th>
                            <th class="py-2.5 px-3 text-right">Ödenen</th>
                            <th class="py-2.5 px-3 text-right">Kalan</th>
                            <th class="py-2.5 px-3 text-center">Durum</th>
                            <th class="py-2.5 px-3 text-right">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($invoices)): ?>
                            <tr><td colspan="7" class="py-8 text-center text-slate-400">Henüz fatura kaydı bulunmuyor.</td></tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $inv): 
                                $rem = (float)$inv['grand_total'] - (float)$inv['paid_amount'];
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-2.5 px-3">
                                    <span class="font-mono font-bold text-slate-900 block"><?= e($inv['invoice_number']) ?></span>
                                    <span class="text-[10px] font-bold <?= $inv['invoice_type'] === 'sales' ? 'text-emerald-700' : 'text-amber-700' ?>">
                                        <?= $inv['invoice_type'] === 'sales' ? '↗ Satış faturası' : '↘ Alış faturası' ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-slate-600"><?= format_date($inv['issue_date']) ?><?php if ($inv['due_date']): ?><span class="block text-[10px] text-slate-400">vade <?= format_date($inv['due_date']) ?></span><?php endif; ?></td>
                                <td class="py-2.5 px-3 text-right font-black text-slate-900"><?= format_money($inv['grand_total']) ?></td>
                                <td class="py-2.5 px-3 text-right text-slate-600"><?= format_money($inv['paid_amount']) ?></td>
                                <td class="py-2.5 px-3 text-right font-bold <?= $rem > 0.005 ? 'text-rose-600' : 'text-slate-400' ?>"><?= format_money(max(0, $rem)) ?></td>
                                <td class="py-2.5 px-3 text-center"><?= $status_badge($inv) ?></td>
                                <td class="py-2.5 px-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <?php if ($inv['payment_status'] !== 'paid'): ?>
                                        <button @click="payInvData = { id: '<?= $inv['id'] ?>', invoice_number: <?= js_val($inv['invoice_number']) ?>, remaining: '<?= $rem ?>', word: '<?= $pay_word($inv) ?>' }; openPayInvModal = true"
                                                class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 font-bold rounded-lg border border-emerald-200 transition text-[11px] flex items-center gap-1">
                                            <i data-lucide="hand-coins" class="w-3.5 h-3.5"></i>
                                            <span><?= $pay_word($inv) ?></span>
                                        </button>
                                        <?php endif; ?>

                                        <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= $inv['id'] ?>" target="_blank" class="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg" title="Yazdır / PDF">
                                            <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                                        </a>

                                        <button @click="editInvData = {
                                                    id: '<?= $inv['id'] ?>',
                                                    invoice_type: '<?= $inv['invoice_type'] ?>',
                                                    invoice_number: <?= js_val($inv['invoice_number']) ?>,
                                                    subtotal: '<?= (float)$inv['subtotal'] ?>',
                                                    vat_rate: '<?= (float)$inv['vat_rate'] ?>',
                                                    issue_date: '<?= $inv['issue_date'] ?>',
                                                    notes: <?= js_val($inv['notes'] ?? '') ?>
                                                }; openEditInvModal = true"
                                                class="p-1.5 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-500 rounded-lg transition" title="Düzenle">
                                            <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                        </button>

                                        <form method="POST" action="" onsubmit="return confirm('Bu faturayı ve bağlı tahsilat/ödemelerini silmek istiyor musunuz?');" class="inline-block">
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

        <!-- TAHSİLAT / ÖDEMELER & DEKONTLAR -->
        <div x-show="tab === 'payments'" x-cloak class="space-y-6">
            <div>
                <h3 class="text-sm font-bold text-slate-800 mb-1">Faturaya bağlı <?= $supplier ? 'ödemeler' : 'tahsilatlar' ?></h3>
                <p class="text-[11px] text-slate-500 mb-3">Fatura üzerinden alınan/yapılan ödemeler. Düzeltmek için ilgili faturayı düzenleyin veya silin.</p>
                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="w-full text-left border-collapse text-xs min-w-[600px]">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                                <th class="py-2.5 px-3">Tarih</th>
                                <th class="py-2.5 px-3">Fatura</th>
                                <th class="py-2.5 px-3">Kasa / banka</th>
                                <th class="py-2.5 px-3 text-right">Tutar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!$invoice_tx): ?>
                                <tr><td colspan="4" class="py-6 text-center text-slate-400">Faturaya bağlı hareket yok.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($invoice_tx as $tx): $is_inc = $tx['type'] === 'income'; ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-2.5 px-3 text-slate-600"><?= format_date($tx['transaction_date']) ?></td>
                                <td class="py-2.5 px-3 font-mono font-bold text-slate-900"><?= e($inv_no[$tx['invoice_id']] ?? ('#' . $tx['invoice_id'])) ?></td>
                                <td class="py-2.5 px-3 text-slate-500"><?= e($tx['account_name'] ?: '—') ?></td>
                                <td class="py-2.5 px-3 text-right font-black <?= $is_inc ? 'text-emerald-700' : 'text-slate-900' ?>"><?= $is_inc ? 'Tahsilat ' : 'Ödeme ' ?><?= format_money($tx['amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-sm font-bold text-slate-800">Faturasız dekontlar ve kasa hareketleri</h3>
                    <?php if (has_permission('contacts.edit')): ?><button @click="openManualAdjModal = true" class="text-xs font-bold text-emerald-600 hover:underline">+ Yeni dekont</button><?php endif; ?>
                </div>
                <p class="text-[11px] text-slate-500 mb-3">Devir bakiyesi, avans, iskonto gibi faturasız borç / alacak kayıtları.</p>
                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="w-full text-left border-collapse text-xs min-w-[680px]">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                                <th class="py-2.5 px-3">Tarih</th>
                                <th class="py-2.5 px-3">Kategori</th>
                                <th class="py-2.5 px-3">Kasa / banka</th>
                                <th class="py-2.5 px-3">Açıklama</th>
                                <th class="py-2.5 px-3 text-right">Tutar & etki</th>
                                <th class="py-2.5 px-3 text-right">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($manual_tx)): ?>
                                <tr><td colspan="6" class="py-6 text-center text-slate-400">Henüz faturasız hareket kaydı bulunmuyor.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($manual_tx as $tx): 
                                $is_inc = ($tx['type'] === 'income');
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-2.5 px-3 text-slate-600 font-medium"><?= format_date($tx['transaction_date']) ?></td>
                                <td class="py-2.5 px-3 font-bold text-slate-900"><?= e($tx['category']) ?></td>
                                <td class="py-2.5 px-3 text-slate-500"><?= e($tx['account_name'] ?: 'Kasa dışı (dekont)') ?></td>
                                <td class="py-2.5 px-3 text-slate-600"><?= e($tx['description']) ?></td>
                                <td class="py-2.5 px-3 text-right font-black <?= $is_inc ? 'text-emerald-700' : 'text-slate-900' ?>">
                                    <?= format_money($tx['amount']) ?>
                                    <span class="text-[10px] block font-semibold text-slate-400"><?= $is_inc ? 'Alacak (A) · bakiyeyi azaltır' : 'Borç (B) · bakiyeyi artırır' ?></span>
                                </td>
                                <td class="py-2.5 px-3 text-right">
                                    <?php if (has_permission('contacts.edit')): ?>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button @click="editTxData = {
                                                    id: '<?= $tx['id'] ?>',
                                                    adj_type: '<?= $is_inc ? 'credit' : 'debit' ?>',
                                                    amount: '<?= (float)$tx['amount'] ?>',
                                                    transaction_date: '<?= $tx['transaction_date'] ?>',
                                                    category: <?= js_val($tx['category']) ?>,
                                                    description: <?= js_val($tx['description'] ?? '') ?>
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
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PROJELER -->
        <div x-show="tab === 'projects'" x-cloak>
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="w-full text-left border-collapse text-xs min-w-[560px]">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                            <th class="py-2.5 px-3">Proje</th>
                            <th class="py-2.5 px-3">Tür</th>
                            <th class="py-2.5 px-3">Durum</th>
                            <th class="py-2.5 px-3 text-right">Bütçe</th>
                            <th class="py-2.5 px-3 text-right">İşlem</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!$projects): ?>
                            <tr><td colspan="5" class="py-8 text-center text-slate-400">Bu cariye bağlı proje yok.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($projects as $p): 
                            $st = PROJECT_STATUSES[$p['status']] ?? ['label' => $p['status'], 'color' => 'bg-slate-100 text-slate-700'];
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-2.5 px-3 font-bold text-slate-900"><?= e($p['project_name']) ?></td>
                            <td class="py-2.5 px-3 text-slate-600"><?= get_project_type_name($p['project_type']) ?></td>
                            <td class="py-2.5 px-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $st['color'] ?>"><?= $st['label'] ?></span></td>
                            <td class="py-2.5 px-3 text-right font-bold text-slate-900"><?= format_money($p['agreed_budget'], $p['currency']) ?></td>
                            <td class="py-2.5 px-3 text-right"><a href="<?= BASE_URL ?>/modules/projects/detail.php?id=<?= $p['id'] ?>" class="text-brand-600 font-bold hover:underline">Görüntüle →</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- BİLGİLER & GEÇMİŞ -->
        <div x-show="tab === 'info'" x-cloak class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="rounded-2xl border border-slate-200 p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">İletişim</h3>
                        <button @click="openEditContactModal = true" class="text-[11px] font-bold text-brand-600 hover:underline">Düzenle</button>
                    </div>
                    <p class="text-xs text-slate-700"><span class="text-slate-400">Yetkili:</span> <?= e($contact['authorized_person'] ?: '—') ?></p>
                    <p class="text-xs text-slate-700"><span class="text-slate-400">Telefon:</span> <?= e($contact['phone'] ?: '—') ?></p>
                    <p class="text-xs text-slate-700 break-all"><span class="text-slate-400">E-posta:</span> <?= e($contact['email'] ?: '—') ?></p>
                    <p class="text-xs text-slate-700"><span class="text-slate-400">Adres:</span> <?= e(trim(($contact['address'] ?? '') . ' ' . ($contact['district'] ?? '') . ' ' . ($contact['city'] ?? '')) ?: '—') ?></p>
                </div>
                <div class="rounded-2xl border border-slate-200 p-4 space-y-2">
                    <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Fatura & vergi</h3>
                    <p class="text-xs text-slate-700"><span class="text-slate-400">Vergi dairesi:</span> <?= e($contact['tax_office'] ?: '—') ?></p>
                    <p class="text-xs text-slate-700"><span class="text-slate-400">VKN:</span> <span class="font-mono"><?= e($contact['tax_number'] ?: '—') ?></span></p>
                    <p class="text-xs text-slate-700"><span class="text-slate-400">TCKN:</span> <span class="font-mono"><?= e($contact['id_number'] ?: '—') ?></span></p>
                    <p class="text-xs text-slate-700 break-all"><span class="text-slate-400">IBAN:</span> <span class="font-mono"><?= e($contact['iban'] ?: '—') ?></span></p>
                </div>
                <div class="rounded-2xl border border-slate-200 p-4 space-y-2">
                    <h3 class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Portal erişimi</h3>
                    <?php if ($client_user): ?>
                        <p class="text-xs font-bold <?= $client_user['status'] === 'active' ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $client_user['status'] === 'active' ? 'Aktif' : 'Kapalı' ?></p>
                        <p class="text-xs text-slate-700 break-all"><?= e($client_user['email']) ?></p>
                        <?php if (has_permission('contacts.edit')): ?>
                        <form method="POST" action="" onsubmit="return confirm('Portal erişim durumu değiştirilsin mi?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_client_user">
                            <button type="submit" class="text-[11px] font-bold underline text-slate-500 hover:text-slate-800"><?= $client_user['status'] === 'active' ? 'Erişimi kapat' : 'Erişimi aç' ?></button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-xs text-slate-400">Tanımlanmadı.</p>
                        <button @click="openPortalModal = true" class="text-[11px] font-bold text-brand-600 hover:underline">Portal aç</button>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-800 mb-3">Değişiklik geçmişi</h3>
                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="w-full text-left border-collapse text-xs min-w-[640px]">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                                <th class="py-2.5 px-3">Tarih</th>
                                <th class="py-2.5 px-3">Kullanıcı</th>
                                <th class="py-2.5 px-3">Alan</th>
                                <th class="py-2.5 px-3">Eski değer</th>
                                <th class="py-2.5 px-3">Yeni değer</th>
                                <th class="py-2.5 px-3 text-right">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!$change_logs): ?>
                                <tr><td colspan="6" class="py-6 text-center text-slate-400">Kayıtlı değişiklik yok.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($change_logs as $log): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-2.5 px-3 text-slate-600"><?= format_date($log['created_at'], true) ?></td>
                                <td class="py-2.5 px-3 font-bold text-slate-900"><?= e($log['user_name']) ?></td>
                                <td class="py-2.5 px-3 font-semibold text-indigo-700"><?= e($log['field_label']) ?></td>
                                <td class="py-2.5 px-3 text-rose-600 line-through"><?= e($log['old_value'] ?: '(Boş)') ?></td>
                                <td class="py-2.5 px-3 text-emerald-700 font-bold"><?= e($log['new_value']) ?></td>
                                <td class="py-2.5 px-3 text-right font-mono text-slate-400"><?= e($log['ip_address']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">İl</label>
                        <?= city_select('city', $contact['city'] ?? '', ['class' => 'w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs']) ?>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">İlçe</label>
                        <input type="text" name="district" value="<?= e($contact['district'] ?? '') ?>" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
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
            <h3 class="text-base font-bold text-slate-900 mb-1" x-text="payInvData.invoice_number + ' · ' + payInvData.word"></h3>
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
                        Onayla
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
                        Dekontu Kaydet
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
                    <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="En az 8 karakter" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                    <p class="text-[10px] text-slate-400 mt-1">Müşteri giriş adresi: <span class="font-mono"><?= e(BASE_URL) ?>/client/login.php</span></p>
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