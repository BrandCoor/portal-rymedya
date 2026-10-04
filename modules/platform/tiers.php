<?php
/**
 * ====================================================================
 * PLATFORM YÖNETİMİ - SEVİYE KURALLARI (OTOMASYON)
 * ====================================================================
 * Silver / Gold / Elite için koşullar yönetici tarafından belirlenir:
 * ör. "en az 10 tamamlanan iş VE en az 4★ ortalama → Gold".
 * Boş bırakılan koşul aranmaz; dolu koşulların tamamı sağlanmalıdır.
 * Kurallar her tamamlanan iş / değerlendirmede otomatik uygulanır;
 * buradan tüm freelancer'lara anında da uygulanabilir.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('platform.manage');
$self = BASE_URL . '/modules/platform/tiers.php';
$auto_tiers = ['silver', 'gold', 'elite'];

/**
 * Kuralları tüm (sabitlenmemiş) freelancer'lara uygular; değişen sayısını döner
 */
function apply_tier_rules_all(): array {
    global $db;
    $changed = ['up' => 0, 'down' => 0];
    $allow_down = platform_setting('platform_tier_downgrade') === '1';
    foreach ($db->query("SELECT user_id FROM freelancer_profiles WHERE status = 'approved'")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $p = recompute_freelancer_metrics((int)$uid);   // metrikleri tazeler (otomatik seviye açıksa uygular da)
        if (!$p || (int)$p['tier_locked'] === 1) continue;
        $target = suggested_tier($p);
        if (tier_rank($target) < tier_rank($p['tier']) && !$allow_down) continue;
        if ($target === $p['tier']) continue;
        $up = tier_rank($target) > tier_rank($p['tier']);
        $db->prepare("UPDATE freelancer_profiles SET tier = ? WHERE user_id = ?")->execute([$target, $uid]);
        notify_user((int)$uid, ($up ? 'Seviyeniz yükseldi: ' : 'Seviyeniz güncellendi: ') . tier_label($target) . '. Aynı anda alabileceğiniz iş sayısı: ' . tier_job_limit($target), '/platform/performance.php');
        $changed[$up ? 'up' : 'down']++;
    }
    return $changed;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $set = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'platform') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    if (in_array($action, ['save', 'save_apply'], true)) {
        $rules = [];
        foreach ($auto_tiers as $t) {
            $in = (array)($_POST['rules'][$t] ?? []);
            $r = ['enabled' => !empty($in['enabled']) ? 1 : 0];
            foreach (TIER_CONDITIONS as $key => [, , , $min, $max, $step]) {
                $v = trim(str_replace(',', '.', (string)($in[$key] ?? '')));
                if ($v === '') continue;
                $n = max($min, min($max, (float)$v));
                $r[$key] = $step < 1 ? round($n, 1) : (int)round($n);
            }
            $rules[$t] = $r;
            $limit = max(1, min(50, (int)($_POST['limit'][$t] ?? tier_job_limit($t))));
            $set->execute(["platform_limit_{$t}", (string)$limit]);
        }
        $set->execute(['platform_limit_standard', (string)max(1, min(50, (int)($_POST['limit']['standard'] ?? 1)))]);
        $set->execute(['platform_tier_rules', json_encode($rules, JSON_UNESCAPED_UNICODE)]);
        $set->execute(['platform_auto_tier', isset($_POST['auto_tier']) ? '1' : '0']);
        $set->execute(['platform_tier_downgrade', isset($_POST['downgrade']) ? '1' : '0']);
        get_settings(true);
        log_activity('platform', 'Freelancer seviye kuralları güncellendi', null, null, '/modules/platform/tiers.php');

        if ($action === 'save_apply') {
            $c = apply_tier_rules_all();
            set_flash('success', "Kurallar kaydedildi ve uygulandı: {$c['up']} freelancer yükseldi, {$c['down']} freelancer düştü. Sabitlenmiş seviyeler değişmedi.");
        } else {
            set_flash('success', 'Seviye kuralları kaydedildi. Freelancer\'ların bir sonraki tamamlanan işinde uygulanır.');
        }
        redirect($self);
    }
    if ($action === 'reset') {
        $db->prepare("DELETE FROM system_settings WHERE setting_key = 'platform_tier_rules'")->execute();
        set_flash('success', 'Seviye kuralları varsayılana döndü.');
        redirect($self);
    }
    redirect($self);
}

$rules = tier_rules();
$freelancers = $db->query("SELECT fp.*, u.full_name FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.status = 'approved' ORDER BY fp.score IS NULL, fp.score DESC, u.full_name")->fetchAll();
$qualify = array_fill_keys($auto_tiers, 0);
$changes = [];
foreach ($freelancers as &$f) {
    foreach ($auto_tiers as $t) {
        if (tier_check($f, $t, $rules)['ok']) $qualify[$t]++;
    }
    $f['target'] = suggested_tier($f, $rules);
    if ($f['target'] !== $f['tier'] && (int)$f['tier_locked'] !== 1 && (tier_rank($f['target']) > tier_rank($f['tier']) || platform_setting('platform_tier_downgrade') === '1')) {
        $changes[] = $f['user_id'];
    }
}
unset($f);
$fmt = fn($v, $unit) => $v === null ? '—' : ($unit === '★' ? number_format($v, 1, ',', '') : number_format($v, 0, ',', '.')) . ($unit === '%' ? '%' : '');

$page_title = 'Seviye kuralları';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/platform/index.php">İş merkezi</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><a href="<?= BASE_URL ?>/modules/platform/freelancers.php">Freelancer'lar</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span>Seviye kuralları</span></div>
        <h1 class="h1">Seviye kuralları</h1>
        <p class="sub">Freelancer'ın hangi şartları sağladığında Silver, Gold veya Elite olacağını belirleyin. Boş bıraktığınız koşul aranmaz; doldurduğunuz koşulların tamamı sağlanmalıdır.</p>
    </div>
</div>

<form method="POST" action="" class="stack-lg">
    <?= csrf_field() ?>
    <section class="card">
        <div class="divide">
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                <div><p class="small" style="font-weight:500">Otomatik uygula</p><p class="xsmall text-muted">Her tamamlanan iş ve değerlendirmeden sonra kurallar kontrol edilir; şartı sağlayanın seviyesi değişir ve kendisine bildirim (ve e-posta) gider.</p></div>
                <label class="switch"><input type="checkbox" name="auto_tier" value="1" <?= platform_setting('platform_auto_tier') === '1' ? 'checked' : '' ?>><span></span></label>
            </div>
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                <div><p class="small" style="font-weight:500">Seviye düşürme</p><p class="xsmall text-muted">Kuralları artık sağlamayanların seviyesi düşer. Kapalıyken seviye yalnızca yükselir.</p></div>
                <label class="switch"><input type="checkbox" name="downgrade" value="1" <?= platform_setting('platform_tier_downgrade') === '1' ? 'checked' : '' ?>><span></span></label>
            </div>
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                <div><p class="small" style="font-weight:500"><?= tier_badge('standard') ?> Standart · eşzamanlı aktif iş</p><p class="xsmall text-muted">Kuralların hiçbirini sağlamayan herkes Standart seviyededir.</p></div>
                <div class="input-group" style="width:130px"><input class="input" type="number" min="1" max="50" name="limit[standard]" value="<?= tier_job_limit('standard') ?>"><span class="addon">iş</span></div>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <?php foreach ($auto_tiers as $t): $r = $rules[$t] ?? []; ?>
        <section class="card" x-data="{ on: <?= !empty($r['enabled']) ? 'true' : 'false' ?> }">
            <div class="card-head">
                <div style="display:flex;gap:10px;align-items:center"><?= tier_badge($t) ?><span class="xsmall text-muted"><?= $qualify[$t] ?> freelancer şu an şartları sağlıyor</span></div>
                <label class="switch" title="Bu seviye otomatik verilsin"><input type="checkbox" name="rules[<?= $t ?>][enabled]" value="1" x-model="on"><span></span></label>
            </div>
            <div class="card-pad stack-sm" :style="on ? '' : 'opacity:.45'">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding-bottom:10px;border-bottom:1px solid var(--line-2);margin-bottom:4px">
                    <span class="small" style="font-weight:500">Eşzamanlı aktif iş</span>
                    <div class="input-group" style="width:120px"><input class="input input-sm" type="number" min="1" max="50" name="limit[<?= $t ?>]" value="<?= tier_job_limit($t) ?>"><span class="addon">iş</span></div>
                </div>
                <?php foreach (TIER_CONDITIONS as $key => [$label, $op, $unit, $min, $max, $step]): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                        <span class="small text-ink-2" style="min-width:0"><?= e($label) ?> <span class="xsmall text-faint"><?= e($op) ?></span></span>
                        <div class="input-group" style="width:120px;flex-shrink:0">
                            <input class="input input-sm" type="number" step="<?= $step ?>" min="<?= $min ?>" max="<?= $max ?>" name="rules[<?= $t ?>][<?= $key ?>]" value="<?= isset($r[$key]) ? e((string)$r[$key]) : '' ?>" placeholder="—" style="text-align:right">
                            <span class="addon" style="min-width:42px;justify-content:center"><?= e($unit) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="xsmall text-muted" style="margin-top:8px"><?= e(tier_rule_summary($t)) ?></p>
            </div>
        </section>
    <?php endforeach; ?>
    </div>

    <div class="alert alert-info"><i data-lucide="lightbulb"></i><div class="small">
        Örnek: <strong>Gold</strong> için "Tamamlanan iş en az 10" ve "Ortalama yıldız en az 4" yazın → 10 işi bitirmiş ve ortalaması 4★ olan herkes Gold olur.
        Yıldız ortalaması ekip ve müşteri (ajans) puanlarının ortalamasıdır. Aynı anda birden fazla seviyenin şartını sağlayan, en yüksek seviyeye geçer.
        Freelancer sayfasından elle verilen seviyeler <strong>sabitlenir</strong> ve kurallardan etkilenmez.
    </div></div>

    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <button type="submit" form="reset-tiers" class="btn btn-ghost" onclick="return confirm('Seviye kuralları varsayılana dönsün mü?');">Varsayılana döndür</button>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-secondary" name="action" value="save">Kaydet</button>
            <button class="btn btn-primary" name="action" value="save_apply" onclick="return confirm('Kurallar kaydedilip tüm freelancer\'lara şimdi uygulansın mı? Seviyesi değişenlere bildirim gider.');"><i data-lucide="play"></i>Kaydet ve herkese uygula</button>
        </div>
    </div>
</form>
<form id="reset-tiers" method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="reset"></form>

<section class="card" style="margin-top:28px">
    <div class="card-head"><div><p class="card-title">Kayıtlı kurallara göre durum</p><p class="card-sub"><?= count($changes) ?> freelancer'ın seviyesi kurallar uygulandığında değişir.</p></div></div>
    <?php if (!$freelancers): ?>
        <?= ui_empty('Onaylı freelancer yok', '', 'users') ?>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Freelancer</th><th class="r">İş</th><th class="r">Yıldız</th><th class="r">Puan</th><th class="r">Zamanında</th><th class="r">KK</th><th class="r">Olay</th><th>Mevcut</th><th>Kurala göre</th></tr></thead>
            <tbody>
            <?php foreach ($freelancers as $f): $chg = in_array($f['user_id'], $changes, false); ?>
                <tr style="<?= $chg ? 'background:var(--surface-2)' : '' ?>">
                    <td><a class="hover:underline" href="<?= BASE_URL ?>/modules/platform/freelancers.php?focus=<?= (int)$f['user_id'] ?>" style="font-weight:500"><?= e($f['full_name']) ?></a></td>
                    <td class="r num"><?= (int)$f['completed_jobs'] ?></td>
                    <td class="r num"><?= $fmt(tier_metric($f, 'min_rating'), '★') ?></td>
                    <td class="r num"><?= $fmt(tier_metric($f, 'min_score'), '') ?></td>
                    <td class="r num"><?= $fmt(tier_metric($f, 'min_on_time'), '%') ?></td>
                    <td class="r num"><?= $fmt(tier_metric($f, 'min_qa'), '%') ?></td>
                    <td class="r num"><?= (int)tier_metric($f, 'max_incidents') ?></td>
                    <td><?= tier_badge($f['tier']) ?><?php if ((int)$f['tier_locked'] === 1): ?> <i data-lucide="lock" style="width:12px;height:12px;color:var(--muted)" title="Sabitlenmiş"></i><?php endif; ?></td>
                    <td><?php if ($f['target'] === $f['tier']): ?><span class="xsmall text-faint">değişmez</span><?php else: ?><?= tier_badge($f['target']) ?><?php if (!$chg): ?> <span class="xsmall text-faint"><?= (int)$f['tier_locked'] === 1 ? '(sabit)' : '(düşürme kapalı)' ?></span><?php endif; ?><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
