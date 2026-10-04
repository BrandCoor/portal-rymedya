<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - PERSONEL, MAAŞ ÖDEME, BORDRO YAZDIR, DÜZENLE & SİL
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_permission('personnel.manage');

// Bordro / avans ile kasa hareketini birebir eşleyen açıklama etiketleri.
// (Önceki "LIKE '%Bordro #1%'" araması #10, #11... bordrolarını da yakalıyordu.)
function payroll_tx_tag(int $id): string { return "(Bordro #{$id})"; }
function advance_tx_tag(int $id): string { return "(Avans #{$id})"; }
function find_tagged_tx(string $tag): array {
    global $db;
    $st = $db->prepare("SELECT * FROM transactions WHERE description LIKE ? AND contact_id IS NULL AND invoice_id IS NULL");
    $st->execute(['%' . $tag]);
    return $st->fetchAll();
}

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ==========================================
    // A. MAAŞ ÖDEME & BORDRO OLUŞTURMA
    // ==========================================
    if ($action === 'pay_salary') {
        $personnel_id       = (int)$_POST['personnel_id'];
        $period_month       = (int)($_POST['period_month'] ?? date('m'));
        $period_year        = (int)($_POST['period_year'] ?? date('Y'));
        $base_salary        = parse_money($_POST['base_salary'] ?? '0');
        $advance_deductions = parse_money($_POST['advance_deductions'] ?? '0');
        $bonus              = parse_money($_POST['bonus'] ?? '0');
        $deductions         = parse_money($_POST['deductions'] ?? '0');
        $account_id         = (int)$_POST['account_id'];
        $payment_date       = valid_date($_POST['payment_date'] ?? '', date('Y-m-d'));

        $net_paid = ($base_salary + $bonus) - ($advance_deductions + $deductions);

        $dup = $db->prepare("SELECT COUNT(*) FROM payrolls WHERE personnel_id = ? AND period_month = ? AND period_year = ? AND status = 'paid'");
        $dup->execute([$personnel_id, $period_month, $period_year]);
        if ((int)$dup->fetchColumn() > 0) {
            set_flash('error', "Bu personele {$period_month}/{$period_year} dönemi için zaten maaş ödemesi yapılmış.");
            redirect(BASE_URL . '/modules/personnel/index.php');
        }

        if ($period_month < 1 || $period_month > 12) {
            set_flash('error', 'Geçersiz dönem.');
            redirect(BASE_URL . '/modules/personnel/index.php');
        }

        if ($net_paid <= 0 || $account_id <= 0) {
            set_flash('error', 'Net ödenecek tutar sıfırdan büyük olmalı ve bir kasa/banka hesabı seçilmelidir.');
            redirect(BASE_URL . '/modules/personnel/index.php');
        }

        $p_info = $db->query("SELECT first_name, last_name FROM personnel WHERE id = {$personnel_id}")->fetch();
        if ($p_info) {

            $ins_pay = $db->prepare("
                INSERT INTO payrolls (personnel_id, period_month, period_year, base_salary, bonus, deductions, advance_deductions, net_paid, status, payment_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, NOW())
            ");
            $ins_pay->execute([
                $personnel_id, $period_month, $period_year, $base_salary, $bonus, $deductions, $advance_deductions, $net_paid, $payment_date
            ]);
            $payroll_id = (int)$db->lastInsertId();

            // Kasadan Çıkış Yap
            $db->prepare("
                INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at)
                VALUES (?, NULL, NULL, NULL, 'expense', 'Personel Maaş Ödemesi', ?, ?, ?, ?, NOW())
            ")->execute([
                $account_id, $net_paid, $payment_date,
                "{$p_info['first_name']} {$p_info['last_name']} - {$period_month}/{$period_year} Net Maaş Ödemesi " . payroll_tx_tag($payroll_id),
                $user['id']
            ]);

            recalculate_account_balance($account_id);

            // Avansları Kapat
            $db->prepare("
                UPDATE advances 
                SET status = 'deducted_from_salary' 
                WHERE personnel_id = ? AND status = 'approved' AND MONTH(request_date) = ? AND YEAR(request_date) = ?
            ")->execute([$personnel_id, $period_month, $period_year]);

            set_flash('success', "{$p_info['first_name']} {$p_info['last_name']} personeline " . format_money($net_paid) . " maaş ödemesi yapıldı ve bordro basıma hazırlandı.");
            redirect(BASE_URL . '/modules/personnel/index.php');
        }
    }

    // ==========================================
    // B. MAAŞ BORDROSUNU SİLME & İPTAL ETME (KASAYA İADE TERS KAYIT)
    // ==========================================
    if ($action === 'delete_payroll') {
        $payroll_id = (int)$_POST['payroll_id'];
        $pay = $db->query("SELECT pr.*, p.first_name, p.last_name FROM payrolls pr JOIN personnel p ON pr.personnel_id = p.id WHERE pr.id = {$payroll_id}")->fetch();

        if ($pay) {
            // 1. Bu bordroya bağlı kasa hareketini bul ve sil, parayı kasaya iade et
            foreach (find_tagged_tx(payroll_tx_tag($payroll_id)) as $tx_row) {
                $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$tx_row['id']]);
            }

            // 2. Kapatılmış avansları tekrar aktif (approved) durumuna getir
            $db->prepare("
                UPDATE advances 
                SET status = 'approved' 
                WHERE personnel_id = ? AND status = 'deducted_from_salary' AND MONTH(request_date) = ? AND YEAR(request_date) = ?
            ")->execute([$pay['personnel_id'], $pay['period_month'], $pay['period_year']]);

            // 3. Bordroyu sil
            $db->prepare("DELETE FROM payrolls WHERE id = ?")->execute([$payroll_id]);

            // Kasa bakiyelerini eşitle
            recalculate_account_balance();

            set_flash('success', "{$pay['first_name']} {$pay['last_name']} personeline ait {$pay['period_month']}/{$pay['period_year']} bordrosu iptal edildi, ödenen tutar kasaya geri yüklendi ve 'Maaş Öde' butonu yeniden açıldı.");
            redirect(BASE_URL . '/modules/personnel/index.php');
        }
    }

    // ==========================================
    // C. MAAŞ BORDROSUNU DÜZENLEME
    // ==========================================
    if ($action === 'edit_payroll') {
        $payroll_id   = (int)$_POST['payroll_id'];
        $bonus        = parse_money($_POST['bonus'] ?? '0');
        $deductions   = parse_money($_POST['deductions'] ?? '0');
        $payment_date = valid_date($_POST['payment_date'] ?? '', date('Y-m-d'));

        $pay = $db->query("SELECT * FROM payrolls WHERE id = {$payroll_id}")->fetch();
        $new_net = $pay ? ($pay['base_salary'] + $bonus) - ($pay['advance_deductions'] + $deductions) : 0;
        if ($pay && $new_net <= 0) {
            set_flash('error', 'Net ödenecek tutar sıfırdan büyük olmalıdır.');
            redirect(BASE_URL . '/modules/personnel/index.php');
        }
        if ($pay) {
            $net_paid = ($pay['base_salary'] + $bonus) - ($pay['advance_deductions'] + $deductions);

            $db->prepare("UPDATE payrolls SET bonus = ?, deductions = ?, net_paid = ?, payment_date = ? WHERE id = ?")
               ->execute([$bonus, $deductions, $net_paid, $payment_date, $payroll_id]);

            // Varsa kasa hareketini de güncelle
            foreach (find_tagged_tx(payroll_tx_tag($payroll_id)) as $tx_row) {
                $db->prepare("UPDATE transactions SET amount = ?, transaction_date = ? WHERE id = ?")
                   ->execute([$net_paid, $payment_date, $tx_row['id']]);
            }

            recalculate_account_balance();

            set_flash('success', 'Maaş bordrosu bilgileri ve net tutar güncellendi.');
            redirect(BASE_URL . '/modules/personnel/index.php');
        }
    }

    // ==========================================
    // D. PERSONEL EKLEME, DÜZENLEME & SİLME
    // ==========================================
    if ($action === 'create_personnel') {
        $first_name  = trim($_POST['first_name'] ?? '');
        $last_name   = trim($_POST['last_name'] ?? '');
        $department  = trim($_POST['department'] ?? 'Prodüksiyon');
        $job_title   = trim($_POST['job_title'] ?? '');
        $base_salary = parse_money($_POST['base_salary'] ?? '0');
        $start_date  = $_POST['start_date'] ?? date('Y-m-d');
        $iban        = trim($_POST['iban'] ?? '');

        if (!empty($first_name) && !empty($last_name)) {
            $db->prepare("INSERT INTO personnel (first_name, last_name, department, job_title, base_salary, start_date, iban, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())")
               ->execute([$first_name, $last_name, $department, $job_title, $base_salary, $start_date, $iban]);
            set_flash('success', "{$first_name} {$last_name} personeli kaydedildi.");
            redirect(BASE_URL . '/modules/personnel/index.php');
        }
    }

    if ($action === 'edit_personnel') {
        $p_id        = (int)$_POST['personnel_id'];
        $base_salary = parse_money($_POST['base_salary'] ?? '0');
        $first_name  = trim($_POST['first_name'] ?? '');
        $last_name   = trim($_POST['last_name'] ?? '');

        if ($first_name === '' || $last_name === '') {
            set_flash('error', 'Ad ve soyad zorunludur.');
            redirect(BASE_URL . '/modules/personnel/index.php');
        }

        $db->prepare("UPDATE personnel SET first_name = ?, last_name = ?, department = ?, job_title = ?, base_salary = ?, start_date = ?, iban = ?, status = ? WHERE id = ?")
           ->execute([$first_name, $last_name, trim($_POST['department'] ?? ''), trim($_POST['job_title'] ?? ''), $base_salary, valid_date($_POST['start_date'] ?? ''), trim($_POST['iban'] ?? ''), ($_POST['status'] ?? 'active') === 'active' ? 'active' : ($_POST['status'] ?? 'inactive'), $p_id]);
        set_flash('success', 'Personel bilgileri güncellendi.');
        redirect(BASE_URL . '/modules/personnel/index.php');
    }

    if ($action === 'delete_personnel') {
        $p_id = (int)$_POST['personnel_id'];
        // Ödenmiş maaş/avans kasa hareketleri muhasebe geçmişi olarak korunur
        $db->prepare("DELETE FROM advances WHERE personnel_id = ?")->execute([$p_id]);
        $db->prepare("DELETE FROM payrolls WHERE personnel_id = ?")->execute([$p_id]);
        $db->prepare("DELETE FROM personnel WHERE id = ?")->execute([$p_id]);
        set_flash('success', 'Personel kaydı silindi.');
        redirect(BASE_URL . '/modules/personnel/index.php');
    }

    // ==========================================
    // E. AVANS İŞLEMLERİ
    // ==========================================
    if ($action === 'create_advance') {
        $amount       = parse_money($_POST['amount'] ?? '0');
        $personnel_id = (int)($_POST['personnel_id'] ?? 0);
        $account_id   = (int)($_POST['account_id'] ?? 0);
        $request_date = valid_date($_POST['request_date'] ?? '', date('Y-m-d'));
        $p_info = $db->query("SELECT first_name, last_name FROM personnel WHERE id = {$personnel_id}")->fetch();

        if ($p_info && $amount > 0) {
            $db->prepare("INSERT INTO advances (personnel_id, amount, request_date, status, reason, approved_by, created_at) VALUES (?, ?, ?, 'approved', ?, ?, NOW())")
               ->execute([$personnel_id, $amount, $request_date, trim($_POST['reason'] ?? ''), $user['id']]);
            $advance_id = (int)$db->lastInsertId();

            // Avans nakit olarak ödendiği için kasadan çıkış yapılır.
            // (Maaşta mahsup edildiğinde net maaş azalır; toplam kasa çıkışı brüt maaşa eşit olur.)
            if ($account_id > 0) {
                $db->prepare("
                    INSERT INTO transactions (account_id, contact_id, invoice_id, project_id, type, category, amount, transaction_date, description, created_by, created_at)
                    VALUES (?, NULL, NULL, NULL, 'expense', 'Personel Avans Ödemesi', ?, ?, ?, ?, NOW())
                ")->execute([$account_id, $amount, $request_date, "{$p_info['first_name']} {$p_info['last_name']} avans ödemesi " . advance_tx_tag($advance_id), $user['id']]);
                recalculate_account_balance($account_id);
            }
            set_flash('success', 'Avans onaylandı' . ($account_id > 0 ? ' ve kasadan çıkış yapıldı.' : '.'));
        } else {
            set_flash('error', 'Lütfen personel ve geçerli bir tutar giriniz.');
        }
        redirect(BASE_URL . '/modules/personnel/index.php');
    }

    if ($action === 'delete_advance') {
        $advance_id = (int)$_POST['advance_id'];
        $adv = $db->query("SELECT * FROM advances WHERE id = {$advance_id}")->fetch();
        if ($adv && $adv['status'] === 'deducted_from_salary') {
            set_flash('error', 'Maaştan mahsup edilmiş bir avans silinemez. Önce ilgili bordroyu iptal ediniz.');
        } elseif ($adv) {
            foreach (find_tagged_tx(advance_tx_tag($advance_id)) as $tx_row) {
                $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$tx_row['id']]);
            }
            $db->prepare("DELETE FROM advances WHERE id = ?")->execute([$advance_id]);
            recalculate_account_balance();
            set_flash('success', 'Avans ve varsa kasa çıkışı silindi.');
        }
        redirect(BASE_URL . '/modules/personnel/index.php');
    }
}

// 2. VERİLERİ ÇEKME
$current_month = (int)date('m');
$current_year  = (int)date('Y');

$personnel_list = $db->query("
    SELECT p.*,
           (SELECT COALESCE(SUM(amount), 0) FROM advances a WHERE a.personnel_id = p.id AND a.status = 'approved' AND MONTH(a.request_date) = {$current_month} AND YEAR(a.request_date) = {$current_year}) as this_month_advance,
           (SELECT COUNT(*) FROM payrolls pr WHERE pr.personnel_id = p.id AND pr.period_month = {$current_month} AND pr.period_year = {$current_year} AND pr.status = 'paid') as is_paid_this_month
    FROM personnel p
    WHERE p.status = 'active'
    ORDER BY p.id DESC
")->fetchAll();

$advances_list = $db->query("
    SELECT a.*, p.first_name, p.last_name, p.job_title, u.full_name as approver_name
    FROM advances a
    JOIN personnel p ON a.personnel_id = p.id
    LEFT JOIN users u ON a.approved_by = u.id
    ORDER BY a.request_date DESC, a.id DESC
    LIMIT 15
")->fetchAll();

$payrolls_list = $db->query("
    SELECT pr.*, p.first_name, p.last_name, p.job_title, p.iban
    FROM payrolls pr
    JOIN personnel p ON pr.personnel_id = p.id
    ORDER BY pr.payment_date DESC, pr.id DESC
    LIMIT 15
")->fetchAll();

$total_monthly_salary  = array_sum(array_column($personnel_list, 'base_salary'));
$total_monthly_advance = array_sum(array_column($personnel_list, 'this_month_advance'));
$accounts = $db->query("SELECT id, account_name, currency, balance FROM accounts WHERE status = 'active'")->fetchAll();

$page_title = 'Personel, Maaş & Avans';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ 
    openPersonnelModal: false, 
    openEditPersonModal: false,
    editPersonData: {},
    openAdvanceModal: false,
    openPaySalaryModal: false,
    paySalaryData: { id: '', name: '', base_salary: 0, advance: 0, bonus: 0, deductions: 0, net: 0 },
    openEditPayrollModal: false,
    editPayrollData: { id: '', name: '', bonus: 0, deductions: 0, payment_date: '' }
}">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Personel, Maaş & Avans Masası</h1>
            <p class="text-xs text-slate-500 mt-0.5">Ajans kadrosu, maaş hakedişleri, avans kesintileri ve bordro ödeme motoru.</p>
        </div>
        <div class="flex items-center gap-2">
            <button @click="openAdvanceModal = true" class="inline-flex items-center gap-1.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-bold py-2.5 px-4 rounded-xl shadow-xs transition">
                <i data-lucide="hand-coins" class="w-4 h-4 text-amber-600"></i>
                <span>Avans Ver / Talep</span>
            </button>
            <button @click="openPersonnelModal = true" class="inline-flex items-center gap-1.5 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition">
                <i data-lucide="user-plus" class="w-4 h-4"></i>
                <span>Yeni Personel Kaydet</span>
            </button>
        </div>
    </div>

    <!-- İK Sayaçları -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Aktif Kadrolu Personel</p>
            <p class="text-2xl font-black text-slate-800 mt-1"><?= count($personnel_list) ?> Kişi</p>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Aylık Toplam Taban Maaş</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= format_money($total_monthly_salary) ?></p>
        </div>
        <div class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Bu Ay Verilen Avanslar</p>
            <p class="text-2xl font-black text-amber-600 mt-1"><?= format_money($total_monthly_advance) ?></p>
        </div>
    </div>

    <!-- ==================================================================== -->
    <!-- 1. KADROLU PERSONEL VE MAAŞ ÖDEME TABLOSU -->
    <!-- ==================================================================== -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-8">
        <div class="p-5 border-b border-slate-200 flex items-center justify-between bg-slate-50/50">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Kadrolu Personel ve Maaş Hakediş Tablosu (<?= turkish_month((int)date('n')) . ' ' . date('Y') ?>)</h3>
                <p class="text-xs text-slate-400 mt-0.5">Net Maaş = Taban Maaş - Alınan Avanslar</p>
            </div>
            <span class="text-xs font-bold text-slate-600 bg-white border border-slate-200 px-3 py-1.5 rounded-xl">
                Dönem: <?= date('m/Y') ?>
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                        <th class="py-3.5 px-4">Ad Soyad & Görev</th>
                        <th class="py-3.5 px-4">Departman</th>
                        <th class="py-3.5 px-4">İşe Giriş</th>
                        <th class="py-3.5 px-4 text-right">Taban Maaş</th>
                        <th class="py-3.5 px-4 text-right">Avans Kesintisi</th>
                        <th class="py-3.5 px-4 text-right">Ödenecek Net Maaş</th>
                        <th class="py-3.5 px-4 text-right">İşlemler & Maaş Öde</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($personnel_list)): ?>
                        <tr><td colspan="7" class="py-10 text-center text-slate-400">Henüz personel kaydı bulunmuyor.</td></tr>
                    <?php else: ?>
                        <?php foreach ($personnel_list as $p): 
                            $net_payable = (float)$p['base_salary'] - (float)$p['this_month_advance'];
                            $is_paid = ((int)$p['is_paid_this_month'] > 0);
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3.5 px-4">
                                <div class="font-bold text-slate-900 text-sm"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
                                <span class="text-[11px] text-slate-500 font-medium"><?= e($p['job_title']) ?></span>
                            </td>

                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-semibold bg-purple-50 text-purple-700 border border-purple-200">
                                    <?= e($p['department']) ?>
                                </span>
                            </td>

                            <td class="py-3.5 px-4 text-slate-600"><?= format_date($p['start_date']) ?></td>
                            <td class="py-3.5 px-4 text-right font-bold text-slate-800"><?= format_money($p['base_salary']) ?></td>

                            <td class="py-3.5 px-4 text-right font-bold text-amber-600">
                                <?= (float)$p['this_month_advance'] > 0 ? '-' . format_money($p['this_month_advance']) : '0,00 ₺' ?>
                            </td>

                            <td class="py-3.5 px-4 text-right font-black text-emerald-600 text-sm">
                                <?= format_money($net_payable) ?>
                            </td>

                            <td class="py-3.5 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <?php if (!$is_paid): ?>
                                        <!-- MAAŞ ÖDE BUTONU -->
                                        <button @click="paySalaryData = {
                                                    id: '<?= $p['id'] ?>',
                                                    name: <?= js_val($p['first_name'] . ' ' . $p['last_name']) ?>,
                                                    base_salary: <?= (float)$p['base_salary'] ?>,
                                                    advance: <?= (float)$p['this_month_advance'] ?>,
                                                    bonus: 0,
                                                    deductions: 0,
                                                    net: <?= $net_payable ?>
                                                }; openPaySalaryModal = true"
                                                class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-xs transition text-xs flex items-center gap-1">
                                            <i data-lucide="wallet" class="w-3.5 h-3.5"></i>
                                            <span>Maaş Öde</span>
                                        </button>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                                            Bu Ay Ödendi
                                        </span>
                                    <?php endif; ?>

                                    <!-- DÜZENLE -->
                                    <button @click="editPersonData = {
                                                id: '<?= $p['id'] ?>',
                                                first_name: <?= js_val($p['first_name']) ?>,
                                                last_name: <?= js_val($p['last_name']) ?>,
                                                department: <?= js_val($p['department']) ?>,
                                                job_title: <?= js_val($p['job_title']) ?>,
                                                base_salary: '<?= (float)$p['base_salary'] ?>',
                                                start_date: '<?= $p['start_date'] ?>',
                                                identity_number: <?= js_val($p['identity_number'] ?? '') ?>,
                                                iban: <?= js_val($p['iban'] ?? '') ?>,
                                                status: '<?= $p['status'] ?>'
                                            }; openEditPersonModal = true"
                                            class="p-1.5 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-600 rounded-lg transition" title="Personeli Düzenle">
                                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                    </button>

                                    <!-- SİL -->
                                    <form method="POST" action="" onsubmit="return confirm('Bu personeli ve tüm bordro kayıtlarını silmek istiyor musunuz?');" class="inline-block">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_personnel">
                                        <input type="hidden" name="personnel_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 transition" title="Personeli Sil">
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

    <!-- ==================================================================== -->
    <!-- 2. ÖDENEN MAAŞLAR & BORDRO GEÇMİŞİ (YAZDIR, DÜZENLE, SİL BUTONLU) -->
    <!-- ==================================================================== -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-8">
        <div class="p-5 border-b border-slate-200 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Ödenen Maaşlar & Bordro Geçmişi</h3>
                <p class="text-xs text-slate-400 mt-0.5">Kasadan çıkışı yapılmış resmi maaş bordroları ve tediye makbuzları.</p>
            </div>
            <span class="text-xs text-slate-500 font-bold">Toplam: <?= count($payrolls_list) ?> Bordro</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase">
                        <th class="py-3 px-4">Ödeme Tarihi</th>
                        <th class="py-3 px-4">Dönem</th>
                        <th class="py-3 px-4">Personel & Görev</th>
                        <th class="py-3 px-4 text-right">Taban Maaş</th>
                        <th class="py-3 px-4 text-right">Düşülen Avans</th>
                        <th class="py-3 px-4 text-right">Prim / Ek</th>
                        <th class="py-3 px-4 text-right">Ödenen Net Tutar</th>
                        <th class="py-3 px-4 text-center">Durum</th>
                        <th class="py-3 px-4 text-right">İşlemler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($payrolls_list)): ?>
                        <tr><td colspan="9" class="py-6 text-center text-slate-400">Henüz ödenmiş maaş bordrosu bulunmuyor.</td></tr>
                    <?php else: ?>
                        <?php foreach ($payrolls_list as $pay): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-4 text-slate-600 font-medium"><?= format_date($pay['payment_date']) ?></td>
                            <td class="py-3 px-4 font-bold font-mono text-slate-900"><?= $pay['period_month'] ?>/<?= $pay['period_year'] ?></td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-slate-900 block"><?= e($pay['first_name'] . ' ' . $pay['last_name']) ?></span>
                                <span class="text-[11px] text-slate-400"><?= e($pay['job_title']) ?></span>
                            </td>
                            <td class="py-3 px-4 text-right font-medium text-slate-700"><?= format_money($pay['base_salary']) ?></td>
                            <td class="py-3 px-4 text-right font-bold text-amber-600">-<?= format_money($pay['advance_deductions']) ?></td>
                            <td class="py-3 px-4 text-right font-bold text-emerald-600">+<?= format_money($pay['bonus']) ?></td>
                            <td class="py-3 px-4 text-right font-black text-slate-900 text-sm"><?= format_money($pay['net_paid']) ?></td>
                            <td class="py-3 px-4 text-center">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                    ÖDENDİ
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <!-- RESMİ BORDRO A4 PDF YAZDIR BUTONU -->
                                    <a href="<?= BASE_URL ?>/modules/personnel/payroll_print.php?id=<?= $pay['id'] ?>" target="_blank"
                                       class="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg transition inline-flex items-center" title="Resmi Bordro PDF Yazdır">
                                        <i data-lucide="printer" class="w-4 h-4"></i>
                                    </a>

                                    <!-- BORDRO DÜZENLE BUTONU -->
                                    <button @click="editPayrollData = {
                                                id: '<?= $pay['id'] ?>',
                                                name: <?= js_val($pay['first_name'] . ' ' . $pay['last_name']) ?>,
                                                bonus: <?= (float)$pay['bonus'] ?>,
                                                deductions: <?= (float)$pay['deductions'] ?>,
                                                payment_date: '<?= $pay['payment_date'] ?>'
                                            }; openEditPayrollModal = true"
                                            class="p-1.5 bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-600 rounded-lg transition" title="Bordroyu Düzenle">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>

                                    <!-- BORDROYU İPTAL ET / SİL BUTONU (KASAYA İADE EDER) -->
                                    <form method="POST" action="" onsubmit="return confirm('DİKKAT: Bu maaş ödemesini iptal etmek istediğinize emin misiniz?\n\n- Ödenen net tutar kasaya/bankaya otomatik iade edilecektir.\n- Düşülen avanslar yeniden açılacaktır.\n- Personel için \'Maaş Öde\' butonu tekrar aktif olacaktır.');" class="inline-block">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_payroll">
                                        <input type="hidden" name="payroll_id" value="<?= $pay['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Maaş Ödemesini İptal Et">
                                            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
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

    <!-- 3. SON AVANS KAYITLARI -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden mb-8">
        <div class="p-5 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Son Avans Kayıtları</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 text-[11px] font-bold text-slate-500 uppercase">
                        <th class="py-3 px-4">Tarih</th>
                        <th class="py-3 px-4">Personel</th>
                        <th class="py-3 px-4">Açıklama</th>
                        <th class="py-3 px-4">Onaylayan</th>
                        <th class="py-3 px-4 text-right">Avans Tutarı</th>
                        <th class="py-3 px-4 text-right">İşlem</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($advances_list)): ?>
                        <tr><td colspan="6" class="py-6 text-center text-slate-400">Kayıtlı avans bulunmuyor.</td></tr>
                    <?php else: ?>
                        <?php foreach ($advances_list as $adv): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-4 text-slate-600"><?= format_date($adv['request_date']) ?></td>
                            <td class="py-3 px-4 font-bold text-slate-900"><?= e($adv['first_name'] . ' ' . $adv['last_name']) ?></td>
                            <td class="py-3 px-4 text-slate-600"><?= !empty($adv['reason']) ? e($adv['reason']) : 'Avans' ?></td>
                            <td class="py-3 px-4 text-slate-500"><?= e($adv['approver_name'] ?? 'Yönetici') ?></td>
                            <td class="py-3 px-4 text-right font-black text-amber-600"><?= format_money($adv['amount']) ?></td>
                            <td class="py-3 px-4 text-right">
                                <form method="POST" action="" onsubmit="return confirm('Avansı silmek istiyor musunuz?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_advance">
                                    <input type="hidden" name="advance_id" value="<?= $adv['id'] ?>">
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

    <!-- ===================================================== -->
    <!-- MODAL 1: MAAŞ ÖDEME & BORDRO KAPAT -->
    <!-- ===================================================== -->
    <div x-show="openPaySalaryModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openPaySalaryModal = false">
            <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Maaş Ödemesi & Bordro Kapat</h3>
                    <p class="text-xs text-slate-500" x-text="paySalaryData.name"></p>
                </div>
                <button type="button" @click="openPaySalaryModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay_salary">
                <input type="hidden" name="personnel_id" :value="paySalaryData.id">
                <input type="hidden" name="base_salary" :value="paySalaryData.base_salary">
                <input type="hidden" name="advance_deductions" :value="paySalaryData.advance">

                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-2 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Taban Maaş:</span>
                        <strong x-text="parseFloat(paySalaryData.base_salary).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺'"></strong>
                    </div>
                    <div class="flex justify-between text-amber-700">
                        <span>- Düşülecek Avanslar:</span>
                        <strong x-text="'-' + parseFloat(paySalaryData.advance).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺'"></strong>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Ek Prim / Bonus (Opsiyonel)</label>
                        <input type="number" step="0.01" name="bonus" x-model="paySalaryData.bonus" placeholder="0.00" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Diğer Kesinti (Opsiyonel)</label>
                        <input type="number" step="0.01" name="deductions" x-model="paySalaryData.deductions" placeholder="0.00" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div class="p-3.5 bg-emerald-50 rounded-2xl border border-emerald-200 flex justify-between items-center text-xs">
                    <span class="font-bold text-emerald-950">Ödenecek Net Tutar:</span>
                    <span class="text-base font-black text-emerald-700"
                          x-text="((parseFloat(paySalaryData.base_salary) + (parseFloat(paySalaryData.bonus) || 0)) - (parseFloat(paySalaryData.advance) + (parseFloat(paySalaryData.deductions) || 0))).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ₺'"></span>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Ödemenin Yapılacağı Kasa/Banka *</label>
                    <select name="account_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= e($a['account_name']) ?> (<?= format_money($a['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Maaş Dönemi (Ay/Yıl)</label>
                        <div class="flex gap-1">
                            <input type="number" name="period_month" value="<?= date('m') ?>" min="1" max="12" class="w-1/2 py-2 px-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-center">
                            <input type="number" name="period_year" value="<?= date('Y') ?>" class="w-1/2 py-2 px-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Ödeme Tarihi</label>
                        <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openPaySalaryModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs shadow-md transition">
                        Maaş Ödemesini Onayla & Kasadan Düş
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================================================== -->
    <!-- MODAL 2: MAAŞ BORDROSUNU DÜZENLE (YENİ) -->
    <!-- ===================================================== -->
    <div x-show="openEditPayrollModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200" @click.away="openEditPayrollModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Bordro Detaylarını Düzenle</h3>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_payroll">
                <input type="hidden" name="payroll_id" :value="editPayrollData.id">

                <div>
                    <p class="text-xs text-slate-500 mb-1">Personel:</p>
                    <p class="text-sm font-bold text-slate-900" x-text="editPayrollData.name"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Prim / Bonus (₺)</label>
                        <input type="number" step="0.01" name="bonus" x-model="editPayrollData.bonus" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Ek Kesinti (₺)</label>
                        <input type="number" step="0.01" name="deductions" x-model="editPayrollData.deductions" class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Ödeme Tarihi</label>
                    <input type="date" name="payment_date" x-model="editPayrollData.payment_date" required class="w-full py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditPayrollModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Bordroyu Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: PERSONEL BİLGİLERİNİ DÜZENLE -->
    <div x-show="openEditPersonModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openEditPersonModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Personel Bilgilerini Düzenle</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_personnel">
                <input type="hidden" name="personnel_id" :value="editPersonData.id">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Ad *</label>
                        <input type="text" name="first_name" required x-model="editPersonData.first_name" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Soyad *</label>
                        <input type="text" name="last_name" required x-model="editPersonData.last_name" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Departman</label>
                        <select name="department" x-model="editPersonData.department" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="Yönetim">Yönetim</option>
                            <option value="Prodüksiyon">Prodüksiyon</option>
                            <option value="Post-Prodüksiyon (Kurgu)">Post-Prodüksiyon (Kurgu)</option>
                            <option value="Kreatif / Yönetmenlik">Kreatif / Yönetmenlik</option>
                            <option value="Kamera & Işık">Kamera & Işık</option>
                            <option value="Muhasebe / Finans">Muhasebe / Finans</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Görev / Ünvan</label>
                        <input type="text" name="job_title" required x-model="editPersonData.job_title" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Taban Maaş (₺) *</label>
                        <input type="number" step="0.01" name="base_salary" required x-model="editPersonData.base_salary" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Maaş IBAN</label>
                        <input type="text" name="iban" x-model="editPersonData.iban" placeholder="TR..." class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">İşe Giriş Tarihi</label>
                        <input type="date" name="start_date" x-model="editPersonData.start_date" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openEditPersonModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-brand-600 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: YENİ PERSONEL -->
    <div x-show="openPersonnelModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openPersonnelModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Yeni Personel Kaydı</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_personnel">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Ad *</label>
                        <input type="text" name="first_name" required placeholder="Ad" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Soyad *</label>
                        <input type="text" name="last_name" required placeholder="Soyad" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Departman *</label>
                        <select name="department" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="Prodüksiyon">Prodüksiyon</option>
                            <option value="Post-Prodüksiyon (Kurgu)">Post-Prodüksiyon (Kurgu)</option>
                            <option value="Kreatif / Yönetmenlik">Kreatif / Yönetmenlik</option>
                            <option value="Kamera & Işık">Kamera & Işık</option>
                            <option value="Muhasebe / Finans">Muhasebe / Finans</option>
                            <option value="Yönetim">Yönetim</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Görev / Ünvan *</label>
                        <input type="text" name="job_title" required placeholder="Editör / Prodüktör" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Taban Maaş (₺) *</label>
                        <input type="number" step="0.01" name="base_salary" required placeholder="0.00" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">İşe Başlama Tarihi</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Maaş IBAN</label>
                        <input type="text" name="iban" placeholder="TR..." class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openPersonnelModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 5: AVANS VER -->
    <div x-show="openAdvanceModal" x-cloak class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl" @click.away="openAdvanceModal = false">
            <h3 class="text-base font-bold text-slate-900 mb-4">Personele Avans Ver</h3>
            <form method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_advance">

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Personel Seçin *</label>
                    <select name="personnel_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <option value="">-- Personel Seçin --</option>
                        <?php foreach ($personnel_list as $pl): ?>
                            <option value="<?= $pl['id'] ?>"><?= e($pl['first_name'] . ' ' . $pl['last_name']) ?> (<?= e($pl['job_title']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Avans Tutarı *</label>
                        <input type="number" step="0.01" name="amount" required placeholder="0.00" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tarih</label>
                        <input type="date" name="request_date" value="<?= date('Y-m-d') ?>" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Ödendiği Kasa / Banka</label>
                    <select name="account_id" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= (int)$acc['id'] ?>"><?= e($acc['account_name']) ?> (<?= format_money($acc['balance'], $acc['currency']) ?>)</option>
                        <?php endforeach; ?>
                        <option value="0">Kasa hareketi oluşturma (sadece kayıt)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Açıklama</label>
                    <input type="text" name="reason" placeholder="Örn: Ay ortası avans" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <div class="pt-3 flex justify-end gap-2">
                    <button type="button" @click="openAdvanceModal = false" class="px-4 py-2 text-xs text-slate-500">İptal</button>
                    <button type="submit" class="px-5 py-2 bg-amber-600 text-white font-bold rounded-xl text-xs shadow-md">Avansı Onayla</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>