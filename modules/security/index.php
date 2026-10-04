<?php
/**
 * ====================================================================
 * GÜVENLİK MERKEZİ (yalnızca süper yönetici)
 * ====================================================================
 * Durum özeti · giriş kayıtları · iki adımlı doğrulama durumları
 * (sıfırlama) · veritabanı yedekleri (al / indir / sil)
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
if (!is_super_admin()) {
    set_flash('error', 'Güvenlik merkezi yalnızca süper yönetici içindir.');
    redirect(BASE_URL . '/modules/dashboard/index.php');
}
$tab = in_array($_GET['tab'] ?? '', ['logins', 'twofa', 'backups'], true) ? $_GET['tab'] : 'overview';
$self = BASE_URL . '/modules/security/index.php?tab=';
$staff_id = (int)current_user()['id'];

// Yedek indirme
if (($_GET['download'] ?? '') !== '') {
    $p = backup_file_path((string)$_GET['download']);
    if (!$p) {
        http_response_code(404);
        exit('Yedek bulunamadı.');
    }
    log_activity('security', 'Veritabanı yedeği indirildi: ' . basename($p));
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($p));
    header('Content-Disposition: attachment; filename="' . basename($p) . '"');
    readfile($p);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'backup_now') {
        [$name, $err] = backup_run();
        log_activity('security', $err ? 'Yedek alınamadı' : "Elle yedek alındı: {$name}");
        set_flash($err ? 'error' : 'success', $err ?: "Yedek alındı: {$name}");
        redirect($self . 'backups');
    }
    if ($action === 'backup_delete') {
        $p = backup_file_path((string)($_POST['name'] ?? ''));
        if ($p && count(backup_list()) > 1) {
            @unlink($p);
            log_activity('security', 'Yedek silindi: ' . basename($p));
            set_flash('success', 'Yedek silindi.');
        } else {
            set_flash('error', 'Son kalan yedek silinemez.');
        }
        redirect($self . 'backups');
    }
    if ($action === 'twofa_reset') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $u = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $u->execute([$uid]);
        if ($row = $u->fetch()) {
            twofa_disable($uid);
            log_activity('security', "İki adımlı doğrulama yönetici tarafından sıfırlandı: {$row['email']}", 'user', $uid, null, $uid);
            set_flash('success', "{$row['full_name']} için iki adımlı doğrulama sıfırlandı; bir sonraki girişte yeniden kurabilir.");
        }
        redirect($self . 'twofa');
    }
    redirect($self . $tab);
}

// Durum özeti
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(BASE_URL, 'https://');
$last_backup = (int)get_setting('backup_last_run', '0');
$backups = backup_list();
$bdir = backup_dir();
$bdir_public = $bdir !== '' && str_starts_with(realpath($bdir) ?: $bdir, realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2));
$staff_total = (int)$db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' AND (u.role_id = 1 OR (r.role_slug NOT IN ('client','agency','freelancer') AND u.contact_id IS NULL))")->fetchColumn();
$staff_2fa = (int)$db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' AND u.totp_enabled = 1 AND (u.role_id = 1 OR (r.role_slug NOT IN ('client','agency','freelancer') AND u.contact_id IS NULL))")->fetchColumn();
$fail_24h = (int)$db->query("SELECT COUNT(*) FROM login_log WHERE success = 0 AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
$auto_last = (int)get_setting('platform_automation_last_run', '0');
$checks = [
    [$https, 'HTTPS kullanılıyor', $https ? 'Site adresi https ile başlıyor.' : 'config/db.php içindeki BASE_URL https olmalı.'],
    [twofa_mode('staff') !== 'off', 'Personel iki adımlı doğrulama', 'Mod: ' . ['off' => 'kapalı', 'optional' => 'isteğe bağlı', 'required' => 'zorunlu'][twofa_mode('staff')] . " · {$staff_2fa}/{$staff_total} personel açmış"],
    [$last_backup > time() - 2 * 86400, 'Güncel veritabanı yedeği', $last_backup ? 'Son yedek: ' . date('d.m.Y H:i', $last_backup) : 'Henüz yedek alınmadı.'],
    [$bdir !== '' && !$bdir_public, 'Yedekler web dışında', $bdir === '' ? 'Yedek klasörü yazılamıyor.' : ($bdir_public ? 'Yedekler proje klasöründe (.htaccess ile kapalı). Mümkünse web kökü dışında bir klasör girin.' : 'Klasör: ' . $bdir)],
    [$auto_last > time() - 3 * 3600, 'Otomasyonlar çalışıyor', $auto_last ? 'Son çalışma: ' . date('d.m.Y H:i', $auto_last) . ' (kart ödeme mutabakatı, hatırlatmalar, yedek)' : 'Henüz çalışmadı; cron/platform.php tanımlayın.'],
    [(int)site_setting('security_idle_staff') > 0, 'Hareketsizlikte otomatik çıkış', (int)site_setting('security_idle_staff') > 0 ? 'Personel: ' . (int)site_setting('security_idle_staff') . ' dk · portal: ' . ((int)site_setting('security_idle_portal') ?: 'kapalı') . ' dk' : 'Kapalı.'],
    [mail_enabled(), 'E-posta gönderimi', mail_enabled() ? 'Şifre sıfırlama ve yeni cihaz uyarıları gönderilebilir.' : 'Kapalı: şifre sıfırlama e-postaları gitmez. Ayarlar → E-posta.'],
];

$logins = $tab === 'logins' ? $db->query("SELECT l.*, u.full_name FROM login_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 300")->fetchAll() : [];
$users2fa = $tab === 'twofa' ? $db->query("SELECT u.id, u.full_name, u.email, u.totp_enabled, u.totp_enabled_at, u.last_login, r.role_name, r.role_slug, u.contact_id, u.role_id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active' ORDER BY u.totp_enabled DESC, u.full_name")->fetchAll() : [];

$page_title = 'Güvenlik';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h1">Güvenlik</h1>
        <p class="sub">Durum, giriş kayıtları, iki adımlı doğrulama ve veritabanı yedekleri. Ayarlar için <a class="link" href="<?= BASE_URL ?>/modules/settings/index.php?tab=security">Ayarlar → Güvenlik</a>.</p>
    </div>
</div>
<nav class="tabs" style="margin-bottom:16px">
    <?php foreach (['overview' => 'Durum', 'logins' => 'Giriş kayıtları', 'twofa' => 'İki adımlı doğrulama', 'backups' => 'Yedekler'] as $k => $l): ?>
        <a href="?tab=<?= $k ?>" class="tab <?= $tab === $k ? 'is-active' : '' ?>"><?= $l ?><?= $k === 'logins' && $fail_24h ? '<span class="count">' . $fail_24h . '</span>' : '' ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
<section class="card"><div class="divide">
    <?php foreach ($checks as [$ok, $label, $info]): ?>
    <div class="card-pad-sm" style="display:flex;gap:12px;align-items:center">
        <i data-lucide="<?= $ok ? 'circle-check' : 'circle-alert' ?>" style="width:20px;height:20px;color:<?= $ok ? 'var(--success)' : 'var(--warning)' ?>;flex-shrink:0"></i>
        <div><p class="small" style="font-weight:500"><?= e($label) ?></p><p class="xsmall text-muted"><?= e($info) ?></p></div>
    </div>
    <?php endforeach; ?>
</div></section>
<p class="xsmall text-muted" style="margin-top:12px">Son 24 saatte <?= $fail_24h ?> başarısız giriş denemesi. Aynı e-posta veya IP'den <?= LOGIN_MAX_ATTEMPTS ?> hatalı denemede giriş <?= LOGIN_LOCKOUT_MINUTES ?> dakika kilitlenir.</p>

<?php elseif ($tab === 'logins'): ?>
<section class="card">
    <?php if (!$logins): ?><?= ui_empty('Kayıt yok', 'Girişler burada listelenir.', 'log-in') ?><?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>Alan</th><th>IP</th><th>Tarayıcı</th><th>Sonuç</th></tr></thead>
        <tbody><?php foreach ($logins as $l): ?>
            <tr><td class="small" style="white-space:nowrap"><?= format_date($l['created_at'], true) ?></td>
                <td class="small"><?= e($l['full_name'] ?? '—') ?><div class="xsmall text-muted"><?= e($l['email'] ?? '') ?></div></td>
                <td class="xsmall"><?= $l['area'] === 'portal' ? 'Portal' : 'Personel' ?></td>
                <td class="xsmall num"><?= e($l['ip'] ?? '') ?></td>
                <td class="xsmall text-muted" style="max-width:260px"><?= e(mb_strimwidth((string)$l['user_agent'], 0, 70, '…')) ?></td>
                <td><?= (int)$l['success'] === 1 ? ui_badge($l['reason'] === '2fa' ? 'Başarılı · 2FA' : 'Başarılı', 'success', true) : ui_badge('Başarısız', 'danger', true) ?><?= (int)$l['success'] === 0 && $l['reason'] ? '<div class="xsmall text-muted">' . e($l['reason']) . '</div>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'twofa'): ?>
<p class="small text-muted" style="margin-bottom:12px">Personel: <strong><?= ['off' => 'kapalı', 'optional' => 'isteğe bağlı', 'required' => 'zorunlu'][twofa_mode('staff')] ?></strong> · Portal: <strong><?= ['off' => 'kapalı', 'optional' => 'isteğe bağlı', 'required' => 'zorunlu'][twofa_mode('portal')] ?></strong>. Telefonunu kaybeden ve kurtarma kodu olmayan kullanıcı için sıfırlayın; bir sonraki girişte yeniden kurar.</p>
<section class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Kullanıcı</th><th>Rol</th><th>Son giriş</th><th>İki adımlı doğrulama</th><th></th></tr></thead>
    <tbody><?php foreach ($users2fa as $u): ?>
        <tr><td class="small"><?= e($u['full_name']) ?><div class="xsmall text-muted"><?= e($u['email']) ?></div></td>
            <td class="xsmall"><?= e($u['role_name']) ?></td>
            <td class="xsmall"><?= $u['last_login'] ? format_date($u['last_login'], true) : '—' ?></td>
            <td><?= (int)$u['totp_enabled'] === 1 ? ui_badge('Açık', 'success', true) . '<div class="xsmall text-muted">' . format_date($u['totp_enabled_at']) . '</div>' : ui_badge('Kapalı', 'neutral', true) ?></td>
            <td class="r"><?php if ((int)$u['totp_enabled'] === 1): ?><form method="POST" action="" onsubmit="return confirm('Bu kullanıcının iki adımlı doğrulaması sıfırlansın mı?');"><?= csrf_field() ?><input type="hidden" name="action" value="twofa_reset"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-ghost btn-sm">Sıfırla</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div></section>

<?php else: ?>
<section class="card" style="margin-bottom:16px">
    <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
        <div><p class="small" style="font-weight:500">Veritabanı yedekleri</p>
            <p class="xsmall text-muted">Klasör: <?= e($bdir ?: 'yazılabilir klasör yok') ?> · <?= site_setting('backup_enabled') === '1' ? 'günlük otomatik' : 'otomatik yedek kapalı' ?> · <?= (int)site_setting('backup_keep_days') ?> gün saklanır. Dosyalar sıkıştırılmış SQL'dir; phpMyAdmin → İçe aktar ile geri yüklenir.</p></div>
        <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="backup_now"><button class="btn btn-primary btn-sm"><i data-lucide="database-backup"></i>Şimdi yedek al</button></form>
    </div>
</section>
<section class="card">
    <?php if (!$backups): ?><?= ui_empty('Henüz yedek yok', '"Şimdi yedek al" ile ilk yedeği alın.', 'database') ?><?php else: ?>
    <div class="divide"><?php foreach ($backups as $b): ?>
        <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
            <div><p class="small" style="font-weight:500;font-family:var(--font-mono,monospace)"><?= e($b['name']) ?></p><p class="xsmall text-muted"><?= date('d.m.Y H:i', $b['time']) ?> · <?= number_format($b['size'] / 1024, 0, ',', '.') ?> KB</p></div>
            <div style="display:flex;gap:6px">
                <a class="btn btn-secondary btn-sm" href="?download=<?= urlencode($b['name']) ?>"><i data-lucide="download"></i>İndir</a>
                <form method="POST" action="" onsubmit="return confirm('Yedek silinsin mi?');"><?= csrf_field() ?><input type="hidden" name="action" value="backup_delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="icon-btn" title="Sil"><i data-lucide="trash-2"></i></button></form>
            </div>
        </div>
    <?php endforeach; ?></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
