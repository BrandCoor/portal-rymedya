<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - ÖDEMELER (AJANS)
 * ====================================================================
 * Açık faturalar, ödeme yapılacak hesaplar, ödeme bildirimi (dekontlu)
 * ve bildirim geçmişi.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_layout.php';

$profile = require_platform_role('agency');
$uid = (int)$_SESSION['client_user_id'];
$cid = (int)$_SESSION['client_contact_id'];
$self = BASE_URL . '/platform/payments.php';

$open = contact_open_invoices($cid, 'sales');
$open_by_id = [];
foreach ($open as $inv) $open_by_id[(int)$inv['id']] = $inv;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'notice') {
        $amount = parse_money($_POST['amount'] ?? '');
        $date = valid_date($_POST['paid_on'] ?? '');
        $method = array_key_exists($_POST['method'] ?? '', PAYMENT_METHODS) ? $_POST['method'] : 'transfer';
        $inv_id = (int)($_POST['invoice_id'] ?? 0) ?: null;
        $err = null;
        if ($amount <= 0) $err = 'Ödediğiniz tutarı girin.';
        elseif (!$date || $date > date('Y-m-d')) $err = 'Ödeme tarihi bugün veya geçmiş bir tarih olmalı.';
        elseif ($inv_id && !isset($open_by_id[$inv_id])) $err = 'Seçilen fatura açık faturalarınız arasında değil.';
        if (!$err) {
            [$receipt, $rerr] = receipt_save($_FILES['receipt'] ?? null);
            $err = $rerr;
        }
        if ($err) {
            set_flash('error', $err);
            redirect($self . '#bildir');
        }
        $db->prepare("INSERT INTO platform_payment_notices (contact_id, user_id, invoice_id, amount, paid_on, method, reference, note, receipt_path, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())")
           ->execute([$cid, $uid, $inv_id, $amount, $date, $method, mb_substr(trim($_POST['reference'] ?? ''), 0, 200) ?: null, trim($_POST['note'] ?? '') ?: null, $receipt]);
        $company = $_SESSION['client_user']['company_name'] ?? '';
        notify_staff_payment("Ödeme bildirimi: {$company} · " . format_money($amount) . ' · ' . PAYMENT_METHODS[$method] . ($inv_id ? ' · ' . $open_by_id[$inv_id]['invoice_number'] : '') . ($receipt ? ' · dekontlu' : ''));
        set_flash('success', 'Ödeme bildiriminiz iletildi. Ekibimiz hesaba geçtiğini doğruladığında faturanıza işlenir ve size bildirim gelir.');
        redirect($self);
    }
    redirect($self);
}

$notices = $db->prepare("SELECT n.*, i.invoice_number FROM platform_payment_notices n LEFT JOIN invoices i ON i.id = n.invoice_id WHERE n.contact_id = ? ORDER BY n.id DESC LIMIT 50");
$notices->execute([$cid]);
$notices = $notices->fetchAll();
$total_due = array_sum(array_map(fn($i) => (float)$i['remaining'], $open));
$overdue = array_sum(array_map(fn($i) => $i['due_date'] && $i['due_date'] < date('Y-m-d') ? (float)$i['remaining'] : 0, $open));
$pending_sum = array_sum(array_map(fn($n) => $n['status'] === 'pending' ? (float)$n['amount'] : 0, $notices));
$banks = platform_setting('platform_show_bank_accounts') === '1'
    ? $db->query("SELECT account_name, bank_name, iban, currency FROM accounts WHERE status = 'active' AND account_type = 'bank' AND iban IS NOT NULL AND iban != ''")->fetchAll()
    : [];
$company = site_setting('company_name');
$pre = (int)($_GET['invoice'] ?? 0);

platform_header('Ödemeler', 'finance');
?>
<div class="page-head">
    <div>
        <h1 class="h1">Ödemeler</h1>
        <p class="sub">Açık faturalarınız, ödeme yapabileceğiniz hesaplar ve ödeme bildirimleri.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/modules/contacts/statement_print.php" target="_blank" class="btn btn-secondary"><i data-lucide="receipt-text"></i>Cari ekstre</a>
        <a href="#bildir" class="btn btn-primary"><i data-lucide="send"></i>Ödeme bildir</a>
    </div>
</div>

<div class="card" style="margin-bottom:24px">
    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
        <div class="kpi"><div class="kpi-label">Açık bakiye</div><div class="kpi-value"><?= format_money($total_due) ?></div><div class="kpi-meta"><?= count($open) ?> fatura</div></div>
        <div class="kpi"><div class="kpi-label">Vadesi geçmiş</div><div class="kpi-value" style="<?= $overdue > 0 ? 'color:var(--danger)' : '' ?>"><?= format_money($overdue) ?></div><div class="kpi-meta"><?= $overdue > 0 ? 'lütfen ödeme yapın' : 'gecikme yok' ?></div></div>
        <div class="kpi"><div class="kpi-label">Doğrulama bekleyen</div><div class="kpi-value"><?= format_money($pending_sum) ?></div><div class="kpi-meta">bildirdiğiniz ödemeler</div></div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
<div class="lg:col-span-2 stack-lg" style="min-width:0">
    <section class="card">
        <div class="card-head"><p class="card-title">Açık faturalar</p></div>
        <?php if (!$open): ?>
            <?= ui_empty('Açık faturanız yok', 'Tüm faturalarınız ödenmiş görünüyor.', 'circle-check') ?>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Fatura</th><th>Vade</th><th class="r">Tutar</th><th class="r">Kalan</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($open as $inv): $late = $inv['due_date'] && $inv['due_date'] < date('Y-m-d'); ?>
                    <tr>
                        <td><div style="font-weight:500"><?= e($inv['invoice_number']) ?></div><div class="xsmall text-muted"><?= e(mb_strimwidth((string)$inv['notes'], 0, 70, '…')) ?></div></td>
                        <td class="small"><?= $inv['due_date'] ? format_date($inv['due_date']) : '—' ?><?php if ($late): ?> <?= ui_badge('Gecikti', 'danger') ?><?php endif; ?></td>
                        <td class="r num"><?= format_money((float)$inv['grand_total']) ?></td>
                        <td class="r money"><?= format_money((float)$inv['remaining']) ?></td>
                        <td class="r"><a class="btn btn-ghost btn-sm" href="?invoice=<?= (int)$inv['id'] ?>#bildir">Ödedim</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><p class="card-title">Ödeme bildirimlerim</p></div>
        <?php if (!$notices): ?>
            <?= ui_empty('Henüz bildirim yok', 'Ödeme yaptığınızda buradan bildirin; ekip doğrulayınca faturanıza işlenir.', 'send') ?>
        <?php else: ?>
        <div class="divide">
            <?php foreach ($notices as $n): [$sl, $st] = PAYMENT_NOTICE_STATUSES[$n['status']] ?? [$n['status'], 'neutral']; ?>
            <div class="card-pad-sm" style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                    <p class="small" style="font-weight:500"><?= format_money((float)$n['amount']) ?> · <?= e(PAYMENT_METHODS[$n['method']] ?? $n['method']) ?><?= $n['invoice_number'] ? ' · ' . e($n['invoice_number']) : '' ?></p>
                    <p class="xsmall text-muted">Ödeme tarihi <?= format_date($n['paid_on']) ?> · bildirim <?= format_date($n['created_at'], true) ?><?= $n['reference'] ? ' · ' . e($n['reference']) : '' ?><?php if ($n['receipt_path']): ?> · <a class="link" target="_blank" href="<?= BASE_URL ?>/platform/receipt.php?id=<?= (int)$n['id'] ?>">dekont</a><?php endif; ?></p>
                    <?php if ($n['staff_note']): ?><p class="xsmall" style="margin-top:4px;color:<?= $n['status'] === 'rejected' ? 'var(--danger)' : 'var(--muted)' ?>">Ekip notu: <?= e($n['staff_note']) ?></p><?php endif; ?>
                </div>
                <?= ui_badge($sl, $st, true) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<aside class="stack-lg" style="min-width:0">
    <?php if ($banks): ?>
    <section class="card">
        <div class="card-head"><p class="card-title">Ödeme yapabileceğiniz hesaplar</p></div>
        <div class="divide">
            <?php foreach ($banks as $bk): ?>
            <div class="card-pad-sm">
                <p class="small" style="font-weight:500"><?= e($bk['bank_name'] ?: $bk['account_name']) ?> <span class="xsmall text-muted"><?= e($bk['currency']) ?></span></p>
                <p class="small num" style="font-family:var(--font-mono, monospace);word-break:break-all"><?= e($bk['iban']) ?></p>
                <p class="xsmall text-muted">Alıcı: <?= e($company) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="card card-emphasis" id="bildir" x-data="{ inv: '<?= $pre && isset($open_by_id[$pre]) ? $pre : '' ?>', amounts: <?= e(json_encode(array_map(fn($i) => (float)$i['remaining'], $open_by_id))) ?>, amount: '<?= $pre && isset($open_by_id[$pre]) ? number_format((float)$open_by_id[$pre]['remaining'], 2, '.', '') : '' ?>' }">
        <div class="card-head"><div><p class="card-title">Ödeme bildir</p><p class="card-sub">Ekip hesaba geçtiğini doğrulayınca faturanıza işlenir.</p></div></div>
        <form method="POST" action="" enctype="multipart/form-data" class="card-pad stack"><?= csrf_field() ?>
            <input type="hidden" name="action" value="notice">
            <div class="field"><label class="label">Fatura</label>
                <select class="select" name="invoice_id" x-model="inv" @change="if (amounts[inv]) amount = amounts[inv].toFixed(2)">
                    <option value="">Genel ödeme / birden fazla fatura</option>
                    <?php foreach ($open as $inv): ?><option value="<?= (int)$inv['id'] ?>"><?= e($inv['invoice_number']) ?> · kalan <?= format_money((float)$inv['remaining']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="grid grid-cols-2 gap-3">
                <div class="field"><label class="label">Tutar <span class="req">*</span></label><div class="input-group"><input class="input" inputmode="decimal" name="amount" x-model="amount" required><span class="addon">₺</span></div></div>
                <div class="field"><label class="label">Ödeme tarihi <span class="req">*</span></label><input class="input" type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
            </div>
            <div class="field"><label class="label">Ödeme yöntemi</label><select class="select" name="method"><?php foreach (PAYMENT_METHODS as $mk => $ml): ?><option value="<?= $mk ?>"><?= e($ml) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label class="label">Dekont no / açıklama</label><input class="input" name="reference" placeholder="ör. EFT referans no"></div>
            <div class="field"><label class="label">Dekont</label><input class="input" type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp"><span class="hint">PDF veya görsel, en fazla 5 MB. İsteğe bağlı.</span></div>
            <div class="field"><label class="label">Not</label><textarea class="textarea" name="note" rows="2" placeholder="Ekibe iletmek istedikleriniz"></textarea></div>
            <div><button class="btn btn-primary"><i data-lucide="send"></i>Bildirimi gönder</button></div>
        </form>
    </section>
</aside>
</div>
<?php platform_footer();
