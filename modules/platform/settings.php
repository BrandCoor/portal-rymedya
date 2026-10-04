<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - KURALLAR (İŞ AKIŞI & PAZARLAMA POLİTİKASI)
 * ====================================================================
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');

// [tip, etiket, açıklama, (int için) min, max, birim]
$sections = [
    'İş akışı' => [
        'platform_auto_publish'      => ['bool', 'Katalogdan girilen işler onaysız yayına alınsın', 'Ajans katalogdan iş girdiğinde iş doğrudan havuza düşer. Kapalıyken her iş önce sizin onayınızı bekler. Özel talepler her durumda fiyat teklifi bekler.'],
        'platform_qa_required'       => ['bool', 'Freelancer teslimleri önce kalite kontrolden geçsin', 'Teslimat önce size düşer; onayladığınızda ajansa iletilir.'],
        'platform_auto_invoice'      => ['bool', 'İş tamamlanınca ajansa otomatik satış faturası kes', 'Freelancer hakediş kaydı her durumda oluşturulur.'],
        'platform_milestone_per_item' => ['bool', 'Katalog işlerinde her hizmet ayrı aşama olsun', 'Çekim, kurgu gibi kalemler ayrı ayrı teslim edilip onaylanır; onaylanan aşamanın hakedişi hemen kayda geçer. Kapalıyken iş tek aşamadır.'],
        'platform_auto_approve_days' => ['int', 'Teslim otomatik onay süresi', 'Ajans teslimi bu süre içinde onaylamaz veya revizyon istemezse aşama otomatik onaylanır. 0 ise kapalı.', 0, 60, 'gün'],
        'platform_max_revisions'     => ['int', 'Ücretsiz revizyon hakkı', 'Ajansa gösterilir; aşıldığında ek ücret uyarısı çıkar.', 0, 20, 'adet'],
        'platform_default_margin'    => ['int', 'Özel tekliflerde varsayılan marj', 'Freelancer ücreti önerisi bu marja göre hesaplanır.', 0, 90, '%'],
        'platform_freelancer_vat'    => ['int', 'Freelancer hakediş KDV oranı', 'Şahıs freelancer\'lar için genellikle 0.', 0, 30, '%'],
    ],
    'Termin ve acil işler' => [
        'platform_block_same_day'        => ['bool', 'Aynı gün başlayan işler alınmasın', 'Başlangıç (çekim) tarihi bugün olan işler reddedilir.'],
        'platform_min_lead_hours'        => ['int', 'En kısa iş giriş süresi', 'Başlangıca bu süreden az kalan işler alınmaz. Hizmet bazında daha uzun süre katalogdan tanımlanabilir.', 0, 720, 'saat'],
        'platform_warn_lead_hours'       => ['int', 'Acil iş eşiği', 'Başlangıca bu süreden az kalan işler acil sayılır; ajans uyarılır ve onay ister.', 0, 720, 'saat'],
        'platform_rush_fee_percent'      => ['int', 'Acil iş farkı', 'Acil işlerin tutarına eklenir. 0 ise fark alınmaz.', 0, 200, '%'],
        'platform_rush_freelancer_share' => ['int', 'Acil farkından freelancer payı', 'Acil iş farkının bu kadarı freelancer hakedişine prim olarak eklenir.', 0, 100, '%'],
    ],
    'Freelancer seviyeleri ve kapasite' => [
        'platform_auto_tier'      => ['bool', 'Seviyeler kurallara göre otomatik güncellensin', 'Her tamamlanan iş ve değerlendirme sonrası "Seviye kuralları" ekranındaki koşullar kontrol edilir. Elle sabitlenen seviyeler etkilenmez.'],
        'platform_tier_downgrade' => ['bool', 'Kuralları artık sağlamayanların seviyesi düşsün', 'Kapalıyken seviye yalnızca yükselir.'],
        'platform_limit_standard' => ['int', 'Standart · eşzamanlı aktif iş', 'Atanmış, üretimde, kalite kontrolde, revizyonda veya ajans onayında olan işler sayılır.', 1, 50, 'iş'],
        'platform_limit_silver'   => ['int', 'Silver · eşzamanlı aktif iş', '', 1, 50, 'iş'],
        'platform_limit_gold'     => ['int', 'Gold · eşzamanlı aktif iş', '', 1, 50, 'iş'],
        'platform_limit_elite'    => ['int', 'Elite · eşzamanlı aktif iş', '', 1, 50, 'iş'],
    ],
    'Görünürlük (pazarlama politikası)' => [
        'platform_pool_enabled'           => ['bool', 'Freelancer iş havuzu açık', 'Kapalıyken freelancer\'lar havuzu göremez; yalnızca doğrudan atadığınız işleri görür.'],
        'platform_default_priority_tier'  => ['tier', 'Yeni işlerde öncelikli seviye', 'Seçilirse yeni işler önce bu seviye ve üstüne açılır.'],
        'platform_default_priority_hours' => ['int', 'Öncelikli erişim süresi', 'Süre dolunca iş diğer uygun seviyelere de açılır.', 0, 720, 'saat'],
        'platform_show_agency_name'       => ['bool', 'Freelancer ajans adını görsün', 'Kapalıyken freelancer işin hangi ajansa ait olduğunu bilmez.'],
    ],
    'Kayıt' => [
        'platform_agency_signup'     => ['bool', 'Ajans kaydı açık', 'Giriş sayfasında "Ajans kaydı" bağlantısı görünür.'],
        'platform_freelancer_signup' => ['bool', 'Freelancer başvurusu açık', 'Giriş sayfasında "Freelancer başvurusu" bağlantısı görünür.'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'platform') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $vals = [];
    foreach ($sections as $fields) {
        foreach ($fields as $key => $def) {
            $type = $def[0];
            if ($type === 'bool') {
                $vals[$key] = isset($_POST[$key]) ? '1' : '0';
            } elseif ($type === 'tier') {
                $vals[$key] = array_key_exists($_POST[$key] ?? '', FREELANCER_TIERS) ? $_POST[$key] : '';
            } else {
                $vals[$key] = (string)max($def[3], min($def[4], (int)($_POST[$key] ?? 0)));
            }
        }
    }
    // Acil eşiği en kısa iş giriş süresinden kısa olamaz
    if ((int)$vals['platform_warn_lead_hours'] < (int)$vals['platform_min_lead_hours']) {
        $vals['platform_warn_lead_hours'] = $vals['platform_min_lead_hours'];
    }
    // Üst seviyenin limiti alt seviyeden az olamaz
    $prev = 0;
    foreach (array_keys(FREELANCER_TIERS) as $t) {
        $k = "platform_limit_{$t}";
        $vals[$k] = (string)max($prev, (int)$vals[$k]);
        $prev = (int)$vals[$k];
    }
    foreach ($vals as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    log_activity('platform', 'Platform kuralları güncellendi', null, null, '/modules/platform/settings.php');
    set_flash('success', 'Platform kuralları kaydedildi.');
    redirect(BASE_URL . '/modules/platform/settings.php');
}

$page_title = 'Platform kuralları';
require_once __DIR__ . '/../../includes/header.php';
?>
<div style="max-width:880px">
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Kurallar</span></div>
        <h1 class="h1">Platform kuralları</h1>
        <p class="sub">Genel kurallar. İş bazında görünürlük (seviye, öncelik, seçili kişiler) her işin kendi sayfasından ayrıca ayarlanır.</p>
    </div>
    <?php if (can_access_module('platform.pricing')): ?><a href="<?= BASE_URL ?>/modules/platform/catalog.php" class="btn btn-secondary"><i data-lucide="tag"></i>Hizmet kataloğu</a><?php endif; ?>
</div>

<form method="POST" action="" class="stack-lg">
    <?= csrf_field() ?>
    <?php foreach ($sections as $title => $fields): ?>
    <section class="card">
        <div class="card-head"><p class="card-title"><?= e($title) ?></p></div>
        <div class="divide">
            <?php foreach ($fields as $key => $def): [$type, $label, $help] = $def; ?>
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;align-items:center;gap:20px">
                <div style="min-width:0">
                    <p class="small" style="font-weight:500"><?= e($label) ?></p>
                    <?php if ($help !== ''): ?><p class="xsmall text-muted" style="margin-top:2px;max-width:560px"><?= e($help) ?></p><?php endif; ?>
                </div>
                <?php if ($type === 'bool'): ?>
                    <label class="switch"><input type="checkbox" name="<?= $key ?>" value="1" <?= platform_setting($key) === '1' ? 'checked' : '' ?>><span></span></label>
                <?php elseif ($type === 'tier'): ?>
                    <select class="select" name="<?= $key ?>" style="width:150px;flex-shrink:0">
                        <option value="">Yok</option>
                        <?php foreach (FREELANCER_TIERS as $tk => $tv): if ($tk === 'standard') continue; ?><option value="<?= $tk ?>" <?= platform_setting($key) === $tk ? 'selected' : '' ?>><?= e($tv['label']) ?>+</option><?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <div class="input-group" style="width:150px;flex-shrink:0">
                        <input class="input" type="number" name="<?= $key ?>" value="<?= e(platform_setting($key)) ?>" min="<?= $def[3] ?>" max="<?= $def[4] ?>" style="text-align:right">
                        <span class="addon"><?= e($def[5]) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($title === 'Freelancer seviyeleri ve kapasite'): ?>
        <div class="card-foot">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px"><p class="xsmall text-muted">Otomatik seviye kuralları:</p><a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/modules/platform/tiers.php"><i data-lucide="workflow"></i>Seviye kurallarını düzenle</a></div>
            <div class="stack-sm">
                <?php foreach (['silver', 'gold', 'elite'] as $tk): ?>
                    <div class="xsmall" style="display:flex;gap:8px;align-items:center"><?= tier_badge($tk) ?><span class="text-muted"><?= e(tier_rule_summary($tk)) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
    <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary">Kuralları kaydet</button></div>
</form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
