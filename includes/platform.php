<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞ PAZARYERİ ÇEKİRDEĞİ
 * ====================================================================
 * Roller:
 *   - Platform yöneticisi (RY Medya personeli, platform.manage izni)
 *   - Ajans (iş veren)      : iş talebi girer, fiyatı onaylar, teslimi onaylar
 *   - Freelancer (iş alan)  : görünür işleri alır / başvurur, teslim eder
 *
 * İş akışı:
 *   submitted → (quote_sent) → open → assigned → in_progress
 *     → qa_review → delivered → completed
 *   (revision: teslimden sonra geri dönüş; cancelled: iptal)
 *
 * Ajans ile freelancer birbirini görmez. Ajansın ödediği tutar
 * (agency_price) ile freelancer'a ödenen ücret (freelancer_fee) ayrıdır.
 */

const JOB_CATEGORIES = [
    'shooting'        => ['label' => 'Çekim / Kameraman',          'icon' => 'video'],
    'editing'         => ['label' => 'Kurgu / Montaj',             'icon' => 'scissors'],
    'color'           => ['label' => 'Color Grading',              'icon' => 'palette'],
    'sound'           => ['label' => 'Ses Kayıt / Miksaj',          'icon' => 'mic'],
    'motion'          => ['label' => 'Motion Graphics / Animasyon', 'icon' => 'sparkles'],
    'drone'           => ['label' => 'Drone Çekimi',               'icon' => 'navigation'],
    'photo'           => ['label' => 'Fotoğraf Çekimi',            'icon' => 'camera'],
    'social'          => ['label' => 'Sosyal Medya İçerik',        'icon' => 'smartphone'],
    'full_production' => ['label' => 'Komple Prodüksiyon',         'icon' => 'clapperboard'],
    'other'           => ['label' => 'Diğer',                      'icon' => 'package'],
];

const FREELANCER_TIERS = [
    'standard' => ['label' => 'Standart', 'rank' => 1, 'color' => 'bg-slate-100 text-slate-700 border-slate-300'],
    'silver'   => ['label' => 'Silver',   'rank' => 2, 'color' => 'bg-zinc-200 text-zinc-800 border-zinc-400'],
    'gold'     => ['label' => 'Gold',     'rank' => 3, 'color' => 'bg-amber-100 text-amber-800 border-amber-400'],
    'elite'    => ['label' => 'Elite',    'rank' => 4, 'color' => 'bg-violet-100 text-violet-800 border-violet-400'],
];

// Her durum için: yönetici / ajans / freelancer gözünden etiket ve renk
const JOB_STATUSES = [
    'submitted'   => ['staff' => 'Yeni Talep (İnceleme)',        'agency' => 'İnceleniyor',                    'freelancer' => '—',                         'color' => 'bg-sky-100 text-sky-800 border-sky-300'],
    'quote_sent'  => ['staff' => 'Fiyat Onayı Bekleniyor',       'agency' => 'Fiyat Onayınız Bekleniyor',      'freelancer' => '—',                         'color' => 'bg-amber-100 text-amber-800 border-amber-300'],
    'open'        => ['staff' => 'Havuzda / Atama Bekliyor',     'agency' => 'Ekip Atanıyor',                  'freelancer' => 'Alınabilir',                'color' => 'bg-indigo-100 text-indigo-800 border-indigo-300'],
    'assigned'    => ['staff' => 'Atandı',                       'agency' => 'Ekip Atandı',                    'freelancer' => 'Size Atandı - Başlamayı Bekliyor', 'color' => 'bg-blue-100 text-blue-800 border-blue-300'],
    'in_progress' => ['staff' => 'Çalışılıyor',                  'agency' => 'Çalışılıyor',                    'freelancer' => 'Çalışıyorsunuz',            'color' => 'bg-cyan-100 text-cyan-800 border-cyan-300'],
    'qa_review'   => ['staff' => 'Kalite Kontrolde',             'agency' => 'Teslime Hazırlanıyor',           'freelancer' => 'Kalite Kontrolde',          'color' => 'bg-purple-100 text-purple-800 border-purple-300'],
    'revision'    => ['staff' => 'Revizyonda',                   'agency' => 'Revizyonda',                     'freelancer' => 'Revizyon İstendi',          'color' => 'bg-orange-100 text-orange-800 border-orange-300'],
    'delivered'   => ['staff' => 'Ajans Onayı Bekleniyor',       'agency' => 'Teslim Edildi - Onayınız Bekleniyor', 'freelancer' => 'Müşteri Onayında',     'color' => 'bg-teal-100 text-teal-800 border-teal-300'],
    'completed'   => ['staff' => 'Tamamlandı',                   'agency' => 'Tamamlandı',                     'freelancer' => 'Tamamlandı',                'color' => 'bg-emerald-100 text-emerald-800 border-emerald-300'],
    'cancelled'   => ['staff' => 'İptal',                        'agency' => 'İptal Edildi',                   'freelancer' => 'İptal',                     'color' => 'bg-rose-100 text-rose-800 border-rose-300'],
];

// Freelancer üzerinde "aktif" sayılan durumlar (eşzamanlı iş limiti için)
const JOB_ACTIVE_STATUSES = ['assigned', 'in_progress', 'qa_review', 'revision', 'delivered'];

const JOB_VISIBILITY = [
    'pool'     => 'Havuz (kurallara uyan tüm onaylı freelancer\'lar)',
    'selected' => 'Sadece seçtiğim freelancer\'lar',
    'internal' => 'Gizli - Sadece ekibim (freelancer görmez)',
];

const JOB_DISPATCH = [
    'first_come'  => 'İlk alan alır (anında atama)',
    'application' => 'Başvuru topla, ben seçeyim',
];

// Platform politikası varsayılanları (Platform Ayarları ekranından değiştirilir)
const PLATFORM_DEFAULTS = [
    'platform_pool_enabled'          => '1',  // Freelancer iş havuzu açık mı?
    'platform_freelancer_signup'     => '1',  // Freelancer kaydı açık mı?
    'platform_agency_signup'         => '1',  // Ajans kaydı açık mı?
    'platform_show_agency_name'      => '0',  // Freelancer ajans adını görsün mü?
    'platform_qa_required'           => '1',  // Freelancer teslimleri önce kalite kontrole mi düşsün?
    'platform_max_active_jobs'       => '3',  // Freelancer başına eşzamanlı iş limiti
    'platform_default_margin'        => '25', // Freelancer ücreti önerisi için varsayılan platform marjı (%)
    'platform_default_priority_hours'=> '0',  // Yeni işlerde üst seviyelere öncelik süresi (saat)
    'platform_auto_invoice'          => '1',  // İş tamamlanınca ajansa satış faturası otomatik kesilsin mi?
    'platform_freelancer_vat'        => '0',  // Freelancer alış faturası KDV oranı
    'platform_max_revisions'         => '2',  // Ücretsiz revizyon hakkı
];

function platform_setting(string $key): string {
    return get_setting($key, PLATFORM_DEFAULTS[$key] ?? '');
}

function tier_rank(?string $tier): int {
    return FREELANCER_TIERS[$tier ?? 'standard']['rank'] ?? 1;
}

function job_status_badge(string $status, string $perspective = 'staff'): string {
    $s = JOB_STATUSES[$status] ?? ['staff' => $status, 'agency' => $status, 'freelancer' => $status, 'color' => 'bg-slate-100 text-slate-700 border-slate-300'];
    return '<span class="inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-bold border ' . $s['color'] . '">' . e($s[$perspective] ?? $s['staff']) . '</span>';
}

function job_category_label(?string $key): string {
    return JOB_CATEGORIES[$key]['label'] ?? ($key ?: '-');
}

/**
 * ====================================================================
 * MIGRATION (v3) - platform tabloları
 * ====================================================================
 */
function run_platform_migrations(): void {
    global $db;

    $tables = [
        "CREATE TABLE IF NOT EXISTS `platform_jobs` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `job_code` VARCHAR(30) NOT NULL UNIQUE,
          `agency_contact_id` INT UNSIGNED NULL,
          `created_by_user_id` INT UNSIGNED NULL,
          `title` VARCHAR(200) NOT NULL,
          `category` VARCHAR(30) NOT NULL DEFAULT 'other',
          `description` TEXT NULL,
          `deliverables` TEXT NULL,
          `reference_links` TEXT NULL,
          `location_city` VARCHAR(80) NULL,
          `location_detail` VARCHAR(255) NULL,
          `is_remote` TINYINT(1) NOT NULL DEFAULT 0,
          `start_date` DATE NULL,
          `deadline` DATE NULL,
          `budget` DECIMAL(15,2) NULL,
          `agency_price` DECIMAL(15,2) NULL,
          `freelancer_fee` DECIMAL(15,2) NULL,
          `currency` VARCHAR(10) NOT NULL DEFAULT 'TRY',
          `status` VARCHAR(20) NOT NULL DEFAULT 'submitted',
          `visibility` VARCHAR(20) NOT NULL DEFAULT 'pool',
          `dispatch_mode` VARCHAR(20) NOT NULL DEFAULT 'first_come',
          `min_tier` VARCHAR(20) NOT NULL DEFAULT 'standard',
          `priority_tier` VARCHAR(20) NULL,
          `priority_hours` INT NOT NULL DEFAULT 0,
          `skill_match_only` TINYINT(1) NOT NULL DEFAULT 0,
          `city_match_only` TINYINT(1) NOT NULL DEFAULT 0,
          `assigned_type` VARCHAR(20) NULL,
          `assigned_user_id` INT UNSIGNED NULL,
          `internal_project_id` INT UNSIGNED NULL,
          `revision_count` INT NOT NULL DEFAULT 0,
          `agency_rating` TINYINT NULL,
          `agency_review` TEXT NULL,
          `freelancer_rating` TINYINT NULL,
          `cancel_reason` VARCHAR(500) NULL,
          `sales_invoice_id` INT UNSIGNED NULL,
          `purchase_invoice_id` INT UNSIGNED NULL,
          `published_at` DATETIME NULL,
          `assigned_at` DATETIME NULL,
          `delivered_at` DATETIME NULL,
          `completed_at` DATETIME NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY `idx_status` (`status`),
          KEY `idx_agency` (`agency_contact_id`),
          KEY `idx_assignee` (`assigned_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `platform_job_visible_to` (
          `job_id` INT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`job_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `platform_applications` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `job_id` INT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `proposed_fee` DECIMAL(15,2) NULL,
          `note` TEXT NULL,
          `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uniq_job_user` (`job_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `platform_messages` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `job_id` INT UNSIGNED NOT NULL,
          `channel` VARCHAR(20) NOT NULL,
          `sender_type` VARCHAR(20) NOT NULL,
          `sender_user_id` INT UNSIGNED NULL,
          `sender_name` VARCHAR(150) NULL,
          `message` TEXT NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_job_channel` (`job_id`, `channel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `platform_deliveries` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `job_id` INT UNSIGNED NOT NULL,
          `submitted_by_user_id` INT UNSIGNED NULL,
          `submitted_by_type` VARCHAR(20) NOT NULL,
          `url` VARCHAR(1000) NOT NULL,
          `note` TEXT NULL,
          `status` VARCHAR(20) NOT NULL DEFAULT 'qa',
          `feedback` TEXT NULL,
          `reviewed_at` DATETIME NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_job` (`job_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `freelancer_profiles` (
          `user_id` INT UNSIGNED PRIMARY KEY,
          `contact_id` INT UNSIGNED NOT NULL,
          `title` VARCHAR(150) NULL,
          `skills` VARCHAR(500) NULL,
          `city` VARCHAR(80) NULL,
          `bio` TEXT NULL,
          `portfolio_url` VARCHAR(500) NULL,
          `day_rate` DECIMAL(15,2) NULL,
          `equipment` TEXT NULL,
          `tier` VARCHAR(20) NOT NULL DEFAULT 'standard',
          `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
          `is_available` TINYINT(1) NOT NULL DEFAULT 1,
          `rating_avg` DECIMAL(3,2) NULL,
          `rating_count` INT NOT NULL DEFAULT 0,
          `completed_jobs` INT NOT NULL DEFAULT 0,
          `admin_notes` TEXT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `agency_profiles` (
          `user_id` INT UNSIGNED PRIMARY KEY,
          `contact_id` INT UNSIGNED NOT NULL,
          `website` VARCHAR(255) NULL,
          `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
          `admin_notes` TEXT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $sql) {
        $db->query($sql);
    }

    // contacts.type ENUM ise 'agency' değeri eklenebilsin diye VARCHAR'a çevrilir (veri korunur)
    $ct = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'type'")->fetchColumn();
    if ($ct && stripos($ct, 'enum(') === 0 && stripos($ct, "'agency'") === false) {
        $db->query("ALTER TABLE `contacts` MODIFY COLUMN `type` VARCHAR(30) NOT NULL DEFAULT 'client'");
    }

    get_role_id_by_slug('agency', 'Ajans (Platform İş Veren)', 'Platformda iş talebi oluşturan ajans hesapları');
    get_role_id_by_slug('freelancer', 'Freelancer (Platform İş Alan)', 'Platformdan iş alan serbest çalışan hesapları');
}

function get_role_id_by_slug(string $slug, string $name, string $description = ''): int {
    global $db;
    $st = $db->prepare("SELECT id FROM roles WHERE role_slug = ? LIMIT 1");
    $st->execute([$slug]);
    $id = $st->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $db->prepare("INSERT INTO roles (role_name, role_slug, description) VALUES (?, ?, ?)")->execute([$name, $slug, $description]);
    return (int)$db->lastInsertId();
}

/**
 * ====================================================================
 * PORTAL KİMLİĞİ
 * ====================================================================
 */
function portal_role(): string {
    return $_SESSION['client_user']['role'] ?? 'client';
}

/**
 * Ajans / freelancer sayfaları için giriş + rol + onay kontrolü.
 * Onaysız hesaplar yalnızca bekleme ekranını ve profilini görür.
 */
function require_platform_role(string $role, bool $require_approved = true): array {
    global $db;
    require_client_login(['agency', 'freelancer']);
    if (portal_role() !== $role) {
        redirect(BASE_URL . '/platform/index.php');
    }
    $uid = (int)$_SESSION['client_user_id'];
    $table = $role === 'agency' ? 'agency_profiles' : 'freelancer_profiles';
    $st = $db->prepare("SELECT * FROM {$table} WHERE user_id = ?");
    $st->execute([$uid]);
    $profile = $st->fetch();
    if (!$profile) {
        // Profil kaydı yoksa (eski veri) bekleyen profil oluşturulur
        $db->prepare("INSERT INTO {$table} (user_id, contact_id, status) VALUES (?, ?, 'pending')")->execute([$uid, (int)$_SESSION['client_contact_id']]);
        $st->execute([$uid]);
        $profile = $st->fetch();
    }
    if ($profile['status'] === 'suspended') {
        unset($_SESSION['client_user_id'], $_SESSION['client_contact_id'], $_SESSION['client_user']);
        set_flash('error', 'Hesabınız askıya alınmıştır. Lütfen platform yöneticisiyle iletişime geçiniz.');
        redirect(BASE_URL . '/client/login.php');
    }
    if ($require_approved && $profile['status'] !== 'approved') {
        redirect(BASE_URL . '/platform/index.php');
    }
    return $profile;
}

/**
 * ====================================================================
 * BİLDİRİMLER
 * ====================================================================
 */
function notify_user(int $user_id, string $message, string $link, ?int $job_id = null): void {
    log_activity('platform', $message, 'job', $job_id, $link, $user_id);
}

function notify_contact_users(?int $contact_id, string $message, string $link, ?int $job_id = null): void {
    global $db;
    if (!$contact_id) {
        return;
    }
    $st = $db->prepare("SELECT id FROM users WHERE contact_id = ? AND status = 'active'");
    $st->execute([$contact_id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        notify_user((int)$uid, $message, $link, $job_id);
    }
}

/**
 * Personele duyuru: portal kullanıcısının yaptığı işlemler zaten "client"
 * aktörüyle yayınlanır. Personelin kendi işlemleri için ayrı bildirim gerekmez.
 */
function notify_staff(string $message, int $job_id): void {
    log_activity('platform', $message, 'job', $job_id, "/modules/platform/job.php?id={$job_id}");
}

function portal_notifications(int $user_id, int $limit = 8): array {
    global $db;
    $seen = $db->prepare("SELECT last_seen_id FROM user_notification_state WHERE user_id = ?");
    $seen->execute([$user_id]);
    $last = (int)$seen->fetchColumn();
    $cnt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE target_user_id = ? AND id > ?");
    $cnt->execute([$user_id, $last]);
    $list = $db->prepare("SELECT * FROM activity_log WHERE target_user_id = ? ORDER BY id DESC LIMIT " . (int)$limit);
    $list->execute([$user_id]);
    $items = $list->fetchAll();
    foreach ($items as &$it) {
        $it['is_unread'] = (int)$it['id'] > $last;
    }
    return ['unread' => (int)$cnt->fetchColumn(), 'items' => $items];
}

/**
 * ====================================================================
 * İŞ KAYITLARI
 * ====================================================================
 */
function generate_job_code(): string {
    return next_sequential_code('platform_jobs', 'job_code', 'IS-');
}

function get_job(int $job_id): ?array {
    global $db;
    $st = $db->prepare("
        SELECT j.*, c.company_title AS agency_name, c.email AS agency_email, c.phone AS agency_phone,
               u.full_name AS assignee_name, u.email AS assignee_email, fp.tier AS assignee_tier, fp.contact_id AS assignee_contact_id
        FROM platform_jobs j
        LEFT JOIN contacts c ON j.agency_contact_id = c.id
        LEFT JOIN users u ON j.assigned_user_id = u.id
        LEFT JOIN freelancer_profiles fp ON fp.user_id = j.assigned_user_id
        WHERE j.id = ?
    ");
    $st->execute([$job_id]);
    $job = $st->fetch();
    return $job ?: null;
}

function job_messages(int $job_id, string $channel): array {
    global $db;
    $st = $db->prepare("SELECT * FROM platform_messages WHERE job_id = ? AND channel = ? ORDER BY id ASC");
    $st->execute([$job_id, $channel]);
    return $st->fetchAll();
}

function add_job_message(int $job_id, string $channel, string $sender_type, ?int $user_id, string $sender_name, string $message): void {
    global $db;
    $db->prepare("INSERT INTO platform_messages (job_id, channel, sender_type, sender_user_id, sender_name, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$job_id, $channel, $sender_type, $user_id, $sender_name, mb_substr($message, 0, 5000)]);
}

function job_deliveries(int $job_id, ?array $statuses = null): array {
    global $db;
    $sql = "SELECT * FROM platform_deliveries WHERE job_id = ?";
    $params = [$job_id];
    if ($statuses) {
        $sql .= " AND status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
        $params = array_merge($params, $statuses);
    }
    $st = $db->prepare($sql . " ORDER BY id DESC");
    $st->execute($params);
    return $st->fetchAll();
}

function freelancer_active_job_count(int $user_id): int {
    global $db;
    $in = "'" . implode("','", JOB_ACTIVE_STATUSES) . "'";
    $st = $db->prepare("SELECT COUNT(*) FROM platform_jobs WHERE assigned_user_id = ? AND status IN ({$in})");
    $st->execute([$user_id]);
    return (int)$st->fetchColumn();
}

/**
 * ====================================================================
 * GÖRÜNÜRLÜK KURALLARI (PAZARLAMA POLİTİKASI)
 * ====================================================================
 * Bir freelancer havuzdaki bir işi görebilir mi? Görmüyorsa nedenini döner.
 * Sıra: havuz açık → profil onaylı → iş açık → görünürlük modu
 *       → seviye eşiği → öncelikli erişim süresi → uzmanlık → şehir
 */
function job_visibility_reason(array $job, array $profile): ?string {
    global $db;
    if (platform_setting('platform_pool_enabled') !== '1') {
        return 'İş havuzu şu an kapalı';
    }
    if (($profile['status'] ?? '') !== 'approved') {
        return 'Profil onaylı değil';
    }
    if ($job['status'] !== 'open') {
        return 'İş havuzda değil';
    }
    if ($job['visibility'] === 'internal') {
        return 'İş ekibe özel';
    }
    if ($job['visibility'] === 'selected') {
        $st = $db->prepare("SELECT COUNT(*) FROM platform_job_visible_to WHERE job_id = ? AND user_id = ?");
        $st->execute([$job['id'], $profile['user_id']]);
        return (int)$st->fetchColumn() > 0 ? null : 'Seçili freelancer listesinde değil';
    }
    $rank = tier_rank($profile['tier']);
    if ($rank < tier_rank($job['min_tier'])) {
        return 'Seviye yetersiz (en az ' . (FREELANCER_TIERS[$job['min_tier']]['label'] ?? $job['min_tier']) . ')';
    }
    if (!empty($job['priority_tier']) && (int)$job['priority_hours'] > 0 && !empty($job['published_at'])) {
        $open_to_all_at = strtotime($job['published_at']) + (int)$job['priority_hours'] * 3600;
        if (time() < $open_to_all_at && $rank < tier_rank($job['priority_tier'])) {
            return 'Öncelikli erişim süresinde';
        }
    }
    if ((int)$job['skill_match_only'] === 1) {
        $skills = array_filter(explode(',', (string)$profile['skills']));
        if (!in_array($job['category'], $skills, true)) {
            return 'Uzmanlık alanı eşleşmiyor';
        }
    }
    if ((int)$job['city_match_only'] === 1 && (int)$job['is_remote'] !== 1 && !empty($job['location_city'])) {
        if (mb_strtolower(trim((string)$profile['city'])) !== mb_strtolower(trim($job['location_city']))) {
            return 'Şehir eşleşmiyor';
        }
    }
    return null;
}

function freelancer_can_see_job(array $job, array $profile): bool {
    if ((int)$job['assigned_user_id'] === (int)$profile['user_id']) {
        return true;
    }
    return job_visibility_reason($job, $profile) === null;
}

/**
 * Freelancer'ın görebildiği havuz işleri
 */
function visible_pool_jobs(array $profile): array {
    global $db;
    $jobs = $db->query("SELECT * FROM platform_jobs WHERE status = 'open' AND visibility != 'internal' ORDER BY published_at DESC, id DESC LIMIT 300")->fetchAll();
    return array_values(array_filter($jobs, fn($j) => job_visibility_reason($j, $profile) === null));
}

/**
 * ====================================================================
 * DURUM GEÇİŞLERİ
 * ====================================================================
 */
function job_publish(int $job_id): void {
    global $db;
    $db->prepare("UPDATE platform_jobs SET status = 'open', published_at = COALESCE(published_at, NOW()) WHERE id = ?")->execute([$job_id]);
    $job = get_job($job_id);
    if (!$job) {
        return;
    }
    notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işiniz onaylandı, ekip ataması yapılıyor.", "/platform/job.php?id={$job_id}", $job_id);
    if ($job['visibility'] === 'selected') {
        $st = $db->prepare("SELECT user_id FROM platform_job_visible_to WHERE job_id = ?");
        $st->execute([$job_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            notify_user((int)$uid, "Size özel yeni iş: {$job['job_code']} · {$job['title']}", "/platform/job.php?id={$job_id}", $job_id);
        }
    }
}

/**
 * Freelancer'a atama. $atomic=true ise "ilk alan alır" yarışında yalnızca
 * iş hâlâ açıksa atanır. Başarılıysa true döner.
 */
function job_assign_freelancer(int $job_id, int $user_id, bool $atomic = false, ?float $fee = null): bool {
    global $db;
    $sql = "UPDATE platform_jobs SET status = 'assigned', assigned_type = 'freelancer', assigned_user_id = ?, assigned_at = NOW()"
         . ($fee !== null ? ", freelancer_fee = ?" : "")
         . " WHERE id = ?" . ($atomic ? " AND status = 'open' AND assigned_user_id IS NULL" : "");
    $params = $fee !== null ? [$user_id, $fee, $job_id] : [$user_id, $job_id];
    $st = $db->prepare($sql);
    $st->execute($params);
    if ($st->rowCount() === 0) {
        return false;
    }
    $db->prepare("UPDATE platform_applications SET status = IF(user_id = ?, 'accepted', 'rejected') WHERE job_id = ? AND status = 'pending'")->execute([$user_id, $job_id]);
    $job = get_job($job_id);
    notify_user($user_id, "İş size atandı: {$job['job_code']} · {$job['title']}. Başlamak için işi açın.", "/platform/job.php?id={$job_id}", $job_id);
    notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işinize ekip atandı.", "/platform/job.php?id={$job_id}", $job_id);
    return true;
}

/**
 * İşi RY Medya ekibi üstlenir: ERP'de iç proje açılır ve işe bağlanır.
 */
function job_take_internal(int $job_id, int $staff_user_id): ?int {
    global $db;
    $job = get_job($job_id);
    if (!$job || !in_array($job['status'], ['open', 'submitted'], true)) {
        return null;
    }
    $project_id = null;
    if (!empty($job['agency_contact_id'])) {
        $code = generate_project_code();
        $type_map = ['editing' => 'other', 'social' => 'social_media', 'full_production' => 'commercial'];
        $db->prepare("
            INSERT INTO projects (client_id, project_code, project_name, project_type, status, agreed_budget, currency, start_date, deadline, description, created_by, created_at)
            VALUES (?, ?, ?, ?, 'pre_production', ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $job['agency_contact_id'], $code, $job['title'], $type_map[$job['category']] ?? 'other',
            (float)($job['agency_price'] ?? $job['budget'] ?? 0), $job['currency'], $job['start_date'], $job['deadline'],
            "Platform işi {$job['job_code']} ekibimiz tarafından üstlenildi.\n\n" . ($job['description'] ?? '') . "\n\nTeslimatlar:\n" . ($job['deliverables'] ?? ''),
            $staff_user_id
        ]);
        $project_id = (int)$db->lastInsertId();
    }
    $db->prepare("UPDATE platform_jobs SET status = 'in_progress', assigned_type = 'internal', assigned_user_id = NULL, internal_project_id = ?, assigned_at = NOW(), published_at = COALESCE(published_at, NOW()) WHERE id = ?")
       ->execute([$project_id, $job_id]);
    $db->prepare("UPDATE platform_applications SET status = 'rejected' WHERE job_id = ? AND status = 'pending'")->execute([$job_id]);
    notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işiniz üzerinde çalışılmaya başlandı.", "/platform/job.php?id={$job_id}", $job_id);
    return $project_id ?: 0;
}

function job_unassign(int $job_id, string $reason = ''): void {
    global $db;
    $job = get_job($job_id);
    if (!$job) {
        return;
    }
    $db->prepare("UPDATE platform_jobs SET status = 'open', assigned_type = NULL, assigned_user_id = NULL, assigned_at = NULL WHERE id = ?")->execute([$job_id]);
    if (!empty($job['assigned_user_id'])) {
        $db->prepare("UPDATE platform_applications SET status = 'withdrawn' WHERE job_id = ? AND user_id = ?")->execute([$job_id, $job['assigned_user_id']]);
    }
}

/**
 * Teslimat: freelancer / ekip teslim eder.
 */
function job_submit_delivery(array $job, string $url, string $note, string $by_type, ?int $user_id): void {
    global $db;
    $qa = $by_type === 'freelancer' && platform_setting('platform_qa_required') === '1';
    $db->prepare("INSERT INTO platform_deliveries (job_id, submitted_by_user_id, submitted_by_type, url, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$job['id'], $user_id, $by_type, $url, $note, $qa ? 'qa' : 'sent']);
    $db->prepare("UPDATE platform_jobs SET status = ?, delivered_at = ? WHERE id = ?")
       ->execute([$qa ? 'qa_review' : 'delivered', $qa ? null : date('Y-m-d H:i:s'), $job['id']]);
    if (!$qa) {
        notify_contact_users($job['agency_contact_id'], "📦 {$job['job_code']} işiniz teslim edildi. Lütfen inceleyip onaylayın.", "/platform/job.php?id={$job['id']}", (int)$job['id']);
    }
}

/**
 * Kalite kontrol sonucu (yönetici)
 */
function job_qa_decision(array $job, int $delivery_id, bool $approve, string $feedback = ''): void {
    global $db;
    if ($approve) {
        $db->prepare("UPDATE platform_deliveries SET status = 'sent', reviewed_at = NOW(), feedback = ? WHERE id = ? AND job_id = ?")->execute([$feedback ?: null, $delivery_id, $job['id']]);
        $db->prepare("UPDATE platform_jobs SET status = 'delivered', delivered_at = NOW() WHERE id = ?")->execute([$job['id']]);
        notify_contact_users($job['agency_contact_id'], "📦 {$job['job_code']} işiniz teslim edildi. Lütfen inceleyip onaylayın.", "/platform/job.php?id={$job['id']}", (int)$job['id']);
        if (!empty($job['assigned_user_id'])) {
            notify_user((int)$job['assigned_user_id'], "Teslimatınız kalite kontrolden geçti ve müşteriye iletildi: {$job['job_code']}", "/platform/job.php?id={$job['id']}", (int)$job['id']);
        }
    } else {
        $db->prepare("UPDATE platform_deliveries SET status = 'qa_rejected', reviewed_at = NOW(), feedback = ? WHERE id = ? AND job_id = ?")->execute([$feedback, $delivery_id, $job['id']]);
        $db->prepare("UPDATE platform_jobs SET status = 'revision' WHERE id = ?")->execute([$job['id']]);
        if (!empty($job['assigned_user_id'])) {
            notify_user((int)$job['assigned_user_id'], "Kalite kontrol düzeltme istedi: {$job['job_code']} — \"" . mb_substr($feedback, 0, 140) . "\"", "/platform/job.php?id={$job['id']}", (int)$job['id']);
        }
    }
}

/**
 * Ajans revizyon ister
 */
function job_request_revision(array $job, string $feedback): void {
    global $db;
    $last = job_deliveries((int)$job['id'], ['sent']);
    if ($last) {
        $db->prepare("UPDATE platform_deliveries SET status = 'revision', feedback = ?, reviewed_at = NOW() WHERE id = ?")->execute([$feedback, $last[0]['id']]);
    }
    $db->prepare("UPDATE platform_jobs SET status = 'revision', revision_count = revision_count + 1 WHERE id = ?")->execute([$job['id']]);
    if (!empty($job['assigned_user_id'])) {
        notify_user((int)$job['assigned_user_id'], "✏️ Revizyon istendi: {$job['job_code']} — \"" . mb_substr($feedback, 0, 140) . "\"", "/platform/job.php?id={$job['id']}", (int)$job['id']);
    }
}

/**
 * İşi kapatır: durum, puan, otomatik faturalar (ajansa satış, freelancer'dan alış).
 */
function job_complete(array $job, ?int $agency_rating = null, string $agency_review = ''): void {
    global $db;
    $job_id = (int)$job['id'];

    $last = job_deliveries($job_id, ['sent']);
    if ($last) {
        $db->prepare("UPDATE platform_deliveries SET status = 'approved', reviewed_at = NOW() WHERE id = ?")->execute([$last[0]['id']]);
    }
    $db->prepare("UPDATE platform_jobs SET status = 'completed', completed_at = NOW(), agency_rating = COALESCE(?, agency_rating), agency_review = COALESCE(?, agency_review) WHERE id = ?")
       ->execute([$agency_rating, $agency_review !== '' ? $agency_review : null, $job_id]);

    // 1. Ajansa satış faturası
    if (empty($job['sales_invoice_id']) && !empty($job['agency_contact_id']) && (float)$job['agency_price'] > 0 && platform_setting('platform_auto_invoice') === '1') {
        $vat = (float)get_setting('default_vat_rate', '20');
        $tax = calculate_tax_breakdown((float)$job['agency_price'], $vat, '0/10', 0);
        $no  = generate_invoice_number('sales');
        $db->prepare("
            INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, due_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at)
            VALUES ('sales', ?, ?, ?, CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), ?, ?, ?, '0/10', 0, 0, 0, ?, 'unpaid', ?, NOW())
        ")->execute([$no, $job['agency_contact_id'], $job['internal_project_id'] ?: null, $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'], "Platform işi {$job['job_code']}: {$job['title']}"]);
        $db->prepare("UPDATE platform_jobs SET sales_invoice_id = ? WHERE id = ?")->execute([(int)$db->lastInsertId(), $job_id]);
        recalculate_contact_balance((int)$job['agency_contact_id']);
    }

    // 2. Freelancer hakediş (alış faturası)
    if ($job['assigned_type'] === 'freelancer' && empty($job['purchase_invoice_id']) && !empty($job['assignee_contact_id']) && (float)$job['freelancer_fee'] > 0) {
        $vat = (float)platform_setting('platform_freelancer_vat');
        $tax = calculate_tax_breakdown((float)$job['freelancer_fee'], $vat, '0/10', 0);
        $no  = generate_invoice_number('purchase');
        $db->prepare("
            INSERT INTO invoices (invoice_type, invoice_number, contact_id, project_id, issue_date, subtotal, vat_rate, vat_amount, withholding_rate, withholding_amount, stoppage_rate, stoppage_amount, grand_total, payment_status, notes, created_at)
            VALUES ('purchase', ?, ?, ?, CURRENT_DATE(), ?, ?, ?, '0/10', 0, 0, 0, ?, 'unpaid', ?, NOW())
        ")->execute([$no, $job['assignee_contact_id'], $job['internal_project_id'] ?: null, $tax['subtotal'], $tax['vat_rate'], $tax['vat_amount'], $tax['grand_total'], "Platform işi hakedişi {$job['job_code']}: {$job['title']}"]);
        $db->prepare("UPDATE platform_jobs SET purchase_invoice_id = ? WHERE id = ?")->execute([(int)$db->lastInsertId(), $job_id]);
        recalculate_contact_balance((int)$job['assignee_contact_id']);
        $db->prepare("UPDATE freelancer_profiles SET completed_jobs = completed_jobs + 1 WHERE user_id = ?")->execute([$job['assigned_user_id']]);
        notify_user((int)$job['assigned_user_id'], "🎉 İş tamamlandı: {$job['job_code']}. Hakedişiniz (" . format_money((float)$job['freelancer_fee']) . ") kazançlarınıza eklendi.", "/platform/earnings.php", $job_id);
    }
}

/**
 * Freelancer puanı (yönetici verir) → profil ortalaması güncellenir
 */
function rate_freelancer(array $job, int $rating): void {
    global $db;
    $rating = max(1, min(5, $rating));
    $db->prepare("UPDATE platform_jobs SET freelancer_rating = ? WHERE id = ?")->execute([$rating, $job['id']]);
    if (!empty($job['assigned_user_id'])) {
        $st = $db->prepare("SELECT AVG(freelancer_rating), COUNT(freelancer_rating) FROM platform_jobs WHERE assigned_user_id = ? AND freelancer_rating IS NOT NULL");
        $st->execute([$job['assigned_user_id']]);
        [$avg, $cnt] = $st->fetch(PDO::FETCH_NUM);
        $db->prepare("UPDATE freelancer_profiles SET rating_avg = ?, rating_count = ? WHERE user_id = ?")->execute([$avg, $cnt, $job['assigned_user_id']]);
    }
}

function render_stars(?float $rating): string {
    if ($rating === null) {
        return '<span class="text-slate-300">Puan yok</span>';
    }
    $full = (int)round($rating);
    return '<span class="text-amber-500">' . str_repeat('★', $full) . '</span><span class="text-slate-300">' . str_repeat('★', 5 - $full) . '</span> <span class="text-slate-500">' . number_format($rating, 1, ',', '') . '</span>';
}

/**
 * Güvenli dış bağlantı kontrolü (yalnızca http/https)
 */
function is_safe_url(string $url): bool {
    return (bool)filter_var($url, FILTER_VALIDATE_URL) && (bool)preg_match('#^https?://#i', $url);
}
