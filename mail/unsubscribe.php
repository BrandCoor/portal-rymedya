<?php
/**
 * ====================================================================
 * ABONELİKTEN ÇIKIŞ (HERKESE AÇIK)
 * ====================================================================
 * Toplu e-postalardaki "Bu listeden çık" bağlantısı. E-posta istemcilerinin
 * tek tıkla çıkış (List-Unsubscribe-Post) isteği de burada karşılanır.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$sub = null;
if (strlen($token) === 32) {
    $st = $db->prepare("SELECT * FROM mail_subscribers WHERE token = ?");
    $st->execute([$token]);
    $sub = $st->fetch() ?: null;
}
$done = false;
if ($sub && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $resub = ($_POST['action'] ?? '') === 'resubscribe';
    $db->prepare("UPDATE mail_subscribers SET status = ?, unsubscribed_at = ? WHERE id = ?")
       ->execute([$resub ? 'subscribed' : 'unsubscribed', $resub ? null : date('Y-m-d H:i:s'), $sub['id']]);
    // Kuyrukta bekleyen kampanya e-postaları iptal edilir
    if (!$resub) {
        $db->prepare("UPDATE mail_queue SET status = 'skipped', last_error = 'Abonelikten çıktı' WHERE subscriber_id = ? AND status = 'queued' AND kind = 'campaign'")->execute([$sub['id']]);
    }
    if (isset($_POST['List-Unsubscribe'])) {
        http_response_code(200);
        exit('OK');
    }
    $st = $db->prepare("SELECT * FROM mail_subscribers WHERE id = ?");
    $st->execute([$sub['id']]);
    $sub = $st->fetch();
    $done = true;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head><?php ui_head('E-posta aboneliği'); ?></head>
<body>
<main style="min-height:100vh;display:grid;place-items:center;padding:24px">
    <div class="card card-pad" style="max-width:440px;width:100%;padding:32px">
        <div style="margin-bottom:24px"><?= brand_html('light') ?></div>
        <?php if (!$sub): ?>
            <h1 class="h2">Bağlantı geçersiz</h1>
            <p class="small text-muted" style="margin-top:8px">Bu bağlantı süresi dolmuş veya hatalı olabilir. Listeden çıkmak için bize e-posta ile yazabilirsiniz<?= site_setting('portal_support_email') !== '' ? ': ' . e(site_setting('portal_support_email')) : '' ?>.</p>
        <?php elseif ($sub['status'] === 'unsubscribed'): ?>
            <h1 class="h2">Listeden çıktınız</h1>
            <p class="small text-muted" style="margin-top:8px"><strong><?= e($sub['email']) ?></strong> adresine artık duyuru ve kampanya e-postası göndermeyeceğiz. İşleriniz ve hesabınızla ilgili bilgilendirmeler gelmeye devam eder.</p>
            <form method="POST" style="margin-top:20px"><input type="hidden" name="action" value="resubscribe"><button class="btn btn-secondary">Yanlışlıkla mı oldu? Yeniden abone ol</button></form>
        <?php else: ?>
            <h1 class="h2">E-posta listesinden çık</h1>
            <p class="small text-muted" style="margin-top:8px"><strong><?= e($sub['email']) ?></strong> adresine duyuru ve kampanya e-postası gönderilmesini durdurmak istiyor musunuz?</p>
            <form method="POST" style="margin-top:20px"><input type="hidden" name="action" value="unsubscribe"><button class="btn btn-primary">Listeden çık</button></form>
            <?php if ($done): ?><p class="xsmall text-muted" style="margin-top:12px">Yeniden abone oldunuz.</p><?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php ui_icons_init(); ?>
</body>
</html>
