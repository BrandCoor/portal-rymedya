<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - HİZMET KATALOĞU (FİYAT LİSTESİ)
 * ====================================================================
 * Ajansın iş ekranındaki hizmetler ve birim fiyatları.
 * Her kalem: ajans fiyatı, freelancer ücreti, en düşük seviye,
 * en kısa iş giriş süresi (ör. drone için uçuş izni).
 * Yetki: platform.pricing · silme: platform.delete
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.pricing');
$can_delete = can_access_module('platform.delete');
$self = BASE_URL . '/modules/platform/catalog.php';

$mark_reviewed = function () use ($db) {
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('platform_catalog_reviewed', '1', 'platform') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $price = parse_money($_POST['agency_price'] ?? '');
        $fee   = parse_money($_POST['freelancer_fee'] ?? '');
        if ($name === '' || $price <= 0) {
            set_flash('error', 'Hizmet adı ve ajans fiyatı zorunlu.');
            redirect($self);
        }
        if ($fee > $price) {
            set_flash('error', 'Freelancer ücreti ajans fiyatından yüksek olamaz.');
            redirect($self);
        }
        $data = [
            array_key_exists($_POST['category'] ?? '', JOB_CATEGORIES) ? $_POST['category'] : 'other',
            $name, trim($_POST['description'] ?? '') ?: null,
            array_key_exists($_POST['unit'] ?? '', SERVICE_UNITS) ? $_POST['unit'] : 'adet',
            $price, $fee,
            array_key_exists($_POST['min_tier'] ?? '', FREELANCER_TIERS) ? $_POST['min_tier'] : 'standard',
            max(0, min(720, (int)($_POST['min_lead_hours'] ?? 0))),
            isset($_POST['is_active']) ? 1 : 0,
            (int)($_POST['sort_order'] ?? 0),
        ];
        if ($id) {
            $db->prepare("UPDATE platform_services SET category = ?, name = ?, description = ?, unit = ?, agency_price = ?, freelancer_fee = ?, min_tier = ?, min_lead_hours = ?, is_active = ?, sort_order = ? WHERE id = ?")->execute([...$data, $id]);
        } else {
            $db->prepare("INSERT INTO platform_services (category, name, description, unit, agency_price, freelancer_fee, min_tier, min_lead_hours, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($data);
        }
        $mark_reviewed();
        log_activity('platform', "Hizmet kataloğu: {$name} " . ($id ? 'güncellendi' : 'eklendi'), null, null, '/modules/platform/catalog.php');
        set_flash('success', $id ? 'Hizmet güncellendi. Yeni fiyat bundan sonraki işlerde geçerli.' : 'Hizmet kataloğa eklendi.');
        redirect($self);
    }

    if ($action === 'toggle') {
        $db->prepare("UPDATE platform_services SET is_active = 1 - is_active WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        redirect($self);
    }

    if ($action === 'bulk_adjust') {
        $pct = (float)str_replace(',', '.', (string)($_POST['percent'] ?? '0'));
        $target = $_POST['target'] ?? 'both';
        if ($pct == 0 || $pct < -50 || $pct > 200) {
            set_flash('error', 'Yüzde -50 ile 200 arasında, sıfırdan farklı olmalı.');
            redirect($self);
        }
        $f = 1 + $pct / 100;
        $cols = ['agency' => ['agency_price'], 'freelancer' => ['freelancer_fee'], 'both' => ['agency_price', 'freelancer_fee']][$target] ?? ['agency_price', 'freelancer_fee'];
        $set = implode(', ', array_map(fn($c) => "{$c} = ROUND({$c} * ? / 50, 0) * 50", $cols));
        $db->prepare("UPDATE platform_services SET {$set}")->execute(array_fill(0, count($cols), $f));
        $db->query("UPDATE platform_services SET freelancer_fee = LEAST(freelancer_fee, agency_price)");
        $mark_reviewed();
        log_activity('platform', "Hizmet kataloğu fiyatları %{$pct} güncellendi ({$target})", null, null, '/modules/platform/catalog.php');
        set_flash('success', 'Fiyatlar güncellendi (50 TL\'ye yuvarlandı).');
        redirect($self);
    }

    if ($action === 'mark_reviewed') {
        $mark_reviewed();
        set_flash('success', 'Katalog gözden geçirildi olarak işaretlendi.');
        redirect($self);
    }

    if ($action === 'delete') {
        if (!$can_delete) {
            set_flash('error', 'Kalıcı silme yetkiniz yok. Hizmeti pasife alabilirsiniz.');
            redirect($self);
        }
        $id = (int)($_POST['id'] ?? 0);
        // Geçmiş işlerin kalemleri kendi ad/fiyat kopyasını taşır; bağ koparılır
        $db->prepare("UPDATE platform_job_items SET service_id = NULL WHERE service_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM platform_services WHERE id = ?")->execute([$id]);
        set_flash('success', 'Hizmet katalogdan silindi.');
        redirect($self);
    }
    redirect($self);
}

$services = catalog_services(false);
$usage = $db->query("SELECT service_id, COUNT(DISTINCT job_id) AS jobs, SUM(quantity) AS qty FROM platform_job_items WHERE service_id IS NOT NULL GROUP BY service_id")->fetchAll(PDO::FETCH_UNIQUE);
$grouped = [];
foreach ($services as $s) {
    $grouped[$s['category']][] = $s;
}
$base_lead = (int)platform_setting('platform_min_lead_hours');
$reviewed = platform_setting('platform_catalog_reviewed') === '1';
$blank = ['id' => 0, 'category' => 'shooting', 'name' => '', 'description' => '', 'unit' => 'gün', 'agency_price' => '', 'freelancer_fee' => '', 'min_tier' => 'standard', 'min_lead_hours' => 0, 'is_active' => 1, 'sort_order' => 0];

$page_title = 'Hizmet kataloğu';
require_once __DIR__ . '/../../includes/header.php';
?>
<div x-data="{ open: false, f: {}, edit(s) { this.f = Object.assign({}, s); this.open = true; } }">
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Hizmet kataloğu</span></div>
        <h1 class="h1">Hizmet kataloğu</h1>
        <p class="sub">Ajanslar bu listeden iş girer; tutar ve freelancer hakedişi otomatik hesaplanır. Fiyat değişiklikleri yalnızca yeni işlere uygulanır.</p>
    </div>
    <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-primary" @click="edit(<?= e(json_encode($blank)) ?>)"><i data-lucide="plus"></i>Hizmet ekle</button>
    </div>
</div>

<?php if (!$reviewed): ?>
    <div class="alert alert-warning" style="margin-bottom:16px;align-items:center"><i data-lucide="tag"></i>
        <div style="flex:1">Katalog örnek fiyatlarla kuruldu. Fiyatları kendi tarifenize göre güncelleyin; herhangi bir kaydı düzenlediğinizde bu uyarı kalkar.</div>
        <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="mark_reviewed"><button class="btn btn-secondary btn-sm">Fiyatlar doğru</button></form>
    </div>
<?php endif; ?>

<?php if (!$services): ?>
    <div class="card"><?= ui_empty('Katalog boş', 'Katalog boşken ajanslar yalnızca özel talep oluşturabilir.', 'tag') ?></div>
<?php else: ?>
<div class="stack-lg">
<?php foreach ($grouped as $cat => $list): ?>
    <section class="card">
        <div class="card-head"><p class="card-title" style="display:flex;gap:8px;align-items:center"><i data-lucide="<?= job_category_icon($cat) ?>" style="width:15px;height:15px"></i><?= e(job_category_label($cat)) ?></p><span class="xsmall text-muted"><?= count($list) ?> hizmet</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Hizmet</th><th class="r">Ajans fiyatı</th><th class="r">Freelancer</th><th class="r">Marj</th><th>Seviye</th><th class="r">Min. süre</th><th class="r">Kullanım</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $s):
                    $m = (float)$s['agency_price'] - (float)$s['freelancer_fee'];
                    $mp = (float)$s['agency_price'] > 0 ? $m / (float)$s['agency_price'] * 100 : 0;
                    $u = $usage[$s['id']] ?? null; ?>
                    <tr style="<?= (int)$s['is_active'] === 1 ? '' : 'opacity:.55' ?>">
                        <td style="max-width:340px">
                            <span style="font-weight:500"><?= e($s['name']) ?></span><?php if ((int)$s['is_active'] !== 1): ?> <?= ui_badge('Pasif', 'neutral') ?><?php endif; ?>
                            <?php if ($s['description']): ?><div class="xsmall text-muted"><?= e($s['description']) ?></div><?php endif; ?>
                        </td>
                        <td class="r money" style="white-space:nowrap"><?= format_money((float)$s['agency_price']) ?> <span class="xsmall text-muted">/ <?= e($s['unit']) ?></span></td>
                        <td class="r num"><?= format_money((float)$s['freelancer_fee']) ?></td>
                        <td class="r num" style="color:<?= $mp < 15 ? 'var(--warning)' : 'var(--success)' ?>">%<?= number_format($mp, 0) ?></td>
                        <td><?= tier_badge($s['min_tier']) ?></td>
                        <td class="r num"><?= (int)$s['min_lead_hours'] > $base_lead ? (int)$s['min_lead_hours'] . ' sa' : '<span class="text-faint">genel</span>' ?></td>
                        <td class="r xsmall text-muted"><?= $u ? (int)$u['jobs'] . ' iş' : '—' ?></td>
                        <td class="r" style="white-space:nowrap">
                            <button type="button" class="btn btn-ghost btn-sm" @click="edit(<?= e(json_encode($s)) ?>)">Düzenle</button>
                            <form method="POST" action="" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-ghost btn-sm"><?= (int)$s['is_active'] === 1 ? 'Pasife al' : 'Aktifleştir' ?></button></form>
                            <?php if ($can_delete): ?>
                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Hizmet katalogdan silinsin mi? Geçmiş işler etkilenmez.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="icon-btn" aria-label="Sil" style="display:inline-grid;color:var(--danger)"><i data-lucide="trash-2"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card" style="margin-top:24px">
    <div class="card-head"><div><p class="card-title">Toplu fiyat güncelleme</p><p class="card-sub">Enflasyon veya sezon güncellemeleri için. Sonuçlar 50 TL'ye yuvarlanır.</p></div></div>
    <form method="POST" action="" class="card-pad" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap" onsubmit="return confirm('Tüm katalog fiyatları güncellensin mi?');"><?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_adjust">
        <div class="field"><label class="label">Değişim</label><div class="input-group" style="width:140px"><input class="input" type="number" step="0.5" name="percent" placeholder="10" required><span class="addon">%</span></div></div>
        <div class="field"><label class="label">Uygulanacak</label><select class="select" name="target" style="width:220px"><option value="both">Ajans fiyatı ve freelancer ücreti</option><option value="agency">Yalnızca ajans fiyatı</option><option value="freelancer">Yalnızca freelancer ücreti</option></select></div>
        <button class="btn btn-secondary">Uygula</button>
    </form>
</section>

<!-- DÜZENLEME -->
<div x-show="open" x-cloak class="modal-backdrop" @keydown.escape.window="open = false">
    <form method="POST" action="" class="modal" @click.outside="open = false"><?= csrf_field() ?>
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" :value="f.id">
        <div class="modal-head"><p class="h3" x-text="f.id > 0 ? 'Hizmeti düzenle' : 'Yeni hizmet'"></p><button type="button" class="icon-btn" @click="open = false"><i data-lucide="x"></i></button></div>
        <div class="modal-body stack">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="field sm:col-span-2"><label class="label">Hizmet adı <span class="req">*</span></label><input class="input" name="name" x-model="f.name" required></div>
                <div class="field sm:col-span-2"><label class="label">Açıklama</label><input class="input" name="description" x-model="f.description" placeholder="Ajansın iş ekranında görünür"></div>
                <div class="field"><label class="label">Kategori</label><select class="select" name="category" x-model="f.category"><?php foreach (JOB_CATEGORIES as $ck => $cv): ?><option value="<?= $ck ?>"><?= e($cv['label']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">Birim</label><select class="select" name="unit" x-model="f.unit"><?php foreach (SERVICE_UNITS as $uk => $ul): ?><option value="<?= e($uk) ?>"><?= e($ul) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">Ajans fiyatı (KDV hariç) <span class="req">*</span></label><div class="input-group"><input class="input" type="number" step="0.01" min="0" name="agency_price" x-model.number="f.agency_price" required><span class="addon">TL</span></div></div>
                <div class="field"><label class="label">Freelancer ücreti</label><div class="input-group"><input class="input" type="number" step="0.01" min="0" name="freelancer_fee" x-model.number="f.freelancer_fee"><span class="addon">TL</span></div>
                    <span class="hint" x-show="f.agency_price > 0" x-text="'Marj: ' + Math.round(((f.agency_price || 0) - (f.freelancer_fee || 0)) / f.agency_price * 100) + '%'"></span></div>
                <div class="field"><label class="label">En düşük freelancer seviyesi</label><select class="select" name="min_tier" x-model="f.min_tier"><?php foreach (FREELANCER_TIERS as $tk => $tv): ?><option value="<?= $tk ?>"><?= e($tv['label']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">En kısa iş giriş süresi</label><div class="input-group"><input class="input" type="number" min="0" max="720" name="min_lead_hours" x-model.number="f.min_lead_hours"><span class="addon">saat</span></div><span class="hint">Genel kural <?= $base_lead ?> saat; daha uzun süre gerekiyorsa girin.</span></div>
                <div class="field"><label class="label">Sıra</label><input class="input" type="number" name="sort_order" x-model.number="f.sort_order"></div>
                <label class="check" style="align-self:end;padding-bottom:8px"><input type="checkbox" name="is_active" value="1" :checked="f.is_active == 1">Katalogda görünsün</label>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" @click="open = false">Vazgeç</button><button class="btn btn-primary">Kaydet</button></div>
    </form>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
