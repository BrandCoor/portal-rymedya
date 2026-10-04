<?php
/**
 * ====================================================================
 * YASAL METİNLER VE ONAY KAYITLARI
 * ====================================================================
 * - Metinlerin varsayılanı includes/legal_texts.php'de; yönetici düzenlerse
 *   legal_documents tablosundaki sürüm kullanılır.
 * - Her onay (kayıt, yeniden onay, kartla ödeme) legal_acceptances'a
 *   kullanıcı, metin, sürüm, IP, tarayıcı ve tarihle yazılır (delil kaydı).
 * - Yönetici "yeniden onay iste" diyerek sürümü artırırsa, kullanıcılardan
 *   bir sonraki girişte yeni metni onaylamaları istenir.
 */

require_once __DIR__ . '/legal_texts.php';

/** Metinler: başlık, kısa ad, footer'da görünsün mü */
const LEGAL_DOCS = [
    'kullanim-kosullari'    => ['title' => 'Kullanım Koşulları ve Üyelik Sözleşmesi', 'short' => 'Kullanım koşulları', 'footer' => true],
    'ajans-sozlesmesi'      => ['title' => 'Ajans Hizmet Sözleşmesi', 'short' => 'Ajans sözleşmesi', 'footer' => false],
    'freelancer-sozlesmesi' => ['title' => 'Freelancer Hizmet Sağlayıcı Sözleşmesi', 'short' => 'Freelancer sözleşmesi', 'footer' => false],
    'kvkk'                  => ['title' => 'KVKK Aydınlatma Metni', 'short' => 'KVKK aydınlatma', 'footer' => true],
    'acik-riza'             => ['title' => 'Açık Rıza Metni', 'short' => 'Açık rıza', 'footer' => false],
    'ticari-ileti'          => ['title' => 'Ticari Elektronik İleti Onay Metni', 'short' => 'Ticari ileti onayı', 'footer' => false],
    'gizlilik'              => ['title' => 'Gizlilik ve Güvenlik Politikası', 'short' => 'Gizlilik', 'footer' => true],
    'cerez'                 => ['title' => 'Çerez Politikası', 'short' => 'Çerezler', 'footer' => true],
    'mesafeli'              => ['title' => 'Ön Bilgilendirme Formu ve Mesafeli Hizmet Sözleşmesi', 'short' => 'Mesafeli hizmet sözleşmesi', 'footer' => true],
    'iptal-iade'            => ['title' => 'İptal, İade ve Ödeme Koşulları', 'short' => 'İptal ve iade', 'footer' => true],
    'iletisim'              => ['title' => 'İletişim ve Künye', 'short' => 'İletişim', 'footer' => true],
];

/** Varsayılan metinlerin yürürlük tarihi */
const LEGAL_DEFAULT_DATE = '2026-10-05';

/** Hesap türüne göre onaylanması zorunlu metinler */
function legal_required_slugs(string $role): array {
    return match ($role) {
        'agency'     => ['kullanim-kosullari', 'ajans-sozlesmesi', 'kvkk'],
        'freelancer' => ['kullanim-kosullari', 'freelancer-sozlesmesi', 'kvkk'],
        default      => ['kullanim-kosullari', 'kvkk'],
    };
}

function run_legal_migrations(): void {
    global $db;
    run_cookie_consent_migrations();
    $db->exec("CREATE TABLE IF NOT EXISTS legal_documents (
        slug VARCHAR(40) NOT NULL PRIMARY KEY,
        title VARCHAR(190) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        version INT NOT NULL DEFAULT 1,
        published_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        updated_by INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS legal_acceptances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        email VARCHAR(190) NULL,
        slug VARCHAR(40) NOT NULL,
        version INT NOT NULL,
        action ENUM('accept','withdraw') NOT NULL DEFAULT 'accept',
        context VARCHAR(40) NOT NULL DEFAULT 'register',
        ref_id INT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        body_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_user_slug (user_id, slug),
        INDEX idx_slug (slug, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Metni döner: slug, title, body (ham), version, published_at, updated_at, custom
 */
function legal_doc(string $slug): ?array {
    global $db;
    if (!isset(LEGAL_DOCS[$slug])) return null;
    static $cache = [];
    if (isset($cache[$slug])) return $cache[$slug];
    $row = null;
    try {
        $st = $db->prepare("SELECT * FROM legal_documents WHERE slug = ?");
        $st->execute([$slug]);
        $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $row = null;   // tablo henüz yoksa varsayılan
    }
    if ($row) {
        return $cache[$slug] = [
            'slug' => $slug, 'title' => $row['title'], 'body' => $row['body'], 'version' => (int)$row['version'],
            'published_at' => $row['published_at'], 'updated_at' => $row['updated_at'], 'custom' => true,
        ];
    }
    return $cache[$slug] = [
        'slug' => $slug, 'title' => LEGAL_DOCS[$slug]['title'], 'body' => LEGAL_DEFAULT_TEXTS[$slug] ?? '', 'version' => 1,
        'published_at' => LEGAL_DEFAULT_DATE . ' 00:00:00', 'updated_at' => LEGAL_DEFAULT_DATE . ' 00:00:00', 'custom' => false,
    ];
}

/** {{yer_tutucu}} → şirket bilgisi. Boş bilgi "[... girilmemiş]" olarak görünür ki eksik fark edilsin. */
function legal_placeholders(): array {
    $v = fn(string $key, string $label) => trim((string)site_setting($key)) !== '' ? trim((string)site_setting($key)) : '[' . $label . ' — Ayarlar → Şirket ve künye]';
    $email = $v('company_email', 'e-posta');
    $host = parse_url(BASE_URL, PHP_URL_HOST) ?: BASE_URL;
    return [
        '{{unvan}}'           => $v('company_name', 'resmi ünvan'),
        '{{marka}}'           => site_setting('company_brand_name') ?: 'RY Medya',
        '{{adres}}'           => preg_replace('/\s*\n\s*/', ', ', $v('company_address', 'adres')),
        '{{eposta}}'          => $email,
        '{{kvkk_eposta}}'     => trim((string)site_setting('company_kvkk_email')) !== '' ? trim((string)site_setting('company_kvkk_email')) : $email,
        '{{telefon}}'         => $v('company_phone', 'telefon'),
        '{{vergi_dairesi}}'   => $v('company_tax_office', 'vergi dairesi'),
        '{{vergi_no}}'        => $v('company_tax_number', 'vergi no'),
        '{{mersis}}'          => $v('company_trade_registry', 'MERSİS no'),
        '{{kep}}'             => $v('company_kep', 'KEP adresi'),
        '{{web}}'             => trim((string)site_setting('company_website')) !== '' ? trim((string)site_setting('company_website')) : $host,
        '{{site}}'            => $host,
        '{{yetkili_mahkeme}}' => trim((string)site_setting('legal_jurisdiction')) !== '' ? trim((string)site_setting('legal_jurisdiction')) : 'İstanbul (Çağlayan)',
    ];
}

/** Ham metni (yer tutucular doldurulmuş) HTML'e çevirir */
function legal_render(string $body): string {
    $body = strtr(str_replace("\r\n", "\n", $body), legal_placeholders());
    $html = '';
    $list = false;
    $para = [];
    $flush = function () use (&$html, &$para) {
        if ($para) {
            $html .= '<p>' . implode('<br>', $para) . '</p>';
            $para = [];
        }
    };
    $inline = fn(string $t) => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($t));
    foreach (explode("\n", $body) as $line) {
        $t = rtrim($line);
        if (str_starts_with($t, '- ')) {
            $flush();
            if (!$list) { $html .= '<ul>'; $list = true; }
            $html .= '<li>' . $inline(substr($t, 2)) . '</li>';
            continue;
        }
        if ($list) { $html .= '</ul>'; $list = false; }
        if ($t === '') { $flush(); continue; }
        if (str_starts_with($t, '### ')) { $flush(); $html .= '<h3>' . $inline(substr($t, 4)) . '</h3>'; continue; }
        if (str_starts_with($t, '## '))  { $flush(); $html .= '<h2>' . $inline(substr($t, 3)) . '</h2>'; continue; }
        $para[] = $inline($t);
    }
    if ($list) $html .= '</ul>';
    $flush();
    return $html;
}

function legal_url(string $slug): string {
    return BASE_URL . '/legal/index.php?d=' . rawurlencode($slug);
}

/** Yeni sekmede açılan metin bağlantısı */
function legal_link(string $slug, ?string $label = null): string {
    $d = legal_doc($slug);
    return '<a class="link" href="' . e(legal_url($slug)) . '" target="_blank" rel="noopener">' . e($label ?? ($d['title'] ?? $slug)) . '</a>';
}

/** Footer'daki yasal bağlantılar */
function legal_footer_links(string $class = ''): string {
    $out = [];
    foreach (LEGAL_DOCS as $slug => $m) {
        if ($m['footer']) $out[] = '<a href="' . e(legal_url($slug)) . '">' . e($m['short']) . '</a>';
    }
    $out[] = '<a href="#cerez-tercihleri" onclick="if(window.openCookiePrefs){openCookiePrefs();return false}">Çerez tercihleri</a>';
    return '<nav class="legal-links ' . e($class) . '">' . implode('', $out) . '</nav>';
}

/**
 * Onay kaydı. $slugs: onaylanan metinler. Sürüm ve metin özeti o anki metinden alınır.
 */
function legal_record(?int $user_id, ?string $email, array $slugs, string $context, ?int $ref_id = null, string $action = 'accept'): void {
    global $db;
    $ins = $db->prepare("INSERT INTO legal_acceptances (user_id, email, slug, version, action, context, ref_id, ip, user_agent, body_hash, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    foreach (array_unique($slugs) as $slug) {
        $d = legal_doc($slug);
        if (!$d) continue;
        $ins->execute([
            $user_id, $email !== null ? mb_strtolower($email) : null, $slug, $d['version'], $action, mb_substr($context, 0, 40), $ref_id,
            mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            hash('sha256', strtr($d['body'], legal_placeholders())),
        ]);
    }
}

/** Kullanıcının son kaydı (onay / geri alma) */
function legal_last(int $user_id, string $slug): ?array {
    global $db;
    $st = $db->prepare("SELECT * FROM legal_acceptances WHERE user_id = ? AND slug = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$user_id, $slug]);
    return $st->fetch() ?: null;
}

/** Kullanıcı açık rıza / ileti onayı gibi isteğe bağlı bir metni şu an onaylı tutuyor mu? */
function legal_has_consent(int $user_id, string $slug): bool {
    $l = legal_last($user_id, $slug);
    return $l && $l['action'] === 'accept';
}

/** Güncel sürümü onaylanmamış zorunlu metinler */
function legal_pending(int $user_id, string $role): array {
    global $db;
    $slugs = legal_required_slugs($role);
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $st = $db->prepare("SELECT slug, MAX(version) v FROM legal_acceptances WHERE user_id = ? AND action = 'accept' AND slug IN ($in) GROUP BY slug");
    $st->execute(array_merge([$user_id], $slugs));
    $have = [];
    foreach ($st->fetchAll() as $r) $have[$r['slug']] = (int)$r['v'];
    return array_values(array_filter($slugs, fn($s) => ($have[$s] ?? 0) < legal_doc($s)['version']));
}

/**
 * Portal kullanıcısının onaylaması gereken güncel metin varsa onay sayfasına yönlendirir.
 * require_client_login() içinden çağrılır.
 */
function legal_portal_guard(int $user_id, string $role): void {
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($page, ['accept.php', 'logout.php', '2fa_setup.php'], true)) return;
    try {
        $pending = legal_pending($user_id, $role);
    } catch (Throwable $e) {
        return;
    }
    if ($pending) {
        $_SESSION['legal_return'] = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '';
        redirect(BASE_URL . '/legal/accept.php');
    }
}

/** Çerez onayı metin sürümü: kategoriler değişirse artırın, herkese yeniden sorulur */
const COOKIE_CONSENT_VERSION = 1;

/**
 * Çerez onay penceresi (KVKK Çerez Uygulamaları Rehberi'ne uygun):
 * "Tümünü kabul et" ve "Yalnızca zorunlu" eşit ağırlıkta sunulur, zorunlu olmayan
 * kategoriler varsayılan kapalıdır, tercih istenildiği an footer'daki
 * "Çerez tercihleri" bağlantısıyla değiştirilebilir. Seçim tarayıcıda saklanır
 * ve ajax/consent.php ile kayıt altına alınır (delil).
 * Sayfada bir kez basılır.
 */
function legal_cookie_notice(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    $url = e(legal_url('cerez'));
    $cfg = json_encode(['v' => COOKIE_CONSENT_VERSION, 'url' => BASE_URL . '/ajax/consent.php', 'secure' => str_starts_with(BASE_URL, 'https')], JSON_UNESCAPED_SLASHES);
    $js = BASE_URL . '/assets/js/cookie-consent.js?v=' . UI_ASSET_VERSION;
    return <<<HTML
<div id="cc" class="cc" role="dialog" aria-modal="false" aria-labelledby="cc-title" hidden>
  <div class="cc-card">
    <p class="cc-title" id="cc-title">Çerez tercihleriniz</p>
    <p class="cc-text">Platformun çalışması için gerekli <strong>zorunlu çerezleri</strong> kullanıyoruz (oturum ve güvenlik). Analitik ve pazarlama çerezleri yalnızca izin verirseniz kullanılır; şu an bu türde çerez kullanmıyoruz. Ayrıntılar: <a href="{$url}" target="_blank" rel="noopener">Çerez Politikası</a></p>
    <div class="cc-prefs" id="cc-prefs" hidden>
      <label class="cc-row"><span><strong>Zorunlu çerezler</strong><small>Giriş, oturum, güvenlik (CSRF) ve bu tercihin saklanması. Kapatılamaz.</small></span><input type="checkbox" checked disabled></label>
      <label class="cc-row"><span><strong>Analitik çerezler</strong><small>Platformun nasıl kullanıldığını anonim olarak ölçmek için.</small></span><input type="checkbox" id="cc-analytics"></label>
      <label class="cc-row"><span><strong>Pazarlama çerezleri</strong><small>İlgi alanınıza göre içerik ve reklam göstermek için.</small></span><input type="checkbox" id="cc-marketing"></label>
    </div>
    <div class="cc-actions">
      <button type="button" class="btn btn-secondary btn-sm" data-cc="necessary">Yalnızca zorunlu</button>
      <button type="button" class="btn btn-ghost btn-sm" data-cc="prefs" id="cc-prefs-btn">Tercihleri yönet</button>
      <button type="button" class="btn btn-secondary btn-sm" data-cc="save" id="cc-save" hidden>Seçimimi kaydet</button>
      <button type="button" class="btn btn-primary btn-sm" data-cc="all">Tümünü kabul et</button>
    </div>
  </div>
</div>
<script>window.CC_CFG = {$cfg};</script>
<script src="{$js}"></script>
HTML;
}

/** Çerez onayı kaydı tablosu */
function run_cookie_consent_migrations(): void {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS cookie_consents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consent_id CHAR(32) NOT NULL,
        version INT NOT NULL,
        analytics TINYINT(1) NOT NULL DEFAULT 0,
        marketing TINYINT(1) NOT NULL DEFAULT 0,
        user_id INT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        page VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_consent (consent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Profil sayfasındaki "Sözleşmeler ve onaylar" kartının POST işlemi (açık rızayı ver / geri al).
 * İşlediyse true döner (çağıran yönlendirir).
 */
function legal_profile_post(int $user_id, ?string $email): bool {
    if (($_POST['action'] ?? '') !== 'legal_consent') return false;
    $want = !empty($_POST['acik_riza']);
    if ($want !== legal_has_consent($user_id, 'acik-riza')) {
        legal_record($user_id, $email, ['acik-riza'], 'profile', null, $want ? 'accept' : 'withdraw');
        log_activity('legal', 'Açık rıza ' . ($want ? 'verildi' : 'geri alındı'), 'user', $user_id, null, $user_id);
    }
    set_flash('success', $want ? 'Açık rızanız kaydedildi.' : 'Açık rızanız geri alındı.');
    return true;
}

/** Profil sayfası kartı: onaylanan metinler ve açık rıza anahtarı */
function legal_profile_card(int $user_id, string $role): string {
    global $db;
    $st = $db->prepare("SELECT a.* FROM legal_acceptances a JOIN (SELECT slug, MAX(id) id FROM legal_acceptances WHERE user_id = ? GROUP BY slug) l ON l.id = a.id ORDER BY a.created_at DESC");
    $st->execute([$user_id]);
    $rows = $st->fetchAll();
    $consent = legal_has_consent($user_id, 'acik-riza');
    ob_start(); ?>
    <form method="POST" action="" class="card" style="margin-top:24px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="legal_consent">
        <div class="card-head"><div><p class="card-title">Sözleşmeler ve onaylar</p><p class="card-sub">Onayladığınız metinler ve tarihleri. Metinlerin tamamı: <a class="link" href="<?= e(BASE_URL . '/legal/index.php') ?>" target="_blank" rel="noopener">yasal metinler</a></p></div></div>
        <div class="divide">
            <?php foreach ($rows as $r): if (!isset(LEGAL_DOCS[$r['slug']])) continue; ?>
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                <div><p class="small" style="font-weight:500"><?= legal_link($r['slug']) ?></p><p class="xsmall text-muted"><?= $r['action'] === 'accept' ? 'Onaylandı' : 'Geri alındı' ?> · sürüm <?= (int)$r['version'] ?> · <?= e(format_date($r['created_at'], true)) ?></p></div>
                <?= $r['action'] === 'accept' ? ui_badge('Onaylı', 'success') : ui_badge('Geri alındı', 'neutral') ?>
            </div>
            <?php endforeach; ?>
            <div class="card-pad-sm" style="display:flex;justify-content:space-between;gap:16px;align-items:center">
                <div><p class="small" style="font-weight:500">Açık rıza</p><p class="xsmall text-muted"><?= legal_link('acik-riza', 'Açık Rıza Metni') ?> kapsamındaki işlemler (isteğe bağlı, dilediğiniz zaman geri alabilirsiniz).</p></div>
                <label class="switch"><input type="checkbox" name="acik_riza" value="1" <?= $consent ? 'checked' : '' ?>><span></span></label>
            </div>
        </div>
        <div class="card-foot" style="display:flex;justify-content:flex-end"><button class="btn btn-secondary">Kaydet</button></div>
    </form>
    <?php
    return (string)ob_get_clean();
}

/** Portal sayfalarının altı: telif + yasal bağlantılar */
function legal_portal_footer(string $right = ''): string {
    return '<footer class="portal-foot no-print"><span>' . e(site_footer_text()) . '</span>' . legal_footer_links() . ($right !== '' ? '<span>' . e($right) . '</span>' : '') . '</footer>' . legal_cookie_notice();
}

/** Giriş / kayıt ekranlarında kartın altındaki yasal bağlantılar */
function legal_auth_links(): string {
    return '<div class="auth-legal">' . legal_footer_links() . '</div>' . legal_cookie_notice();
}
