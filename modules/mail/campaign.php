<?php
/**
 * ====================================================================
 * E-POSTA MERKEZİ - KAMPANYA (TOPLU GÖNDERİM)
 * ====================================================================
 * Taslak: konu, içerik (zengin metin), hedef kitle
 * Gönderim: test e-postası → kuyruğa al → parti parti gönder
 * Her alıcıya kişiselleştirilmiş içerik ve abonelikten çıkış bağlantısı
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
require_module_permission('mail.manage');
$user = current_user();
$id = (int)($_GET['id'] ?? 0);
$camp = null;
if ($id) {
    $st = $db->prepare("SELECT * FROM mail_campaigns WHERE id = ?");
    $st->execute([$id]);
    $camp = $st->fetch() ?: null;
    if (!$camp) {
        set_flash('error', 'Kampanya bulunamadı.');
        redirect(BASE_URL . '/modules/mail/index.php');
    }
}
$self = BASE_URL . '/modules/mail/campaign.php' . ($id ? "?id={$id}" : '');

function audience_from_post(): array {
    return [
        'kinds' => array_values(array_intersect(array_keys(MAIL_SUBSCRIBER_KINDS), (array)($_POST['kinds'] ?? $_GET['kinds'] ?? []))),
        'tiers' => array_values(array_intersect(array_keys(FREELANCER_TIERS), (array)($_POST['tiers'] ?? $_GET['tiers'] ?? []))),
        'tag' => mb_substr(trim((string)($_POST['tag'] ?? $_GET['tag'] ?? '')), 0, 40),
        'consent_only' => !empty($_POST['consent_only'] ?? $_GET['consent_only'] ?? null),
    ];
}

// Canlı alıcı sayısı
if (isset($_GET['count'])) {
    header('Content-Type: application/json');
    echo json_encode(['count' => mail_audience(audience_from_post(), true)]);
    exit;
}

// Önizleme (iframe)
if (isset($_GET['preview']) && $camp) {
    $sample = ['name' => $user['full_name'] ?? 'Ayşe Yılmaz', 'company' => 'Örnek Ajans', 'email' => $user['email'] ?? 'ornek@firma.com', 'token' => str_repeat('0', 32)];
    echo mail_render(mail_merge($camp['body_html'], $sample), ['preheader' => $camp['preheader'] ?? '', 'unsubscribe_url' => '#', 'title' => $camp['subject']]);
    exit;
}

$editable = !$camp || $camp['status'] === 'draft';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['save', 'save_review'], true) && $editable) {
        $name    = trim($_POST['name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body    = mail_sanitize_html((string)($_POST['body_html'] ?? ''));
        if ($subject === '' || trim(strip_tags($body, '<img>')) === '') {
            set_flash('error', 'Konu ve içerik zorunlu.');
            redirect($self);
        }
        $aud = json_encode(audience_from_post(), JSON_UNESCAPED_UNICODE);
        if ($camp) {
            $db->prepare("UPDATE mail_campaigns SET name = ?, subject = ?, preheader = ?, body_html = ?, audience = ? WHERE id = ?")
               ->execute([$name ?: $subject, $subject, trim($_POST['preheader'] ?? ''), $body, $aud, $id]);
        } else {
            $db->prepare("INSERT INTO mail_campaigns (name, subject, preheader, body_html, audience, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'draft', ?, NOW())")
               ->execute([$name ?: $subject, $subject, trim($_POST['preheader'] ?? ''), $body, $aud, $user['id']]);
            $id = (int)$db->lastInsertId();
        }
        set_flash('success', 'Taslak kaydedildi.');
        redirect(BASE_URL . "/modules/mail/campaign.php?id={$id}" . ($action === 'save_review' ? '#gonder' : ''));
    }

    if (!$camp) {
        redirect($self);
    }

    if ($action === 'test') {
        $to = trim($_POST['test_to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Test için geçerli bir adres girin.');
            redirect($self . '#gonder');
        }
        $sample = ['name' => $user['full_name'] ?? '', 'company' => '', 'email' => $to, 'token' => str_repeat('0', 32)];
        $html = mail_render(mail_merge($camp['body_html'], $sample), ['preheader' => $camp['preheader'] ?? '', 'unsubscribe_url' => BASE_URL . '/mail/unsubscribe.php', 'title' => $camp['subject']]);
        $err = mail_send_direct($to, '[TEST] ' . $camp['subject'], $html);
        set_flash($err ? 'error' : 'success', $err ? 'Test e-postası gönderilemedi: ' . $err : "Test e-postası {$to} adresine gönderildi.");
        redirect($self . '#gonder');
    }

    if ($action === 'send' && $camp['status'] === 'draft') {
        if (!mail_enabled()) {
            set_flash('error', 'E-posta gönderimi kapalı. Ayarlar → E-posta bölümünden açın.');
            redirect($self . '#gonder');
        }
        mail_sync_subscribers();
        $aud = json_decode((string)$camp['audience'], true) ?: [];
        $list = mail_audience($aud);
        if (!$list) {
            set_flash('error', 'Seçilen hedef kitlede abone yok.');
            redirect($self . '#gonder');
        }
        $db->beginTransaction();
        try {
            foreach ($list as $sub) {
                $unsub = mail_unsubscribe_url($sub);
                $html = mail_render(mail_merge($camp['body_html'], $sub), ['preheader' => $camp['preheader'] ?? '', 'unsubscribe_url' => $unsub, 'title' => $camp['subject']]);
                mail_queue_add($sub['email'], $sub['name'], strtr($camp['subject'], ['{ad}' => explode(' ', trim((string)$sub['name']))[0] ?? '', '{firma}' => (string)$sub['company']]), $html,
                    ['kind' => 'campaign', 'campaign_id' => $id, 'subscriber_id' => (int)$sub['id'], 'user_id' => $sub['user_id'] ? (int)$sub['user_id'] : null, 'unsubscribe_url' => $unsub]);
            }
            $db->prepare("UPDATE mail_campaigns SET status = 'sending', total = ?, sent = 0, failed = 0, queued_at = NOW() WHERE id = ?")->execute([count($list), $id]);
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }
        log_activity('mail_campaign', "Toplu e-posta gönderimi başladı: {$camp['subject']} (" . count($list) . ' alıcı)', null, null, "/modules/mail/campaign.php?id={$id}");
        set_flash('success', count($list) . ' alıcı için gönderim başladı. Bu sayfa açık kaldıkça e-postalar parti parti gönderilir; zamanlanmış görev tanımlıysa sayfayı kapatabilirsiniz.');
        redirect($self);
    }

    if ($action === 'cancel' && $camp['status'] === 'sending') {
        $db->prepare("UPDATE mail_queue SET status = 'skipped', last_error = 'Kampanya durduruldu' WHERE campaign_id = ? AND status = 'queued'")->execute([$id]);
        $db->prepare("UPDATE mail_campaigns SET status = 'cancelled', finished_at = NOW() WHERE id = ?")->execute([$id]);
        mail_campaign_refresh($id);
        set_flash('success', 'Gönderim durduruldu; bekleyen e-postalar iptal edildi.');
        redirect($self);
    }

    if ($action === 'retry_failed') {
        $db->prepare("UPDATE mail_queue SET status = 'queued', attempts = 0 WHERE campaign_id = ? AND status = 'failed'")->execute([$id]);
        $db->prepare("UPDATE mail_campaigns SET status = 'sending', finished_at = NULL WHERE id = ?")->execute([$id]);
        set_flash('success', 'Hatalı e-postalar yeniden kuyruğa alındı.');
        redirect($self);
    }

    if ($action === 'duplicate') {
        $db->prepare("INSERT INTO mail_campaigns (name, subject, preheader, body_html, audience, status, created_by, created_at) SELECT CONCAT(name, ' (kopya)'), subject, preheader, body_html, audience, 'draft', ?, NOW() FROM mail_campaigns WHERE id = ?")
           ->execute([$user['id'], $id]);
        set_flash('success', 'Kampanya kopyalandı.');
        redirect(BASE_URL . '/modules/mail/campaign.php?id=' . (int)$db->lastInsertId());
    }

    if ($action === 'delete' && $camp['status'] !== 'sending') {
        $db->prepare("DELETE FROM mail_queue WHERE campaign_id = ? AND status IN ('queued', 'skipped')")->execute([$id]);
        $db->prepare("DELETE FROM mail_campaigns WHERE id = ?")->execute([$id]);
        set_flash('success', 'Kampanya silindi.');
        redirect(BASE_URL . '/modules/mail/index.php');
    }
    redirect($self);
}

$aud = $camp ? (json_decode((string)$camp['audience'], true) ?: []) : ['kinds' => ['client', 'agency'], 'tiers' => [], 'tag' => '', 'consent_only' => false];
$kind_counts = $db->query("SELECT kind, COUNT(*) FROM mail_subscribers WHERE status = 'subscribed' GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
$tags = [];
foreach ($db->query("SELECT tags FROM mail_subscribers WHERE tags IS NOT NULL AND tags != ''")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    foreach (explode(',', $t) as $x) if (trim($x) !== '') $tags[trim($x)] = true;
}
$recipients = $camp ? mail_audience($aud, true) : 0;
$camp_status = ['draft' => ['Taslak', 'neutral'], 'sending' => ['Gönderiliyor', 'warning'], 'sent' => ['Gönderildi', 'success'], 'cancelled' => ['Durduruldu', 'danger']];

$page_title = $camp ? $camp['name'] : 'Yeni toplu e-posta';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <div class="crumb"><a href="<?= BASE_URL ?>/modules/mail/index.php">E-posta</a><i data-lucide="chevron-right" style="width:13px;height:13px"></i><span><?= $camp ? 'Kampanya' : 'Yeni' ?></span></div>
        <h1 class="h1"><?= e($camp['name'] ?? 'Yeni toplu e-posta') ?></h1>
        <?php if ($camp): [$sl, $stn] = $camp_status[$camp['status']] ?? [$camp['status'], 'neutral']; ?><p class="sub" style="display:flex;gap:8px;align-items:center"><?= ui_badge($sl, $stn, true) ?> <span><?= format_date($camp['created_at'], true) ?></span></p><?php endif; ?>
    </div>
    <?php if ($camp): ?>
    <div style="display:flex;gap:8px">
        <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><button class="btn btn-secondary"><i data-lucide="copy"></i>Kopyala</button></form>
        <?php if ($camp['status'] !== 'sending'): ?>
        <form method="POST" action="" onsubmit="return confirm('Kampanya silinsin mi?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-ghost" style="color:var(--danger)">Sil</button></form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!mail_enabled()): ?>
    <div class="alert alert-warning" style="margin-bottom:16px"><i data-lucide="mail-warning"></i><div>E-posta gönderimi henüz açık değil. Taslak hazırlayabilirsiniz; göndermek için <a class="link" href="<?= BASE_URL ?>/modules/settings/index.php?tab=mail">Ayarlar → E-posta</a> bölümünü tamamlayın.</div></div>
<?php endif; ?>

<?php if ($camp && $camp['status'] !== 'draft'):
    $pct = $camp['total'] ? round(($camp['sent'] + $camp['failed']) / $camp['total'] * 100) : 0; ?>
<section class="card" style="margin-bottom:20px" x-data="campaignProgress(<?= $id ?>, '<?= e($camp['status']) ?>')" x-init="start()">
    <div class="card-pad">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
            <div>
                <p class="eyebrow">Gönderim durumu</p>
                <p style="font-size:20px;font-weight:600;margin-top:4px"><span x-text="sent"><?= (int)$camp['sent'] ?></span> / <?= (int)$camp['total'] ?> gönderildi <span class="small" style="color:var(--danger)" x-show="failed > 0" x-text="'· ' + failed + ' hatalı'"></span></p>
            </div>
            <div style="display:flex;gap:8px">
                <?php if ($camp['status'] === 'sending'): ?>
                    <form method="POST" action="" onsubmit="return confirm('Gönderim durdurulsun mu?');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-secondary btn-sm">Durdur</button></form>
                <?php endif; ?>
                <?php if ((int)$camp['failed'] > 0): ?>
                    <form method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="retry_failed"><button class="btn btn-secondary btn-sm">Hatalıları yeniden dene</button></form>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/modules/mail/log.php?campaign=<?= $id ?>">Alıcı kayıtları</a>
            </div>
        </div>
        <div class="progress" style="margin-top:14px"><span :style="'width:' + pct + '%'" style="width:<?= $pct ?>%"></span></div>
        <p class="xsmall text-muted" style="margin-top:8px" x-text="note"><?= $camp['status'] === 'sending' ? 'Gönderiliyor…' : ($camp['finished_at'] ? 'Tamamlandı: ' . format_date($camp['finished_at'], true) : '') ?></p>
    </div>
</section>
<?php endif; ?>

<?php if ($editable): ?>
<form method="POST" action="" class="grid grid-cols-1 xl:grid-cols-3 gap-6" x-data="composer()" @submit="sync()">
    <?= csrf_field() ?>
    <div class="xl:col-span-2 stack-lg" style="min-width:0">
        <section class="card">
            <div class="card-pad stack">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="field"><label class="label">Konu <span class="req">*</span></label><input class="input" name="subject" required value="<?= e($camp['subject'] ?? '') ?>" placeholder="Yaz sezonu çekim paketleri"></div>
                    <div class="field"><label class="label">Önizleme metni</label><input class="input" name="preheader" value="<?= e($camp['preheader'] ?? '') ?>" placeholder="Gelen kutusunda konunun yanında görünür"></div>
                </div>
                <div class="field"><label class="label">Kampanya adı (iç kullanım)</label><input class="input" name="name" value="<?= e($camp['name'] ?? '') ?>" placeholder="Boşsa konu kullanılır"></div>
            </div>
        </section>
        <section class="card">
            <div style="display:flex;gap:4px;flex-wrap:wrap;padding:8px 10px;border-bottom:1px solid var(--line-2);position:sticky;top:60px;background:var(--surface);z-index:4;border-radius:12px 12px 0 0">
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('bold')" title="Kalın"><i data-lucide="bold"></i></button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('italic')" title="İtalik"><i data-lucide="italic"></i></button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('underline')" title="Altı çizili"><i data-lucide="underline"></i></button>
                <span style="width:1px;background:var(--line);margin:4px 2px"></span>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('formatBlock', 'h2')">Başlık</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('formatBlock', 'h3')">Alt başlık</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('formatBlock', 'p')">Paragraf</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('insertUnorderedList')" title="Madde"><i data-lucide="list"></i></button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('insertOrderedList')" title="Numaralı"><i data-lucide="list-ordered"></i></button>
                <span style="width:1px;background:var(--line);margin:4px 2px"></span>
                <button type="button" class="btn btn-ghost btn-sm" @click="link()" title="Bağlantı"><i data-lucide="link"></i></button>
                <button type="button" class="btn btn-ghost btn-sm" @click="button()"><i data-lucide="square-mouse-pointer"></i>Buton</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="image()" title="Görsel (https adresi)"><i data-lucide="image"></i></button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('insertHorizontalRule')" title="Ayraç"><i data-lucide="minus"></i></button>
                <button type="button" class="btn btn-ghost btn-sm" @click="cmd('removeFormat')" title="Biçimi temizle"><i data-lucide="remove-formatting"></i></button>
                <span style="width:1px;background:var(--line);margin:4px 2px"></span>
                <select class="select" style="height:30px;width:auto;font-size:12.5px" @change="insert($event.target.value); $event.target.value = ''">
                    <option value="">Kişiselleştir…</option>
                    <option value="{ad}">Ad</option><option value="{adsoyad}">Ad soyad</option><option value="{firma}">Firma</option><option value="{eposta}">E-posta</option>
                </select>
            </div>
            <div x-ref="ed" contenteditable="true" class="prose-text" style="min-height:360px;padding:22px 24px;outline:none;white-space:normal;font-size:15px;line-height:1.65"><?= $camp['body_html'] ?? '<p>Merhaba {ad},</p><p></p>' ?></div>
            <textarea name="body_html" x-ref="out" hidden></textarea>
            <div class="card-foot xsmall text-muted">Kişiselleştirme etiketleri alıcıya göre doldurulur: <span class="mono">{ad}</span>, <span class="mono">{adsoyad}</span>, <span class="mono">{firma}</span>, <span class="mono">{eposta}</span>. Her e-postanın altına otomatik olarak "Bu listeden çık" bağlantısı eklenir.</div>
        </section>
    </div>

    <aside class="stack-lg" style="min-width:0">
        <section class="card sticky-summary" x-data="audience()" x-init="count()">
            <div class="card-head"><div><p class="card-title">Hedef kitle</p><p class="card-sub">Yalnızca abone durumundaki adresler.</p></div></div>
            <div class="card-pad stack" @change="count()">
                <div class="stack-sm">
                    <?php foreach (MAIL_SUBSCRIBER_KINDS as $k => $l): ?>
                        <label class="check" style="justify-content:space-between"><span style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="kinds[]" value="<?= $k ?>" <?= in_array($k, (array)($aud['kinds'] ?? []), true) ? 'checked' : '' ?>><?= e($l) ?></span><span class="xsmall text-muted num"><?= (int)($kind_counts[$k] ?? 0) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="field">
                    <span class="label">Freelancer seviyesi</span>
                    <div style="display:flex;gap:6px 12px;flex-wrap:wrap">
                        <?php foreach (FREELANCER_TIERS as $tk => $tv): ?><label class="check xsmall"><input type="checkbox" name="tiers[]" value="<?= $tk ?>" <?= in_array($tk, (array)($aud['tiers'] ?? []), true) ? 'checked' : '' ?>><?= e($tv['label']) ?></label><?php endforeach; ?>
                    </div>
                    <span class="hint">Boşsa tüm seviyeler.</span>
                </div>
                <div class="field"><label class="label">Etiket</label>
                    <input class="input" name="tag" list="tag-list" value="<?= e($aud['tag'] ?? '') ?>" placeholder="Tüm etiketler" @input.debounce.400ms="count()">
                    <datalist id="tag-list"><?php foreach (array_keys($tags) as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
                </div>
                <label class="check small"><input type="checkbox" name="consent_only" value="1" <?= !empty($aud['consent_only']) ? 'checked' : '' ?>>Yalnızca ticari ileti izni olanlar</label>
                <div class="panel" style="padding:12px 14px;display:flex;justify-content:space-between;align-items:baseline">
                    <span class="small text-muted">Alıcı</span><span style="font-size:22px;font-weight:600" class="num" x-text="n">…</span>
                </div>
                <button class="btn btn-secondary btn-block" name="action" value="save">Taslağı kaydet</button>
                <button class="btn btn-primary btn-block" name="action" value="save_review">Kaydet ve gönderime geç</button>
            </div>
        </section>
    </aside>
</form>
<?php endif; ?>

<?php if ($camp): ?>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6" style="margin-top:24px" id="gonder">
    <section class="card xl:col-span-2" style="overflow:hidden">
        <div class="card-head"><div><p class="card-title">Önizleme</p><p class="card-sub">Konu: <?= e($camp['subject']) ?></p></div></div>
        <iframe src="?id=<?= $id ?>&preview=1" style="width:100%;height:640px;border:0;background:#F4F3F0" title="Önizleme"></iframe>
    </section>
    <aside class="stack-lg">
        <section class="card">
            <div class="card-head"><p class="card-title">Test e-postası</p></div>
            <form method="POST" action="" class="card-pad stack-sm"><?= csrf_field() ?>
                <input type="hidden" name="action" value="test">
                <input class="input" type="email" name="test_to" value="<?= e($user['email'] ?? '') ?>" required>
                <button class="btn btn-secondary btn-block"><i data-lucide="send"></i>Test gönder</button>
            </form>
        </section>
        <?php if ($camp['status'] === 'draft'): ?>
        <section class="card card-emphasis">
            <div class="card-pad stack">
                <p class="eyebrow">Gönderim</p>
                <p style="font-size:26px;font-weight:600"><?= $recipients ?> <span class="small text-muted" style="font-weight:400">alıcı</span></p>
                <p class="xsmall text-muted">E-postalar kuyruğa alınır ve parti başına <?= (int)site_setting('mail_batch_size') ?> adet gönderilir. Bu sayfa açık kaldıkça gönderim sürer; sunucuda zamanlanmış görev (cron) tanımlıysa arka planda devam eder.</p>
                <form method="POST" action="" onsubmit="return confirm('<?= $recipients ?> alıcıya gönderim başlatılsın mı? Bu işlem geri alınamaz.');"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="send">
                    <button class="btn btn-accent btn-block btn-lg" <?= $recipients && mail_enabled() ? '' : 'disabled' ?>><i data-lucide="send"></i>Gönderimi başlat</button>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </aside>
</div>
<?php endif; ?>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
function composer() {
    return {
        cmd(c, v = null) { this.$refs.ed.focus(); document.execCommand(c, false, v); },
        insert(t) { if (!t) return; this.$refs.ed.focus(); document.execCommand('insertText', false, t); },
        link() { const u = prompt('Bağlantı adresi (https://…)'); if (u && /^(https?:\/\/|mailto:)/i.test(u)) this.cmd('createLink', u); },
        image() { const u = prompt('Görsel adresi (https://… ile başlamalı)'); if (u && /^https:\/\//i.test(u)) this.cmd('insertImage', u); },
        button() {
            const t = prompt('Buton metni', 'Detayları gör'); if (!t) return;
            const u = prompt('Buton bağlantısı (https://…)'); if (!u || !/^https?:\/\//i.test(u)) return;
            const a = document.createElement('a'); a.href = u; a.textContent = t; a.setAttribute('data-button', '1');
            a.style.cssText = 'display:inline-block;background:var(--accent);color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600';
            const p = document.createElement('p'); p.appendChild(a);
            this.$refs.ed.focus(); document.execCommand('insertHTML', false, p.outerHTML);
        },
        sync() { this.$refs.out.value = this.$refs.ed.innerHTML; }
    };
}
function audience() {
    return {
        n: '…',
        count() {
            const form = this.$root.closest('form');
            const fd = new FormData(form); const q = new URLSearchParams();
            for (const [k, v] of fd.entries()) if (['kinds[]', 'tiers[]', 'tag', 'consent_only'].includes(k)) q.append(k, v);
            fetch('?count=1&' + q.toString()).then(r => r.json()).then(d => this.n = d.count).catch(() => this.n = '?');
        }
    };
}
function campaignProgress(id, status) {
    return {
        sent: <?= (int)($camp['sent'] ?? 0) ?>, failed: <?= (int)($camp['failed'] ?? 0) ?>, total: <?= (int)($camp['total'] ?? 0) ?>, pct: 0, note: '', busy: false,
        start() { this.calc(); if (status === 'sending') this.tick(); },
        calc() { this.pct = this.total ? Math.round((this.sent + this.failed) / this.total * 100) : 0; },
        tick() {
            const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('campaign_id', id);
            this.note = 'Gönderiliyor… sayfayı kapatmayın.';
            fetch('<?= BASE_URL ?>/modules/mail/process.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                if (d.error) { this.note = d.error; return; }
                if (d.campaign) { this.sent = +d.campaign.sent; this.failed = +d.campaign.failed; this.calc(); }
                if (d.remaining > 0 && d.campaign && d.campaign.status === 'sending') { setTimeout(() => this.tick(), 1500); }
                else { this.note = 'Gönderim tamamlandı.'; setTimeout(() => location.reload(), 1200); }
            }).catch(() => { this.note = 'Bağlantı hatası, yeniden deneniyor…'; setTimeout(() => this.tick(), 5000); });
        }
    };
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
