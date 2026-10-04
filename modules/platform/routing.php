<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - OTOMATİK YÖNLENDİRME KURALLARI
 * ====================================================================
 * Yeni giren işin "Aksiyon bekleyen" listesine mi düşeceği, doğrudan
 * atamaya mı gideceği veya ekibe mi ayrılacağı; tutar, acil durum,
 * termin, iş türü, hizmet, ajans, şehir, anahtar kelime gibi koşullara
 * göre yönetici tarafından belirlenir. İlk eşleşen kural uygulanır.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');
$self = BASE_URL . '/modules/platform/routing.php';

/** Formdan gelen kuralı temizler */
function routing_from_post(array $in, string $id): array {
    $num = fn($v) => ($v = trim(str_replace(['.', ','], ['', '.'], (string)$v))) === '' ? '' : max(0, (float)$v);
    $pick = fn($v, array $allowed) => in_array((string)$v, $allowed, true) ? (string)$v : '';
    $cond = [];
    foreach (array_keys(ROUTE_NUM_CONDITIONS) as $k) {
        $v = $num($in['cond'][$k] ?? '');
        if ($v !== '') $cond[$k] = $v;
    }
    $c = (array)($in['cond'] ?? []);
    foreach (['source' => ['catalog', 'custom'], 'rush' => ['yes', 'no'], 'location' => ['remote', 'onsite']] as $k => $allowed) {
        if (($v = $pick($c[$k] ?? '', $allowed)) !== '') $cond[$k] = $v;
    }
    if (!empty($c['overdue'])) $cond['overdue'] = 1;
    $cats = array_values(array_intersect((array)($c['categories'] ?? []), array_keys(JOB_CATEGORIES)));
    if ($cats) $cond['categories'] = $cats;
    $svcs = array_values(array_filter(array_map('intval', (array)($c['services'] ?? []))));
    if ($svcs) $cond['services'] = $svcs;
    $ags = array_values(array_filter(array_map('intval', (array)($c['agencies'] ?? []))));
    if ($ags) $cond['agencies'] = $ags;
    $cities = array_values(array_unique(array_filter(array_map('normalize_city', (array)($c['cities'] ?? [])))));
    if ($cities) $cond['cities'] = implode(', ', $cities);
    $v = implode(', ', routing_list($c['keywords'] ?? ''));
    if ($v !== '') $cond['keywords'] = mb_substr($v, 0, 300);
    $a = (array)($in['action'] ?? []);
    $action = [
        'route'          => array_key_exists($a['route'] ?? '', ROUTE_ACTIONS) ? $a['route'] : 'review',
        'visibility'     => $pick($a['visibility'] ?? '', ['pool', 'internal']),
        'dispatch'       => $pick($a['dispatch'] ?? '', array_keys(JOB_DISPATCH)),
        'min_tier'       => $pick($a['min_tier'] ?? '', array_keys(FREELANCER_TIERS)),
        'priority_tier'  => $pick($a['priority_tier'] ?? '', ['none', 'silver', 'gold', 'elite']),
        'priority_hours' => ($a['priority_hours'] ?? '') === '' ? '' : max(0, min(720, (int)$a['priority_hours'])),
        'skill_match'    => $pick($a['skill_match'] ?? '', ['0', '1']),
        'city_match'     => $pick($a['city_match'] ?? '', ['0', '1']),
        'notify'         => !empty($a['notify']) ? 1 : 0,
    ];
    return [
        'id' => preg_match('/^[a-f0-9]{8}$/', $id) ? $id : bin2hex(random_bytes(4)),
        'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 80),
        'enabled' => !empty($in['enabled']) ? 1 : 0,
        'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 200),
        'cond' => $cond, 'action' => $action,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $rules = routing_rules();
    $idx = null;
    foreach ($rules as $i => $r) if (($r['id'] ?? '') === ($_POST['id'] ?? '')) $idx = $i;

    if ($action === 'save') {
        $rule = routing_from_post((array)($_POST['rule'] ?? []), (string)($_POST['id'] ?? ''));
        if ($rule['name'] === '') {
            set_flash('error', 'Kurala bir ad verin.');
            redirect($self);
        }
        if ($idx === null) {
            $pos = $_POST['position'] ?? 'end';
            $pos === 'start' ? array_unshift($rules, $rule) : $rules[] = $rule;
        } else {
            $rules[$idx] = $rule;
        }
        routing_save($rules);
        log_activity('platform', "Yönlendirme kuralı kaydedildi: {$rule['name']}", null, null, '/modules/platform/routing.php');
        set_flash('success', "\"{$rule['name']}\" kaydedildi.");
        redirect($self . '#r-' . $rule['id']);
    }
    if ($idx !== null && in_array($action, ['up', 'down'], true)) {
        $to = $action === 'up' ? $idx - 1 : $idx + 1;
        if (isset($rules[$to])) {
            [$rules[$idx], $rules[$to]] = [$rules[$to], $rules[$idx]];
            routing_save($rules);
        }
        redirect($self);
    }
    if ($idx !== null && $action === 'toggle') {
        $rules[$idx]['enabled'] = empty($rules[$idx]['enabled']) ? 1 : 0;
        routing_save($rules);
        redirect($self);
    }
    if ($idx !== null && $action === 'delete') {
        $name = $rules[$idx]['name'];
        array_splice($rules, $idx, 1);
        routing_save($rules);
        log_activity('platform', "Yönlendirme kuralı silindi: {$name}", null, null, '/modules/platform/routing.php');
        set_flash('success', "\"{$name}\" silindi.");
        redirect($self);
    }
    if ($action === 'fallback') {
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('platform_auto_publish', ?, 'platform') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([isset($_POST['auto_publish']) ? '1' : '0']);
        get_settings(true);
        set_flash('success', 'Genel kural kaydedildi.');
        redirect($self);
    }
    if ($action === 'presets' && !$rules) {
        $mk = function (string $name, array $cond, array $act, string $note = '') {
            return ['id' => bin2hex(random_bytes(4)), 'name' => $name, 'enabled' => 1, 'note' => $note, 'cond' => $cond,
                    'action' => $act + ['route' => 'review', 'visibility' => '', 'dispatch' => '', 'min_tier' => '', 'priority_tier' => '', 'priority_hours' => '', 'skill_match' => '', 'city_match' => '', 'notify' => 0]];
        };
        routing_save([
            $mk('Vadesi geçmiş borcu olan ajans', ['overdue' => 1], ['route' => 'review', 'notify' => 1], 'Ödeme durumunu kontrol edin.'),
            $mk('Yüksek tutarlı işler', ['price_min' => 50000], ['route' => 'review', 'dispatch' => 'application', 'min_tier' => 'gold'], 'Teklif toplayıp deneyimli freelancer seçin.'),
            $mk('Yeni ajansın ilk işleri', ['agency_jobs_max' => 0], ['route' => 'review'], 'İlk işte brief ve tarihleri ajansla teyit edin.'),
            $mk('Acil işler önce üst seviyeye', ['rush' => 'yes', 'source' => 'catalog'], ['route' => 'publish', 'priority_tier' => 'gold', 'priority_hours' => 4, 'notify' => 1]),
            $mk('Küçük katalog işleri doğrudan atamaya', ['source' => 'catalog', 'price_max' => 15000], ['route' => 'publish']),
        ]);
        set_flash('success', 'Örnek kurallar eklendi; tutarları kendinize göre düzenleyin.');
        redirect($self);
    }
    redirect($self);
}

$rules = routing_rules();
$services = catalog_services(false);
$agencies = $db->query("SELECT c.id, c.company_title FROM contacts c WHERE c.type = 'agency' ORDER BY c.company_title")->fetchAll();
$edit_id = $_GET['edit'] ?? '';

// Son işler üzerinde önizleme: bugün girilseler hangi kurala uyarlardı
$recent = $db->query("SELECT j.*, c.company_title AS agency_name FROM platform_jobs j LEFT JOIN contacts c ON c.id = j.agency_contact_id ORDER BY j.id DESC LIMIT 20")->fetchAll();
$preview = [];
foreach ($recent as $j) {
    $items = job_items((int)$j['id']);
    [$route, , $rule] = routing_decide([
        'source' => $j['pricing_source'], 'price' => $j['agency_price'] ?? $j['budget'], 'fee' => $j['freelancer_fee'], 'rush' => (int)$j['is_rush'] === 1,
        'start_date' => $j['start_date'], 'deadline' => $j['deadline'], 'items' => $items, 'category' => $j['category'],
        'is_remote' => (int)$j['is_remote'] === 1, 'city' => $j['location_city'], 'agency_id' => (int)$j['agency_contact_id'],
        'title' => $j['title'], 'description' => $j['description'],
    ], default_job_policy($items, $j['category'], (int)$j['is_remote'] === 1));
    $preview[] = ['job' => $j, 'route' => $route, 'rule' => $rule];
}
$used = [];
foreach ($preview as $p) if ($p['rule']) $used[$p['rule']['id']] = ($used[$p['rule']['id']] ?? 0) + 1;

$opt = fn(array $list, string $cur, string $empty = 'Değiştirme') => '<option value="">' . e($empty) . '</option>' . implode('', array_map(fn($k, $v) => '<option value="' . e((string)$k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($list), $list));

$page_title = 'Yönlendirme kuralları';
require_once __DIR__ . '/../../includes/header.php';

/** Kural düzenleme formu */
function routing_form(array $r, bool $is_new, array $services, array $agencies, callable $opt): void {
    $c = $r['cond'] ?? []; $a = $r['action'] ?? [];
    $v = fn($k) => isset($c[$k]) && $c[$k] !== '' ? e(rtrim(rtrim(number_format((float)$c[$k], 2, ',', ''), '0'), ',')) : '';
    ?>
    <form method="POST" action="" class="card-pad stack" style="border-top:1px solid var(--line-2)"><?= csrf_field() ?>
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($is_new ? '' : $r['id']) ?>">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="field sm:col-span-2"><label class="label">Kural adı</label><input class="input" name="rule[name]" value="<?= e($r['name']) ?>" required placeholder="ör. 50.000 ₺ üzeri işler onaya düşsün"></div>
            <div class="field"><label class="label">Durum</label><label class="check small" style="height:38px"><input type="checkbox" name="rule[enabled]" value="1" <?= !empty($r['enabled']) ? 'checked' : '' ?>>Kural aktif</label></div>
        </div>

        <p class="eyebrow" style="margin-top:6px">Koşullar <span class="text-faint" style="text-transform:none;letter-spacing:0">— boş bırakılan aranmaz; doldurulanların tamamı sağlanmalı</span></p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach (ROUTE_NUM_CONDITIONS as $k => [$l, $u, $op]): ?>
                <div class="field"><label class="label"><?= e($l) ?> <span class="text-faint"><?= e($op) ?></span></label>
                    <div class="input-group"><input class="input" inputmode="decimal" name="rule[cond][<?= $k ?>]" value="<?= $v($k) ?>" placeholder="—" style="text-align:right"><span class="addon" style="min-width:46px;justify-content:center"><?= e($u) ?></span></div></div>
            <?php endforeach; ?>
            <div class="field"><label class="label">İş tipi</label><select class="select" name="rule[cond][source]"><?= $opt(['catalog' => 'Katalogdan iş', 'custom' => 'Özel teklif talebi'], (string)($c['source'] ?? ''), 'Hepsi') ?></select></div>
            <div class="field"><label class="label">Acil iş</label><select class="select" name="rule[cond][rush]"><?= $opt(['yes' => 'Yalnızca acil', 'no' => 'Acil olmayan'], (string)($c['rush'] ?? ''), 'Hepsi') ?></select></div>
            <div class="field"><label class="label">Yer</label><select class="select" name="rule[cond][location]"><?= $opt(['onsite' => 'Yerinde (çekim vb.)', 'remote' => 'Uzaktan'], (string)($c['location'] ?? ''), 'Hepsi') ?></select></div>
            <div class="field"><label class="label">Başlık / brief'te geçen kelime</label><input class="input" name="rule[cond][keywords]" value="<?= e($c['keywords'] ?? '') ?>" placeholder="ör. canlı yayın, gece"></div>
            <div class="field"><label class="label">Ajans ödemesi</label><label class="check small" style="height:38px"><input type="checkbox" name="rule[cond][overdue]" value="1" <?= !empty($c['overdue']) ? 'checked' : '' ?>>Vadesi geçmiş borcu varsa</label></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="field"><label class="label">İş türü <span class="text-faint">(biri)</span></label>
                <div class="panel" style="padding:8px 10px;max-height:160px;overflow-y:auto"><?php foreach (JOB_CATEGORIES as $ck => $cv): ?><label class="check xsmall" style="display:flex"><input type="checkbox" name="rule[cond][categories][]" value="<?= $ck ?>" <?= in_array($ck, (array)($c['categories'] ?? []), true) ? 'checked' : '' ?>><?= e($cv['label']) ?></label><?php endforeach; ?></div></div>
            <div class="field"><label class="label">İçerdiği hizmet <span class="text-faint">(biri)</span></label>
                <div class="panel" style="padding:8px 10px;max-height:160px;overflow-y:auto"><?php foreach ($services as $sv): ?><label class="check xsmall" style="display:flex"><input type="checkbox" name="rule[cond][services][]" value="<?= (int)$sv['id'] ?>" <?= in_array((int)$sv['id'], array_map('intval', (array)($c['services'] ?? [])), true) ? 'checked' : '' ?>><?= e($sv['name']) ?></label><?php endforeach; ?></div></div>
            <div class="field"><label class="label">İl <span class="text-faint">(biri · yerinde işler)</span></label>
                <?php $sel_c = array_map('normalize_city', array_map('trim', explode(',', (string)($c['cities'] ?? '')))); ?>
                <div class="panel" style="padding:8px 10px;max-height:160px;overflow-y:auto"><?php foreach (TR_CITIES as $tc): ?><label class="check xsmall" style="display:flex"><input type="checkbox" name="rule[cond][cities][]" value="<?= e($tc) ?>" <?= in_array($tc, $sel_c, true) ? 'checked' : '' ?>><?= e($tc) ?></label><?php endforeach; ?></div></div>
            <div class="field"><label class="label">Ajans <span class="text-faint">(biri)</span></label>
                <div class="panel" style="padding:8px 10px;max-height:160px;overflow-y:auto"><?php if (!$agencies): ?><p class="xsmall text-muted">Henüz ajans yok.</p><?php endif; ?><?php foreach ($agencies as $ag): ?><label class="check xsmall" style="display:flex"><input type="checkbox" name="rule[cond][agencies][]" value="<?= (int)$ag['id'] ?>" <?= in_array((int)$ag['id'], array_map('intval', (array)($c['agencies'] ?? [])), true) ? 'checked' : '' ?>><?= e($ag['company_title']) ?></label><?php endforeach; ?></div></div>
        </div>

        <p class="eyebrow" style="margin-top:6px">Ne olsun?</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <?php foreach (ROUTE_ACTIONS as $rk => [$rl, $rt]): ?>
                <label class="panel" style="padding:12px;display:flex;gap:10px;align-items:flex-start;cursor:pointer"><input type="radio" name="rule[action][route]" value="<?= $rk ?>" <?= ($a['route'] ?? 'review') === $rk ? 'checked' : '' ?> style="margin-top:3px">
                    <span><span class="small" style="font-weight:500;display:block"><?= e($rl) ?></span><span class="xsmall text-muted"><?= ['review' => 'İş "Aksiyon bekleyen" listesine düşer; siz onaylayınca yayına çıkar.', 'publish' => 'İş onay beklemeden "Atama"ya düşer ve aşağıdaki kurallarla freelancer\'lara açılır.', 'internal' => 'İş onaysız açılır ama yalnızca ekibiniz görür; "Ekibimize al" ile üstlenirsiniz.'][$rk] ?></span></span></label>
            <?php endforeach; ?>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="field"><label class="label">Kim görsün</label><select class="select" name="rule[action][visibility]"><?= $opt(['pool' => 'Havuz', 'internal' => 'Ekibe özel'], (string)($a['visibility'] ?? '')) ?></select></div>
            <div class="field"><label class="label">Dağıtım</label><select class="select" name="rule[action][dispatch]"><?= $opt(JOB_DISPATCH, (string)($a['dispatch'] ?? '')) ?></select></div>
            <div class="field"><label class="label">En düşük seviye</label><select class="select" name="rule[action][min_tier]"><?= $opt(array_map(fn($t) => $t['label'], FREELANCER_TIERS), (string)($a['min_tier'] ?? '')) ?></select></div>
            <div class="field"><label class="label">Öncelikli seviye</label><select class="select" name="rule[action][priority_tier]"><?= $opt(['none' => 'Yok', 'silver' => 'Silver+', 'gold' => 'Gold+', 'elite' => 'Elite'], (string)($a['priority_tier'] ?? '')) ?></select></div>
            <div class="field"><label class="label">Öncelik süresi</label><div class="input-group"><input class="input" type="number" min="0" max="720" name="rule[action][priority_hours]" value="<?= e((string)($a['priority_hours'] ?? '')) ?>" placeholder="Değiştirme"><span class="addon">saat</span></div></div>
            <div class="field"><label class="label">Uzmanlık eşleşmesi</label><select class="select" name="rule[action][skill_match]"><?= $opt(['1' => 'Yalnızca uzmanlar', '0' => 'Herkes'], (string)($a['skill_match'] ?? '')) ?></select></div>
            <div class="field"><label class="label">Şehir eşleşmesi</label><select class="select" name="rule[action][city_match]"><?= $opt(['1' => 'Yalnızca aynı şehir', '0' => 'Tüm şehirler'], (string)($a['city_match'] ?? '')) ?></select></div>
            <div class="field"><label class="label">Bildirim</label><label class="check small" style="height:38px"><input type="checkbox" name="rule[action][notify]" value="1" <?= !empty($a['notify']) ? 'checked' : '' ?>>Ekibe öncelikli bildirim</label></div>
        </div>
        <div class="field"><label class="label">Ekip notu <span class="text-faint">(iş sayfasında ve bildirimde görünür)</span></label><input class="input" name="rule[note]" value="<?= e($r['note'] ?? '') ?>" placeholder="ör. Ödeme durumunu muhasebeyle teyit edin"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php if ($is_new): ?>
                <select class="select" name="position" style="width:auto"><option value="end">Listenin sonuna ekle</option><option value="start">En başa ekle (önce denensin)</option></select>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm"><?= $is_new ? 'Kuralı ekle' : 'Kaydet' ?></button>
            <a href="<?= BASE_URL ?>/modules/platform/routing.php" class="btn btn-ghost btn-sm">Vazgeç</a>
        </div>
    </form>
    <?php
}
?>
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><a href="<?= BASE_URL ?>/modules/platform/settings.php">Kurallar</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Yönlendirme</span></div>
        <h1 class="h1">Otomatik yönlendirme kuralları</h1>
        <p class="sub">Ajansın girdiği yeni iş, tutarına ve özelliklerine göre <strong>Aksiyon bekleyen</strong> listesine mi düşsün, doğrudan <strong>Atama</strong>ya mı gitsin, ekibe mi ayrılsın? Kurallar yukarıdan aşağı denenir; koşullarını sağlayan ilk kural uygulanır.</p>
    </div>
    <a href="?edit=new#yeni" class="btn btn-primary"><i data-lucide="plus"></i>Yeni kural</a>
</div>

<div class="stack-lg">
<?php if ($edit_id === 'new'): ?>
    <section class="card" id="yeni"><div class="card-head"><p class="card-title">Yeni kural</p></div><?php routing_form(routing_blank(), true, $services, $agencies, $opt); ?></section>
<?php endif; ?>

<?php if (!$rules && $edit_id !== 'new'): ?>
    <section class="card"><?= ui_empty('Henüz kural yok', 'Şu an tüm katalog işleri aşağıdaki genel kurala göre yönlendiriliyor. Örnek kurallarla başlayıp tutarları kendinize göre düzenleyebilirsiniz.', 'route',
        '<form method="POST" action="" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="presets"><button class="btn btn-secondary">Örnek kuralları ekle</button></form> <a class="btn btn-primary" href="?edit=new#yeni">Yeni kural</a>') ?></section>
<?php endif; ?>

<?php foreach ($rules as $i => $r): $a = $r['action'] ?? []; [$rl, $rt] = ROUTE_ACTIONS[$a['route'] ?? 'review'] ?? ROUTE_ACTIONS['review']; ?>
    <section class="card" id="r-<?= e($r['id']) ?>" style="<?= empty($r['enabled']) ? 'opacity:.6' : '' ?>">
        <div class="card-head" style="flex-wrap:wrap;gap:10px">
            <div style="display:flex;gap:12px;align-items:flex-start;min-width:0;flex:1">
                <span class="badge badge-square" style="flex-shrink:0"><?= $i + 1 ?></span>
                <div style="min-width:0">
                    <p class="card-title" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= e($r['name']) ?> <?= ui_badge($rl, $rt, true) ?><?= empty($r['enabled']) ? ui_badge('Pasif', 'neutral') : '' ?><?= !empty($a['notify']) ? ui_badge('Öncelikli bildirim', 'accent') : '' ?></p>
                    <p class="xsmall text-muted" style="margin-top:3px"><strong>Koşul:</strong> <?= e(routing_summary($r)) ?></p>
                    <?php
                    $chg = [];
                    if (($a['visibility'] ?? '') !== '') $chg[] = $a['visibility'] === 'internal' ? 'ekibe özel' : 'havuz';
                    if (($a['dispatch'] ?? '') !== '') $chg[] = JOB_DISPATCH[$a['dispatch']] ?? '';
                    if (($a['min_tier'] ?? '') !== '') $chg[] = 'en az ' . tier_label($a['min_tier']);
                    if (($a['priority_tier'] ?? '') !== '') $chg[] = $a['priority_tier'] === 'none' ? 'öncelik yok' : 'öncelik ' . tier_label($a['priority_tier']) . '+' . (($a['priority_hours'] ?? '') !== '' ? " {$a['priority_hours']} saat" : '');
                    if (($a['skill_match'] ?? '') !== '') $chg[] = $a['skill_match'] === '1' ? 'yalnızca uzmanlar' : 'tüm uzmanlıklar';
                    if (($a['city_match'] ?? '') !== '') $chg[] = $a['city_match'] === '1' ? 'aynı şehir' : 'tüm şehirler';
                    ?>
                    <?php if ($chg): ?><p class="xsmall text-muted"><strong>Görünürlük:</strong> <?= e(implode(' · ', $chg)) ?></p><?php endif; ?>
                    <?php if (($r['note'] ?? '') !== ''): ?><p class="xsmall text-muted"><strong>Not:</strong> <?= e($r['note']) ?></p><?php endif; ?>
                    <p class="xsmall text-faint">Son 20 işten <?= (int)($used[$r['id']] ?? 0) ?> tanesine uyuyor.</p>
                </div>
            </div>
            <div style="display:flex;gap:4px;flex-wrap:wrap">
                <?php foreach ([['up', 'arrow-up', 'Yukarı', $i > 0], ['down', 'arrow-down', 'Aşağı', $i < count($rules) - 1]] as [$act, $ic, $tt, $show]): if (!$show) continue; ?>
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="icon-btn" title="<?= $tt ?>"><i data-lucide="<?= $ic ?>"></i></button></form>
                <?php endforeach; ?>
                <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn btn-ghost btn-sm"><?= empty($r['enabled']) ? 'Aktifleştir' : 'Pasifleştir' ?></button></form>
                <a href="?edit=<?= e($r['id']) ?>#r-<?= e($r['id']) ?>" class="btn btn-secondary btn-sm"><i data-lucide="pencil"></i>Düzenle</a>
                <form method="POST" action="" onsubmit="return confirm('Kural silinsin mi?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="icon-btn" title="Sil"><i data-lucide="trash-2"></i></button></form>
            </div>
        </div>
        <?php if ($edit_id === $r['id']) routing_form($r, false, $services, $agencies, $opt); ?>
    </section>
<?php endforeach; ?>

<section class="card">
    <form method="POST" action="" class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?>
        <input type="hidden" name="action" value="fallback">
        <div style="min-width:0;flex:1">
            <p class="small" style="font-weight:500">Hiçbir kurala uymayan katalog işleri onaysız atamaya gitsin</p>
            <p class="xsmall text-muted">Kapalıyken kurala uymayan her katalog işi önce sizin onayınızı bekler. Özel teklif talepleri fiyatlandırma gerektirdiği için her zaman onaya düşer; kurallar yalnızca görünürlüklerini ve bildirimi belirler.</p>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <label class="switch"><input type="checkbox" name="auto_publish" value="1" <?= platform_setting('platform_auto_publish') === '1' ? 'checked' : '' ?>><span></span></label>
            <button class="btn btn-secondary btn-sm">Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head"><div><p class="card-title">Önizleme: son 20 iş bugün girilseydi</p><p class="card-sub">Kayıtlı kurallarla hangi listeye düşeceğini gösterir; mevcut işler değişmez.</p></div></div>
    <?php if (!$preview): ?>
        <?= ui_empty('Henüz iş yok', '', 'inbox') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>İş</th><th>Ajans</th><th class="r">Tutar</th><th>Uyan kural</th><th>Sonuç</th></tr></thead>
            <tbody>
            <?php foreach ($preview as $p): $j = $p['job']; [$pl, $pt] = ROUTE_ACTIONS[$p['route']]; ?>
                <tr class="row-link" onclick="location.href='<?= BASE_URL ?>/modules/platform/job.php?id=<?= (int)$j['id'] ?>'">
                    <td><span class="code-tag"><?= e($j['job_code']) ?></span><div class="small"><?= e($j['title']) ?></div></td>
                    <td class="small"><?= e($j['agency_name'] ?? '—') ?></td>
                    <td class="r num"><?= $j['agency_price'] !== null ? format_money((float)$j['agency_price']) : ($j['budget'] !== null ? '~' . format_money((float)$j['budget']) : '—') ?></td>
                    <td class="small"><?= $p['rule'] ? e($p['rule']['name']) : '<span class="text-faint">genel kural</span>' ?></td>
                    <td><?= ui_badge($pl, $pt, true) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
