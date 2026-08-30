<?php
/**
 * ====================================================================
 * RY MEDYA - MÜŞTERİ PORTALI (OTOMATİK BAKİYE EŞİTLEME ENTEGRELİ)
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Müşteri Giriş Kontrolü
if (!isset($_SESSION['client_user_id']) || !isset($_SESSION['client_contact_id'])) {
    redirect(BASE_URL . '/client/login.php');
}

$contact_id  = (int)$_SESSION['client_contact_id'];
$client_user = $_SESSION['client_user'];

// BAKİYEYİ AKTİF HAREKETLERDEN ANINDA YENİDEN HESAPLA VE EŞİTLE
$accurate_balance = recalculate_contact_balance($contact_id);

// Sistem Ayarlarını Çek
$settings_raw  = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$bank_name     = $settings_raw['bank_primary_name'] ?? 'Garanti BBVA';
$bank_receiver = $settings_raw['bank_primary_receiver'] ?? 'RY MEDYA PRODÜKSİYON A.Ş.';
$bank_iban     = $settings_raw['bank_primary_iban'] ?? 'TR00 0000 0000 0000 0000 0000 00';
$payment_note  = $settings_raw['bank_payment_note'] ?? 'Ödemelerinizde açıklama kısmına lütfen fatura veya proje kodunuzu yazınız.';
$support_email = $settings_raw['portal_support_email'] ?? 'info@rymedya.com.tr';

// Cari Bilgilerini Getir
$c_stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$c_stmt->execute([$contact_id]);
$contact = $c_stmt->fetch();

// 1. Projeleri Getir
$p_stmt = $db->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM shoots s WHERE s.project_id = p.id) as shoot_count,
           (SELECT COUNT(*) FROM project_revisions pr WHERE pr.project_id = p.id) as rev_count
    FROM projects p
    WHERE p.client_id = ?
    ORDER BY p.id DESC
");
$p_stmt->execute([$contact_id]);
$projects = $p_stmt->fetchAll();

// 2. MÜŞTERİNİN TÜM HESAP HAREKETLERİNİ ÇEK
$ledger = [];

// A. Faturalar
$inv_stmt = $db->prepare("SELECT * FROM invoices WHERE contact_id = ? ORDER BY issue_date DESC");
$inv_stmt->execute([$contact_id]);
$invoices = $inv_stmt->fetchAll();

foreach ($invoices as $inv) {
    $is_sales = ($inv['invoice_type'] === 'sales');
    $ledger[] = [
        'date'        => $inv['issue_date'],
        'doc_no'      => $inv['invoice_number'],
        'type'        => $is_sales ? 'Satış Faturası' : 'Alış / Gider Faturası',
        'is_debit'    => $is_sales,
        'amount'      => (float)$inv['grand_total'],
        'status'      => $inv['payment_status'],
        'description' => $inv['notes'] ?: ($is_sales ? 'Prodüksiyon Fatura Bedeli' : 'Gider Kaydı'),
        'pdf_link'    => BASE_URL . "/modules/finance/invoice_print.php?id={$inv['id']}"
    ];
}

// B. Manuel Borç Dekontları ve Kasa Tahsilatları
$tx_stmt = $db->prepare("SELECT * FROM transactions WHERE contact_id = ? ORDER BY transaction_date DESC");
$tx_stmt->execute([$contact_id]);
$transactions = $tx_stmt->fetchAll();

foreach ($transactions as $tx) {
    $is_income = ($tx['type'] === 'income');
    $ledger[] = [
        'date'        => $tx['transaction_date'],
        'doc_no'      => 'DKN-' . $tx['id'],
        'type'        => $is_income ? 'Tahsilat / Ödeme Makbuzu' : 'Manuel Borç Dekontu',
        'is_debit'    => !$is_income,
        'amount'      => (float)$tx['amount'],
        'status'      => 'completed',
        'description' => $tx['description'] ?: ($is_income ? 'Banka / Nakit Tahsilat' : $tx['category']),
        'pdf_link'    => null
    ];
}

usort($ledger, function($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
});
?>
<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müşteri Portalı | <?= e($contact['company_title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="h-full flex flex-col font-sans text-slate-800 antialiased bg-slate-100">

    <!-- ÜST MENÜ BAR (NAVBAR) -->
    <nav class="h-16 bg-slate-900 border-b border-slate-800 px-4 sm:px-8 flex items-center justify-between sticky top-0 z-50">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white shadow-md">
                <i data-lucide="clapperboard" class="w-5 h-5"></i>
            </div>
            <div>
                <span class="text-sm font-black text-white tracking-wide block leading-tight">RY MEDYA</span>
                <span class="text-[10px] text-indigo-400 font-bold uppercase tracking-wider">Müşteri Portalı</span>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <a href="<?= BASE_URL ?>/client/profile.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold transition">
                <i data-lucide="user-cog" class="w-4 h-4 text-indigo-400"></i>
                <span>Bilgilerim & Güvenlik</span>
            </a>

            <div class="hidden md:block text-right border-l border-slate-700 pl-3">
                <p class="text-xs font-bold text-white"><?= e($contact['company_title']) ?></p>
                <p class="text-[11px] text-slate-400"><?= e($client_user['full_name']) ?></p>
            </div>

            <a href="<?= BASE_URL ?>/client/logout.php" class="p-2 bg-slate-800 hover:bg-rose-600 text-slate-300 hover:text-white rounded-xl transition" title="Çıkış Yap">
                <i data-lucide="log-out" class="w-4 h-4"></i>
            </a>
        </div>
    </nav>

    <!-- ANA İÇERİK -->
    <main class="flex-1 max-w-6xl w-full mx-auto p-4 sm:p-8 space-y-6">
        
        <?= display_flash() ?>

        <!-- HOŞ GELDİN KARTI & MATEMATİKSEL KUSURSUZ BAKİYE -->
        <div class="bg-gradient-to-r from-slate-900 to-indigo-950 rounded-3xl p-6 sm:p-8 text-white shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
                <span class="px-3 py-1 bg-indigo-600/50 border border-indigo-400/30 rounded-full text-[10px] font-bold uppercase tracking-wider text-indigo-200">
                    Müşteri Hesabı
                </span>
                <h2 class="text-2xl font-black mt-3"><?= e($contact['company_title']) ?></h2>
                <p class="text-xs text-slate-300 mt-1">Prodüksiyon aşamalarınızı, onay bekleyen kurgularınızı ve hesap hareketlerinizi buradan takip edebilirsiniz.</p>
            </div>

            <div class="p-5 bg-white/10 backdrop-blur-md rounded-2xl border border-white/10 text-right flex flex-col justify-between">
                <span class="text-[10px] uppercase font-bold text-slate-300 tracking-wider">Güncel Net Bakiye Durumunuz</span>
                <p class="text-3xl font-black <?= $accurate_balance > 0 ? 'text-amber-400' : 'text-emerald-400' ?> mt-1">
                    <?= format_money($accurate_balance) ?>
                </p>
                <span class="text-[11px] text-slate-300 mt-1 block">
                    <?= $accurate_balance > 0 ? 'Ödeme Bekleyen Toplam Borç' : ($accurate_balance < 0 ? 'Alacaklısınız' : 'Hesabınız Dengede') ?>
                </span>
                <a href="<?= BASE_URL ?>/modules/contacts/statement_print.php?id=<?= $contact['id'] ?>" target="_blank" class="mt-3 inline-flex items-center justify-end gap-1 text-xs font-bold text-indigo-300 hover:text-white transition">
                    <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                    <span>Resmi Cari Ekstreyi İndir (PDF)</span>
                </a>
            </div>
        </div>

        <!-- 1. PROJELERİM -->
        <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
            <div class="flex items-center justify-between mb-6 pb-3 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Prodüksiyon Projeleriniz (<?= count($projects) ?>)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Çekim takvimi, kreatif süreç ve video önizlemeleri.</p>
                </div>
            </div>

            <?php if (empty($projects)): ?>
                <div class="py-12 text-center text-slate-400">
                    <i data-lucide="film" class="w-10 h-10 mx-auto mb-2 opacity-40"></i>
                    <p class="text-sm font-medium">Henüz kayıtlı bir projeniz bulunmuyor.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($projects as $p): 
                        $st = PROJECT_STATUSES[$p['status']] ?? ['label' => $p['status'], 'color' => 'bg-slate-100 text-slate-700'];
                    ?>
                    <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 flex flex-col justify-between hover:border-indigo-400 transition">
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="px-2.5 py-0.5 rounded-md text-[10px] font-mono font-bold bg-white border border-slate-200 text-slate-700">
                                    <?= e($p['project_code']) ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $st['color'] ?>">
                                    <?= $st['label'] ?>
                                </span>
                            </div>

                            <h4 class="text-base font-bold text-slate-900"><?= e($p['project_name']) ?></h4>
                            <p class="text-xs text-slate-500 mt-1">Tür: <strong><?= get_project_type_name($p['project_type']) ?></strong></p>
                            
                            <div class="flex items-center gap-4 mt-3 text-xs text-slate-600">
                                <span>🎬 <strong><?= $p['shoot_count'] ?></strong> Çekim Günü</span>
                                <span>✂️ <strong><?= $p['rev_count'] ?></strong> Kurgu Versiyonu</span>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-200 flex items-center justify-between">
                            <span class="text-xs text-slate-500">Teslim: <strong><?= format_date($p['deadline']) ?></strong></span>
                            <a href="<?= BASE_URL ?>/client/project_detail.php?id=<?= $p['id'] ?>" class="inline-flex items-center gap-1 text-xs font-bold text-indigo-600 hover:text-indigo-800">
                                <span>İncele & Video İzle</span>
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. TÜM HESAP HAREKETLERİ, BORÇLANDIRMALAR & FATURALAR DÖKÜMÜ -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Sol: Tüm Borç & Fatura Dökümü Tablosu (8 Kolon) -->
            <div class="lg:col-span-8 bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                    <div>
                        <h3 class="text-base font-bold text-slate-900">Hesap Hareketleri, Faturalar & Borç Dökümü</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Tüm faturalarınız, borç dekontları ve ödeme kayıtlarınız.</p>
                    </div>
                    <a href="<?= BASE_URL ?>/modules/contacts/statement_print.php?id=<?= $contact['id'] ?>" target="_blank" class="text-xs font-bold text-indigo-600 hover:underline flex items-center gap-1">
                        <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                        <span>Resmi Ekstre (PDF)</span>
                    </a>
                </div>

                <?php if (empty($ledger)): ?>
                    <p class="text-xs text-slate-400 py-8 text-center">Henüz bir hesap hareketi veya fatura bulunmuyor.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($ledger as $item): ?>
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-mono font-bold text-slate-900"><?= e($item['doc_no']) ?></span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $item['is_debit'] ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900' ?>">
                                        <?= e($item['type']) ?>
                                    </span>
                                </div>
                                <p class="text-slate-600 font-medium"><?= e($item['description']) ?></p>
                                <span class="text-[10px] text-slate-400 block mt-0.5">İşlem Tarihi: <?= format_date($item['date']) ?></span>
                            </div>

                            <div class="text-right flex items-center justify-end gap-3">
                                <div>
                                    <span class="font-black text-sm block <?= $item['is_debit'] ? 'text-rose-600' : 'text-emerald-600' ?>">
                                        <?= $item['is_debit'] ? '+' : '-' ?><?= format_money($item['amount']) ?>
                                    </span>
                                    <span class="text-[10px] font-bold text-slate-400 block">
                                        <?= $item['is_debit'] ? '(Borçlandınız)' : '(Ödeme / Alacak)' ?>
                                    </span>
                                </div>

                                <?php if (!empty($item['pdf_link'])): ?>
                                    <a href="<?= e($item['pdf_link']) ?>" target="_blank" class="p-2 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-xl transition" title="Fatura PDF İndir">
                                        <i data-lucide="printer" class="w-4 h-4"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sağ: Banka & Havale Bilgisi (4 Kolon) -->
            <div class="lg:col-span-4 bg-white rounded-3xl border border-slate-200 p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <i data-lucide="credit-card" class="w-4 h-4 text-indigo-600"></i>
                        <h3 class="text-sm font-bold text-slate-900">Banka & Havale Bilgileri</h3>
                    </div>
                    <p class="text-xs text-slate-500 mb-4"><?= e($payment_note) ?></p>

                    <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-2 text-xs">
                        <span class="text-[10px] font-bold text-slate-400 uppercase">Alıcı Ünvan</span>
                        <p class="font-bold text-slate-900"><?= e($bank_receiver) ?></p>
                        
                        <span class="text-[10px] font-bold text-slate-400 uppercase block pt-2">Banka & IBAN</span>
                        <p class="font-bold text-slate-800"><?= e($bank_name) ?></p>
                        <p class="font-mono text-slate-700 font-bold bg-white p-2.5 rounded-xl border border-slate-200 text-xs select-all">
                            <?= e($bank_iban) ?>
                        </p>
                    </div>
                </div>

                <p class="text-[11px] text-slate-400 text-center mt-4">
                    İletişim & Destek: <strong><?= e($support_email) ?></strong>
                </p>
            </div>

        </div>

    </main>

    <footer class="py-4 text-center text-xs text-slate-400 border-t border-slate-200 bg-white">
        &copy; <?= date('Y') ?> <?= e($settings_raw['company_name'] ?? 'RY Medya Prodüksiyon') ?>. Tüm hakları saklıdır.
    </footer>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>