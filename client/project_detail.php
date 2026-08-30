<?php
/**
 * ====================================================================
 * RY MEDYA - MÜŞTERİ PROJE DETAY, ONAY & OTOMATİK FATURALANDIRMA MOTORU
 * ====================================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Müşteri Giriş Kontrolü
if (!isset($_SESSION['client_user_id']) || !isset($_SESSION['client_contact_id'])) {
    redirect(BASE_URL . '/client/login.php');
}

$contact_id = (int)$_SESSION['client_contact_id'];
$project_id = (int)($_GET['id'] ?? 0);

// Projeyi Getir (Sadece bu müşteriye ait olanı)
$stmt = $db->prepare("SELECT * FROM projects WHERE id = ? AND client_id = ?");
$stmt->execute([$project_id, $contact_id]);
$project = $stmt->fetch();

if (!$project) {
    die("Yetkisiz erişim veya proje bulunamadı!");
}

// 1. MÜŞTERİDEN REVİZYON VEYA ONAY GELMESİ (POST HANDLER)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ====================================================================
    // A. KURGUYU ONAYLAMA (OTOMATİK SATIŞ FATURASI VE CARİLEŞTİRME MOTORU)
    // ====================================================================
    if ($action === 'approve_cut') {
        $revision_id = (int)$_POST['revision_id'];

        // 1. Revizyonu Onaylandı Yap
        $up = $db->prepare("UPDATE project_revisions SET status = 'approved' WHERE id = ? AND project_id = ?");
        $up->execute([$revision_id, $project_id]);

        // 2. Bu Projeye Daha Önce Satış Faturası Kesilmiş mi Kontrol Et
        $chk_inv = $db->prepare("SELECT id FROM invoices WHERE project_id = ? AND invoice_type = 'sales' LIMIT 1");
        $chk_inv->execute([$project_id]);
        $existing_inv = $chk_inv->fetch();

        if (!$existing_inv) {
            // Taban Bütçe
            $base_budget = (float)$project['agreed_budget'];

            // Sette Müşteriye Yansıtılacak (Bütçeye Hariç) Ek Giderleri Topla
            $rebillable_cost = (float)$db->query("
                SELECT COALESCE(SUM(scg.agreed_fee), 0) 
                FROM shoot_crew_gear scg 
                JOIN shoots s ON scg.shoot_id = s.id 
                WHERE s.project_id = {$project_id} AND scg.is_rebillable = 1
            ")->fetchColumn();

            // Faturalandırılacak Toplam Matrah
            $final_subtotal = $base_budget + $rebillable_cost;

            // KDV Oranı (Sistem Ayarlarından veya Varsayılan %20)
            $settings_raw = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $vat_rate = (float)($settings_raw['default_vat_rate'] ?? 20);
            $inv_prefix = $settings_raw['invoice_prefix'] ?? 'RYM-';

            $tax = calculate_tax_breakdown($final_subtotal, $vat_rate, '0/10', 0);
            $auto_inv_no = $inv_prefix . date('Y') . '-' . str_pad((string)$project['id'], 4, '0', STR_PAD_LEFT);

            // Resmi Satış Faturasını Oluştur
            $ins_inv = $db->prepare("
                INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at)
                VALUES ('sales', ?, ?, ?, CURRENT_DATE(), ?, ?, ?, '0/10', 0, 0, 0, ?, 'unpaid', ?, NOW())
            ");
            $ins_inv->execute([
                $auto_inv_no, $contact_id, $project_id,
                $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'],
                "{$project['project_name']} onaylanan prodüksiyon ve teslimat faturası"
            ]);

            // Müşteri Carisine KDV Dahil Genel Toplamı Borç Yaz
            $db->prepare("UPDATE contacts SET balance = balance + ? WHERE id = ?")->execute([$tax['grand_total'], $contact_id]);

            // Projeyi Faturalandırıldı Durumuna Getir
            $db->prepare("UPDATE projects SET status = 'invoiced' WHERE id = ?")->execute([$project_id]);

            set_flash('success', 'Tebrikler! Kurgu versiyonunu onayladınız. Projeniz tamamlandı, resmi faturanız (' . format_money($tax['grand_total']) . ') oluşturularak hesabınıza işlendi.');
        } else {
            // Fatura zaten varsa projeyi tamamlandı yap
            $db->prepare("UPDATE projects SET status = 'invoiced' WHERE id = ?")->execute([$project_id]);
            set_flash('success', 'Kurgu versiyonu onaylandı.');
        }

        redirect(BASE_URL . "/client/project_detail.php?id={$project_id}");
    }

    // ====================================================================
    // B. REVİZYON TALEBİ GÖNDERME
    // ====================================================================
    if ($action === 'submit_revision') {
        $revision_id = (int)$_POST['revision_id'];
        $feedback    = trim($_POST['feedback_notes'] ?? '');

        if (!empty($feedback)) {
            $up = $db->prepare("UPDATE project_revisions SET status = 'revision_requested', feedback_notes = ? WHERE id = ? AND project_id = ?");
            $up->execute([$feedback, $revision_id, $project_id]);

            $db->prepare("UPDATE projects SET status = 'revision' WHERE id = ?")->execute([$project_id]);

            set_flash('success', 'Revizyon talebiniz ve notlarınız prodüksiyon ekibimize iletildi.');
            redirect(BASE_URL . "/client/project_detail.php?id={$project_id}");
        }
    }
}

// Çekim Günlerini Getir
$shoots = $db->prepare("SELECT * FROM shoots WHERE project_id = ? ORDER BY shoot_date ASC");
$shoots->execute([$project_id]);
$shoot_list = $shoots->fetchAll();

// Kurgu Versiyonlarını Getir
$revs = $db->prepare("SELECT * FROM project_revisions WHERE project_id = ? ORDER BY id DESC");
$revs->execute([$project_id]);
$revisions = $revs->fetchAll();

// Varsa Projeye Kesilen Satış Faturasını Getir
$inv_stmt = $db->prepare("SELECT * FROM invoices WHERE project_id = ? AND invoice_type = 'sales' LIMIT 1");
$inv_stmt->execute([$project_id]);
$project_invoice = $inv_stmt->fetch();

$st = PROJECT_STATUSES[$project['status']] ?? ['label' => $project['status'], 'color' => 'bg-slate-100 text-slate-700'];
?>
<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($project['project_name']) ?> | Müşteri Portalı</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="h-full flex flex-col font-sans text-slate-800 antialiased bg-slate-100">

    <!-- ÜST MENÜ -->
    <nav class="h-16 bg-slate-900 border-b border-slate-800 px-4 sm:px-8 flex items-center justify-between sticky top-0 z-50">
        <a href="<?= BASE_URL ?>/client/index.php" class="flex items-center gap-2 text-white text-xs font-bold hover:text-indigo-300 transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>Portal Ana Sayfasına Dön</span>
        </a>
        <span class="font-mono text-xs font-bold text-indigo-400 bg-slate-800 px-3 py-1 rounded-lg border border-slate-700">
            <?= e($project['project_code']) ?>
        </span>
    </nav>

    <!-- ANA İÇERİK -->
    <main class="flex-1 max-w-5xl w-full mx-auto p-4 sm:p-8 space-y-6">
        
        <?= display_flash() ?>

        <!-- PROJE BAŞLIĞI VE DURUM KARTI -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $st['color'] ?>">
                    <?= $st['label'] ?>
                </span>
                <h1 class="text-2xl font-black text-slate-900 mt-2"><?= e($project['project_name']) ?></h1>
                <p class="text-xs text-slate-500 mt-1">Tür: <strong><?= get_project_type_name($project['project_type']) ?></strong> | Planlanan Teslim: <strong><?= format_date($project['deadline']) ?></strong></p>
            </div>

            <!-- Fatura Bilgisi Varsa Göster -->
            <?php if ($project_invoice): ?>
            <div class="p-4 bg-emerald-50 rounded-2xl border border-emerald-200 text-right flex items-center gap-4">
                <div>
                    <span class="text-[10px] uppercase font-bold text-emerald-800 block">Resmi Proje Faturası</span>
                    <span class="text-base font-black text-emerald-950"><?= format_money($project_invoice['grand_total']) ?></span>
                </div>
                <a href="<?= BASE_URL ?>/modules/finance/invoice_print.php?id=<?= $project_invoice['id'] ?>" target="_blank" class="p-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl shadow-xs transition" title="Fatura PDF Yazdır">
                    <i data-lucide="printer" class="w-4 h-4"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- 1. POST-PRODÜKSİYON / KURGU İZLEME & ONAY MASASI -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Kurgu Versiyonları & Video Önizlemeleri</h3>
                    <p class="text-xs text-slate-500">Ajansımız tarafından yüklenen kurguları izleyebilir, onaylayabilir veya revizyon notu yazabilirsiniz.</p>
                </div>
            </div>

            <?php if (empty($revisions)): ?>
                <div class="py-12 text-center text-slate-400 border-2 border-dashed border-slate-200 rounded-2xl">
                    <i data-lucide="film" class="w-8 h-8 mx-auto mb-1 opacity-50"></i>
                    <p class="text-xs font-medium">İlk kurgu versiyonu hazırlanıyor.</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Ekibimiz kurguyu tamamladığında video önizleme linki burada belirecektir.</p>
                </div>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($revisions as $rev): ?>
                    <div class="p-6 bg-slate-50 rounded-2xl border border-slate-200 space-y-4" x-data="{ showRevisionForm: false }">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-900 text-sm"><?= e($rev['version_title']) ?></span>
                                    <?php if ($rev['status'] === 'approved'): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">✓ ONAYLANDI</span>
                                    <?php elseif ($rev['status'] === 'revision_requested'): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-orange-100 text-orange-800 border border-orange-300">Revizyon İstendi</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800 border border-indigo-300">İncelemenize Sunuldu</span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1 block">Yüklenme Tarihi: <?= format_date($rev['created_at'], true) ?></span>
                            </div>

                            <!-- Aksiyon Butonları -->
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if (!empty($rev['preview_url'])): ?>
                                    <a href="<?= e($rev['preview_url']) ?>" target="_blank" class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-sm transition">
                                        <i data-lucide="play" class="w-4 h-4"></i>
                                        <span>Videoyu İzle / Önizle</span>
                                    </a>
                                <?php endif; ?>

                                <?php if ($rev['status'] !== 'approved'): ?>
                                    <!-- Kurguyu Onayla Butonu (Otomatik Fatura ve Cari Tetikleyici) -->
                                    <form method="POST" action="" onsubmit="return confirm('Bu kurgu versiyonunu onaylamak istiyor musunuz?\n\n- Proje tamamlanacak ve faturası oluşturulacaktır.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve_cut">
                                        <input type="hidden" name="revision_id" value="<?= $rev['id'] ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-sm transition cursor-pointer">
                                            <i data-lucide="check" class="w-4 h-4"></i>
                                            <span>Kurguyu Onayla</span>
                                        </button>
                                    </form>

                                    <!-- Revizyon İste Butonu -->
                                    <button @click="showRevisionForm = !showRevisionForm" class="inline-flex items-center gap-1 bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold py-2.5 px-4 rounded-xl border border-slate-200 transition cursor-pointer">
                                        <i data-lucide="message-square-plus" class="w-4 h-4"></i>
                                        <span>Revizyon İste</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Revizyon Formu -->
                        <div x-show="showRevisionForm" x-cloak class="pt-4 border-t border-slate-200">
                            <form method="POST" action="" class="space-y-3">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="submit_revision">
                                <input type="hidden" name="revision_id" value="<?= $rev['id'] ?>">

                                <label class="block text-xs font-bold text-slate-700">Değişiklik / Revizyon Notlarınız:</label>
                                <textarea name="feedback_notes" rows="3" required placeholder="Örn: 00:14 saniyedeki geçiş hızlandırılsın..."
                                          class="w-full p-3 bg-white border border-slate-300 rounded-xl text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500"></textarea>

                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="showRevisionForm = false" class="px-4 py-2 text-xs font-semibold text-slate-500">Vazgeç</button>
                                    <button type="submit" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold rounded-xl shadow-md transition">
                                        Revizyon Notunu Gönder
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. ÇEKİM GÜNLERİ VE MEKANLAR -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm">
            <h3 class="text-base font-bold text-slate-900 mb-4 pb-2 border-b border-slate-100">Çekim Takvimi & Set Günleri</h3>
            <?php if (empty($shoot_list)): ?>
                <p class="text-xs text-slate-400 py-4 text-center">Planlanmış çekim seti bulunmuyor.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach ($shoot_list as $sh): ?>
                    <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-xs">
                        <div class="flex items-center justify-between font-bold text-slate-900 mb-1">
                            <span><?= e($sh['title']) ?></span>
                            <span class="text-indigo-600"><?= format_date($sh['shoot_date']) ?></span>
                        </div>
                        <p class="text-slate-600 flex items-center gap-1 mt-1">
                            <i data-lucide="map-pin" class="w-3.5 h-3.5 text-slate-400"></i>
                            <strong><?= e($sh['location_name']) ?></strong>
                        </p>
                        <p class="text-[11px] text-slate-500 mt-0.5"><?= e($sh['location_address']) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>