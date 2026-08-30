<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - KAPSAMLI SİSTEM, FİNANS, BANKA & PORTAL AYARLARI
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    redirect(BASE_URL . '/modules/auth/login.php');
}
require_permission('settings.manage');

// ====================================================================
// 1. TÜM FORM İŞLEMLERİ (HEADER'DAN ÖNCE ÇALIŞIR - ASLA BOŞ EKRAN VERMEZ)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_all_settings') {
        $settings_to_save = [
            // 1. Şirket & Künye
            'company_name'             => trim($_POST['company_name'] ?? ''),
            'company_brand_name'       => trim($_POST['company_brand_name'] ?? ''),
            'company_email'            => trim($_POST['company_email'] ?? ''),
            'company_phone'            => trim($_POST['company_phone'] ?? ''),
            'company_address'          => trim($_POST['company_address'] ?? ''),
            'company_city'             => trim($_POST['company_city'] ?? ''),
            'company_tax_office'       => trim($_POST['company_tax_office'] ?? ''),
            'company_tax_number'       => trim($_POST['company_tax_number'] ?? ''),
            'company_trade_registry'   => trim($_POST['company_trade_registry'] ?? ''),

            // 2. Banka & IBAN
            'bank_primary_name'        => trim($_POST['bank_primary_name'] ?? ''),
            'bank_primary_receiver'    => trim($_POST['bank_primary_receiver'] ?? ''),
            'bank_primary_iban'        => trim($_POST['bank_primary_iban'] ?? ''),
            'bank_secondary_name'      => trim($_POST['bank_secondary_name'] ?? ''),
            'bank_secondary_iban'      => trim($_POST['bank_secondary_iban'] ?? ''),
            'bank_payment_note'        => trim($_POST['bank_payment_note'] ?? ''),

            // 3. Finans & Vergi Parametreleri
            'default_currency'         => $_POST['default_currency'] ?? 'TRY',
            'default_vat_rate'         => trim($_POST['default_vat_rate'] ?? '20'),
            'corporate_tax_rate'       => trim($_POST['corporate_tax_rate'] ?? '25'),
            'invoice_prefix'           => trim($_POST['invoice_prefix'] ?? 'RYM-'),
            'project_prefix'           => trim($_POST['project_prefix'] ?? 'PRJ-'),

            // 4. Müşteri Portalı & Operasyon
            'portal_support_email'     => trim($_POST['portal_support_email'] ?? ''),
            'portal_support_phone'     => trim($_POST['portal_support_phone'] ?? ''),
            'callsheet_default_notes'  => trim($_POST['callsheet_default_notes'] ?? '')
        ];

        $stmt = $db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_group) 
            VALUES (?, ?, 'master')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($settings_to_save as $key => $val) {
            $stmt->execute([$key, $val]);
        }

        set_flash('success', 'Tüm sistem, banka IBAN ve şirket ayarları başarıyla kaydedildi.');
        redirect(BASE_URL . '/modules/settings/index.php');
    }
}

// 2. VERİLERİ ÇEKME
$settings_raw = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

if (!function_exists('get_conf')) {
    function get_conf(string $key, string $default = ''): string {
        global $settings_raw;
        return $settings_raw[$key] ?? $default;
    }
}

$page_title = 'Sistem & Şirket Yapılandırması';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-5xl mx-auto" x-data="{ activeTab: 'company' }">
    <!-- Üst Başlık & Eylemler -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Sistem & Ajans Yapılandırma Masası</h1>
            <p class="text-xs text-slate-500 mt-1">Banka IBAN'ları, şirket künyesi, PDF dökümleri ve müşteri portalı kuralları.</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="<?= BASE_URL ?>/modules/settings/roles.php" class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold py-2.5 px-4 rounded-xl shadow-md transition cursor-pointer">
                <i data-lucide="shield-check" class="w-4 h-4"></i>
                <span>Roller & Yetkiler (RBAC)</span>
            </a>
        </div>
    </div>

    <!-- Sekme Başlıkları -->
    <div class="flex flex-wrap border-b border-slate-200 mb-6 gap-4 bg-white p-2 rounded-2xl shadow-xs border border-slate-200">
        <button @click="activeTab = 'company'" :class="activeTab === 'company' ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'" class="py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center gap-2">
            <i data-lucide="building-2" class="w-4 h-4"></i>
            <span>1. Şirket & Künye</span>
        </button>

        <button @click="activeTab = 'bank'" :class="activeTab === 'bank' ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'" class="py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center gap-2">
            <i data-lucide="credit-card" class="w-4 h-4"></i>
            <span>2. Banka & IBAN Bilgileri</span>
        </button>

        <button @click="activeTab = 'finance'" :class="activeTab === 'finance' ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'" class="py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center gap-2">
            <i data-lucide="calculator" class="w-4 h-4"></i>
            <span>3. Finans, Vergi & Kodlar</span>
        </button>

        <button @click="activeTab = 'portal'" :class="activeTab === 'portal' ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'" class="py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center gap-2">
            <i data-lucide="monitor" class="w-4 h-4"></i>
            <span>4. Müşteri Portalı & Set Kuralları</span>
        </button>
    </div>

    <!-- Form Kartı -->
    <form method="POST" action="" class="space-y-6">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_all_settings">

        <!-- ==================================================================== -->
        <!-- TAB 1: ŞİRKET & KÜNYE BİLGİLERİ -->
        <!-- ==================================================================== -->
        <div x-show="activeTab === 'company'" class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm space-y-5">
            <div class="pb-3 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Ajans Resmi Künyesi</h3>
                <p class="text-xs text-slate-400 mt-0.5">Fatura başlıklarında, cari ekstrelerde ve sözleşmelerde çıkacak resmi bilgiler.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Şirket Resmi Ünvanı *</label>
                    <input type="text" name="company_name" value="<?= e(get_conf('company_name', 'RY Medya Prodüksiyon A.Ş.')) ?>" required
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:ring-2 focus:ring-brand-500">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Marka / Ticari Adı</label>
                    <input type="text" name="company_brand_name" value="<?= e(get_conf('company_brand_name', 'RY Medya')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Resmi İletişim E-Postası *</label>
                    <input type="email" name="company_email" value="<?= e(get_conf('company_email', 'info@rymedya.com.tr')) ?>" required
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Telefon Numarası *</label>
                    <input type="text" name="company_phone" value="<?= e(get_conf('company_phone', '+90 (212) 000 00 00')) ?>" required
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Şehir / İlçe</label>
                    <input type="text" name="company_city" value="<?= e(get_conf('company_city', 'İstanbul / Beşiktaş')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Vergi Dairesi</label>
                    <input type="text" name="company_tax_office" value="<?= e(get_conf('company_tax_office', 'Beşiktaş V.D.')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Vergi Numarası (VKN)</label>
                    <input type="text" name="company_tax_number" value="<?= e(get_conf('company_tax_number', '1234567890')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Ticaret Sicil No</label>
                    <input type="text" name="company_trade_registry" value="<?= e(get_conf('company_trade_registry', '123456-5')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-900">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Merkez Açık Adresi</label>
                <textarea name="company_address" rows="2"
                          class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900"><?= e(get_conf('company_address', 'Levent Mah. Cömert Sk. No: 1 Beşiktaş / İstanbul')) ?></textarea>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- TAB 2: BANKA & IBAN BİLGİLERİ -->
        <!-- ==================================================================== -->
        <div x-show="activeTab === 'bank'" x-cloak class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm space-y-5">
            <div class="pb-3 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Şirket Banka Hesapları & IBAN Masası</h3>
                <p class="text-xs text-slate-400 mt-0.5">Müşteri portalında ve kesilen faturalarda gösterilecek resmi havale hesapları.</p>
            </div>

            <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 space-y-4">
                <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider">1. Ana Ticari Banka Hesabı (Varsayılan TL)</h4>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Banka Adı & Şube *</label>
                        <input type="text" name="bank_primary_name" value="<?= e(get_conf('bank_primary_name', 'Garanti BBVA - Levent Ticari Şubesi')) ?>" required
                               class="w-full py-2.5 px-3.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-900">
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Hesap Sahibi / Alıcı Ünvan *</label>
                        <input type="text" name="bank_primary_receiver" value="<?= e(get_conf('bank_primary_receiver', 'RY MEDYA PRODÜKSİYON A.Ş.')) ?>" required
                               class="w-full py-2.5 px-3.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-900">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Ana IBAN Numarası (TR...) *</label>
                    <input type="text" name="bank_primary_iban" value="<?= e(get_conf('bank_primary_iban', 'TR00 0000 0000 0000 0000 0000 00')) ?>" required
                           class="w-full py-2.5 px-3.5 bg-white border border-slate-300 rounded-xl text-xs font-mono font-bold text-slate-900">
                </div>
            </div>

            <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 space-y-4">
                <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider">2. İkincil / Döviz Hesabı (USD / EUR / Alternatif)</h4>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Banka & Para Birimi</label>
                        <input type="text" name="bank_secondary_name" value="<?= e(get_conf('bank_secondary_name', 'QNB Finansbank (USD/EUR Hesabı)')) ?>"
                               class="w-full py-2.5 px-3.5 bg-white border border-slate-300 rounded-xl text-xs text-slate-900">
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">İkincil IBAN</label>
                        <input type="text" name="bank_secondary_iban" value="<?= e(get_conf('bank_secondary_iban', 'TR00 0000 0000 0000 0000 0000 01')) ?>"
                               class="w-full py-2.5 px-3.5 bg-white border border-slate-300 rounded-xl text-xs font-mono text-slate-900">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Müşteriye Gösterilecek Havale Notu</label>
                <textarea name="bank_payment_note" rows="2"
                          class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900"><?= e(get_conf('bank_payment_note', 'Ödemelerinizde açıklama kısmına lütfen Fatura veya Proje Kodunuzu yazınız.')) ?></textarea>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- TAB 3: FİNANS, VERGİ & KODLAR -->
        <!-- ==================================================================== -->
        <div x-show="activeTab === 'finance'" x-cloak class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm space-y-5">
            <div class="pb-3 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Finans & Vergi Parametreleri</h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Varsayılan Para Birimi</label>
                    <select name="default_currency" class="w-full py-2.5 px-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800">
                        <?php foreach (CURRENCIES as $ck => $cn): ?>
                            <option value="<?= $ck ?>" <?= get_conf('default_currency', 'TRY') === $ck ? 'selected' : '' ?>><?= $cn ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Varsayılan KDV Oranı (%)</label>
                    <input type="number" name="default_vat_rate" value="<?= e(get_conf('default_vat_rate', '20')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Kurumlar Vergisi (%)</label>
                    <input type="number" name="corporate_tax_rate" value="<?= e(get_conf('corporate_tax_rate', '25')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Otomatik Proje Kod Ön Eki</label>
                    <input type="text" name="project_prefix" value="<?= e(get_conf('project_prefix', 'PRJ-')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Otomatik Fatura No Ön Eki</label>
                    <input type="text" name="invoice_prefix" value="<?= e(get_conf('invoice_prefix', 'RYM-')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold text-slate-900">
                </div>
            </div>
        </div>

        <!-- ==================================================================== -->
        <!-- TAB 4: MÜŞTERİ PORTALI VE CALL SHEET DİREKTİFLERİ -->
        <!-- ==================================================================== -->
        <div x-show="activeTab === 'portal'" x-cloak class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm space-y-5">
            <div class="pb-3 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Müşteri Portalı İletişimi & Set Kuralları</h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Müşteri Destek / WhatsApp Hattı</label>
                    <input type="text" name="portal_support_phone" value="<?= e(get_conf('portal_support_phone', '+90 555 000 00 00')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Müşteri Destek E-Postası</label>
                    <input type="email" name="portal_support_email" value="<?= e(get_conf('portal_support_email', 'destek@rymedya.com.tr')) ?>"
                           class="w-full py-2.5 px-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1.5">Call Sheet (Çağrı Kağıdı) Varsayılan Kurallar Metni</label>
                <textarea name="callsheet_default_notes" rows="3"
                          class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900"><?= e(get_conf('callsheet_default_notes', 'Tüm set ekibinin çağrı saatinden en geç 15 dakika önce sette hazır bulunması rica olunur. Güvenlik ve gizlilik kurallarına riayet ediniz.')) ?></textarea>
            </div>
        </div>

        <!-- Kaydet Butonu -->
        <div class="flex items-center justify-end pt-4">
            <button type="submit" class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-3 px-8 rounded-xl shadow-lg shadow-brand-600/30 transition cursor-pointer">
                <i data-lucide="check" class="w-4 h-4"></i>
                <span>Tüm Ayarları ve IBAN Bilgilerini Kaydet</span>
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>