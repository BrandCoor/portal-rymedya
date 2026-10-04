<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - ÖDEMELER
 * ====================================================================
 * Ajans ödeme bildirimleri: dekontu kontrol et → tahsilatı faturalara dağıt
 * (kalan cari hesaba) veya gerekçeyle reddet.
 * Freelancer ödeme talepleri: talepteki hakedişleri seçilen hesaptan öde
 * veya gerekçeyle reddet. Her sonuç ilgili kişiye bildirilir.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');
$staff_id = (int)current_user()['id'];
$tab = in_array($_GET['tab'] ?? '', ['payouts', 'cards'], true) ? $_GET['tab'] : 'notices';
$self = BASE_URL . '/modules/platform/payments.php?tab=' . $tab;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $date = valid_date($_POST['date'] ?? '', date('Y-m-d'));
    $note = trim($_POST['staff_note'] ?? '');
    if (in_array($action, ['notice_confirm', 'notice_reject'], true)) {
        $n = payment_notice_get((int)($_POST['id'] ?? 0));
        if (!$n) {
            set_flash('error', 'Bildirim bulunamadı.');
            redirect($self);
        }
        if ($action === 'notice_reject') {
            if (mb_strlen($note) < 3) {
                set_flash('error', 'Ret gerekçesi yazın; ajansa iletilir.');
                redirect($self . '#n-' . $n['id']);
            }
            payment_notice_reject($n, $note, $staff_id);
            set_flash('success', 'Bildirim reddedildi, ajans bilgilendirildi.');
            redirect($self);
        }
        $alloc = [];
        foreach ((array)($_POST['alloc'] ?? []) as $iid => $v) $alloc[(int)$iid] = parse_money((string)$v);
        $err = payment_notice_confirm($n, $alloc, (int)($_POST['account_id'] ?? 0), $date, $staff_id, $note);
        set_flash($err ? 'error' : 'success', $err ?: 'Ödeme onaylandı ve tahsilat kaydedildi.');
        redirect($self . ($err ? '#n-' . $n['id'] : ''));
    }
    if (in_array($action, ['payout_pay', 'payout_reject'], true)) {
        $r = payout_get((int)($_POST['id'] ?? 0));
        if (!$r) {
            set_flash('error', 'Talep bulunamadı.');
            redirect($self);
        }
        if ($action === 'payout_reject') {
            if (mb_strlen($note) < 3) {
                set_flash('error', 'Ret gerekçesi yazın; freelancer\'a iletilir.');
                redirect($self . '#r-' . $r['id']);
            }
            payout_reject($r, $note, $staff_id);
            set_flash('success', 'Talep reddedildi, freelancer bilgilendirildi.');
            redirect($self);
        }
        $err = payout_pay($r, (int)($_POST['account_id'] ?? 0), $date, $staff_id, $note);
        set_flash($err ? 'error' : 'success', $err ?: 'Ödeme kaydedildi; freelancer bilgilendirildi.');
        redirect($self . ($err ? '#r-' . $r['id'] : ''));
    }
    redirect($self);
}

$accounts = $db->query("SELECT id, account_name, balance, currency FROM accounts WHERE status = 'active' ORDER BY account_type = 'bank' DESC, account_name")->fetchAll();
$counts = $db->query("SELECT (SELECT COUNT(*) FROM platform_payment_notices WHERE status = 'pending') AS n, (SELECT COUNT(*) FROM platform_payout_requests WHERE status = 'pending') AS r")->fetch();
$notices = $db->query("SELECT n.*, c.company_title, u.full_name, i.invoice_number FROM platform_payment_notices n JOIN contacts c ON c.id = n.contact_id LEFT JOIN users u ON u.id = n.user_id LEFT JOIN invoices i ON i.id = n.invoice_id ORDER BY n.status = 'pending' DESC, n.id DESC LIMIT 100")->fetchAll();
$cards = $db->query("SELECT p.*, c.company_title FROM platform_card_payments p JOIN contacts c ON c.id = p.contact_id ORDER BY p.id DESC LIMIT 200")->fetchAll();
$payouts = $db->query("SELECT r.*, u.full_name, u.email FROM platform_payout_requests r JOIN users u ON u.id = r.user_id ORDER BY r.status = 'pending' DESC, r.id DESC LIMIT 100")->fetchAll();
$acc_select = function () use ($accounts): string {
    return '<select class="select" name="account_id" required style="width:auto"><option value="">Hesap seçin</option>'
        . implode('', array_map(fn($a) => '<option value="' . (int)$a['id'] . '">' . e($a['account_name']) . ' · ' . e(format_money((float)$a['balance'])) . '</option>', $accounts)) . '</select>';
};

$page_title = 'Ödemeler';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Ödemeler</span></div>
        <h1 class="h1">Ödemeler</h1>
        <p class="sub">Ajansların bildirdiği ödemeleri doğrulayın, freelancer ödeme taleplerini ödeyin. Her işlem faturaya ve kasa/banka hesabına işlenir.</p>
    </div>
</div>

<nav class="tabs" style="margin-bottom:16px">
    <a href="?tab=notices" class="tab <?= $tab === 'notices' ? 'is-active' : '' ?>">Ajans ödeme bildirimleri<span class="count"><?= (int)$counts['n'] ?></span></a>
    <a href="?tab=payouts" class="tab <?= $tab === 'payouts' ? 'is-active' : '' ?>">Freelancer ödeme talepleri<span class="count"><?= (int)$counts['r'] ?></span></a>
    <a href="?tab=cards" class="tab <?= $tab === 'cards' ? 'is-active' : '' ?>">Kartla ödemeler (iyzico)</a>
</nav>

<?php if (!$accounts): ?><div class="alert alert-warning" style="margin-bottom:16px"><i data-lucide="landmark"></i><div>Ödeme kaydı için önce <a class="link" href="<?= BASE_URL ?>/modules/finance/accounts.php">kasa / banka hesabı</a> tanımlayın.</div></div><?php endif; ?>

<?php if ($tab === 'notices'): ?>
<div class="stack">
    <?php if (!$notices): ?><section class="card"><?= ui_empty('Ödeme bildirimi yok', 'Ajanslar panellerindeki "Ödemeler" ekranından ödeme bildirdiğinde burada görünür.', 'wallet') ?></section><?php endif; ?>
    <?php foreach ($notices as $n): [$sl, $st] = PAYMENT_NOTICE_STATUSES[$n['status']] ?? [$n['status'], 'neutral'];
        $pending = $n['status'] === 'pending';
        $open = $pending ? contact_open_invoices((int)$n['contact_id'], 'sales') : [];
        // Önerilen dağıtım: seçilen fatura önce, kalan eskiden yeniye
        $left = (float)$n['amount']; $sug = [];
        usort($open, fn($a, $b) => ((int)$b['id'] === (int)$n['invoice_id']) <=> ((int)$a['id'] === (int)$n['invoice_id']));
        foreach ($open as $inv) { $v = min($left, (float)$inv['remaining']); $sug[(int)$inv['id']] = $v; $left = round($left - $v, 2); }
    ?>
    <section class="card" id="n-<?= (int)$n['id'] ?>" x-data="{ reject: false }" style="<?= $pending ? '' : 'opacity:.85' ?>">
        <div class="card-head" style="flex-wrap:wrap;gap:10px">
            <div style="min-width:0">
                <p class="card-title"><?= e($n['company_title']) ?> · <?= format_money((float)$n['amount']) ?></p>
                <p class="card-sub"><?= e(PAYMENT_METHODS[$n['method']] ?? $n['method']) ?> · ödeme <?= format_date($n['paid_on']) ?> · bildiren <?= e($n['full_name'] ?? '—') ?>, <?= format_date($n['created_at'], true) ?><?= $n['invoice_number'] ? ' · fatura ' . e($n['invoice_number']) : '' ?><?= $n['reference'] ? ' · ref ' . e($n['reference']) : '' ?></p>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <?php if ($n['receipt_path']): ?><a class="btn btn-secondary btn-sm" target="_blank" href="<?= BASE_URL ?>/platform/receipt.php?id=<?= (int)$n['id'] ?>"><i data-lucide="file-text"></i>Dekont</a><?php endif; ?>
                <?= ui_badge($sl, $st, true) ?>
            </div>
        </div>
        <?php if ($n['note'] || $n['staff_note']): ?><div class="card-pad-sm small"><?php if ($n['note']): ?><p>Ajans notu: <?= e($n['note']) ?></p><?php endif; ?><?php if ($n['staff_note']): ?><p class="text-muted">Ekip notu: <?= e($n['staff_note']) ?></p><?php endif; ?></div><?php endif; ?>
        <?php if ($pending): ?>
        <form method="POST" action="" class="card-pad stack" style="border-top:1px solid var(--line-2)"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
            <?php if ($open): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Açık fatura</th><th>Vade</th><th class="r">Kalan</th><th class="r" style="width:160px">İşlenecek tutar</th></tr></thead>
                <tbody><?php foreach ($open as $inv): ?>
                    <tr><td class="small"><?= e($inv['invoice_number']) ?></td><td class="small"><?= $inv['due_date'] ? format_date($inv['due_date']) : '—' ?></td><td class="r num"><?= format_money((float)$inv['remaining']) ?></td>
                        <td class="r"><input class="input input-sm" style="text-align:right" inputmode="decimal" name="alloc[<?= (int)$inv['id'] ?>]" value="<?= $sug[(int)$inv['id']] > 0 ? number_format($sug[(int)$inv['id']], 2, ',', '') : '' ?>"></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <p class="xsmall text-muted">Tutarlar eski faturadan başlayarak önerildi. Faturalara dağıtılmayan kısım cari hesaba avans olarak işlenir.</p>
            <?php else: ?>
            <p class="xsmall text-muted">Ajansın açık faturası yok; tutar cari hesaba avans olarak işlenir.</p>
            <?php endif; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <?= $acc_select() ?>
                <input class="input" type="date" name="date" value="<?= e($n['paid_on']) ?>" style="width:auto">
                <input class="input" name="staff_note" placeholder="Not (ajansa iletilir; retlerde zorunlu)" style="flex:1;min-width:200px">
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-primary btn-sm" name="action" value="notice_confirm" onclick="return confirm('Ödeme hesaba geçti olarak onaylansın mı? Tahsilat kaydedilir.');"><i data-lucide="check"></i>Hesaba geçti, onayla</button>
                <button class="btn btn-ghost btn-sm" name="action" value="notice_reject" formnovalidate>Reddet</button>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<?php elseif ($tab === 'cards'): ?>
<?php if (!iyzico_enabled()): ?>
    <div class="alert alert-info" style="margin-bottom:16px"><i data-lucide="credit-card"></i><div>Kartla ödeme kapalı. Açmak için <a class="link" href="<?= BASE_URL ?>/modules/settings/index.php?tab=bank">Ayarlar → Banka ve ödeme</a> bölümünden iyzico anahtarlarını girip açın ve <a class="link" href="<?= BASE_URL ?>/modules/platform/settings.php">Platform kuralları → Ödemeler</a> bölümünden tahsilat hesabını seçin.</div></div>
<?php endif; ?>
<section class="card">
    <?php if (!$cards): ?>
        <?= ui_empty('Kartla ödeme yok', 'Ajanslar Ödemeler ekranından kartla ödediğinde burada listelenir; tahsilat faturaya otomatik işlenir.', 'credit-card') ?>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Ajans</th><th>Tarih</th><th class="r">Tutar</th><th>Taksit</th><th>iyzico işlem no</th><th>Durum</th></tr></thead>
        <tbody><?php foreach ($cards as $c): [$cl, $ct] = CARD_PAYMENT_STATUSES[$c['status']] ?? [$c['status'], 'neutral']; ?>
            <tr><td class="small"><?= e($c['company_title']) ?></td><td class="small"><?= format_date($c['completed_at'] ?: $c['created_at'], true) ?></td>
                <td class="r money"><?= format_money((float)$c['amount']) ?><?= $c['paid_price'] !== null && abs((float)$c['paid_price'] - (float)$c['amount']) > 0.009 ? '<div class="xsmall text-muted">kart: ' . format_money((float)$c['paid_price']) . '</div>' : '' ?></td>
                <td class="small"><?= $c['installment'] ? (int)$c['installment'] : '—' ?></td><td class="xsmall num"><?= e($c['payment_id'] ?? '—') ?></td>
                <td><?= ui_badge($cl, $ct, true) ?><?= $c['error'] ? '<div class="xsmall text-muted">' . e($c['error']) . '</div>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php else: ?>
<div class="stack">
    <?php if (!$payouts): ?><section class="card"><?= ui_empty('Ödeme talebi yok', 'Freelancer\'lar Kazanç ekranından onaylanmış hakedişleri için ödeme talep ettiğinde burada görünür.', 'hand-coins') ?></section><?php endif; ?>
    <?php foreach ($payouts as $r): [$rl, $rt] = PAYOUT_STATUSES[$r['status']] ?? [$r['status'], 'neutral']; $pending = $r['status'] === 'pending'; $invs = payout_invoices($r);
        $due = array_sum(array_map(fn($i) => (float)$i['remaining'], $invs)); ?>
    <section class="card" id="r-<?= (int)$r['id'] ?>" style="<?= $pending ? '' : 'opacity:.85' ?>">
        <div class="card-head" style="flex-wrap:wrap;gap:10px">
            <div style="min-width:0">
                <p class="card-title"><a class="link" href="<?= BASE_URL ?>/modules/platform/freelancers.php?focus=<?= (int)$r['user_id'] ?>"><?= e($r['full_name']) ?></a> · talep #<?= (int)$r['id'] ?> · <?= format_money((float)$r['amount']) ?></p>
                <p class="card-sub"><?= format_date($r['created_at'], true) ?> · IBAN <?= e($r['iban'] ?: '—') ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?><?= $r['staff_note'] ? ' · ekip: ' . e($r['staff_note']) : '' ?></p>
            </div>
            <?= ui_badge($rl, $rt, true) ?>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Hakediş</th><th>Fatura</th><th class="r">Tutar</th><th class="r">Kalan</th></tr></thead>
            <tbody><?php foreach ($invs as $i): ?>
                <tr><td class="small"><?= e($i['label'] ?? '—') ?></td><td class="small"><a class="link" target="_blank" href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_number']) ?></a></td><td class="r num"><?= format_money((float)$i['grand_total']) ?></td><td class="r money"><?= format_money((float)$i['remaining']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($pending): ?>
        <form method="POST" action="" class="card-pad" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-top:1px solid var(--line-2)"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <?= $acc_select() ?>
            <input class="input" type="date" name="date" value="<?= date('Y-m-d') ?>" style="width:auto">
            <input class="input" name="staff_note" placeholder="Not (freelancer'a iletilir; retlerde zorunlu)" style="flex:1;min-width:200px">
            <button class="btn btn-primary btn-sm" name="action" value="payout_pay" onclick="return confirm('<?= e(format_money($due)) ?> ödendi olarak kaydedilsin mi?');"><i data-lucide="banknote"></i><?= e(format_money($due)) ?> öde</button>
            <button class="btn btn-ghost btn-sm" name="action" value="payout_reject" formnovalidate>Reddet</button>
        </form>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
