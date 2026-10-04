<?php
/**
 * ====================================================================
 * YASAL METİNLER YÖNETİMİ (yalnızca süper yönetici)
 * ====================================================================
 * - Metinleri düzenle (önizlemeli); "yeniden onay iste" sürümü artırır,
 *   kullanıcılar bir sonraki girişte yeni sürümü onaylar.
 * - Varsayılan metne dön.
 * - Onay kayıtları: kim, hangi metni, hangi sürümü, ne zaman, hangi IP'den
 *   onayladı / geri aldı. CSV olarak indirilebilir (delil amaçlı).
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/functions.php';

require_staff_login();
if (!is_super_admin()) {
    set_flash('error', 'Yasal metinler yalnızca süper yönetici tarafından yönetilebilir.');
    redirect(BASE_URL . '/modules/dashboard/index.php');
}
$self = BASE_URL . '/modules/legal/index.php';
$staff_id = (int)current_user()['id'];
$tab = ($_GET['tab'] ?? '') === 'log' ? 'log' : 'docs';
$edit = isset($_GET['edit']) && isset(LEGAL_DOCS[$_GET['edit']]) ? (string)$_GET['edit'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $slug = (string)($_POST['slug'] ?? '');
    if (isset(LEGAL_DOCS[$slug]) && $action === 'save') {
        $cur = legal_doc($slug);
        $title = trim((string)($_POST['title'] ?? '')) ?: LEGAL_DOCS[$slug]['title'];
        $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
        $bump = !empty($_POST['bump']);
        if ($body === '') {
            set_flash('error', 'Metin boş olamaz.');
            redirect($self . '?edit=' . rawurlencode($slug));
        }
        $version = $cur['version'] + ($bump ? 1 : 0);
        $published = $bump ? date('Y-m-d H:i:s') : $cur['published_at'];
        $db->prepare("INSERT INTO legal_documents (slug, title, body, version, published_at, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, NOW(), ?)
                      ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), version = VALUES(version), published_at = VALUES(published_at), updated_at = NOW(), updated_by = VALUES(updated_by)")
           ->execute([$slug, $title, $body, $version, $published, $staff_id]);
        log_activity('legal', 'Yasal metin güncellendi: ' . $title . ($bump ? ' (sürüm ' . $version . ', yeniden onay istendi)' : ''));
        set_flash('success', $bump ? "Kaydedildi. Sürüm {$version} yayımlandı; ilgili kullanıcılardan bir sonraki girişte onay istenecek." : 'Kaydedildi (sürüm değişmedi).');
        redirect($self . '?edit=' . rawurlencode($slug));
    }
    if (isset(LEGAL_DOCS[$slug]) && $action === 'reset') {
        $cur = legal_doc($slug);
        // Sürüm geriye gitmesin: varsayılan metin, mevcut sürüm +1 olarak yayımlanır
        $db->prepare("INSERT INTO legal_documents (slug, title, body, version, published_at, updated_at, updated_by) VALUES (?, ?, ?, ?, NOW(), NOW(), ?)
                      ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), version = VALUES(version), published_at = NOW(), updated_at = NOW(), updated_by = VALUES(updated_by)")
           ->execute([$slug, LEGAL_DOCS[$slug]['title'], LEGAL_DEFAULT_TEXTS[$slug] ?? '', $cur['version'] + 1, $staff_id]);
        log_activity('legal', 'Yasal metin varsayılana döndürüldü: ' . LEGAL_DOCS[$slug]['title']);
        set_flash('success', 'Varsayılan metin yeni sürüm olarak yayımlandı.');
        redirect($self . '?edit=' . rawurlencode($slug));
    }
    redirect($self);
}

// Onay kayıtları
$q = trim((string)($_GET['q'] ?? ''));
$f_slug = isset(LEGAL_DOCS[$_GET['doc'] ?? '']) ? (string)$_GET['doc'] : '';
$where = [];
$args = [];
if ($q !== '') { $where[] = '(a.email LIKE ? OR u.full_name LIKE ? OR a.ip LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($f_slug !== '') { $where[] = 'a.slug = ?'; $args[] = $f_slug; }
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if ($tab === 'log' && ($_GET['export'] ?? '') === 'csv') {
    $st = $db->prepare("SELECT a.*, u.full_name FROM legal_acceptances a LEFT JOIN users u ON u.id = a.user_id $sql_where ORDER BY a.id DESC");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sozlesme-onaylari-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kayıt no', 'Tarih', 'Kullanıcı ID', 'Ad soyad', 'E-posta', 'Metin', 'Sürüm', 'İşlem', 'Bağlam', 'Referans', 'IP', 'Tarayıcı', 'Metin özeti (SHA-256)'], ';');
    while ($r = $st->fetch()) {
        fputcsv($out, [$r['id'], $r['created_at'], $r['user_id'], $r['full_name'], $r['email'], LEGAL_DOCS[$r['slug']]['title'] ?? $r['slug'], $r['version'], $r['action'] === 'accept' ? 'Onay' : 'Geri alma', $r['context'], $r['ref_id'], $r['ip'], $r['user_agent'], $r['body_hash']], ';');
    }
    log_activity('legal', 'Sözleşme onay kayıtları CSV olarak indirildi');
    exit;
}

$counts = [];
foreach ($db->query("SELECT slug, COUNT(DISTINCT user_id) n FROM legal_acceptances WHERE action = 'accept' GROUP BY slug")->fetchAll() as $r) $counts[$r['slug']] = (int)$r['n'];
$log = [];
if ($tab === 'log') {
    $st = $db->prepare("SELECT a.*, u.full_name FROM legal_acceptances a LEFT JOIN users u ON u.id = a.user_id $sql_where ORDER BY a.id DESC LIMIT 300");
    $st->execute($args);
    $log = $st->fetchAll();
}
$context_labels = ['register' => 'Kayıt', 'reaccept' => 'Yeniden onay', 'card_payment' => 'Kartla ödeme', 'profile' => 'Profil'];

$missing = array_filter(['company_name' => 'Resmi ünvan', 'company_address' => 'Adres', 'company_email' => 'E-posta', 'company_phone' => 'Telefon', 'company_tax_office' => 'Vergi dairesi', 'company_tax_number' => 'Vergi no', 'company_trade_registry' => 'MERSİS no', 'company_kep' => 'KEP adresi'], fn($k) => trim((string)site_setting($k)) === '', ARRAY_FILTER_USE_KEY);

$page_title = 'Yasal metinler';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h1">Yasal metinler</h1>
        <p class="sub">Sözleşmeler, KVKK ve politikalar. Herkese açık sayfa: <a class="link" href="<?= BASE_URL ?>/legal/index.php" target="_blank" rel="noopener"><?= e(parse_url(BASE_URL, PHP_URL_HOST) ?: BASE_URL) ?>/legal</a></p>
    </div>
</div>

<?php if ($missing): ?>
<div class="alert alert-warning" style="margin-bottom:16px"><i data-lucide="triangle-alert"></i><div>Metinlerde kullanılan şirket bilgilerinden bazıları eksik: <strong><?= e(implode(', ', $missing)) ?></strong>. Eksik bilgiler metinlerde "[… — Ayarlar → Şirket ve künye]" olarak görünür. <a class="link" href="<?= BASE_URL ?>/modules/settings/index.php?tab=company">Şimdi doldurun</a></div></div>
<?php endif; ?>

<nav class="tabs" style="margin-bottom:16px">
    <a href="<?= $self ?>" class="tab <?= $tab === 'docs' ? 'is-active' : '' ?>">Metinler</a>
    <a href="<?= $self ?>?tab=log" class="tab <?= $tab === 'log' ? 'is-active' : '' ?>">Onay kayıtları</a>
</nav>

<?php if ($tab === 'docs' && $edit): $d = legal_doc($edit); ?>
<div x-data="{ preview: false }">
<form method="POST" action="" class="card"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="slug" value="<?= e($edit) ?>">
    <div class="card-head">
        <div><p class="card-title"><?= e($d['title']) ?></p><p class="card-sub">Sürüm <?= (int)$d['version'] ?> · yürürlük <?= e(format_date($d['published_at'])) ?><?= $d['custom'] ? ' · düzenlenmiş' : ' · varsayılan metin' ?></p></div>
        <div style="display:flex;gap:6px">
            <a class="btn btn-ghost btn-sm" href="<?= e(legal_url($edit)) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i>Sayfayı aç</a>
            <a class="btn btn-ghost btn-sm" href="<?= $self ?>"><i data-lucide="arrow-left"></i>Listeye dön</a>
        </div>
    </div>
    <div class="card-pad stack">
        <div class="field"><label class="label">Başlık</label><input class="input" name="title" value="<?= e($d['title']) ?>" maxlength="190"></div>
        <div class="field">
            <label class="label" style="display:flex;justify-content:space-between;align-items:center">Metin
                <span class="seg" style="font-weight:400"><a href="#" :class="!preview && 'is-active'" @click.prevent="preview = false">Düzenle</a><a href="#" :class="preview && 'is-active'" @click.prevent="preview = true">Kayıtlı hali</a></span>
            </label>
            <textarea x-show="!preview" class="textarea mono" name="body" rows="28" style="font-size:12.5px;line-height:1.6"><?= e($d['body']) ?></textarea>
            <div x-show="preview" x-cloak class="panel legal-doc" style="padding:20px;max-height:640px;overflow:auto"><?= legal_render($d['body']) ?></div>
            <span class="hint">Biçim: "## " ana başlık, "### " alt başlık, "- " madde, boş satır yeni paragraf, **kalın**. Yer tutucular şirket bilgisiyle dolar: {{unvan}} {{marka}} {{adres}} {{eposta}} {{kvkk_eposta}} {{telefon}} {{vergi_dairesi}} {{vergi_no}} {{mersis}} {{kep}} {{web}} {{site}} {{yetkili_mahkeme}}</span>
        </div>
        <label class="check option-card" style="padding:12px 14px;align-items:flex-start">
            <input type="checkbox" name="bump" value="1">
            <span class="small"><strong>Önemli değişiklik — yeni sürüm yayımla ve yeniden onay iste.</strong><br><span class="text-muted">Sürüm <?= (int)$d['version'] + 1 ?> olur. Bu metni onaylaması zorunlu olan kullanıcılar (<?= e(implode(', ', array_keys(array_filter(['ajans' => in_array($edit, legal_required_slugs('agency'), true), 'freelancer' => in_array($edit, legal_required_slugs('freelancer'), true), 'müşteri' => in_array($edit, legal_required_slugs('client'), true)])))) ?: 'zorunlu onay yok' ?>) bir sonraki girişlerinde yeni metni onaylamadan devam edemez. Yazım düzeltmeleri için işaretlemeyin.</span></span>
        </label>
    </div>
    <div class="card-foot" style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
        <button type="submit" form="reset-form" class="btn btn-ghost" onclick="return confirm('Varsayılan metin yeni sürüm olarak yayımlansın mı? Yaptığınız düzenlemeler kaybolur.');"><i data-lucide="rotate-ccw"></i>Varsayılan metne dön</button>
        <button class="btn btn-primary"><i data-lucide="save"></i>Kaydet</button>
    </div>
</form>
<form id="reset-form" method="POST" action=""><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="slug" value="<?= e($edit) ?>"></form>
</div>

<?php elseif ($tab === 'docs'): ?>
<section class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Metin</th><th>Kimler onaylar</th><th class="r">Sürüm</th><th>Yürürlük</th><th class="r">Onaylayan</th><th></th></tr></thead>
    <tbody>
    <?php foreach (LEGAL_DOCS as $s => $m): $d = legal_doc($s);
        $who = [];
        foreach (['agency' => 'Ajans', 'freelancer' => 'Freelancer', 'client' => 'Müşteri'] as $rk => $rl) if (in_array($s, legal_required_slugs($rk), true)) $who[] = $rl;
        $how = $who ? implode(', ', $who) . ' (zorunlu)' : match ($s) { 'acik-riza', 'ticari-ileti' => 'İsteğe bağlı', 'mesafeli', 'iptal-iade' => 'Kartla ödemede', default => 'Bilgilendirme' };
    ?>
        <tr>
            <td><a class="link" href="?edit=<?= e($s) ?>" style="font-weight:500"><?= e($d['title']) ?></a><?php if ($d['custom']): ?> <?= ui_badge('Düzenlendi', 'info') ?><?php endif; ?></td>
            <td class="small"><?= e($how) ?></td>
            <td class="r num"><?= (int)$d['version'] ?></td>
            <td class="small"><?= e(format_date($d['published_at'])) ?></td>
            <td class="r num"><a class="link" href="?tab=log&doc=<?= e($s) ?>"><?= $counts[$s] ?? 0 ?></a></td>
            <td class="r"><a class="btn btn-ghost btn-sm" href="?edit=<?= e($s) ?>"><i data-lucide="pencil"></i>Düzenle</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></section>
<p class="xsmall text-muted" style="margin-top:12px">Metinler genel bir şablondur; yayına almadan önce bir avukata kontrol ettirmeniz önerilir. Şirket bilgileri Ayarlar → Şirket ve künye bölümünden otomatik doldurulur.</p>

<?php else: ?>
<form method="GET" action="" class="card card-pad-sm" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <input type="hidden" name="tab" value="log">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="E-posta, ad veya IP" style="max-width:260px">
    <select class="select" name="doc" style="max-width:280px"><option value="">Tüm metinler</option><?php foreach (LEGAL_DOCS as $s => $m): ?><option value="<?= e($s) ?>" <?= $f_slug === $s ? 'selected' : '' ?>><?= e($m['title']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-secondary btn-sm">Filtrele</button>
    <a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(['tab' => 'log', 'q' => $q, 'doc' => $f_slug, 'export' => 'csv'])) ?>" style="margin-left:auto"><i data-lucide="download"></i>CSV indir</a>
</form>
<section class="card">
    <?php if (!$log): ?><?= ui_empty('Kayıt yok', 'Sözleşme onayları burada listelenir.', 'file-check') ?><?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>Metin</th><th class="r">Sürüm</th><th>İşlem</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($log as $r): ?>
            <tr>
                <td class="small" style="white-space:nowrap"><?= e(format_date($r['created_at'], true)) ?></td>
                <td><div class="small" style="font-weight:500"><?= e($r['full_name'] ?? '—') ?></div><div class="xsmall text-muted"><?= e($r['email'] ?? '') ?></div></td>
                <td class="small"><?= e(LEGAL_DOCS[$r['slug']]['short'] ?? $r['slug']) ?></td>
                <td class="r num"><?= (int)$r['version'] ?></td>
                <td><?= $r['action'] === 'accept' ? ui_badge('Onay', 'success') : ui_badge('Geri alma', 'neutral') ?> <span class="xsmall text-muted"><?= e($context_labels[$r['context']] ?? $r['context']) ?></span></td>
                <td class="xsmall mono" title="<?= e($r['user_agent'] ?? '') ?>"><?= e($r['ip'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<p class="xsmall text-muted" style="margin-top:12px">Her kayıtta onaylanan metnin SHA-256 özeti de saklanır (CSV'de görünür); böylece kullanıcının tam olarak hangi metni onayladığı ispatlanabilir. Son 300 kayıt gösterilir, tamamı için CSV indirin.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
