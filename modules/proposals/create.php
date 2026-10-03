<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - FİYAT TEKLİFİ HAZIRLAMA & DÜZENLEME (DİNAMİK CARİ LİSTELİ)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('proposals.manage');

// Düzenleme modu (?id=)
$edit_id = (int)($_GET['id'] ?? 0);
$proposal = null;
if ($edit_id > 0) {
    $p_stmt = $db->prepare("SELECT * FROM proposals WHERE id = ?");
    $p_stmt->execute([$edit_id]);
    $proposal = $p_stmt->fetch();
    if (!$proposal) {
        set_flash('error', 'Teklif bulunamadı.');
        redirect(BASE_URL . '/modules/proposals/index.php');
    }
    if (!empty($proposal['converted_project_id'])) {
        set_flash('error', 'Projeye dönüştürülmüş bir teklif düzenlenemez.');
        redirect(BASE_URL . '/modules/proposals/index.php');
    }
}

// ====================================================================
// 1. TEKLİF KAYDETME / GÜNCELLEME İŞLEMİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_proposal' || $action === 'update_proposal') {
        $client_id      = (int)($_POST['client_id'] ?? 0);
        $title          = trim($_POST['title'] ?? '');
        $project_type   = $_POST['project_type'] ?? 'commercial';
        $workflow_model = $_POST['workflow_model'] ?? ($proposal['workflow_model'] ?? 'internal_full');
        $subtotal       = parse_money($_POST['subtotal'] ?? '0');
        $vat_rate       = (float)($_POST['vat_rate'] ?? 20);
        $currency       = array_key_exists($_POST['currency'] ?? '', CURRENCIES) ? $_POST['currency'] : 'TRY';
        $valid_until    = valid_date($_POST['valid_until'] ?? '', date('Y-m-d', strtotime('+15 days')));
        $scope_items    = trim($_POST['scope_items'] ?? '');
        $terms          = trim($_POST['terms'] ?? '');

        $form_url = BASE_URL . '/modules/proposals/create.php' . ($proposal ? '?id=' . (int)$proposal['id'] : '');

        $has_items = (bool)array_filter((array)($_POST['item_description'] ?? []), fn($d) => trim((string)$d) !== '');
        if ($client_id <= 0 || $title === '' || (!$has_items && $subtotal <= 0)) {
            set_flash('error', 'Lütfen geçerli bir müşteri, teklif başlığı ve tutar (veya fiyat kalemleri) giriniz.');
            redirect($form_url);
        }

        // Kalemler varsa matrah kalemlerin toplamıdır
        $items = [];
        $descs = $_POST['item_description'] ?? [];
        foreach ((array)$descs as $i => $desc) {
            $desc = trim((string)$desc);
            if ($desc === '') {
                continue;
            }
            $qty   = parse_money($_POST['item_quantity'][$i] ?? '1') ?: 1;
            $price = parse_money($_POST['item_unit_price'][$i] ?? '0');
            $items[] = [
                'description' => mb_substr($desc, 0, 255),
                'quantity'    => $qty,
                'unit'        => mb_substr(trim((string)($_POST['item_unit'][$i] ?? 'Adet')) ?: 'Adet', 0, 30),
                'unit_price'  => $price,
                'line_total'  => round($qty * $price, 2),
            ];
        }
        if ($items) {
            $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
            if ($subtotal <= 0) {
                set_flash('error', 'Teklif kalemlerinin toplamı sıfırdan büyük olmalıdır.');
                redirect($form_url);
            }
        }

        $tax = calculate_tax_breakdown($subtotal, $vat_rate, '0/10', 0);

        $save_items = function (int $pid) use ($db, $items) {
            $db->prepare("DELETE FROM proposal_items WHERE proposal_id = ?")->execute([$pid]);
            $ins = $db->prepare("INSERT INTO proposal_items (proposal_id, description, quantity, unit, unit_price, line_total, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($items as $i => $it) {
                $ins->execute([$pid, $it['description'], $it['quantity'], $it['unit'], $it['unit_price'], $it['line_total'], $i]);
            }
        };

        if ($action === 'update_proposal' && $proposal) {
            $db->prepare("
                UPDATE proposals SET client_id = ?, title = ?, project_type = ?, workflow_model = ?, subtotal = ?, vat_rate = ?, grand_total = ?, currency = ?, valid_until = ?, scope_items = ?, terms = ?
                WHERE id = ?
            ")->execute([
                $client_id, $title, $project_type, $workflow_model, $tax['subtotal'], $tax['vat_rate'], $tax['grand_total'],
                $currency, $valid_until, $scope_items, $terms, $proposal['id']
            ]);
            $save_items((int)$proposal['id']);
            set_flash('success', "{$proposal['proposal_code']} numaralı teklif güncellendi.");
        } else {
            // Otomatik Teklif Kodu (Örn: TKL-2026-0001) - silinen teklifler sonrası çakışmaz
            $proposal_code = generate_proposal_code();

            $db->prepare("
                INSERT INTO proposals (proposal_code, client_id, title, project_type, workflow_model, subtotal, vat_rate, grand_total, currency, valid_until, status, scope_items, terms, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, NOW())
            ")->execute([
                $proposal_code, $client_id, $title, $project_type, $workflow_model,
                $tax['subtotal'], $tax['vat_rate'], $tax['grand_total'], $currency, $valid_until,
                $scope_items, $terms, $user['id']
            ]);
            $save_items((int)$db->lastInsertId());
            log_activity('proposal_created', "Yeni teklif hazırlandı: {$proposal_code} · {$title} (" . format_money($tax['grand_total'], $currency) . ")", 'proposal', null, '/modules/proposals/index.php');
            set_flash('success', "{$proposal_code} numaralı fiyat teklifi oluşturuldu.");
        }
        redirect(BASE_URL . '/modules/proposals/index.php');
    }
}

// 2. TÜM CARİLERİ ÇEKME (MÜŞTERİ + DİĞER TÜRLER GRUPLANMIŞ)
$clients = $db->query("SELECT id, company_title, authorized_person, type FROM contacts ORDER BY company_title ASC")->fetchAll();
$project_types = get_project_types();

// Mevcut kalemler
$existing_items = [];
if ($proposal) {
    $it = $db->prepare("SELECT description, quantity, unit, unit_price FROM proposal_items WHERE proposal_id = ? ORDER BY sort_order, id");
    $it->execute([$proposal['id']]);
    foreach ($it->fetchAll() as $row) {
        $existing_items[] = ['description' => $row['description'], 'quantity' => (float)$row['quantity'], 'unit' => $row['unit'], 'unit_price' => (float)$row['unit_price']];
    }
}

// Form varsayılanları
$fv = [
    'client_id'    => (int)($proposal['client_id'] ?? ($_GET['client_id'] ?? 0)),
    'title'        => $proposal['title'] ?? '',
    'project_type' => $proposal['project_type'] ?? '',
    'currency'     => $proposal['currency'] ?? get_setting('default_currency', 'TRY'),
    'valid_until'  => $proposal['valid_until'] ?? date('Y-m-d', strtotime('+15 days')),
    'scope_items'  => $proposal['scope_items'] ?? '',
    'subtotal'     => isset($proposal['subtotal']) ? (float)$proposal['subtotal'] : 0,
    'vat_rate'     => isset($proposal['vat_rate']) ? (int)$proposal['vat_rate'] : (int)get_setting('default_vat_rate', '20'),
    'terms'        => $proposal['terms'] ?? '',
];

$page_title = $proposal ? 'Teklifi Düzenle' : 'Yeni Fiyat Teklifi Hazırla';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto" x-data="{
        subtotal: <?= js_val($fv['subtotal']) ?>,
        vatRate: '<?= $fv['vat_rate'] ?>',
        items: <?= js_val($existing_items) ?>,
        addItem(d = '', u = 'Adet') { this.items.push({ description: d, quantity: 1, unit: u, unit_price: 0 }); },
        itemsTotal() { return this.items.reduce((s, i) => s + (parseFloat(i.quantity) || 0) * (parseFloat(i.unit_price) || 0), 0); },
        effectiveSubtotal() { return this.items.length ? this.itemsTotal() : (parseFloat(this.subtotal) || 0); },
        fmt(v) { return v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    }">
    <div class="mb-6 flex items-center justify-between">
        <a href="<?= BASE_URL ?>/modules/proposals/index.php" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900 bg-white border border-slate-200 py-2 px-3.5 rounded-xl shadow-sm transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>Tekliflere Dön</span>
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm">
        <div class="mb-6 pb-4 border-b border-slate-100">
            <h2 class="text-lg font-bold text-slate-900"><?= $proposal ? e($proposal['proposal_code']) . ' Teklifini Düzenle' : 'Yeni Fiyat Teklifi & Kapsam Dökümü Oluştur' ?></h2>
            <p class="text-xs text-slate-500 mt-0.5">Müşteriye sunulacak çekim, kurgu, teslimat şartları ve bütçe detayları.</p>
        </div>

        <form method="POST" action="" class="space-y-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $proposal ? 'update_proposal' : 'create_proposal' ?>">

            <!-- Satır 1: Müşteri Seçimi (TÜM CARİLER LİSTELENİR) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold uppercase text-slate-600">Teklif Verilecek Cari / Müşteri *</label>
                        <a href="<?= BASE_URL ?>/modules/contacts/index.php" target="_blank" class="text-[11px] font-semibold text-brand-600 hover:underline">+ Yeni Cari Aç</a>
                    </div>
                    <select name="client_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500">
                        <option value="">-- Müşteri veya Cari Seçin --</option>
                        
                        <!-- MÜŞTERİLER GRUBU -->
                        <optgroup label="MÜŞTERİLER">
                            <?php foreach ($clients as $c): if ($c['type'] === 'client'): ?>
                                <option value="<?= $c['id'] ?>" <?= $fv['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['company_title']) ?> <?= !empty($c['authorized_person']) ? '(' . e($c['authorized_person']) . ')' : '' ?></option>
                            <?php endif; endforeach; ?>
                        </optgroup>

                        <!-- DİĞER TÜM CARİLER -->
                        <optgroup label="DİĞER CARİLER (Tedarikçi, Freelancer vb.)">
                            <?php foreach ($clients as $c): if ($c['type'] !== 'client'): ?>
                                <option value="<?= $c['id'] ?>" <?= $fv['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['company_title']) ?> (<?= e(CONTACT_TYPES[$c['type']] ?? $c['type']) ?>)</option>
                            <?php endif; endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Teklif Başlığı *</label>
                    <input type="text" name="title" required value="<?= e($fv['title']) ?>" placeholder="Örn: 2026 Dijital Reklam Filmi ve Sosyal Medya Kurgu Paketi" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Prodüksiyon Türü</label>
                    <select name="project_type" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                        <?php foreach ($project_types as $k => $name): ?>
                            <option value="<?= e($k) ?>" <?= $fv['project_type'] === $k ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Teklif Para Birimi</label>
                    <select name="currency" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                        <?php foreach (CURRENCIES as $c => $n): ?>
                            <option value="<?= $c ?>" <?= $fv['currency'] === $c ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Teklif Geçerlilik Tarihi</label>
                    <input type="date" name="valid_until" value="<?= e($fv['valid_until']) ?>" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
            </div>

            <!-- Hizmet Kalemleri & Kapsam Dökümü -->
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Teklif Kapsamı & Hizmet Kalemleri (PDF'te Çıkacak)</label>
                <textarea name="scope_items" rows="4" placeholder="- 1 Gün 4K Sinema Kamerası ve Işık Ekibi ile Set Çekimi&#10;- 2 Adet Master Reklam Kurgusu (60sn & 30sn)&#10;- 4 Adet Instagram Reels / TikTok Dikey Format Video&#10;- Profesyonel Ses Tasarımı, Color Grading & Lisanslı Müzik"
                          class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900"><?= e($fv['scope_items']) ?></textarea>
            </div>

            <!-- Fiyat Kalemleri -->
            <div class="p-5 bg-white rounded-2xl border border-slate-200 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700">Fiyat Kalemleri (Opsiyonel)</label>
                        <p class="text-[11px] text-slate-400">Kalem eklerseniz teklif fiyatı kalemlerin toplamından otomatik hesaplanır ve PDF'te tablo olarak çıkar.</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-[11px]">
                        <?php foreach (['Çekim Günü (Ekip + Ekipman)' => 'Gün', 'Kurgu / Montaj' => 'Adet', 'Color Grading' => 'Adet', 'Ses Tasarımı & Miksaj' => 'Adet', 'Drone Çekimi' => 'Gün', 'Dikey Reels Versiyonu' => 'Adet'] as $preset => $unit): ?>
                            <button type="button" @click="addItem(<?= js_val($preset) ?>, <?= js_val($unit) ?>)" class="px-2 py-1 bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 rounded-lg font-semibold text-slate-600">+ <?= e($preset) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <template x-for="(item, idx) in items" :key="idx">
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <input type="text" name="item_description[]" x-model="item.description" placeholder="Hizmet / kalem açıklaması" class="col-span-12 sm:col-span-5 py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                        <input type="number" step="0.01" min="0" name="item_quantity[]" x-model="item.quantity" class="col-span-3 sm:col-span-1 py-2 px-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-right">
                        <input type="text" name="item_unit[]" x-model="item.unit" class="col-span-3 sm:col-span-1 py-2 px-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                        <input type="number" step="0.01" min="0" name="item_unit_price[]" x-model="item.unit_price" placeholder="Birim fiyat" class="col-span-4 sm:col-span-2 py-2 px-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-right font-bold">
                        <span class="col-span-1 sm:col-span-2 text-right text-xs font-black text-slate-900" x-text="fmt((parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0))"></span>
                        <button type="button" @click="items.splice(idx, 1)" class="col-span-1 text-slate-300 hover:text-rose-600 text-center">✕</button>
                    </div>
                </template>

                <div class="flex items-center justify-between">
                    <button type="button" @click="addItem()" class="text-xs font-bold text-indigo-600 hover:underline">+ Boş Kalem Ekle</button>
                    <span x-show="items.length" class="text-xs font-bold text-slate-700">Kalemler Toplamı: <span class="font-black text-slate-900" x-text="fmt(itemsTotal())"></span></span>
                </div>
            </div>

            <!-- Bütçe & KDV -->
            <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Teklif Fiyatı (KDV Hariç Matrah) *</label>
                        <input type="number" step="0.01" name="subtotal" x-model="subtotal" :required="!items.length" :disabled="items.length > 0" :placeholder="items.length ? 'Kalemlerden hesaplanıyor' : '0.00'" class="w-full py-2.5 px-3.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">KDV Oranı</label>
                        <select name="vat_rate" x-model="vatRate" class="w-full py-2.5 px-3 bg-white border border-slate-300 rounded-xl text-xs font-bold">
                            <?php foreach (VAT_RATES as $vr): ?>
                                <option value="<?= $vr ?>">%<?= $vr ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="p-3 bg-white rounded-xl border border-slate-200 flex justify-between items-center text-xs font-bold">
                    <span>Müşteriye Sunulacak Toplam Tutar (KDV Dahil):</span>
                    <span class="text-base font-black text-indigo-600"
                          x-text="fmt(effectiveSubtotal() * (1 + (parseFloat(vatRate) / 100)))"></span>
                </div>
            </div>

            <!-- Teklif Şartları -->
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Ödeme & Telif Şartları</label>
                <textarea name="terms" rows="2" placeholder="%50 Peşinat sözleşme imzalanmasında, %50 bakiye final video tesliminde tahsil edilir. Fiyatlar 15 gün geçerlidir."
                          class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900"><?= e($fv['terms']) ?></textarea>
            </div>

            <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
                <a href="<?= BASE_URL ?>/modules/proposals/index.php" class="px-5 py-2.5 text-xs font-semibold text-slate-500 hover:text-slate-700">İptal</a>
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-xs shadow-md shadow-indigo-600/30 transition">
                    <?= $proposal ? '✓ Değişiklikleri Kaydet' : '✓ Teklifi Oluştur & Kaydet' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>