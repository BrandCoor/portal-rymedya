<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - CARİ HESAPLAR LİSTESİ & KART AÇMA
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    redirect(BASE_URL . '/modules/auth/login.php');
}
require_permission('contacts.view');

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // Yeni Cari Kaydetme
    if ($action === 'create_contact') {
        require_permission('contacts.create');

        $type              = $_POST['type'] ?? 'client';
        $company_title     = trim($_POST['company_title'] ?? '');
        $authorized_person = trim($_POST['authorized_person'] ?? '');
        $phone             = trim($_POST['phone'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $tax_office        = trim($_POST['tax_office'] ?? '');
        $tax_number        = trim($_POST['tax_number'] ?? '');
        $id_number         = trim($_POST['id_number'] ?? '');
        $iban              = trim($_POST['iban'] ?? '');
        $city              = trim($_POST['city'] ?? '');
        $district          = trim($_POST['district'] ?? '');
        $address           = trim($_POST['address'] ?? '');
        $notes             = trim($_POST['notes'] ?? '');

        if (!empty($company_title)) {
            $stmt = $db->prepare("
                INSERT INTO contacts (type, company_title, authorized_person, phone, email, tax_office, tax_number, id_number, iban, city, district, address, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $type, $company_title, $authorized_person, $phone, $email,
                $tax_office, $tax_number, $id_number, $iban, $city, $district, $address, $notes
            ]);

            set_flash('success', "{$company_title} carisi başarıyla kaydedildi.");
            redirect(BASE_URL . '/modules/contacts/index.php?type=' . $type);
        }
    }

    // Cari Silme
    if ($action === 'delete_contact') {
        require_permission('contacts.delete');
        $del_id = (int)$_POST['contact_id'];
        
        $db->prepare("DELETE FROM users WHERE contact_id = ?")->execute([$del_id]);
        $db->prepare("DELETE FROM contact_change_logs WHERE contact_id = ?")->execute([$del_id]);
        $db->prepare("DELETE FROM transactions WHERE contact_id = ?")->execute([$del_id]);
        $db->prepare("DELETE FROM invoices WHERE contact_id = ?")->execute([$del_id]);

        $projs = $db->query("SELECT id FROM projects WHERE client_id = {$del_id}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($projs as $p_id) {
            $db->prepare("DELETE FROM shoots WHERE project_id = ?")->execute([$p_id]);
            $db->prepare("DELETE FROM project_revisions WHERE project_id = ?")->execute([$p_id]);
            $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$p_id]);
        }

        $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$del_id]);
        
        set_flash('success', 'Cari kartı başarıyla silindi.');
        redirect(BASE_URL . '/modules/contacts/index.php');
    }
}

// 2. FİLTRELEME & SORGULAR
$type_filter = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM contacts WHERE 1=1";
$params = [];

if (!empty($type_filter)) {
    $sql .= " AND type = ?";
    $params[] = $type_filter;
}

if (!empty($search)) {
    $sql .= " AND (company_title LIKE ? OR authorized_person LIKE ? OR phone LIKE ? OR email LIKE ? OR tax_number LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

$sql .= " ORDER BY id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll();

$stats = [
    'total'       => $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn(),
    'clients'     => $db->query("SELECT COUNT(*) FROM contacts WHERE type = 'client'")->fetchColumn(),
    'freelancers' => $db->query("SELECT COUNT(*) FROM contacts WHERE type = 'freelancer'")->fetchColumn(),
    'rentals'     => $db->query("SELECT COUNT(*) FROM contacts WHERE type = 'equipment_rental'")->fetchColumn(),
];

$page_title = 'Cari Hesap Yönetimi';
require_once __DIR__ . '/../../includes/header.php';
?>

<div x-data="{ openModal: false }">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Cari Hesap Rehberi</h1>
            <p class="text-xs text-slate-500 mt-0.5">Müşteriler, freelance set ekipleri, kiralama şirketleri ve stüdyolar.</p>
        </div>
        <?php if (has_permission('contacts.create')): ?>
        <button @click="openModal = true" class="inline-flex items-center justify-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md shadow-brand-600/20 transition cursor-pointer">
            <i data-lucide="user-plus" class="w-4 h-4"></i>
            <span>Yeni Cari Kart Aç</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- İstatistik Kartları -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <a href="<?= BASE_URL ?>/modules/contacts/index.php" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between hover:border-brand-500 transition">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Toplam Cari</p>
                <p class="text-2xl font-black text-slate-800 mt-1"><?= number_format($stats['total']) ?></p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-600 flex items-center justify-center">
                <i data-lucide="users" class="w-6 h-6"></i>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/modules/contacts/index.php?type=client" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between hover:border-brand-500 transition">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Müşteriler</p>
                <p class="text-2xl font-black text-brand-600 mt-1"><?= number_format($stats['clients']) ?></p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-brand-50 text-brand-600 flex items-center justify-center">
                <i data-lucide="briefcase" class="w-6 h-6"></i>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/modules/contacts/index.php?type=freelancer" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between hover:border-brand-500 transition">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Freelancer / Ekip</p>
                <p class="text-2xl font-black text-purple-600 mt-1"><?= number_format($stats['freelancers']) ?></p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
                <i data-lucide="camera" class="w-6 h-6"></i>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/modules/contacts/index.php?type=equipment_rental" class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between hover:border-brand-500 transition">
            <div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Ekipman & Kiralama</p>
                <p class="text-2xl font-black text-amber-600 mt-1"><?= number_format($stats['rentals']) ?></p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <i data-lucide="box" class="w-6 h-6"></i>
            </div>
        </a>
    </div>

    <!-- Filtre Çubuğu -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-6">
        <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-7 lg:col-span-8 relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </div>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Cari ünvanı, yetkili veya vergi no ara..."
                       class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:ring-2 focus:ring-brand-500">
            </div>

            <div class="sm:col-span-3 lg:col-span-3">
                <select name="type" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-700">
                    <option value="">Tüm Cari Türleri</option>
                    <?php foreach (CONTACT_TYPES as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $type_filter === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-2 lg:col-span-1 flex gap-2">
                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold py-2.5 px-3 rounded-xl transition">
                    Filtre
                </button>
            </div>
        </form>
    </div>

    <!-- Cariler Tablosu -->
    <div class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50/75 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                        <th class="py-3.5 px-4">Cari Ünvan</th>
                        <th class="py-3.5 px-4">Tür</th>
                        <th class="py-3.5 px-4">İletişim</th>
                        <th class="py-3.5 px-4">Vergi No</th>
                        <th class="py-3.5 px-4 text-right">Bakiye</th>
                        <th class="py-3.5 px-4 text-right">İşlemler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($contacts)): ?>
                        <tr><td colspan="6" class="py-10 text-center text-slate-400">Kayıtlı cari bulunamadı.</td></tr>
                    <?php else: ?>
                        <?php foreach ($contacts as $c): ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="py-3.5 px-4">
                                <a href="<?= BASE_URL ?>/modules/contacts/detail.php?id=<?= $c['id'] ?>" class="font-bold text-slate-900 hover:text-brand-600 block">
                                    <?= e($c['company_title']) ?>
                                </a>
                                <?php if (!empty($c['authorized_person'])): ?>
                                    <span class="text-[11px] text-slate-400"><?= e($c['authorized_person']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-700">
                                    <?= CONTACT_TYPES[$c['type']] ?? $c['type'] ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-slate-600"><?= e($c['phone'] ?? '-') ?></td>
                            <td class="py-3.5 px-4 font-mono text-slate-600"><?= e($c['tax_number'] ?? '-') ?></td>
                            <td class="py-3.5 px-4 text-right font-black text-slate-900"><?= format_money($c['balance']) ?></td>
                            <td class="py-3.5 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="<?= BASE_URL ?>/modules/contacts/detail.php?id=<?= $c['id'] ?>" class="p-1.5 bg-slate-100 hover:bg-brand-50 text-slate-700 hover:text-brand-600 font-bold rounded-lg text-xs transition">
                                        Ekstre
                                    </a>

                                    <?php if (has_permission('contacts.delete')): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Bu cariyi silmek istiyor musunuz?');" class="inline-block">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_contact">
                                        <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Cariyi Sil">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL: YENİ CARİ EKLE -->
    <div x-show="openModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6">
        <div class="bg-white rounded-3xl max-w-2xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden" @click.away="openModal = false">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between flex-shrink-0">
                <h3 class="text-base font-bold text-slate-900">Yeni Cari Kart Aç</h3>
                <button type="button" @click="openModal = false" class="text-slate-400 hover:text-slate-800">✕</button>
            </div>

            <form method="POST" action="" class="flex-1 overflow-y-auto p-6 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_contact">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Cari Türü *</label>
                        <select name="type" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <?php foreach (CONTACT_TYPES as $ckey => $cval): ?>
                                <option value="<?= $ckey ?>"><?= $cval ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Şirket Ünvanı / Kişi *</label>
                        <input type="text" name="company_title" required placeholder="Örn: ABC Medya A.Ş." class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Yetkili Kişi</label>
                        <input type="text" name="authorized_person" placeholder="Ad Soyad" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Telefon</label>
                        <input type="text" name="phone" placeholder="0555 000 00 00" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">E-Posta</label>
                        <input type="email" name="email" placeholder="muhasebe@sirket.com" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Vergi Dairesi</label>
                        <input type="text" name="tax_office" placeholder="Kadıköy V.D." class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Vergi No (VKN)</label>
                        <input type="text" name="tax_number" placeholder="10 Haneli VKN" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Açık Adres</label>
                    <textarea name="address" rows="2" placeholder="Fatura adresi..." class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click="openModal = false" class="px-4 py-2 text-xs font-semibold text-slate-500">İptal</button>
                    <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-bold rounded-xl text-xs shadow-md">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>