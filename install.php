<?php
/**
 * ====================================================================
 * KURULUM SİHİRBAZI (sıfırdan kurulum)
 * ====================================================================
 * 1) Sunucu gereksinimlerini kontrol eder.
 * 2) Veritabanı bilgilerini, site adresini ve ilk yönetici hesabını alır.
 * 3) config/db.php dosyasını yazar, temel tabloları kurar, yöneticiyi oluşturur.
 * 4) İlk açılışta diğer tüm tablolar otomatik oluşturulur (run_migrations).
 * Kurulum bittiğinde config/installed.lock oluşur ve bu sayfa bir daha çalışmaz.
 * Güvenlik için kurulumdan sonra bu dosyayı sunucudan silin.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Istanbul');
session_start();

const LOCK_FILE = __DIR__ . '/config/installed.lock';
const CONFIG_FILE = __DIR__ . '/config/db.php';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Kurulum zaten yapılmış mı? (kilit dosyası ya da kullanıcısı olan bir veritabanı) */
function already_installed(): bool {
    if (is_file(LOCK_FILE)) return true;
    if (!is_file(CONFIG_FILE)) return false;
    $src = (string)@file_get_contents(CONFIG_FILE);
    return !str_contains($src, 'VERITABANI_ADI');
}

$done = isset($_GET['done']) && is_file(LOCK_FILE) && !empty($_SESSION['install_done']);
$locked = !$done && already_installed();

// ---------------- Gereksinimler ----------------
$checks = [
    ['PHP 8.1 veya üzeri', version_compare(PHP_VERSION, '8.1.0', '>='), 'Sunucudaki sürüm: ' . PHP_VERSION . '. cPanel → "PHP Sürümünü Seç" bölümünden 8.1+ (önerilen 8.3) seçin.'],
    ['PDO MySQL eklentisi', extension_loaded('pdo_mysql'), 'cPanel → PHP eklentilerinden "pdo_mysql"i açın.'],
    ['mbstring eklentisi', extension_loaded('mbstring'), 'Türkçe karakter işlemleri için gerekli.'],
    ['OpenSSL eklentisi', extension_loaded('openssl'), 'Şifreli ayarlar ve iki adımlı doğrulama için gerekli.'],
    ['cURL eklentisi', extension_loaded('curl'), 'iyzico ödemeleri ve e-posta için önerilir.'],
    ['zlib eklentisi', extension_loaded('zlib'), 'Otomatik veritabanı yedeği (.sql.gz) için gerekli.'],
    ['config klasörü yazılabilir', is_writable(__DIR__ . '/config'), 'FTP/Dosya Yöneticisi ile "config" klasörünün izinlerini 755 yapın.'],
    ['assets/uploads klasörü yazılabilir', (is_dir(__DIR__ . '/assets/uploads') || @mkdir(__DIR__ . '/assets/uploads', 0755, true)) && is_writable(__DIR__ . '/assets/uploads'), 'Logo, dekont ve dosya yüklemeleri için "assets/uploads" klasörü yazılabilir olmalı (755).'],
];
$req_ok = !in_array(false, array_column($checks, 1), true);

// Site adresini isteğe göre tahmin et
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$guess = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

$errors = [];
$old = $_POST + ['base_url' => $guess, 'db_host' => 'localhost', 'company_brand' => 'RY Medya'];
if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(16));

if (!$locked && !$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['install_csrf'], (string)($_POST['csrf'] ?? ''))) $errors[] = 'Oturum süresi doldu, sayfayı yenileyip tekrar deneyin.';
    if (!$req_ok) $errors[] = 'Önce kırmızı işaretli sunucu gereksinimlerini giderin.';

    $base   = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
    $dbhost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $dbname = trim((string)($_POST['db_name'] ?? ''));
    $dbuser = trim((string)($_POST['db_user'] ?? ''));
    $dbpass = (string)($_POST['db_pass'] ?? '');
    $name   = trim((string)($_POST['admin_name'] ?? ''));
    $email  = mb_strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $pass   = (string)($_POST['admin_pass'] ?? '');
    $pass2  = (string)($_POST['admin_pass2'] ?? '');
    $company = trim((string)($_POST['company_name'] ?? ''));
    $brand  = trim((string)($_POST['company_brand'] ?? ''));

    if (!preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', $base)) $errors[] = 'Site adresi https:// ile başlamalı (ör. https://platform.rymedya.com.tr).';
    if ($dbname === '' || $dbuser === '') $errors[] = 'Veritabanı adı ve kullanıcısı zorunludur.';
    if ($name === '') $errors[] = 'Yönetici adı soyadı zorunludur.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Geçerli bir yönetici e-postası girin.';
    if (strlen($pass) < 8 || !preg_match('/[A-Za-zğüşıöçĞÜŞİÖÇ]/u', $pass) || !preg_match('/\d/', $pass)) $errors[] = 'Yönetici şifresi en az 8 karakter olmalı, harf ve rakam içermeli.';
    elseif ($pass !== $pass2) $errors[] = 'Şifreler eşleşmiyor.';
    if ($company === '') $errors[] = 'Şirket ünvanı zorunludur.';

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host={$dbhost};dbname={$dbname};charset=utf8mb4", $dbuser, $dbpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec("SET time_zone = '+03:00'");
        } catch (Throwable $e) {
            $errors[] = 'Veritabanına bağlanılamadı. Bilgileri kontrol edin ve kullanıcının bu veritabanında "Tüm Ayrıcalıklar"a sahip olduğundan emin olun. (' . $e->getMessage() . ')';
        }
    }
    if (!$errors && $pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
        $errors[] = 'Bu veritabanında zaten bir kurulum var. Sıfırdan kurulum için boş bir veritabanı kullanın (veya mevcut veritabanını boşaltın).';
    }

    if (!$errors) {
        try {
            // 1) Temel tablolar
            require __DIR__ . '/includes/install_schema.php';
            foreach (preg_split('/;\s*\n/', INSTALL_SCHEMA_SQL) as $stmt) {
                if (trim($stmt) !== '') $pdo->exec($stmt);
            }
            // 2) Yönetici ve temel ayarlar
            $pdo->prepare("INSERT INTO users (role_id, full_name, email, password, status, created_at) VALUES (1, ?, ?, ?, 'active', NOW())")
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $set = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ([
                ['company_name', $company, 'company'], ['company_brand_name', $brand ?: $company, 'brand'],
                ['brand_title_suffix', $brand ?: $company, 'brand'], ['company_email', $email, 'company'],
                ['base_url_last', $base, 'system'],
            ] as $row) $set->execute($row);

            // 3) Yapılandırma dosyası
            $tpl = (string)file_get_contents(__DIR__ . '/config/db.example.php');
            $secret = bin2hex(random_bytes(32));
            $cfg = strtr($tpl, [
                "define('BASE_URL', 'https://platform.rymedya.com.tr');" => "define('BASE_URL', " . var_export($base, true) . ");\n\n// Uygulama gizli anahtarı (şifreli ayarlar için; değiştirmeyin, paylaşmayın)\ndefine('APP_SECRET', " . var_export($secret, true) . ");",
                "'mysql:host=localhost;dbname=VERITABANI_ADI;charset=utf8mb4'" => var_export("mysql:host={$dbhost};dbname={$dbname};charset=utf8mb4", true),
                "'VERITABANI_KULLANICISI'" => var_export($dbuser, true),
                "'VERITABANI_SIFRESI'" => var_export($dbpass, true),
                "ÖRNEK DOSYA" => "KURULUMLA OLUŞTURULDU (" . date('d.m.Y H:i') . ")",
            ]);
            if (str_contains($cfg, 'VERITABANI_') || !str_contains($cfg, 'APP_SECRET')) {
                throw new RuntimeException('config/db.example.php beklenen biçimde değil; dosyayı depodaki son sürümle değiştirin.');
            }
            if (@file_put_contents(CONFIG_FILE, $cfg) === false) {
                throw new RuntimeException('config/db.php yazılamadı. "config" klasörüne yazma izni verin (755).');
            }
            @chmod(CONFIG_FILE, 0640);
            @file_put_contents(LOCK_FILE, 'Kurulum: ' . date('c') . "\n");
            $_SESSION['install_done'] = ['url' => $base, 'email' => $email];
            header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? '/install.php') . '?done=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Kurulum tamamlanamadı: ' . $e->getMessage();
        }
    }
}

// ---------------- Kurulum sonrası: ilk açılış (diğer tablolar oluşturulur) ----------------
$finish_error = '';
if ($done) {
    try {
        require_once CONFIG_FILE;
        require_once __DIR__ . '/config/constants.php';
        require_once __DIR__ . '/includes/functions.php';   // run_migrations() burada çalışır
        $tables = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    } catch (Throwable $e) {
        $finish_error = $e->getMessage();
    }
    $info = $_SESSION['install_done'];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Kurulum · RY Medya Platform</title>
<style>
:root{--bg:#F4F3F0;--card:#fff;--ink:#151517;--ink2:#3D3D43;--muted:#6E6D74;--line:#E4E2DD;--line2:#EEEDE9;--accent:#D2462F;--ok:#1C7347;--okbg:#E7F3EC;--bad:#B3261E;--badbg:#FBEAE8;--warnbg:#FAF0DD;--warn:#94590A}
*{box-sizing:border-box}body{margin:0;font:14px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink)}
.wrap{max-width:760px;margin:0 auto;padding:40px 20px 64px}
.brand{display:flex;align-items:center;gap:10px;font-weight:650;margin-bottom:28px}.mark{width:32px;height:32px;border-radius:9px;background:var(--accent);color:#fff;display:grid;place-items:center;font-size:13px;font-weight:700}
h1{font-size:28px;line-height:1.2;letter-spacing:-.02em;margin:0 0 6px}.sub{color:var(--muted);margin:0 0 28px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;margin-bottom:18px;overflow:hidden}
.card h2{font-size:15px;margin:0;padding:16px 20px;border-bottom:1px solid var(--line2);display:flex;align-items:center;gap:10px}
.step{width:24px;height:24px;border-radius:50%;background:var(--ink);color:#fff;font-size:12px;display:grid;place-items:center;flex-shrink:0}
.body{padding:18px 20px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}
@media(max-width:600px){.grid{grid-template-columns:1fr}.wrap{padding:24px 16px 48px}h1{font-size:23px}}
label{display:block;font-weight:550;font-size:13px;margin-bottom:6px;color:var(--ink2)}
input{width:100%;height:40px;padding:0 12px;border:1px solid var(--line);border-radius:9px;font:inherit;background:#fff;color:var(--ink)}
input:focus{outline:none;border-color:var(--ink);box-shadow:0 0 0 3px #15151714}
.hint{font-size:12px;color:var(--muted);margin-top:5px}
.req{list-style:none;margin:0;padding:0}.req li{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid var(--line2)}.req li:last-child{border:0}
.dot{width:20px;height:20px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-size:12px;font-weight:700;margin-top:1px}.dot.ok{background:var(--okbg);color:var(--ok)}.dot.bad{background:var(--badbg);color:var(--bad)}
.req small{display:block;color:var(--muted)}
.alert{padding:14px 16px;border-radius:12px;margin-bottom:18px;font-size:13.5px}.alert.bad{background:var(--badbg);color:var(--bad)}.alert.ok{background:var(--okbg);color:var(--ok)}.alert.warn{background:var(--warnbg);color:var(--warn)}
.alert ul{margin:6px 0 0;padding-left:18px}
.btn{display:inline-flex;align-items:center;justify-content:center;height:44px;padding:0 22px;border:0;border-radius:10px;background:var(--ink);color:#fff;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}.btn:disabled{opacity:.4;cursor:not-allowed}.btn.block{width:100%}.btn.sec{background:#fff;color:var(--ink);border:1px solid var(--line)}
code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px}pre{background:#151517;color:#EDEDEF;padding:12px 14px;border-radius:10px;overflow-x:auto;margin:8px 0 0;white-space:pre-wrap;word-break:break-all}
ol.steps{margin:0;padding-left:20px}ol.steps li{margin:8px 0}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand"><span class="mark">RY</span>RY Medya Platform</div>

<?php if ($locked): ?>
    <h1>Kurulum zaten yapılmış</h1>
    <p class="sub">Bu sunucuda sistem kurulu. Güvenlik nedeniyle kurulum sihirbazı yeniden çalışmaz.</p>
    <div class="alert warn">Sıfırdan kurmak istiyorsanız: <code>config/db.php</code> ve <code>config/installed.lock</code> dosyalarını silin ve boş bir veritabanı kullanın. Mevcut verilerinizi kaybetmemek için önce yedek alın.</div>
    <a class="btn" href="index.php">Sisteme git</a>

<?php elseif ($done): ?>
    <h1>Kurulum tamamlandı 🎉</h1>
    <p class="sub">Sistem <strong><?= h($info['url']) ?></strong> adresinde hazır.</p>
    <?php if ($finish_error): ?>
        <div class="alert bad">İlk açılışta bir sorun oluştu: <?= h($finish_error) ?></div>
    <?php else: ?>
        <div class="alert ok">Veritabanı kuruldu (<?= (int)$tables ?> tablo). Yönetici hesabı: <strong><?= h($info['email']) ?></strong></div>
    <?php endif; ?>
    <div class="card"><h2><span class="step">!</span>Şimdi yapmanız gerekenler</h2><div class="body">
        <ol class="steps">
            <li><strong>Bu dosyayı silin:</strong> FTP ile sunucudaki <code>install.php</code> dosyasını silin (kilitli olsa da silinmesi en güvenlisi).</li>
            <li><strong>Giriş yapın:</strong> <a href="<?= h($info['url']) ?>/modules/auth/login.php"><?= h($info['url']) ?>/modules/auth/login.php</a></li>
            <li><strong>Ayarları doldurun:</strong> Ayarlar → Marka (logo, renk), Şirket ve künye (adres, vergi, MERSİS, KEP), E-posta (SMTP), Banka ve ödeme (IBAN, iyzico).</li>
            <li><strong>Platform kuralları ve katalog:</strong> İş merkezi → Hizmet kataloğu ve Kurallar.</li>
            <li><strong>Zamanlanmış görevler (cPanel → Cron Jobs):</strong>
                <pre>*/5 * * * *  php <?= h(__DIR__) ?>/cron/mail-queue.php
0 * * * *    php <?= h(__DIR__) ?>/cron/platform.php
15 3 * * *   php <?= h(__DIR__) ?>/cron/backup.php</pre>
            </li>
            <li><strong>Güvenlik:</strong> Ayarlar → Güvenlik bölümünden iki adımlı doğrulamayı açın.</li>
        </ol>
    </div></div>
    <a class="btn" href="<?= h($info['url']) ?>/modules/auth/login.php">Giriş sayfasına git</a>
    <?php unset($_SESSION['install_done'], $_SESSION['install_csrf']); ?>

<?php else: ?>
    <h1>Kurulum</h1>
    <p class="sub">Birkaç dakikada sistemi sıfırdan kurun. Başlamadan önce cPanel → <strong>MySQL Veritabanları</strong>'ndan boş bir veritabanı ve kullanıcı oluşturup kullanıcıya bu veritabanında <strong>tüm ayrıcalıkları</strong> verin.</p>

    <?php if ($errors): ?><div class="alert bad"><strong>Kurulum yapılamadı:</strong><ul><?php foreach ($errors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="card"><h2><span class="step">1</span>Sunucu gereksinimleri</h2><div class="body">
        <ul class="req">
        <?php foreach ($checks as [$label, $ok, $help]): ?>
            <li><span class="dot <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✕' ?></span><div><?= h($label) ?><?php if (!$ok): ?><small><?= h($help) ?></small><?php endif; ?></div></li>
        <?php endforeach; ?>
        </ul>
    </div></div>

    <form method="POST" action="install.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['install_csrf']) ?>">
        <div class="card"><h2><span class="step">2</span>Site adresi</h2><div class="body">
            <label for="base_url">Sistemin adresi</label>
            <input id="base_url" name="base_url" value="<?= h($old['base_url']) ?>" required>
            <p class="hint">Sonunda / olmadan. Örnek: https://platform.rymedya.com.tr — SSL (https) önceden kurulmuş olmalı.</p>
        </div></div>

        <div class="card"><h2><span class="step">3</span>Veritabanı</h2><div class="body grid">
            <div><label for="db_host">Sunucu</label><input id="db_host" name="db_host" value="<?= h($old['db_host']) ?>" required><p class="hint">Genelde localhost</p></div>
            <div><label for="db_name">Veritabanı adı</label><input id="db_name" name="db_name" value="<?= h($old['db_name'] ?? '') ?>" placeholder="ngsiteyo_platform" required></div>
            <div><label for="db_user">Kullanıcı adı</label><input id="db_user" name="db_user" value="<?= h($old['db_user'] ?? '') ?>" placeholder="ngsiteyo_platform" required></div>
            <div><label for="db_pass">Şifre</label><input id="db_pass" name="db_pass" type="password" value="" autocomplete="new-password"></div>
            <p class="hint full">cPanel'de veritabanı ve kullanıcı adının başına hesap adı eklenir (ör. <code>ngsiteyo_</code>); tam adı yazın.</p>
        </div></div>

        <div class="card"><h2><span class="step">4</span>Şirket</h2><div class="body grid">
            <div><label for="company_name">Resmi ünvan</label><input id="company_name" name="company_name" value="<?= h($old['company_name'] ?? '') ?>" placeholder="RYMedya Video Prodüksiyon ..." required></div>
            <div><label for="company_brand">Marka adı</label><input id="company_brand" name="company_brand" value="<?= h($old['company_brand']) ?>"></div>
        </div></div>

        <div class="card"><h2><span class="step">5</span>Yönetici hesabı</h2><div class="body grid">
            <div class="full"><label for="admin_name">Ad soyad</label><input id="admin_name" name="admin_name" value="<?= h($old['admin_name'] ?? '') ?>" required></div>
            <div class="full"><label for="admin_email">E-posta (giriş adresi)</label><input id="admin_email" name="admin_email" type="email" value="<?= h($old['admin_email'] ?? '') ?>" required></div>
            <div><label for="admin_pass">Şifre</label><input id="admin_pass" name="admin_pass" type="password" minlength="8" required autocomplete="new-password"><p class="hint">En az 8 karakter, harf ve rakam.</p></div>
            <div><label for="admin_pass2">Şifre tekrar</label><input id="admin_pass2" name="admin_pass2" type="password" minlength="8" required autocomplete="new-password"></div>
        </div></div>

        <button class="btn block" <?= $req_ok ? '' : 'disabled' ?>>Kurulumu başlat</button>
        <p class="hint" style="text-align:center;margin-top:12px">Kurulum boş bir veritabanına yapılır; dolu veritabanına dokunulmaz.</p>
    </form>
<?php endif; ?>
</div>
</body>
</html>
