<?php
/**
 * ====================================================================
 * AYARLAR
 * ====================================================================
 * Tüm bölümler includes/settings_schema.php'deki şemadan oluşur.
 * Görsel alanlar (logo, favicon, arka plan) assets/uploads/branding/
 * klasörüne yüklenir. Her bölüm ayrı kaydedilir ve varsayılana döndürülebilir.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_permission('settings.manage');
$user = current_user();

$tab = array_key_exists($_GET['tab'] ?? '', SETTINGS_SCHEMA) ? $_GET['tab'] : 'brand';
$self = BASE_URL . '/modules/settings/index.php?tab=';
const BRANDING_DIR = __DIR__ . '/../../assets/uploads/branding/';

/**
 * Görsel yükler; başarılıysa göreli yolu, hata varsa [null, mesaj] döner
 */
function settings_store_image(array $file, string $key, string $kind): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'Dosya yüklenemedi (sunucu yükleme sınırını kontrol edin).'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return [null, 'Görsel en fazla 2 MB olabilir.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
            'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
    if (!isset($map[$mime])) {
        return [null, 'Desteklenmeyen dosya türü. PNG, JPG, WEBP, SVG veya ICO yükleyin.'];
    }
    $ext = $map[$mime];
    if ($ext === 'ico' && $kind !== 'favicon') {
        return [null, 'ICO yalnızca favicon için kullanılabilir.'];
    }
    if ($ext === 'svg') {
        // SVG içinde betik veya dış içerik olmamalı
        $svg = (string)file_get_contents($file['tmp_name']);
        if (preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject|<iframe|<embed|<object|xlink:href\s*=\s*["\'](?!#|data:image)/i', $svg)) {
            return [null, 'SVG dosyası betik veya dış bağlantı içeremez.'];
        }
    } elseif ($ext !== 'ico' && @getimagesize($file['tmp_name']) === false) {
        return [null, 'Dosya geçerli bir görsel değil.'];
    }
    if (!is_dir(BRANDING_DIR) && !@mkdir(BRANDING_DIR, 0755, true)) {
        return [null, 'Yükleme klasörü oluşturulamadı: assets/uploads/branding'];
    }
    $name = preg_replace('/[^a-z0-9_]/', '', $key) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], BRANDING_DIR . $name)) {
        return [null, 'Dosya kaydedilemedi; klasör yazma iznini kontrol edin.'];
    }
    return ['assets/uploads/branding/' . $name, null];
}

function settings_delete_image(?string $path): void {
    if ($path && preg_match('#^assets/uploads/branding/[A-Za-z0-9._-]+$#', $path)) {
        @unlink(__DIR__ . '/../../' . $path);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $section = $_POST['section'] ?? '';
    if (!array_key_exists($section, SETTINGS_SCHEMA)) {
        redirect($self . 'brand');
    }
    $fields = SETTINGS_SCHEMA[$section]['fields'];
    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)");
    $current = get_settings(true);

    // Test e-postası
    if (($_POST['action'] ?? '') === 'test_mail') {
        $to = trim($_POST['test_to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Geçerli bir alıcı adresi girin.');
            redirect($self . 'mail');
        }
        $html = mail_render(mail_notice_html($user['full_name'] ?? '', 'Bu bir test e-postasıdır. Bu mesajı görüyorsanız e-posta ayarlarınız doğru çalışıyor.'), ['button' => ['Portala git', BASE_URL . '/modules/dashboard/index.php'], 'title' => 'Test e-postası']);
        $err = mail_send_direct($to, (site_setting('mail_subject_prefix') !== '' ? site_setting('mail_subject_prefix') . ' ' : '') . 'Test e-postası', $html);
        set_flash($err ? 'error' : 'success', $err ? 'Test e-postası gönderilemedi: ' . $err : "Test e-postası {$to} adresine gönderildi.");
        redirect($self . 'mail');
    }

    // Varsayılana döndür
    if (($_POST['action'] ?? '') === 'reset') {
        $del = $db->prepare("DELETE FROM system_settings WHERE setting_key = ?");
        foreach ($fields as $key => $f) {
            if ($f[0] === 'image') {
                settings_delete_image($current[$key] ?? null);
            }
            $del->execute([$key]);
        }
        log_activity('settings', 'Ayarlar varsayılana döndürüldü: ' . SETTINGS_SCHEMA[$section]['title'], null, null, '/modules/settings/index.php?tab=' . $section);
        set_flash('success', SETTINGS_SCHEMA[$section]['title'] . ' varsayılan değerlere döndürüldü.');
        redirect($self . $section);
    }

    $errors = [];
    $values = [];
    foreach ($fields as $key => $f) {
        [$type, $label] = $f;
        $raw = $_POST[$key] ?? '';
        switch ($type) {
            case 'bool':
                $values[$key] = isset($_POST[$key]) ? '1' : '0';
                break;
            case 'number':
                [$min, $max] = $f[4];
                $n = max($min, min($max, (float)str_replace(',', '.', (string)$raw)));
                $values[$key] = (string)($n == (int)$n ? (int)$n : $n);
                break;
            case 'color':
                $raw = trim((string)$raw);
                if (!valid_hex_color($raw)) { $errors[] = "{$label}: #RRGGBB biçiminde bir renk girin."; continue 2; }
                $values[$key] = strtoupper($raw);
                break;
            case 'email':
                $raw = trim((string)$raw);
                if ($raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) { $errors[] = "{$label}: geçerli bir e-posta girin."; continue 2; }
                $values[$key] = $raw;
                break;
            case 'url':
                $raw = trim((string)$raw);
                if ($raw !== '' && !is_safe_url($raw)) { $errors[] = "{$label}: http(s):// ile başlayan bir adres girin."; continue 2; }
                $values[$key] = $raw;
                break;
            case 'select':
                $opts = is_string($f[4]) ? constant($f[4]) : $f[4];
                $values[$key] = array_key_exists($raw, $opts) ? $raw : (string)$f[2];
                break;
            case 'points':
                $rows = [];
                foreach ((array)($_POST[$key] ?? []) as $r) {
                    $rows[] = [
                        preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($r[0] ?? '')))),
                        mb_substr(trim((string)($r[1] ?? '')), 0, 80),
                        mb_substr(trim((string)($r[2] ?? '')), 0, 240),
                    ];
                }
                $values[$key] = json_encode(array_slice($rows, 0, (int)($f[4] ?? 3)), JSON_UNESCAPED_UNICODE);
                break;
            case 'image':
                if (!empty($_POST[$key . '__remove'])) {
                    settings_delete_image($current[$key] ?? null);
                    $values[$key] = '';
                }
                [$path, $err] = settings_store_image($_FILES[$key] ?? [], $key, $f[4] ?? 'logo');
                if ($err) { $errors[] = "{$label}: {$err}"; break; }
                if ($path) {
                    settings_delete_image($current[$key] ?? null);
                    $values[$key] = $path;
                }
                break;
            case 'password':
                if (!empty($_POST[$key . '__clear'])) {
                    $values[$key] = '';
                } elseif ((string)$raw !== '') {
                    $values[$key] = smtp_password_encrypt((string)$raw);
                }
                break;
            case 'textarea':
                $values[$key] = mb_substr(str_replace("\r\n", "\n", trim((string)$raw)), 0, 4000);
                break;
            default:
                $values[$key] = mb_substr(trim((string)$raw), 0, 500);
        }
    }

    foreach ($values as $k => $v) {
        $stmt->execute([$k, $v, $section]);
    }
    get_settings(true);
    log_activity('settings', 'Ayarlar güncellendi: ' . SETTINGS_SCHEMA[$section]['title'], null, null, '/modules/settings/index.php?tab=' . $section);
    if ($errors) {
        set_flash('warning', 'Diğer alanlar kaydedildi, ancak: ' . implode(' ', $errors));
    } else {
        set_flash('success', SETTINGS_SCHEMA[$section]['title'] . ' kaydedildi.');
    }
    redirect($self . $section);
}

$sec = SETTINGS_SCHEMA[$tab];
$page_title = 'Ayarlar';
require_once __DIR__ . '/../../includes/header.php';

/** Alan değeri (kayıtlıysa o, değilse varsayılan) */
$val = function (string $key, array $f) {
    if ($f[0] === 'points') {
        $rows = site_points($key);
        return array_pad($rows, (int)($f[4] ?? 3), ['', '', '']);
    }
    if ($f[0] === 'image' || $f[0] === 'password') {
        return $f[0] === 'image' ? get_setting($key, '') : '';
    }
    $all = get_settings();
    return array_key_exists($key, $all) ? (string)$all[$key] : (string)$f[2];
};
$extra_links = [
    ['/modules/platform/settings.php', 'sliders-horizontal', 'Platform kuralları', 'Termin, acil iş, seviye limitleri', 'platform.manage'],
    ['/modules/platform/catalog.php', 'tags', 'Hizmet kataloğu', 'Ajans fiyat listesi', 'platform.pricing'],
    ['/modules/settings/roles.php', 'shield-check', 'Roller ve kullanıcılar', 'Yetkiler, personel hesapları', null],
    ['/modules/taxes/index.php', 'percent', 'Vergi', 'Vergi takvimi ve hesaplar', null],
];
?>
<div class="page-head">
    <div>
        <h1 class="h1">Ayarlar</h1>
        <p class="sub">Marka, giriş sayfaları, şirket bilgileri, belgeler ve portal metinleri.</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <aside class="stack" style="min-width:0">
        <nav class="card" style="padding:6px">
            <?php foreach (SETTINGS_SCHEMA as $sk => $sv): ?>
                <a href="?tab=<?= $sk ?>" class="menu-item" style="<?= $tab === $sk ? 'background:var(--surface-3);color:var(--ink);font-weight:500' : '' ?>"><i data-lucide="<?= $sv['icon'] ?>"></i><?= e($sv['title']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="card" style="padding:6px">
            <p class="eyebrow" style="padding:8px 9px 4px">Diğer ayarlar</p>
            <?php foreach ($extra_links as [$href, $icon, $label, $hint, $perm]): if ($perm && !can_access_module($perm)) continue; ?>
                <a href="<?= BASE_URL . $href ?>" class="menu-item" style="align-items:flex-start"><i data-lucide="<?= $icon ?>" style="margin-top:2px"></i><span><span style="display:block"><?= e($label) ?></span><span class="xsmall text-muted"><?= e($hint) ?></span></span></a>
            <?php endforeach; ?>
        </div>
    </aside>

    <div class="lg:col-span-3" style="min-width:0">
        <?php if ($tab === 'bank'): $iy = iyzico_issues(); ?>
            <div class="alert <?= $iy ? 'alert-warning' : 'alert-success' ?>" style="margin-bottom:16px"><i data-lucide="credit-card"></i><div>
                <?php if (!$iy): ?><strong>Kartla ödeme açık</strong> (<?= site_setting('iyzico_mode') === 'live' ? 'canlı' : 'test / sandbox' ?>); ajanslar Ödemeler ekranında "Kartla öde" düğmesini görür.
                <?php else: ?><strong>Kartla ödeme ajanslara görünmüyor:</strong> <?= e(implode(' ', $iy)) ?><?php endif; ?>
            </div></div>
        <?php endif; ?>
        <form method="POST" action="?tab=<?= $tab ?>" enctype="multipart/form-data" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="<?= $tab ?>">
            <div class="card-head">
                <div><p class="card-title"><?= e($sec['title']) ?></p><p class="card-sub"><?= e($sec['desc']) ?></p></div>
                <?php if (!empty($sec['preview'])): ?>
                    <a href="<?= BASE_URL . $sec['preview'] . (str_contains($sec['preview'], '?') ? '&' : '?') ?>preview=1" target="_blank" class="btn btn-secondary btn-sm"><i data-lucide="external-link"></i>Önizle</a>
                <?php endif; ?>
            </div>
            <div class="divide">
            <?php foreach ($sec['fields'] as $key => $f):
                [$type, $label] = $f; $hint = $f[3] ?? ''; $v = $val($key, $f); ?>
                <div class="card-pad" style="display:grid;grid-template-columns:minmax(0,1fr);gap:8px">
                    <?php if ($type === 'bool'): ?>
                        <div style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                            <div><p class="small" style="font-weight:500"><?= e($label) ?></p><?php if ($hint): ?><p class="xsmall text-muted"><?= e($hint) ?></p><?php endif; ?></div>
                            <label class="switch"><input type="checkbox" name="<?= $key ?>" value="1" <?= $v === '1' ? 'checked' : '' ?>><span></span></label>
                        </div>

                    <?php elseif ($type === 'image'): $url = site_image($key); $kind = $f[4] ?? 'logo'; ?>
                        <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap" x-data="{ preview: null }">
                            <div style="width:<?= $kind === 'favicon' ? '64px' : '180px' ?>;height:64px;border:1px dashed var(--line);border-radius:10px;display:grid;place-items:center;flex-shrink:0;overflow:hidden;background:<?= in_array($key, ['brand_logo_dark', 'login_aside_image'], true) ? 'var(--sidebar)' : 'var(--surface-2)' ?>">
                                <template x-if="preview"><img :src="preview" style="max-width:100%;max-height:56px;object-fit:contain"></template>
                                <template x-if="!preview">
                                    <?php if ($url): ?><img src="<?= e($url) ?>" alt="" style="max-width:100%;max-height:<?= $key === 'login_aside_image' ? '64px;width:100%;object-fit:cover' : '56px;object-fit:contain' ?>">
                                    <?php else: ?><span class="xsmall text-faint">Görsel yok</span><?php endif; ?>
                                </template>
                            </div>
                            <div style="flex:1;min-width:220px" class="stack-sm">
                                <p class="small" style="font-weight:500"><?= e($label) ?></p>
                                <?php if ($hint): ?><p class="xsmall text-muted"><?= e($hint) ?></p><?php endif; ?>
                                <input type="file" name="<?= $key ?>" accept="<?= $kind === 'favicon' ? 'image/png,image/x-icon,image/vnd.microsoft.icon,image/svg+xml,image/webp' : 'image/png,image/jpeg,image/webp,image/svg+xml,image/gif' ?>" class="small"
                                       @change="const f = $event.target.files[0]; preview = f ? URL.createObjectURL(f) : null">
                                <?php if ($url): ?><label class="check xsmall"><input type="checkbox" name="<?= $key ?>__remove" value="1">Görseli kaldır</label><?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($type === 'points'): ?>
                        <p class="small" style="font-weight:500"><?= e($label) ?></p>
                        <?php if ($hint): ?><p class="xsmall text-muted"><?= e($hint) ?> <a class="link" href="https://lucide.dev/icons/" target="_blank" rel="noopener">Simge listesi</a></p><?php endif; ?>
                        <?php foreach ($v as $i => $row): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2" style="align-items:center">
                                <div class="sm:col-span-3" style="display:flex;gap:8px;align-items:center">
                                    <span style="width:30px;height:30px;display:grid;place-items:center;border:1px solid var(--line);border-radius:8px;flex-shrink:0"><i data-lucide="<?= e($row[0] ?: 'circle') ?>" style="width:15px;height:15px"></i></span>
                                    <input class="input" name="<?= $key ?>[<?= $i ?>][0]" value="<?= e($row[0] ?? '') ?>" placeholder="simge">
                                </div>
                                <input class="input sm:col-span-3" name="<?= $key ?>[<?= $i ?>][1]" value="<?= e($row[1] ?? '') ?>" placeholder="Kalın başlık">
                                <input class="input sm:col-span-6" name="<?= $key ?>[<?= $i ?>][2]" value="<?= e($row[2] ?? '') ?>" placeholder="Açıklama">
                            </div>
                        <?php endforeach; ?>
                        <p class="xsmall text-faint">Boş bırakılan satırlar gösterilmez.</p>

                    <?php else: ?>
                        <label class="label" for="f_<?= $key ?>"><?= e($label) ?></label>
                        <?php if ($type === 'password'): $has = get_setting($key, '') !== ''; ?>
                            <input class="input" id="f_<?= $key ?>" type="password" name="<?= $key ?>" value="" autocomplete="new-password" placeholder="<?= $has ? '•••••••• (kayıtlı)' : '' ?>" style="max-width:320px">
                            <?php if ($has): ?><label class="check xsmall"><input type="checkbox" name="<?= $key ?>__clear" value="1">Kayıtlı şifreyi sil</label><?php endif; ?>
                        <?php elseif ($type === 'textarea'): ?>
                            <textarea class="textarea" id="f_<?= $key ?>" name="<?= $key ?>" rows="3"><?= e($v) ?></textarea>
                        <?php elseif ($type === 'color'): ?>
                            <div style="display:flex;gap:8px;align-items:center" x-data="{ c: '<?= e($v) ?>' }">
                                <input type="color" x-model="c" style="width:42px;height:36px;border:1px solid var(--line);border-radius:8px;padding:2px;background:#fff">
                                <input class="input mono" id="f_<?= $key ?>" name="<?= $key ?>" x-model="c" maxlength="7" style="width:120px">
                                <button type="button" class="btn btn-ghost btn-sm" @click="c = '<?= e((string)$f[2]) ?>'">Varsayılan</button>
                            </div>
                        <?php elseif ($type === 'number'): [$min, $max, $unit] = $f[4]; ?>
                            <div class="input-group" style="width:180px"><input class="input" type="number" id="f_<?= $key ?>" name="<?= $key ?>" value="<?= e($v) ?>" min="<?= $min ?>" max="<?= $max ?>" step="any"><span class="addon"><?= e($unit) ?></span></div>
                        <?php elseif ($type === 'select'): $opts = is_string($f[4]) ? constant($f[4]) : $f[4]; ?>
                            <select class="select" id="f_<?= $key ?>" name="<?= $key ?>" style="max-width:320px">
                                <?php foreach ($opts as $ok => $ol): ?><option value="<?= e($ok) ?>" <?= $v === (string)$ok ? 'selected' : '' ?>><?= e(is_array($ol) ? ($ol['name'] ?? $ol['label'] ?? $ok) : $ol) ?></option><?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input class="input" id="f_<?= $key ?>" type="<?= $type === 'email' ? 'email' : ($type === 'url' ? 'url' : 'text') ?>" name="<?= $key ?>" value="<?= e($v) ?>">
                        <?php endif; ?>
                        <?php if ($hint): ?><span class="hint"><?= e($hint) ?></span><?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="card-foot" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;position:sticky;bottom:0;z-index:5">
                <button type="submit" form="reset-form" class="btn btn-ghost btn-sm" onclick="return confirm('Bu bölümdeki tüm alanlar varsayılan değerlere dönsün mü? Yüklenen görseller silinir.');">Varsayılana döndür</button>
                <button type="submit" name="action" value="save" class="btn btn-primary">Kaydet</button>
            </div>
        </form>
        <?php if ($tab === 'mail'): ?>
        <form method="POST" action="?tab=mail" class="card card-pad" style="margin-top:16px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><?= csrf_field() ?>
            <input type="hidden" name="section" value="mail"><input type="hidden" name="action" value="test_mail">
            <div class="field" style="flex:1;min-width:240px"><label class="label">Test e-postası gönder</label><input class="input" type="email" name="test_to" value="<?= e($user['email'] ?? '') ?>" required><span class="hint">Önce ayarları kaydedin. Gönderim sonucu ve varsa hata mesajı burada gösterilir.</span></div>
            <button class="btn btn-secondary"><i data-lucide="send"></i>Test gönder</button>
            <?php if (can_access_module('mail.manage')): ?><a href="<?= BASE_URL ?>/modules/mail/log.php" class="btn btn-ghost">Gönderim kayıtları</a><?php endif; ?>
        </form>
        <?php endif; ?>
        <form id="reset-form" method="POST" action="?tab=<?= $tab ?>"><?= csrf_field() ?><input type="hidden" name="section" value="<?= $tab ?>"><input type="hidden" name="action" value="reset"></form>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
