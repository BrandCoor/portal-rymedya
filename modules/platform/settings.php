<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - POLİTİKA AYARLARI (PAZARLAMA KURALLARI)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');

$fields = [
    'platform_pool_enabled'          => ['bool', 'Freelancer iş havuzu açık', 'Kapalıyken freelancer\'lar havuzu göremez; yalnızca doğrudan atadığınız işleri görürler.'],
    'platform_freelancer_signup'     => ['bool', 'Freelancer başvurusu açık', 'Giriş sayfasında "Freelancer Başvurusu" butonu görünür.'],
    'platform_agency_signup'         => ['bool', 'Ajans kaydı açık', 'Giriş sayfasında "Ajans Kaydı" butonu görünür.'],
    'platform_show_agency_name'      => ['bool', 'Freelancer ajans adını görsün', 'Kapalıyken freelancer işin hangi ajansa ait olduğunu bilmez (müşteri koruması).'],
    'platform_qa_required'           => ['bool', 'Freelancer teslimleri önce kalite kontrolden geçsin', 'Açıkken teslimat önce size düşer; onayladığınızda ajansa iletilir.'],
    'platform_auto_invoice'          => ['bool', 'İş tamamlanınca ajansa otomatik satış faturası kes', 'Freelancer hakediş kaydı her durumda oluşturulur.'],
    'platform_max_active_jobs'       => ['int', 'Freelancer başına eşzamanlı iş limiti', 'Bir freelancer aynı anda en fazla bu kadar iş alabilir.'],
    'platform_default_margin'        => ['int', 'Varsayılan platform marjı (%)', 'Freelancer ücreti önerisi bu marja göre hesaplanır.'],
    'platform_default_priority_hours'=> ['int', 'Yeni işlerde öncelikli erişim süresi (saat)', 'İşe öncelikli seviye seçildiğinde, diğer seviyeler bu süre dolunca işi görür.'],
    'platform_max_revisions'         => ['int', 'Ücretsiz revizyon hakkı', 'Ajansa gösterilir; aşıldığında ek ücret uyarısı çıkar.'],
    'platform_freelancer_vat'        => ['int', 'Freelancer hakediş KDV oranı (%)', 'Şahıs freelancer\'lar için genellikle 0.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'platform') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($fields as $key => [$type]) {
        $val = $type === 'bool' ? (isset($_POST[$key]) ? '1' : '0') : (string)max(0, min(1000, (int)($_POST[$key] ?? 0)));
        $stmt->execute([$key, $val]);
    }
    log_activity('platform', 'Platform politika ayarları güncellendi', null, null, '/modules/platform/settings.php');
    set_flash('success', 'Platform politikası kaydedildi.');
    redirect(BASE_URL . '/modules/platform/settings.php');
}

$page_title = 'Platform Politika Ayarları';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="max-w-3xl mx-auto">
    <a href="<?= BASE_URL ?>/modules/platform/index.php" class="text-xs font-semibold text-slate-500">← İş Platformu</a>
    <h1 class="text-2xl font-black text-slate-900">Platform Politika Ayarları</h1>
    <p class="text-xs text-slate-500 mb-5">Genel kurallar. İş bazında görünürlük (seviye, öncelik, seçili freelancer'lar) her işin kendi sayfasından ayrıca ayarlanır.</p>

    <form method="POST" action="" class="bg-white border border-slate-200 rounded-3xl divide-y divide-slate-100">
        <?= csrf_field() ?>
        <?php foreach ($fields as $key => [$type, $label, $help]): ?>
        <div class="p-5 flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-bold text-slate-900"><?= e($label) ?></p>
                <p class="text-xs text-slate-500"><?= e($help) ?></p>
            </div>
            <?php if ($type === 'bool'): ?>
                <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                    <input type="checkbox" name="<?= $key ?>" value="1" <?= platform_setting($key) === '1' ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-200 rounded-full peer-checked:bg-emerald-500 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-5"></div>
                </label>
            <?php else: ?>
                <input type="number" name="<?= $key ?>" value="<?= e(platform_setting($key)) ?>" min="0" class="w-24 py-2 px-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-right font-bold flex-shrink-0">
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="p-5 flex justify-end"><button class="px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white text-sm font-bold rounded-xl">Kaydet</button></div>
    </form>

    <div class="mt-6 p-5 bg-slate-900 text-slate-200 rounded-3xl text-xs space-y-2">
        <p class="font-bold text-white text-sm">Pazarlama politikası nasıl uygulanır?</p>
        <p>• <strong>Seviye (tier):</strong> Freelancer'lara Standart / Silver / Gold / Elite seviyesi verin. İşlerde "asgari seviye" seçerek değerli işleri yalnızca üst seviyelere açın.</p>
        <p>• <strong>Öncelikli erişim:</strong> Bir işi örneğin ilk 24 saat yalnızca Gold+ seviyeye gösterin; süre dolunca diğerlerine açılır. Bu, iyi çalışanları ödüllendirir.</p>
        <p>• <strong>Seçili liste:</strong> Bir işi yalnızca güvendiğiniz birkaç kişiye özel yayınlayın.</p>
        <p>• <strong>Gizli (ekibe özel):</strong> İş hiçbir freelancer'a görünmez; siz üstlenir veya doğrudan atarsınız.</p>
        <p>• <strong>Uzmanlık / şehir eşleşmesi:</strong> Çekim işlerini sadece o şehirdeki kameramanlara gösterin.</p>
        <p>• İş sayfasındaki <strong>"Bu işi kimler görüyor?"</strong> listesinden kuralların etkisini anında görebilirsiniz.</p>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
