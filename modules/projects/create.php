<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - YENİ PROJE OLUŞTURMA & OPERASYON İŞ MODELİ
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    redirect(BASE_URL . '/modules/auth/login.php');
}
require_permission('projects.create');

// Self-Healing DB
try {
    $db->query("SELECT workflow_model FROM projects LIMIT 1");
} catch (Exception $e) {
    $db->query("
        ALTER TABLE `projects` 
        ADD COLUMN `workflow_model` ENUM('internal_full', 'external_edit_only', 'external_shoot_only', 'outsource_full', 'outsource_edit', 'outsource_shoot') DEFAULT 'internal_full' AFTER `project_type`,
        ADD COLUMN `outsource_contact_id` INT UNSIGNED NULL AFTER `workflow_model`
    ");
}

// Operasyon Modelleri
const WORKFLOW_MODELS = [
    'internal_full'       => ['label' => '🏢 Ajans İçi Tam Prodüksiyon (Çekim + Kurgu Bizden)', 'color' => 'bg-emerald-50 text-emerald-800'],
    'external_edit_only'  => ['label' => '✂️ Yalnızca Kurgu / Edit Hizmeti (Çekim Müşteriden)', 'color' => 'bg-purple-50 text-purple-800'],
    'external_shoot_only' => ['label' => '🎬 Yalnızca Çekim Hizmeti (Kurgu Müşteride)', 'color' => 'bg-blue-50 text-blue-800'],
    'outsource_full'      => ['label' => '🤝 Dış Ekip / Taşeron Prodüksiyon (Dış Çekim & Dış Edit)', 'color' => 'bg-amber-50 text-amber-800'],
    'outsource_edit'      => ['label' => '👥 Çekim Bizden, Edit Dış Kurgucudan (Taşeron Edit)', 'color' => 'bg-cyan-50 text-cyan-800'],
    'outsource_shoot'     => ['label' => '🎥 Çekim Dış Ekipten, Edit Bizden (Taşeron Çekim)', 'color' => 'bg-rose-50 text-rose-800']
];

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // A. Yeni Tür Ekle
    if ($action === 'add_project_type') {
        $type_name = trim($_POST['new_type_name'] ?? '');
        if (!empty($type_name)) {
            $type_key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', 'ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'], ['_', 'i', 'g', 'u', 's', 'o', 'c', 'i', 'g', 'u', 's', 'o', 'c'], $type_name)));
            $db->prepare("INSERT IGNORE INTO project_types (type_key, type_name) VALUES (?, ?)")->execute([$type_key, $type_name]);
            set_flash('success', "Yeni prodüksiyon türü '{$type_name}' eklendi.");
            redirect(BASE_URL . '/modules/projects/create.php');
        }
    }

    // B. Türü Sil
    if ($action === 'delete_project_type') {
        $del_key = $_POST['type_key'] ?? '';
        if (!empty($del_key)) {
            $db->prepare("DELETE FROM project_types WHERE type_key = ?")->execute([$del_key]);
            set_flash('success', 'Prodüksiyon türü kaldırıldı.');
            redirect(BASE_URL . '/modules/projects/create.php');
        }
    }

    // C. Yeni Projeyi Kaydetme
    if ($action === 'create_project') {
        $client_id            = (int)($_POST['client_id'] ?? 0);
        $new_client           = trim($_POST['new_client_title'] ?? '');
        $project_name         = trim($_POST['project_name'] ?? '');
        $project_code         = trim($_POST['project_code'] ?? generate_project_code());
        $project_type         = $_POST['project_type'] ?? 'commercial';
        $workflow_model       = $_POST['workflow_model'] ?? 'internal_full';
        $outsource_contact_id = !empty($_POST['outsource_contact_id']) ? (int)$_POST['outsource_contact_id'] : null;
        $status               = $_POST['status'] ?? 'pre_production';
        $agreed_budget        = (float)str_replace(['.', ','], ['', '.'], $_POST['agreed_budget'] ?? '0');
        $currency             = $_POST['currency'] ?? 'TRY';
        $start_date           = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $deadline             = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $description          = trim($_POST['description'] ?? '');

        if ($client_id === 0 && !empty($new_client)) {
            $c_stmt = $db->prepare("INSERT INTO contacts (type, company_title, created_at) VALUES ('client', ?, NOW())");
            $c_stmt->execute([$new_client]);
            $client_id = (int)$db->lastInsertId();
        }

        if ($client_id > 0 && !empty($project_name)) {
            $stmt = $db->prepare("
                INSERT INTO projects (client_id, project_code, project_name, project_type, workflow_model, outsource_contact_id, status, agreed_budget, currency, start_date, deadline, description, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $client_id, $project_code, $project_name, $project_type, $workflow_model, $outsource_contact_id, $status,
                $agreed_budget, $currency, $start_date, $deadline, $description, $user['id']
            ]);

            $new_project_id = $db->lastInsertId();
            set_flash('success', "{$project_name} projesi başarıyla başlatıldı!");
            redirect(BASE_URL . "/modules/projects/detail.php?id={$new_project_id}");
        } else {
            set_flash('error', 'Lütfen geçerli bir müşteri ve proje adı giriniz.');
            redirect(BASE_URL . '/modules/projects/create.php');
        }
    }
}

// 2. VERİLERİ ÇEKME
$clients = $db->query("SELECT id, company_title, authorized_person, type FROM contacts ORDER BY company_title ASC")->fetchAll();
$freelancers = $db->query("SELECT id, company_title, type FROM contacts WHERE type IN ('freelancer', 'supplier', 'equipment_rental', 'studio') ORDER BY company_title ASC")->fetchAll();
$project_types = get_project_types();
$auto_code = generate_project_code();

$page_title = 'Yeni Proje Başlat';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto" x-data="{ openTypeModal: false, selectedModel: 'internal_full' }">
    <div class="mb-6 flex items-center justify-between">
        <a href="<?= BASE_URL ?>/modules/projects/index.php" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900 bg-white border border-slate-200 py-2 px-3.5 rounded-xl shadow-sm transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            <span>Projelere Dön</span>
        </a>
        <span class="text-xs font-mono font-bold text-brand-600 bg-brand-50 border border-brand-200 px-3 py-1.5 rounded-lg">
            Kod: <?= e($auto_code) ?>
        </span>
    </div>

    <div class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm">
        <div class="mb-6 pb-4 border-b border-slate-100">
            <h2 class="text-lg font-bold text-slate-900">Yeni Prodüksiyon Projesi Tanımla</h2>
            <p class="text-xs text-slate-500 mt-0.5">Müşteri, iş modeli, bütçe ve operasyon detaylarını belirleyin.</p>
        </div>

        <form method="POST" action="" class="space-y-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_project">

            <!-- Satır 1: Müşteri & Kod -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold uppercase text-slate-600">Cari / Müşteri Seçin *</label>
                        <a href="<?= BASE_URL ?>/modules/contacts/index.php" target="_blank" class="text-[11px] font-semibold text-brand-600 hover:underline">+ Yeni Cari Aç</a>
                    </div>
                    <select name="client_id" required class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500">
                        <option value="">-- Müşteri veya Cari Seçiniz --</option>
                        <optgroup label="MÜŞTERİLER">
                            <?php foreach ($clients as $c): if ($c['type'] === 'client'): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['company_title']) ?> <?= !empty($c['authorized_person']) ? "({$c['authorized_person']})" : '' ?></option>
                            <?php endif; endforeach; ?>
                        </optgroup>
                        <optgroup label="DİĞER CARİLER">
                            <?php foreach ($clients as $c): if ($c['type'] !== 'client'): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['company_title']) ?> (<?= CONTACT_TYPES[$c['type']] ?? $c['type'] ?>)</option>
                            <?php endif; endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Proje Kodu</label>
                    <input type="text" name="project_code" value="<?= e($auto_code) ?>" required class="w-full py-2.5 px-3 bg-slate-100 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-700">
                </div>
            </div>

            <!-- Satır 2: OPERASYON & İŞ MODELİ (YENİ EKLENDİ) -->
            <div class="p-4 bg-indigo-50/70 border border-indigo-200 rounded-2xl space-y-3">
                <label class="block text-xs font-bold uppercase text-indigo-950">Operasyon & Üretim Modeli (İşin Yapılış Şekli) *</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <select name="workflow_model" x-model="selectedModel" class="w-full py-2.5 px-3 bg-white border border-indigo-300 rounded-xl text-xs font-bold text-indigo-950 focus:ring-2 focus:ring-indigo-500">
                            <?php foreach (WORKFLOW_MODELS as $wm_key => $wm_val): ?>
                                <option value="<?= $wm_key ?>"><?= $wm_val['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Taşeron / Dış Ekip Seçimi (Sadece outsource modellerde görünür) -->
                    <div x-show="selectedModel.includes('outsource')">
                        <select name="outsource_contact_id" class="w-full py-2.5 px-3 bg-white border border-amber-300 rounded-xl text-xs font-semibold text-amber-950">
                            <option value="">-- Taşeron / Dış Ekip Carisi Seçin (Opsiyonel) --</option>
                            <?php foreach ($freelancers as $fl): ?>
                                <option value="<?= $fl['id'] ?>"><?= e($fl['company_title']) ?> (<?= CONTACT_TYPES[$fl['type']] ?? $fl['type'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Satır 3: Proje Adı & Prodüksiyon Türü -->
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-4">
                <div class="sm:col-span-7">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Proje Adı *</label>
                    <input type="text" name="project_name" required placeholder="Örn: 2026 Yaz Lansmanı Reklam Filmi veya Podcast Kurgusu" class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:ring-2 focus:ring-brand-500">
                </div>

                <div class="sm:col-span-5">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold uppercase text-slate-600">Prodüksiyon Türü *</label>
                        <button type="button" @click="openTypeModal = true" class="text-[11px] font-bold text-brand-600 hover:text-brand-800 transition">
                            ⚙️ Tür Ekle / Sil
                        </button>
                    </div>
                    <select name="project_type" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500">
                        <?php foreach ($project_types as $t_key => $t_title): ?>
                            <option value="<?= e($t_key) ?>"><?= e($t_title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Satır 4: Bütçe, Para Birimi & Durum -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Anlaşılan Bütçe (KDV Hariç) *</label>
                    <input type="number" step="0.01" name="agreed_budget" placeholder="0.00" required class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Para Birimi</label>
                    <select name="currency" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800">
                        <?php foreach (CURRENCIES as $code => $name): ?>
                            <option value="<?= $code ?>"><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Başlangıç Durumu</label>
                    <select name="status" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800">
                        <?php foreach (PROJECT_STATUSES as $st_key => $st_val): ?>
                            <option value="<?= $st_key ?>"><?= $st_val['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Satır 5: Tarihler -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Başlangıç Tarihi</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Planlanan Teslim Tarihi (Deadline)</label>
                    <input type="date" name="deadline" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800">
                </div>
            </div>

            <!-- Satır 6: Brief & Notlar -->
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Proje Notları & Kreatif Brief</label>
                <textarea name="description" rows="3" placeholder="Yönetmen notları, kurgu revizyon beklentileri..." class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800"></textarea>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <a href="<?= BASE_URL ?>/modules/projects/index.php" class="px-5 py-2.5 text-xs font-semibold text-slate-600 hover:text-slate-800 bg-slate-100 rounded-xl transition">İptal</a>
                <button type="submit" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold py-2.5 px-6 rounded-xl shadow-md shadow-brand-600/30 transition">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Projeyi Oluştur ve Masaya Geç</span>
                </button>
            </div>
        </form>
    </div>

    <!-- MODAL: TÜRLERİ YÖNET -->
    <div x-show="openTypeModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200" @click.away="openTypeModal = false">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                <h3 class="text-base font-bold text-slate-900">Prodüksiyon Türlerini Yönet</h3>
                <button type="button" @click="openTypeModal = false" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" action="" class="mb-6 p-4 bg-brand-50/60 border border-brand-200 rounded-2xl space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_project_type">

                <label class="block text-xs font-bold uppercase text-brand-900">Yeni Tür Adı Ekle</label>
                <div class="flex gap-2">
                    <input type="text" name="new_type_name" required placeholder="Örn: YouTube Podcast veya Moda Çekimi" class="flex-1 py-2 px-3 bg-white border border-brand-300 rounded-xl text-xs text-slate-900">
                    <button type="submit" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold rounded-xl shadow-xs transition">+ Ekle</button>
                </div>
            </form>

            <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                <?php foreach ($project_types as $k => $name): ?>
                <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                    <span class="font-semibold text-slate-800"><?= e($name) ?></span>
                    <?php if ($k !== 'other'): ?>
                    <form method="POST" action="" onsubmit="return confirm('Türü silmek istiyor musunuz?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_project_type">
                        <input type="hidden" name="type_key" value="<?= e($k) ?>">
                        <button type="submit" class="text-slate-400 hover:text-rose-600 p-1"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="pt-4 border-t border-slate-100 flex justify-end">
                <button type="button" @click="openTypeModal = false" class="px-5 py-2 bg-slate-900 text-white text-xs font-bold rounded-xl">Tamamla / Kapat</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>