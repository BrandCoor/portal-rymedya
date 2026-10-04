<?php
/**
 * ====================================================================
 * GÜNCEL SÖZLEŞMELERİ ONAYLA
 * ====================================================================
 * Portal kullanıcısı, zorunlu metinlerin güncel sürümünü onaylamadıysa
 * (ilk giriş veya yönetici "yeniden onay iste" dediyse) buraya yönlendirilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

require_client_login(['client', 'agency', 'freelancer']);
$uid  = (int)$_SESSION['client_user_id'];
$role = $_SESSION['client_user']['role'] ?? 'client';
$home = portal_home_url($role);
$pending = legal_pending($uid, $role);
$error = '';

$return = (string)($_SESSION['legal_return'] ?? '');
$target = (str_starts_with($return, '/') && !str_starts_with($return, '//')) ? $return : $home;

if (!$pending) {
    unset($_SESSION['legal_return']);
    redirect($target);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ok = array_filter($pending, fn($s) => !empty($_POST['doc'][$s]));
    if (count($ok) !== count($pending)) {
        $error = 'Devam etmek için listelenen tüm metinleri okuyup onaylamanız gerekir.';
    } else {
        legal_record($uid, $_SESSION['client_user']['email'] ?? null, $pending, 'reaccept');
        log_activity('legal', 'Güncel sözleşmeler onaylandı: ' . implode(', ', $pending), 'user', $uid, null, $uid);
        unset($_SESSION['legal_return']);
        set_flash('success', 'Teşekkürler, onayınız kaydedildi.');
        redirect($target);
    }
}

$first = !legal_last($uid, 'kullanim-kosullari');
auth_page_open('Sözleşme onayı', $role === 'agency' ? 'register_agency' : ($role === 'freelancer' ? 'register_freelancer' : 'login_portal'));
?>
<h1 class="h1"><?= $first ? 'Sözleşmeleri onaylayın' : 'Sözleşmelerimiz güncellendi' ?></h1>
<p class="small text-muted" style="margin-top:8px">
    <?= $first
        ? 'Platformu kullanmaya devam etmek için aşağıdaki metinleri okuyup onaylamanız gerekiyor.'
        : 'Aşağıdaki metinlerin yeni sürümü yayımlandı. Platformu kullanmaya devam etmek için güncel sürümü okuyup onaylamanız gerekiyor.' ?>
</p>
<?php if ($error): ?><div class="alert alert-danger" style="margin-top:16px"><i data-lucide="alert-circle"></i><span><?= e($error) ?></span></div><?php endif; ?>

<form method="POST" action="" class="stack" style="margin-top:20px"><?= csrf_field() ?>
    <?php foreach ($pending as $s): $d = legal_doc($s); ?>
        <label class="check option-card" style="padding:12px 14px;align-items:flex-start">
            <input type="checkbox" name="doc[<?= e($s) ?>]" value="1" required>
            <span class="small">
                <?= legal_link($s) ?> <span class="xsmall text-muted">(sürüm <?= (int)$d['version'] ?>, <?= e(format_date($d['published_at'])) ?>)</span><br>
                <?= $s === 'kvkk' ? 'metnini okudum, kişisel verilerimin işlenmesi hakkında bilgilendirildim.' : 'metnini okudum, anladım ve kabul ediyorum.' ?>
            </span>
        </label>
    <?php endforeach; ?>
    <button class="btn btn-primary btn-block">Onayla ve devam et</button>
</form>
<p class="xsmall text-muted" style="margin-top:16px;text-align:center">Kabul etmiyorsanız <a class="link" href="<?= BASE_URL ?>/client/logout.php">çıkış yapabilir</a>, hesabınızın kapatılması için <?= site_setting('company_email') !== '' ? e(site_setting('company_email')) . ' adresinden ' : '' ?>bizimle iletişime geçebilirsiniz.</p>
<?php auth_page_close();
