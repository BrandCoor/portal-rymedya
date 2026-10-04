<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - İŞ PAZARYERİ ÇEKİRDEĞİ (v2)
 * ====================================================================
 * Roller
 *   Platform yöneticisi : RY Medya personeli (platform.manage)
 *   Ajans               : katalogdan iş girer veya özel teklif ister
 *   Freelancer          : görünür kılınan işleri alır / teklif verir, teslim eder
 *
 * İş akışı (katalog)
 *   Ajans hizmetleri seçer → fiyat anında hesaplanır → iş girer
 *   → (otomatik yayın veya yönetici onayı) → havuz → atama → üretim
 *   → kalite kontrol → teslim → ajans onayı → kapanış (fatura + hakediş)
 *
 * Özel iş akışı (katalog dışı)
 *   Ajans özel talep girer → yönetici fiyatlar → ajans onaylar → havuz ...
 *
 * Kurallar
 *   - Ajans ve freelancer birbirini görmez; freelancer ajans fiyatını görmez.
 *   - Termin: aynı gün / çok yakın tarihli işler engellenir veya acil
 *     ücretiyle kabul edilir (ayarlanabilir).
 *   - Freelancer'ın aynı anda alabileceği iş sayısı seviyesine bağlıdır.
 *   - Seviye, performans puanına göre otomatik hesaplanabilir.
 */

const JOB_CATEGORIES = [
    'shooting'        => ['label' => 'Çekim / Kamera',              'icon' => 'video',        'onsite' => true],
    'drone'           => ['label' => 'Drone Çekimi',                'icon' => 'navigation',   'onsite' => true],
    'photo'           => ['label' => 'Fotoğraf',                    'icon' => 'camera',       'onsite' => true],
    'editing'         => ['label' => 'Kurgu / Montaj',              'icon' => 'scissors',     'onsite' => false],
    'color'           => ['label' => 'Color Grading',               'icon' => 'palette',      'onsite' => false],
    'sound'           => ['label' => 'Ses Kayıt / Miksaj',          'icon' => 'audio-lines',  'onsite' => false],
    'motion'          => ['label' => 'Motion Graphics',             'icon' => 'shapes',       'onsite' => false],
    'social'          => ['label' => 'Sosyal Medya İçerik',         'icon' => 'smartphone',   'onsite' => false],
    'full_production' => ['label' => 'Komple Prodüksiyon',          'icon' => 'clapperboard', 'onsite' => true],
    'other'           => ['label' => 'Diğer',                       'icon' => 'package',      'onsite' => false],
];

const SERVICE_UNITS = ['gün' => 'Gün', 'yarım gün' => 'Yarım gün', 'saat' => 'Saat', 'adet' => 'Adet', 'dakika' => 'Dakika', 'paket' => 'Paket', 'proje' => 'Proje'];

const FREELANCER_TIERS = [
    'standard' => ['label' => 'Standart', 'rank' => 1, 'tone' => 'neutral'],
    'silver'   => ['label' => 'Silver',   'rank' => 2, 'tone' => 'info'],
    'gold'     => ['label' => 'Gold',     'rank' => 3, 'tone' => 'warning'],
    'elite'    => ['label' => 'Elite',    'rank' => 4, 'tone' => 'violet'],
];

// Otomatik seviye için asgari performans puanı ve tamamlanan iş sayısı

// Her durum: yönetici / ajans / freelancer gözünden etiket ve renk tonu
const JOB_STATUSES = [
    'submitted'   => ['staff' => 'Onay bekliyor',        'agency' => 'İnceleniyor',              'freelancer' => '—',                   'tone' => 'info'],
    'quote_sent'  => ['staff' => 'Teklif ajansta',       'agency' => 'Fiyat onayınızda',         'freelancer' => '—',                   'tone' => 'warning'],
    'open'        => ['staff' => 'Havuzda',              'agency' => 'Ekip atanıyor',            'freelancer' => 'Alınabilir',          'tone' => 'accent'],
    'assigned'    => ['staff' => 'Atandı',               'agency' => 'Ekip atandı',              'freelancer' => 'Başlamanız bekleniyor', 'tone' => 'info'],
    'in_progress' => ['staff' => 'Üretimde',             'agency' => 'Üretimde',                 'freelancer' => 'Üretimde',            'tone' => 'info'],
    'qa_review'   => ['staff' => 'Kalite kontrolde',     'agency' => 'Teslime hazırlanıyor',     'freelancer' => 'Kalite kontrolde',    'tone' => 'violet'],
    'revision'    => ['staff' => 'Revizyonda',           'agency' => 'Revizyonda',               'freelancer' => 'Revizyon istendi',    'tone' => 'warning'],
    'delivered'   => ['staff' => 'Ajans onayında',       'agency' => 'Teslim edildi',            'freelancer' => 'Müşteri onayında',    'tone' => 'violet'],
    'completed'   => ['staff' => 'Tamamlandı',           'agency' => 'Tamamlandı',               'freelancer' => 'Tamamlandı',          'tone' => 'success'],
    'cancelled'   => ['staff' => 'İptal',                'agency' => 'İptal edildi',             'freelancer' => 'İptal',               'tone' => 'danger'],
];

const JOB_ACTIVE_STATUSES = ['assigned', 'in_progress', 'qa_review', 'revision', 'delivered'];

const JOB_VISIBILITY = [
    'pool'     => 'Havuz — kurallara uyan onaylı freelancer\'lar',
    'selected' => 'Seçili freelancer\'lar',
    'internal' => 'Ekibe özel — freelancer görmez',
];

const JOB_DISPATCH = [
    'first_come'  => 'İlk alan alır',
    'application' => 'Teklif topla, ben seçeyim',
];

// Platform politikası varsayılanları (Platform Ayarları ekranından değiştirilir)
const PLATFORM_DEFAULTS = [
    'platform_pool_enabled'          => '1',
    'platform_freelancer_signup'     => '1',
    'platform_agency_signup'         => '1',
    'platform_show_agency_name'      => '0',
    'platform_qa_required'           => '1',
    'platform_auto_invoice'          => '1',
    'platform_auto_publish'          => '1',  // Katalogdan girilen işler yönetici onayı beklemeden havuza düşsün
    'platform_auto_tier'             => '1',  // Seviye performansa göre otomatik güncellensin
    'platform_default_margin'        => '25',
    // Yeni işlerin varsayılan görünürlük / dağıtım kuralları (iş sayfasından tek tek değiştirilebilir)
    'platform_onsite_confirm'        => 'auto',
    'platform_payout_min'            => '0',    // Freelancer ödeme talebi alt sınırı (₺)
    'platform_payout_days'           => '5',    // Ödeme talebinin işleme süresi (iş günü, freelancer'a gösterilir)
    'platform_show_bank_accounts'    => '1',    // Ajansın ödeme ekranında IBAN'lı banka hesapları görünsün // Yerinde işin "yapıldı" onayı: auto | staff | agency
    'platform_default_visibility'    => 'pool',
    'platform_default_dispatch'      => 'first_come',
    'platform_default_min_tier'      => 'standard',
    'platform_default_skill_match'   => '1',
    'platform_default_city_match'    => '1',  // Yalnızca yerinde (çekim) işlerde uygulanır
    'platform_default_priority_tier' => '',   // Yeni işlerde öncelikli seviye (boş = yok)
    'platform_default_priority_hours'=> '0',
    'platform_max_revisions'         => '2',
    'platform_freelancer_vat'        => '0',
    // Termin kuralları
    'platform_block_same_day'        => '1',  // Aynı gün başlayan işler alınmasın
    'platform_min_lead_hours'        => '24', // Bundan kısa sürede başlayan işler engellenir
    'platform_warn_lead_hours'       => '72', // Bundan kısa sürede başlayan işler "acil" sayılır
    'platform_rush_fee_percent'      => '25', // Acil iş ek ücreti (%) — 0 ise acil iş ücretsiz kabul edilir
    'platform_rush_freelancer_share' => '60', // Acil ücretinin freelancer'a aktarılan payı (%)
    // Seviye bazlı eşzamanlı iş limitleri
    'platform_limit_standard'        => '1',
    'platform_limit_silver'          => '2',
    'platform_limit_gold'            => '3',
    'platform_limit_elite'           => '5',
    'platform_tier_downgrade'        => '1',  // Kurallar sağlanmazsa seviye düşebilir
    'platform_milestone_per_item'    => '1',  // Katalog işinde her hizmet kalemi ayrı aşama
    'platform_auto_approve_days'     => '7',  // Ajans bu kadar gün yanıtlamazsa teslim otomatik onaylanır (0 = kapalı)
    'platform_catalog_reviewed'      => '0',
];

function platform_setting(string $key): string {
    return get_setting($key, PLATFORM_DEFAULTS[$key] ?? '');
}

function tier_rank(?string $tier): int {
    return FREELANCER_TIERS[$tier ?? 'standard']['rank'] ?? 1;
}

function tier_label(?string $tier): string {
    return FREELANCER_TIERS[$tier ?? 'standard']['label'] ?? 'Standart';
}

function tier_job_limit(?string $tier): int {
    return max(0, (int)platform_setting('platform_limit_' . ($tier ?: 'standard')));
}

function job_status_label(string $status, string $perspective = 'staff'): string {
    return JOB_STATUSES[$status][$perspective] ?? JOB_STATUSES[$status]['staff'] ?? $status;
}

function job_status_badge(string $status, string $perspective = 'staff'): string {
    $tone = JOB_STATUSES[$status]['tone'] ?? 'neutral';
    return ui_badge(job_status_label($status, $perspective), $tone, true);
}

function tier_badge(?string $tier): string {
    $t = FREELANCER_TIERS[$tier ?? 'standard'] ?? FREELANCER_TIERS['standard'];
    return ui_badge($t['label'], $t['tone']);
}

function job_category_label(?string $key): string {
    return JOB_CATEGORIES[$key]['label'] ?? ($key ?: '-');
}

function job_category_icon(?string $key): string {
    return JOB_CATEGORIES[$key]['icon'] ?? 'package';
}

/**
 * ====================================================================
 * MIGRATION (v3)
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

/**
 * ====================================================================
 * MIGRATION (v4) - katalog, iş kalemleri, değişiklik günlüğü,
 * performans metrikleri, yazışma başlıkları
 * ====================================================================
 */
function run_platform_migrations_v4(): void {
    global $db;

    $db->query("CREATE TABLE IF NOT EXISTS `platform_services` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `category` VARCHAR(30) NOT NULL,
          `name` VARCHAR(150) NOT NULL,
          `description` VARCHAR(500) NULL,
          `unit` VARCHAR(20) NOT NULL DEFAULT 'adet',
          `agency_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
          `freelancer_fee` DECIMAL(15,2) NOT NULL DEFAULT 0,
          `min_tier` VARCHAR(20) NOT NULL DEFAULT 'standard',
          `min_lead_hours` INT NOT NULL DEFAULT 0,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `sort_order` INT NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `platform_job_items` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `job_id` INT UNSIGNED NOT NULL,
          `service_id` INT UNSIGNED NULL,
          `name` VARCHAR(150) NOT NULL,
          `unit` VARCHAR(20) NOT NULL,
          `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1,
          `agency_unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
          `freelancer_unit_fee` DECIMAL(15,2) NOT NULL DEFAULT 0,
          KEY `idx_job` (`job_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS `platform_job_changes` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `job_id` INT UNSIGNED NOT NULL,
          `user_id` INT UNSIGNED NULL,
          `actor` VARCHAR(20) NOT NULL,
          `field_label` VARCHAR(100) NOT NULL,
          `old_value` TEXT NULL,
          `new_value` TEXT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_job` (`job_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $add = function (string $table, string $column, string $ddl) use ($db) {
        if (!column_exists($table, $column)) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        }
    };
    $add('platform_jobs', 'pricing_source', "`pricing_source` VARCHAR(20) NOT NULL DEFAULT 'custom'");
    $add('platform_jobs', 'is_rush', "`is_rush` TINYINT(1) NOT NULL DEFAULT 0");
    $add('platform_jobs', 'rush_fee', "`rush_fee` DECIMAL(15,2) NOT NULL DEFAULT 0");
    $add('platform_jobs', 'first_delivered_at', "`first_delivered_at` DATETIME NULL");
    $add('platform_jobs', 'agency_notes', "`agency_notes` TEXT NULL");
    $add('platform_messages', 'thread_user_id', "`thread_user_id` INT UNSIGNED NULL");
    $add('platform_applications', 'reject_reason', "`reject_reason` TEXT NULL");
    $add('platform_applications', 'available_from', "`available_from` DATE NULL");
    $add('platform_applications', 'reviewed_at', "`reviewed_at` DATETIME NULL");
    foreach ([
        'score' => "`score` DECIMAL(5,2) NULL",
        'on_time_rate' => "`on_time_rate` DECIMAL(5,2) NULL",
        'qa_pass_rate' => "`qa_pass_rate` DECIMAL(5,2) NULL",
        'avg_revisions' => "`avg_revisions` DECIMAL(5,2) NULL",
        'agency_rating_avg' => "`agency_rating_avg` DECIMAL(3,2) NULL",
        'releases_count' => "`releases_count` INT NOT NULL DEFAULT 0",
        'removed_count' => "`removed_count` INT NOT NULL DEFAULT 0",
        'late_count' => "`late_count` INT NOT NULL DEFAULT 0",
        'tier_locked' => "`tier_locked` TINYINT(1) NOT NULL DEFAULT 0",
        'metrics_updated_at' => "`metrics_updated_at` DATETIME NULL",
    ] as $col => $ddl) {
        $add('freelancer_profiles', $col, $ddl);
    }

    // Eski freelancer mesajları atanan kişinin başlığına taşınır
    $db->query("UPDATE platform_messages m JOIN platform_jobs j ON j.id = m.job_id SET m.thread_user_id = j.assigned_user_id WHERE m.channel = 'freelancer' AND m.thread_user_id IS NULL");

    // Örnek hizmet kataloğu (yönetici fiyatları güncellemeli)
    if ((int)$db->query("SELECT COUNT(*) FROM platform_services")->fetchColumn() === 0) {
        $seed = [
            ['shooting', 'Kameraman — tam gün (10 saat)', 'Kamera, temel lens seti ve operatör dahil', 'gün', 9000, 6500, 'standard', 48],
            ['shooting', 'Kameraman — yarım gün (5 saat)', 'Kısa röportaj, etkinlik veya ürün çekimi', 'yarım gün', 5500, 4000, 'standard', 48],
            ['shooting', 'Işık & ses ekipman paketi', 'LED ışık seti, yaka ve boom mikrofon', 'gün', 4000, 3000, 'standard', 48],
            ['drone', 'Drone çekimi', 'Lisanslı pilot; uçuş izni için en az 72 saat gerekir', 'gün', 8000, 6000, 'silver', 72],
            ['photo', 'Fotoğraf çekimi — yarım gün', 'Retuşlu 20 kare teslim', 'yarım gün', 5000, 3600, 'standard', 48],
            ['editing', 'Kurgu — kısa video (60 sn\'ye kadar)', '2 revizyon dahil', 'adet', 3500, 2500, 'standard', 48],
            ['editing', 'Kurgu — uzun video (5 dk\'ya kadar)', '2 revizyon dahil', 'adet', 9000, 6500, 'silver', 72],
            ['color', 'Color grading', '60 sn\'ye kadar, LUT ve teslim formatları dahil', 'adet', 2500, 1800, 'silver', 48],
            ['sound', 'Ses miksajı & mastering', 'Müzik, VO ve efekt dengesi', 'adet', 2000, 1400, 'standard', 24],
            ['motion', 'Motion graphics (30 sn\'ye kadar)', 'Logo animasyonu, alt yazı, grafik paket', 'adet', 6000, 4300, 'silver', 72],
            ['social', 'Dikey video paketi (3 adet Reels/TikTok)', 'Mevcut görüntülerden 9:16 kurgu', 'paket', 7500, 5300, 'standard', 48],
        ];
        $ins = $db->prepare("INSERT INTO platform_services (category, name, description, unit, agency_price, freelancer_fee, min_tier, min_lead_hours, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        foreach ($seed as $i => $row) {
            $ins->execute(array_merge($row, [$i]));
        }
    }

    // İzinler: kalıcı silme ve katalog/fiyat yönetimi rol olarak atanabilir
    ensure_permission('platform.delete', 'Platform kayıtlarını kalıcı silme (iş, ajans, freelancer, katalog)', 'platform', 'settings.manage');
    ensure_permission('platform.pricing', 'Hizmet kataloğu ve fiyatları yönetme', 'platform', 'settings.manage');

    ensure_permission('platform.manage', MODULE_PERMISSIONS['platform.manage']['description'], 'platform', 'projects.edit');

    // Hazır rol: Platform Yöneticisi (iş merkezi + katalog + kalıcı silme). Roller sayfasından kişilere atanır.
    $chk = $db->prepare("SELECT COUNT(*) FROM roles WHERE role_slug = 'platform_admin'");
    $chk->execute();
    if ((int)$chk->fetchColumn() === 0) {
        $rid = get_role_id_by_slug('platform_admin', 'Platform Yöneticisi', 'İş merkezi, hizmet kataloğu ve platform kayıtlarını kalıcı silme yetkisi');
        $perm = $db->prepare("SELECT id FROM permissions WHERE permission_key IN ('platform.manage', 'platform.delete', 'platform.pricing')");
        $perm->execute();
        $grant = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($perm->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $grant->execute([$rid, (int)$pid]);
        }
    }
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
        $db->prepare("INSERT INTO {$table} (user_id, contact_id, status) VALUES (?, ?, 'pending')")->execute([$uid, (int)$_SESSION['client_contact_id']]);
        $st->execute([$uid]);
        $profile = $st->fetch();
    }
    if ($profile['status'] === 'suspended') {
        unset($_SESSION['client_user_id'], $_SESSION['client_contact_id'], $_SESSION['client_user']);
        set_flash('error', 'Hesabınız askıya alınmıştır. Platform ekibiyle iletişime geçin.');
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

function notify_staff(string $message, int $job_id): void {
    log_activity('platform', $message, 'job', $job_id, "/modules/platform/job.php?id={$job_id}");
    try {
        mail_notify_staff_inbox($message, "/modules/platform/job.php?id={$job_id}");
    } catch (Throwable $e) {
        error_log('Ekip e-postası: ' . $e->getMessage());
    }
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
 * HİZMET KATALOĞU & FİYATLAMA
 * ====================================================================
 */
function catalog_services(bool $only_active = true): array {
    global $db;
    return $db->query("SELECT * FROM platform_services" . ($only_active ? " WHERE is_active = 1" : "") . " ORDER BY FIELD(category, '" . implode("','", array_keys(JOB_CATEGORIES)) . "'), sort_order, id")->fetchAll();
}

/**
 * Formdan gelen [service_id => quantity] listesini doğrular ve kalemlere çevirir.
 */
function build_order_items(array $quantities): array {
    global $db;
    $items = [];
    if (!$quantities) {
        return $items;
    }
    $ids = array_map('intval', array_keys($quantities));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT * FROM platform_services WHERE is_active = 1 AND id IN ({$in})");
    $st->execute($ids);
    foreach ($st->fetchAll() as $svc) {
        $qty = round((float)str_replace(',', '.', (string)($quantities[$svc['id']] ?? 0)), 2);
        if ($qty <= 0) {
            continue;
        }
        $items[] = [
            'service_id' => (int)$svc['id'], 'name' => $svc['name'], 'unit' => $svc['unit'], 'category' => $svc['category'],
            'quantity' => min($qty, 999), 'agency_unit_price' => (float)$svc['agency_price'], 'freelancer_unit_fee' => (float)$svc['freelancer_fee'],
            'min_tier' => $svc['min_tier'], 'min_lead_hours' => (int)$svc['min_lead_hours'],
        ];
    }
    return $items;
}

/**
 * Kalemlerden ajans fiyatı ve freelancer ücretini hesaplar (acil ücreti dahil).
 */
function price_order(array $items, bool $rush): array {
    $subtotal = 0.0;
    $fee = 0.0;
    foreach ($items as $it) {
        $subtotal += $it['quantity'] * $it['agency_unit_price'];
        $fee      += $it['quantity'] * $it['freelancer_unit_fee'];
    }
    $rush_pct  = $rush ? (float)platform_setting('platform_rush_fee_percent') : 0;
    $rush_fee  = round($subtotal * $rush_pct / 100, 2);
    $fee_bonus = round($rush_fee * (float)platform_setting('platform_rush_freelancer_share') / 100, 2);
    return [
        'subtotal'       => round($subtotal, 2),
        'rush_fee'       => $rush_fee,
        'agency_price'   => round($subtotal + $rush_fee, 2),
        'freelancer_fee' => round($fee + $fee_bonus, 2),
        'rush_bonus'     => $fee_bonus,
    ];
}

function save_job_items(int $job_id, array $items): void {
    global $db;
    $db->prepare("DELETE FROM platform_job_items WHERE job_id = ?")->execute([$job_id]);
    $ins = $db->prepare("INSERT INTO platform_job_items (job_id, service_id, name, unit, quantity, agency_unit_price, freelancer_unit_fee) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $it) {
        $ins->execute([$job_id, $it['service_id'] ?? null, $it['name'], $it['unit'], $it['quantity'], $it['agency_unit_price'], $it['freelancer_unit_fee']]);
    }
}

function job_items(int $job_id): array {
    global $db;
    $st = $db->prepare("SELECT ji.*, ps.category, ps.min_tier, ps.min_lead_hours FROM platform_job_items ji LEFT JOIN platform_services ps ON ps.id = ji.service_id WHERE ji.job_id = ? ORDER BY ji.id");
    $st->execute([$job_id]);
    return $st->fetchAll();
}

/**
 * İşin baskın kategorisi (en yüksek tutarlı kalem)
 */
function dominant_category(array $items, string $fallback = 'other'): string {
    $best = null;
    $max = -1;
    foreach ($items as $it) {
        $v = $it['quantity'] * $it['agency_unit_price'];
        if ($v > $max) {
            $max = $v;
            $best = $it['category'] ?? null;
        }
    }
    return $best ?: $fallback;
}

/**
 * ====================================================================
 * TERMİN KURALLARI (AYNI GÜN / ACİL İŞ)
 * ====================================================================
 * Referans tarih: çekim/başlangıç tarihi; yoksa teslim tarihi.
 * Saat hesabı referans günün 09:00'u baz alınarak yapılır.
 * level: ok | warn (acil, ek ücret) | block (kabul edilmez)
 */
function lead_time_rules(array $items = []): array {
    $service_min = 0;
    foreach ($items as $it) {
        $service_min = max($service_min, (int)($it['min_lead_hours'] ?? 0));
    }
    $block = max((int)platform_setting('platform_min_lead_hours'), $service_min);
    $warn  = max((int)platform_setting('platform_warn_lead_hours'), $block);
    return [
        'block_hours'    => $block,
        'warn_hours'     => $warn,
        'block_same_day' => platform_setting('platform_block_same_day') === '1',
        'rush_percent'   => (float)platform_setting('platform_rush_fee_percent'),
        'service_min'    => $service_min,
    ];
}

function assess_lead_time(?string $start_date, ?string $deadline, array $items = []): array {
    $rules = lead_time_rules($items);
    $ref = $start_date ?: $deadline;
    $base = ['level' => 'ok', 'hours' => null, 'message' => '', 'rules' => $rules];
    if (!$ref) {
        return $base;
    }
    $today = date('Y-m-d');
    $hours = (strtotime($ref . ' 09:00:00') - time()) / 3600;
    $base['hours'] = round($hours, 1);
    $what = $start_date ? 'Başlangıç' : 'Teslim';

    if ($ref < $today) {
        return array_merge($base, ['level' => 'block', 'message' => "{$what} tarihi geçmiş bir gün olamaz."]);
    }
    if ($ref === $today && $rules['block_same_day']) {
        return array_merge($base, ['level' => 'block', 'message' => "Aynı gün başlayan işler kabul edilmiyor. {$what} tarihini en erken " . format_date(date('Y-m-d', strtotime('+' . max(1, (int)ceil($rules['block_hours'] / 24)) . ' day'))) . " olarak seçin."]);
    }
    if ($hours < $rules['block_hours']) {
        $why = $rules['service_min'] >= $rules['block_hours'] && $rules['service_min'] > (int)platform_setting('platform_min_lead_hours')
            ? 'Seçtiğiniz hizmetler için en az ' . $rules['block_hours'] . ' saat önceden iş girişi gerekir.'
            : 'İşler en az ' . $rules['block_hours'] . ' saat önceden girilmelidir.';
        return array_merge($base, ['level' => 'block', 'message' => $why]);
    }
    if ($hours < $rules['warn_hours']) {
        $msg = 'Bu iş ' . $rules['warn_hours'] . ' saatten kısa sürede başlıyor ve acil iş olarak işlenecek.';
        if ($rules['rush_percent'] > 0) {
            $msg .= ' Fiyata %' . rtrim(rtrim(number_format($rules['rush_percent'], 1, ',', ''), '0'), ',') . ' acil iş farkı eklenir.';
        }
        return array_merge($base, ['level' => 'warn', 'message' => $msg]);
    }
    return $base;
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

/**
 * Mesajlar. Ajans kanalı tektir; freelancer kanalı her freelancer için ayrı başlıktır.
 */
function job_messages(int $job_id, string $channel, ?int $thread_user_id = null): array {
    global $db;
    if ($channel === 'freelancer') {
        $st = $db->prepare("SELECT * FROM platform_messages WHERE job_id = ? AND channel = 'freelancer' AND thread_user_id = ? ORDER BY id ASC");
        $st->execute([$job_id, (int)$thread_user_id]);
    } else {
        $st = $db->prepare("SELECT * FROM platform_messages WHERE job_id = ? AND channel = ? ORDER BY id ASC");
        $st->execute([$job_id, $channel]);
    }
    return $st->fetchAll();
}

function add_job_message(int $job_id, string $channel, string $sender_type, ?int $user_id, string $sender_name, string $message, ?int $thread_user_id = null): void {
    global $db;
    $db->prepare("INSERT INTO platform_messages (job_id, channel, thread_user_id, sender_type, sender_user_id, sender_name, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$job_id, $channel, $thread_user_id, $sender_type, $user_id, $sender_name, mb_substr($message, 0, 5000)]);
}

/**
 * Freelancer yazışma başlıkları (yönetici görünümü): kişi başına son mesaj
 */
function job_freelancer_threads(int $job_id): array {
    global $db;
    $st = $db->prepare("
        SELECT m.thread_user_id, u.full_name, COUNT(*) AS cnt, MAX(m.id) AS last_id
        FROM platform_messages m JOIN users u ON u.id = m.thread_user_id
        WHERE m.job_id = ? AND m.channel = 'freelancer'
        GROUP BY m.thread_user_id, u.full_name ORDER BY last_id DESC
    ");
    $st->execute([$job_id]);
    return $st->fetchAll();
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

function job_changes(int $job_id, int $limit = 50): array {
    return job_events($job_id, 'staff', $limit);
}

/**
 * Alan değişikliği kaydı (iş kaydına "Değişiklik" olarak yazılır)
 */
function log_job_change(int $job_id, string $actor, ?int $user_id, string $label, $old, $new): void {
    $vis = in_array($label, JOB_CHANGE_PRICE_LABELS, true) ? 'agency' : (in_array($label, ['Freelancer ücreti', 'Atama'], true) ? 'freelancer' : (in_array($label, ['Görünürlük politikası'], true) ? 'staff' : 'all'));
    job_event($job_id, 'change', $label, ['actor' => $actor, 'user_id' => $user_id, 'old' => $old === null ? null : (string)$old, 'new' => $new === null ? null : (string)$new, 'visibility' => $vis]);
}

const DELIVERY_STATUSES = [
    'qa'          => ['Kalite kontrolde', 'violet'],
    'sent'        => ['Ajans incelemesinde', 'info'],
    'approved'    => ['Onaylandı', 'success'],
    'revision'    => ['Revizyon istendi', 'warning'],
    'qa_rejected' => ['Kalite kontrolden döndü', 'danger'],
];

function delivery_status_badge(string $status, string $perspective = 'staff'): string {
    $d = DELIVERY_STATUSES[$status] ?? [$status, 'neutral'];
    if ($perspective === 'agency' && $status === 'sent') {
        $d = ['İncelemenizde', 'info'];
    }
    return ui_badge($d[0], $d[1], true);
}

const JOB_CHANGE_ACTORS = ['agency' => 'Ajans', 'staff' => 'Platform ekibi', 'freelancer' => 'Freelancer', 'system' => 'Sistem'];

/**
 * Fiyat içeren değişiklik satırları freelancer'a gösterilmez
 */
const JOB_CHANGE_PRICE_LABELS = ['İş tutarı', 'Sipariş tutarı', 'Ajans fiyatı', 'Acil iş farkı'];

/**
 * Freelancer'a ilişkin satırlar (kimin atandığı, hakediş, dağıtım politikası) ajansa gösterilmez
 */
const JOB_CHANGE_INTERNAL_LABELS = ['Freelancer ücreti', 'Atama', 'Görünürlük politikası'];

function render_job_changes(array $changes, string $perspective = 'staff'): string {
    return render_job_events(array_values(array_filter($changes, fn($e) => job_event_visible($e, $perspective))), $perspective);
}

/**
 * ====================================================================
 * AJANSIN DÜZENLEYEBİLECEĞİ ALANLAR
 * ====================================================================
 * Atamadan önce   : başlık, brief, teslimatlar, referanslar, tarihler,
 *                   lokasyon, hizmet kalemleri (fiyat yeniden hesaplanır)
 * Üretim sürecinde: referanslar ve ek notlar; teslim tarihi yalnızca ileri alınabilir
 * Teslimden sonra : düzenleme kapalı
 */
function agency_edit_scope(array $job): string {
    if (in_array($job['status'], ['submitted', 'quote_sent', 'open'], true)) {
        return 'full';
    }
    if (in_array($job['status'], ['assigned', 'in_progress', 'revision', 'qa_review'], true)) {
        return 'limited';
    }
    return 'locked';
}

/**
 * ====================================================================
 * FREELANCER PERFORMANS KARNESİ & SEVİYE
 * ====================================================================
 * Puan (0-100):
 *   %35 Puanlama   — ekip ve ajans puanlarının ortalaması
 *   %25 Zamanında teslim oranı
 *   %20 Kalite kontrolden ilk seferde geçme oranı
 *   %10 Revizyon yükü (az revizyon = yüksek puan)
 *   %10 Güvenilirlik (işi bırakma / atamadan alınma)
 */
function freelancer_active_job_count(int $user_id): int {
    global $db;
    $in = "'" . implode("','", JOB_ACTIVE_STATUSES) . "'";
    $st = $db->prepare("SELECT COUNT(*) FROM platform_jobs WHERE assigned_user_id = ? AND status IN ({$in})");
    $st->execute([$user_id]);
    return (int)$st->fetchColumn();
}

function recompute_freelancer_metrics(int $user_id): array {
    global $db;
    $st = $db->prepare("SELECT * FROM freelancer_profiles WHERE user_id = ?");
    $st->execute([$user_id]);
    $p = $st->fetch();
    if (!$p) {
        return [];
    }

    $jobs = $db->prepare("SELECT id, deadline, first_delivered_at, revision_count, freelancer_rating, agency_rating FROM platform_jobs WHERE assigned_user_id = ? AND assigned_type = 'freelancer' AND status = 'completed'");
    $jobs->execute([$user_id]);
    $rows = $jobs->fetchAll();
    $completed = count($rows);

    $on_time_total = 0; $on_time_ok = 0; $late = 0;
    $rev_sum = 0; $staff_r = []; $agency_r = [];
    foreach ($rows as $r) {
        if ($r['deadline'] && $r['first_delivered_at']) {
            $on_time_total++;
            if (strtotime($r['first_delivered_at']) <= strtotime($r['deadline'] . ' 23:59:59')) {
                $on_time_ok++;
            } else {
                $late++;
            }
        }
        $rev_sum += (int)$r['revision_count'];
        if ($r['freelancer_rating']) $staff_r[] = (int)$r['freelancer_rating'];
        if ($r['agency_rating']) $agency_r[] = (int)$r['agency_rating'];
    }

    // Kalite kontrolden ilk seferde geçme: her işin ilk teslimi reddedilmemiş mi?
    $qa = $db->prepare("
        SELECT d.status FROM platform_deliveries d
        JOIN (SELECT job_id, MIN(id) AS first_id FROM platform_deliveries WHERE submitted_by_type = 'freelancer' AND submitted_by_user_id = ? GROUP BY job_id) f ON f.first_id = d.id
    ");
    $qa->execute([$user_id]);
    $firsts = $qa->fetchAll(PDO::FETCH_COLUMN);
    $qa_pass = $firsts ? count(array_filter($firsts, fn($s) => $s !== 'qa_rejected')) / count($firsts) : null;

    $on_time = $on_time_total ? $on_time_ok / $on_time_total : null;
    $avg_rev = $completed ? $rev_sum / $completed : null;
    $ratings = array_merge($staff_r, $agency_r);
    $rating_c = $ratings ? array_sum($ratings) / count($ratings) / 5 : null;
    $incidents = (int)$p['releases_count'] + (int)$p['removed_count'];
    $reliability = max(0, 1 - ($incidents / max(1, $completed + $incidents)) * 2);

    $score = null;
    if ($completed > 0) {
        $score = 100 * (
            0.35 * ($rating_c ?? 0.8) +
            0.25 * ($on_time ?? 1) +
            0.20 * ($qa_pass ?? 1) +
            0.10 * (1 - min(($avg_rev ?? 0) / 3, 1)) +
            0.10 * $reliability
        );
        $score = round($score, 1);
    }

    $db->prepare("UPDATE freelancer_profiles SET score = ?, on_time_rate = ?, qa_pass_rate = ?, avg_revisions = ?, completed_jobs = ?, late_count = ?,
                  rating_avg = ?, rating_count = ?, agency_rating_avg = ?, metrics_updated_at = NOW() WHERE user_id = ?")
       ->execute([
           $score, $on_time !== null ? round($on_time * 100, 1) : null, $qa_pass !== null ? round($qa_pass * 100, 1) : null,
           $avg_rev !== null ? round($avg_rev, 2) : null, $completed, $late,
           $staff_r ? round(array_sum($staff_r) / count($staff_r), 2) : null, count($staff_r),
           $agency_r ? round(array_sum($agency_r) / count($agency_r), 2) : null, $user_id
       ]);

    // Otomatik seviye (yöneticinin tanımladığı kurallara göre)
    if (platform_setting('platform_auto_tier') === '1' && (int)$p['tier_locked'] !== 1 && $completed > 0) {
        $st->execute([$user_id]);
        $fresh = $st->fetch();
        $suggested = suggested_tier($fresh);
        if (tier_rank($suggested) < tier_rank($p['tier']) && platform_setting('platform_tier_downgrade') !== '1') {
            $suggested = $p['tier'];
        }
        if ($suggested !== $p['tier']) {
            $db->prepare("UPDATE freelancer_profiles SET tier = ? WHERE user_id = ?")->execute([$suggested, $user_id]);
            $up = tier_rank($suggested) > tier_rank($p['tier']);
            notify_user($user_id, ($up ? 'Seviyeniz yükseldi: ' : 'Seviyeniz güncellendi: ') . tier_label($suggested) . '. Aynı anda alabileceğiniz iş sayısı: ' . tier_job_limit($suggested), '/platform/performance.php');
        }
    }

    $st->execute([$user_id]);
    return $st->fetch() ?: [];
}

/**
 * ====================================================================
 * SEVİYE KURALLARI (YÖNETİCİ TANIMLI OTOMASYON)
 * ====================================================================
 * Her seviye için koşullar; boş bırakılan koşul aranmaz, dolu olanların
 * tamamı sağlanmalıdır. En yüksek şartı sağlanan seviye önerilir.
 * Kurallar system_settings.platform_tier_rules (JSON) içinde saklanır.
 */
const TIER_CONDITIONS = [
    'min_jobs'          => ['Tamamlanan iş', 'en az', 'iş', 0, 1000, 1],
    'min_rating'        => ['Ortalama yıldız (ekip + müşteri)', 'en az', '★', 1, 5, 0.1],
    'min_agency_rating' => ['Müşteri (ajans) yıldızı', 'en az', '★', 1, 5, 0.1],
    'min_score'         => ['Performans puanı', 'en az', 'puan', 0, 100, 1],
    'min_on_time'       => ['Zamanında teslim oranı', 'en az', '%', 0, 100, 1],
    'min_qa'            => ['Kalite kontrolden ilk seferde geçme', 'en az', '%', 0, 100, 1],
    'max_incidents'     => ['Bırakılan / geri alınan iş', 'en fazla', 'iş', 0, 100, 1],
    'max_late'          => ['Geciken teslim', 'en fazla', 'iş', 0, 100, 1],
    'min_days'          => ['Platformdaki süre', 'en az', 'gün', 0, 3650, 1],
];

function tier_rules_default(): array {
    return [
        'silver' => ['enabled' => 1, 'min_jobs' => 3, 'min_score' => 65],
        'gold'   => ['enabled' => 1, 'min_jobs' => 8, 'min_score' => 78],
        'elite'  => ['enabled' => 1, 'min_jobs' => 15, 'min_score' => 90],
    ];
}

function tier_rules(): array {
    $raw = get_setting('platform_tier_rules', '');
    $rules = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($rules) ? $rules + tier_rules_default() : tier_rules_default();
}

/**
 * Freelancer'ın bir koşuldaki güncel değeri (veri yoksa null)
 */
function tier_metric(array $p, string $cond): ?float {
    switch ($cond) {
        case 'min_jobs':          return (float)($p['completed_jobs'] ?? 0);
        case 'min_rating':
            $r = array_values(array_filter([$p['rating_avg'] ?? null, $p['agency_rating_avg'] ?? null], fn($x) => $x !== null));
            return $r ? array_sum(array_map('floatval', $r)) / count($r) : null;
        case 'min_agency_rating': return isset($p['agency_rating_avg']) && $p['agency_rating_avg'] !== null ? (float)$p['agency_rating_avg'] : null;
        case 'min_score':         return isset($p['score']) && $p['score'] !== null ? (float)$p['score'] : null;
        case 'min_on_time':       return isset($p['on_time_rate']) && $p['on_time_rate'] !== null ? (float)$p['on_time_rate'] : null;
        case 'min_qa':            return isset($p['qa_pass_rate']) && $p['qa_pass_rate'] !== null ? (float)$p['qa_pass_rate'] : null;
        case 'max_incidents':     return (float)((int)($p['releases_count'] ?? 0) + (int)($p['removed_count'] ?? 0));
        case 'max_late':          return (float)($p['late_count'] ?? 0);
        case 'min_days':          return !empty($p['created_at']) ? floor((time() - strtotime($p['created_at'])) / 86400) : 0.0;
    }
    return null;
}

/**
 * Bir seviyenin koşullarını değerlendirir
 * Dönüş: ['ok' => bool, 'checks' => [[key, label, current, need, pass, op, unit], ...]]
 */
function tier_check(array $profile, string $tier, ?array $rules = null): array {
    $rules = $rules ?? tier_rules();
    $rule = $rules[$tier] ?? [];
    $checks = [];
    $ok = !empty($rule['enabled']);
    foreach (TIER_CONDITIONS as $key => [$label, $op_label, $unit]) {
        if (!isset($rule[$key]) || $rule[$key] === '' || $rule[$key] === null) continue;
        $need = (float)$rule[$key];
        $cur = tier_metric($profile, $key);
        $pass = $cur !== null && (str_starts_with($key, 'max_') ? $cur <= $need : $cur >= $need);
        $ok = $ok && $pass;
        $checks[] = ['key' => $key, 'label' => $label, 'current' => $cur, 'need' => $need, 'pass' => $pass, 'op' => $op_label, 'unit' => $unit];
    }
    return ['ok' => $ok && (bool)$checks, 'checks' => $checks];
}

/**
 * Kurallara göre önerilen seviye ($profile: freelancer_profiles satırı)
 */
function suggested_tier(array $profile, ?array $rules = null): string {
    $result = 'standard';
    foreach (array_keys(FREELANCER_TIERS) as $tier) {
        if ($tier === 'standard') continue;
        if (tier_check($profile, $tier, $rules)['ok']) {
            $result = $tier;
        }
    }
    return $result;
}

/**
 * Koşulu okunur metne çevirir (ör. "en az 4★ ortalama yıldız")
 */
function tier_condition_text(string $key, $need): string {
    [$label, $op, $unit] = TIER_CONDITIONS[$key];
    $n = rtrim(rtrim(number_format((float)$need, 1, ',', ''), '0'), ',');
    return "{$label}: {$op} {$n}" . ($unit === '%' ? '%' : ($unit === '★' ? '★' : " {$unit}"));
}

function tier_rule_summary(string $tier): string {
    $rule = tier_rules()[$tier] ?? null;
    if (!$rule || empty($rule['enabled'])) return $tier === 'standard' ? 'başlangıç seviyesi' : 'otomatik verilmiyor';
    $parts = [];
    foreach (TIER_CONDITIONS as $key => $_) {
        if (isset($rule[$key]) && $rule[$key] !== '' && $rule[$key] !== null) {
            $parts[] = tier_condition_text($key, $rule[$key]);
        }
    }
    return $parts ? implode(' · ', $parts) : 'koşul tanımlı değil';
}

/**
 * Bir sonraki (otomatik verilen) seviye ve koşulların durumu
 */
function next_tier_progress(array $profile): ?array {
    $rank = tier_rank($profile['tier']);
    foreach (array_keys(FREELANCER_TIERS) as $tier) {
        if (tier_rank($tier) <= $rank) continue;
        $rule = tier_rules()[$tier] ?? [];
        if (empty($rule['enabled'])) continue;
        $res = tier_check($profile, $tier);
        return ['tier' => $tier, 'checks' => $res['checks'], 'ok' => $res['ok']];
    }
    return null;
}

/**
 * Kontrol satırının ilerleme yüzdesi (gösterim için)
 */
function tier_check_progress(array $c): float {
    if ($c['current'] === null) return 0;
    if (str_starts_with($c['key'], 'max_')) return $c['pass'] ? 100 : max(0, 100 - ($c['current'] - $c['need']) * 25);
    return $c['need'] > 0 ? min(100, $c['current'] / $c['need'] * 100) : 100;
}

function tier_value_text(array $c): string {
    if ($c['current'] === null) return 'veri yok';
    $v = in_array($c['unit'], ['★'], true) ? number_format($c['current'], 1, ',', '') : number_format($c['current'], 0, ',', '.');
    return $v . ($c['unit'] === '%' ? '%' : ($c['unit'] === '★' ? '★' : ''));
}

/**
 * ====================================================================
 * GÖRÜNÜRLÜK KURALLARI (PAZARLAMA POLİTİKASI)
 * ====================================================================
 */
function job_visibility_reason(array $job, array $profile): ?string {
    global $db;
    if (platform_setting('platform_pool_enabled') !== '1') {
        return 'İş havuzu kapalı';
    }
    if (($profile['status'] ?? '') !== 'approved') {
        return 'Profil onaylı değil';
    }
    if ($job['status'] !== 'open') {
        return 'İş havuzda değil';
    }
    if ($job['visibility'] === 'internal') {
        return 'Ekibe özel';
    }
    if ($job['visibility'] === 'selected') {
        $st = $db->prepare("SELECT COUNT(*) FROM platform_job_visible_to WHERE job_id = ? AND user_id = ?");
        $st->execute([$job['id'], $profile['user_id']]);
        return (int)$st->fetchColumn() > 0 ? null : 'Seçili listede değil';
    }
    $rank = tier_rank($profile['tier']);
    if ($rank < tier_rank($job['min_tier'])) {
        return 'Seviye yetersiz (' . tier_label($job['min_tier']) . '+)';
    }
    if (!empty($job['priority_tier']) && (int)$job['priority_hours'] > 0 && !empty($job['published_at'])) {
        $open_to_all_at = strtotime($job['published_at']) + (int)$job['priority_hours'] * 3600;
        if (time() < $open_to_all_at && $rank < tier_rank($job['priority_tier'])) {
            return 'Öncelik süresinde (' . tier_label($job['priority_tier']) . '+ görüyor)';
        }
    }
    if ((int)$job['skill_match_only'] === 1) {
        $skills = array_filter(explode(',', (string)$profile['skills']));
        if (!in_array($job['category'], $skills, true)) {
            return 'Uzmanlık eşleşmiyor';
        }
    }
    if ((int)$job['city_match_only'] === 1 && (int)$job['is_remote'] !== 1 && !empty($job['location_city'])) {
        if ((normalize_city($profile['city'] ?? '') ?: tr_lower((string)$profile['city'])) !== (normalize_city($job['location_city']) ?: tr_lower($job['location_city']))) {
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

function visible_pool_jobs(array $profile): array {
    global $db;
    $jobs = $db->query("SELECT * FROM platform_jobs WHERE status = 'open' AND visibility != 'internal' ORDER BY is_rush DESC, published_at DESC, id DESC LIMIT 300")->fetchAll();
    return array_values(array_filter($jobs, fn($j) => job_visibility_reason($j, $profile) === null));
}

/**
 * Freelancer'ın yeni iş alıp alamayacağı (limit, müsaitlik)
 */
function freelancer_capacity(array $profile): array {
    $active = freelancer_active_job_count((int)$profile['user_id']);
    $limit  = tier_job_limit($profile['tier']);
    return ['active' => $active, 'limit' => $limit, 'can_take' => $active < $limit && (int)$profile['is_available'] === 1];
}

/**
 * Yeni iş için varsayılan dağıtım politikası
 */
function default_job_policy(array $items, string $category, bool $is_remote): array {
    // Ayarlardaki en düşük seviye; hizmet daha yüksek seviye istiyorsa o geçerli
    $min = array_key_exists(platform_setting('platform_default_min_tier'), FREELANCER_TIERS) ? platform_setting('platform_default_min_tier') : 'standard';
    foreach ($items as $it) {
        if (tier_rank($it['min_tier'] ?? 'standard') > tier_rank($min)) {
            $min = $it['min_tier'];
        }
    }
    $ptier = platform_setting('platform_default_priority_tier');
    $vis = platform_setting('platform_default_visibility');
    $dis = platform_setting('platform_default_dispatch');
    return [
        'visibility'       => in_array($vis, ['pool', 'internal'], true) ? $vis : 'pool',
        'dispatch_mode'    => array_key_exists($dis, JOB_DISPATCH) ? $dis : 'first_come',
        'min_tier'         => $min,
        'priority_tier'    => array_key_exists($ptier, FREELANCER_TIERS) ? $ptier : null,
        'priority_hours'   => array_key_exists($ptier, FREELANCER_TIERS) ? (int)platform_setting('platform_default_priority_hours') : 0,
        'skill_match_only' => platform_setting('platform_default_skill_match') === '1' ? 1 : 0,
        'city_match_only'  => (platform_setting('platform_default_city_match') === '1' && !$is_remote && (JOB_CATEGORIES[$category]['onsite'] ?? false)) ? 1 : 0,
    ];
}

/**
 * ====================================================================
 * DURUM GEÇİŞLERİ
 * ====================================================================
 */
function job_publish(int $job_id): void {
    global $db;
    $before = get_job($job_id);
    $db->prepare("UPDATE platform_jobs SET status = 'open', published_at = COALESCE(published_at, NOW()) WHERE id = ?")->execute([$job_id]);
    $job = get_job($job_id);
    if (!$job) {
        return;
    }
    milestones_sync($job_id);
    if ($before && $before['status'] !== 'open') {
        job_event($job_id, 'published', 'Yayına alındı', ['new' => JOB_VISIBILITY[$job['visibility']] ?? $job['visibility'], 'visibility' => 'all']);
    }
    notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işiniz onaylandı; ekip ataması yapılıyor.", "/platform/job.php?id={$job_id}", $job_id);
    if ($job['visibility'] === 'selected') {
        $st = $db->prepare("SELECT user_id FROM platform_job_visible_to WHERE job_id = ?");
        $st->execute([$job_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            notify_user((int)$uid, "Size özel iş: {$job['job_code']} · {$job['title']}", "/platform/job.php?id={$job_id}", $job_id);
        }
    }
}

/**
 * Freelancer'ı işe atar. $via: 'self' (ilk alan alır) | 'offer' (teklif kabulü) | 'staff' (doğrudan atama)
 */
function job_assign_freelancer(int $job_id, int $user_id, bool $atomic = false, ?float $fee = null, string $via = 'staff'): bool {
    global $db;
    $sql = "UPDATE platform_jobs SET status = 'assigned', assigned_type = 'freelancer', assigned_user_id = ?, assigned_at = NOW(), assigned_via = ?"
         . ($fee !== null ? ", freelancer_fee = ?" : "")
         . " WHERE id = ?" . ($atomic ? " AND status = 'open' AND assigned_user_id IS NULL" : "");
    $params = $fee !== null ? [$user_id, $via, $fee, $job_id] : [$user_id, $via, $job_id];
    $st = $db->prepare($sql);
    $st->execute($params);
    if ($st->rowCount() === 0) {
        return false;
    }
    $rejected = $db->prepare("SELECT a.user_id, u.full_name FROM platform_applications a JOIN users u ON u.id = a.user_id WHERE a.job_id = ? AND a.status = 'pending' AND a.user_id != ?");
    $rejected->execute([$job_id, $user_id]);
    $db->prepare("UPDATE platform_applications SET status = IF(user_id = ?, 'accepted', 'rejected'), reviewed_at = NOW(), reject_reason = IF(user_id = ?, reject_reason, COALESCE(reject_reason, 'İş başka bir freelancer\'a atandı.')) WHERE job_id = ? AND status = 'pending'")
       ->execute([$user_id, $user_id, $job_id]);
    milestones_sync($job_id);
    $job = get_job($job_id);
    $via_txt = ['self' => 'İlk alan aldı', 'offer' => 'Teklif kabul edildi', 'staff' => 'Ekip tarafından atandı'][$via] ?? $via;
    job_event($job_id, 'assigned', 'Freelancer atandı: ' . $job['assignee_name'], ['new' => $via_txt, 'amount' => (float)$job['freelancer_fee'], 'visibility' => 'freelancer']);
    job_event($job_id, 'assigned', 'Prodüksiyon ekibi atandı', ['visibility' => 'agency']);
    foreach ($rejected->fetchAll() as $r) {
        job_event($job_id, 'offer_rejected', 'Teklif kapandı: ' . $r['full_name'], ['new' => 'İş başka bir freelancer\'a atandı.', 'visibility' => 'staff']);
    }
    notify_user($user_id, "İş size atandı: {$job['job_code']} · {$job['title']}" . ($via !== 'self' ? '. Kabul edip başlayabilir veya reddedebilirsiniz.' : ''), "/platform/job.php?id={$job_id}", $job_id);
    notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işinize ekip atandı.", "/platform/job.php?id={$job_id}", $job_id);
    return true;
}

function job_take_internal(int $job_id, int $staff_user_id): ?int {
    global $db;
    $job = get_job($job_id);
    if (!$job || !in_array($job['status'], ['open', 'submitted'], true)) {
        return null;
    }
    $project_id = null;
    if (!empty($job['agency_contact_id'])) {
        $code = generate_project_code();
        $type_map = ['social' => 'social_media', 'full_production' => 'commercial'];
        $items_txt = implode("\n", array_map(fn($i) => '- ' . $i['name'] . ' × ' . rtrim(rtrim(number_format((float)$i['quantity'], 2, ',', ''), '0'), ','), job_items($job_id)));
        $db->prepare("
            INSERT INTO projects (client_id, project_code, project_name, project_type, status, agreed_budget, currency, start_date, deadline, description, created_by, created_at)
            VALUES (?, ?, ?, ?, 'pre_production', ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $job['agency_contact_id'], $code, $job['title'], $type_map[$job['category']] ?? 'other',
            (float)($job['agency_price'] ?? $job['budget'] ?? 0), $job['currency'], $job['start_date'], $job['deadline'],
            "Platform işi {$job['job_code']}\n\n" . ($job['description'] ?? '') . ($items_txt ? "\n\nHizmetler:\n{$items_txt}" : '') . "\n\nTeslimatlar:\n" . ($job['deliverables'] ?? ''),
            $staff_user_id
        ]);
        $project_id = (int)$db->lastInsertId();
    }
    $db->prepare("UPDATE platform_jobs SET status = 'in_progress', assigned_type = 'internal', assigned_user_id = NULL, assigned_via = 'internal', internal_project_id = ?, assigned_at = NOW(), published_at = COALESCE(published_at, NOW()) WHERE id = ?")
       ->execute([$project_id, $job_id]);
    $db->prepare("UPDATE platform_applications SET status = 'rejected', reviewed_at = NOW(), reject_reason = COALESCE(reject_reason, 'İş ekibimiz tarafından üstlenildi.') WHERE job_id = ? AND status = 'pending'")->execute([$job_id]);
    milestones_sync($job_id);
    job_event($job_id, 'internal', 'İş ekibimize alındı', ['new' => $project_id ? 'ERP projesi açıldı' : null, 'visibility' => 'staff']);
    job_event($job_id, 'started', 'Üretime alındı', ['visibility' => 'agency']);
    notify_contact_users($job['agency_contact_id'], "{$job['job_code']} işiniz üretime alındı.", "/platform/job.php?id={$job_id}", $job_id);
    return $project_id ?: 0;
}

/**
 * Atamayı kaldırır.
 * $by: 'freelancer' (işi bıraktı, güvenilirliğe işlenir) | 'staff' (ekip aldı, işlenir)
 *      | 'neutral' (freelancer kaynaklı değil) | 'declined' (atama teklifi reddedildi, işlenmez)
 */
function job_unassign(int $job_id, string $by = 'staff', string $reason = ''): void {
    global $db;
    $job = get_job($job_id);
    if (!$job) {
        return;
    }
    $db->prepare("UPDATE platform_jobs SET status = 'open', assigned_type = NULL, assigned_user_id = NULL, assigned_at = NULL, assigned_via = NULL WHERE id = ?")->execute([$job_id]);
    // Bırakan kişiye özel kabul edilmiş ücret geri alınır; katalog işinde ücret kalemlerden yeniden hesaplanır
    if ($job['pricing_source'] === 'catalog') {
        $items = array_map(fn($i) => ['quantity' => (float)$i['quantity'], 'agency_unit_price' => (float)$i['agency_unit_price'], 'freelancer_unit_fee' => (float)$i['freelancer_unit_fee']],
                           array_filter(job_items($job_id), fn($i) => ($i['status'] ?? 'active') === 'active' && (int)($i['is_extra'] ?? 0) === 0));
        if ($items) {
            $extra = (float)$db->query("SELECT COALESCE(SUM(fee), 0) FROM platform_milestones WHERE job_id = {$job_id} AND is_extra = 1 AND status IN ('" . implode("','", MILESTONE_LIVE) . "', 'pending_freelancer')")->fetchColumn();
            $db->prepare("UPDATE platform_jobs SET freelancer_fee = ? WHERE id = ?")->execute([price_order(array_values($items), (int)$job['is_rush'] === 1)['freelancer_fee'] + $extra, $job_id]);
        }
    }
    // Freelancer kabulü bekleyen ek kalemler yeni atamada tekrar sorulur
    $db->prepare("UPDATE platform_milestones SET status = 'open' WHERE job_id = ? AND status = 'pending_freelancer'")->execute([$job_id]);
    milestones_sync($job_id);
    // Yalnızca "başkasına atandı" gerekçesiyle kapanan teklifler yeniden teklif verebilsin
    $reopen = $db->prepare("SELECT user_id FROM platform_applications WHERE job_id = ? AND status = 'rejected' AND reject_reason = 'İş başka bir freelancer\'a atandı.'");
    $reopen->execute([$job_id]);
    foreach ($reopen->fetchAll(PDO::FETCH_COLUMN) as $ru) {
        notify_user((int)$ru, "{$job['job_code']} yeniden teklife açıldı.", "/platform/job.php?id={$job_id}", $job_id);
    }
    $db->prepare("UPDATE platform_applications SET status = 'withdrawn', reject_reason = NULL WHERE job_id = ? AND status = 'rejected' AND reject_reason = 'İş başka bir freelancer\'a atandı.'")->execute([$job_id]);
    if (!empty($job['assigned_user_id'])) {
        $db->prepare("UPDATE platform_applications SET status = 'withdrawn' WHERE job_id = ? AND user_id = ?")->execute([$job_id, $job['assigned_user_id']]);
        if (!in_array($by, ['neutral', 'declined'], true)) {
            $col = $by === 'freelancer' ? 'releases_count' : 'removed_count';
            $db->prepare("UPDATE freelancer_profiles SET {$col} = {$col} + 1 WHERE user_id = ?")->execute([$job['assigned_user_id']]);
        }
        $types = ['freelancer' => ['released', 'İşi bıraktı: '], 'declined' => ['award_declined', 'Atamayı reddetti: '], 'staff' => ['unassigned', 'Atama kaldırıldı: '], 'neutral' => ['unassigned', 'Atama kaldırıldı: ']];
        [$t, $l] = $types[$by] ?? $types['staff'];
        job_event($job_id, $t, $l . $job['assignee_name'], ['new' => $reason !== '' ? $reason : null, 'visibility' => 'freelancer']);
        job_event($job_id, 'unassigned', 'Ekip ataması yenileniyor', ['visibility' => 'agency']);
    }
}

/**
 * Teslim: sıradaki (veya seçilen) aşama için. Kalite kontrol açıksa önce ekibe düşer.
 */
function job_submit_delivery(array $job, string $url, string $note, string $by_type, ?int $user_id, ?int $milestone_id = null): bool {
    global $db;
    $m = $milestone_id ? get_milestone($milestone_id) : milestone_next((int)$job['id'], true);
    if ($m && ((int)$m['job_id'] !== (int)$job['id'] || !in_array($m['status'], ['open', 'revision'], true) || (int)$m['needs_delivery'] !== 1)) {
        $m = milestone_next((int)$job['id'], true);
    }
    // Aşamalı işte teslim edilecek (bağlantılı) aşama kalmadıysa teslim alınmaz; yerinde işler "yapıldı" ile kapanır
    if (!$m && job_milestones((int)$job['id'])) {
        return false;
    }
    $qa = $by_type === 'freelancer' && (platform_setting('platform_qa_required') === '1' || ($m && milestone_internal($m)));
    $db->prepare("INSERT INTO platform_deliveries (job_id, milestone_id, submitted_by_user_id, submitted_by_type, url, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$job['id'], $m['id'] ?? null, $user_id, $by_type, $url, $note, $qa ? 'qa' : 'sent']);
    if ($m) {
        $db->prepare("UPDATE platform_milestones SET status = 'in_review' WHERE id = ?")->execute([$m['id']]);
    }
    $db->prepare("UPDATE platform_jobs SET status = ?, delivered_at = ?, first_delivered_at = COALESCE(first_delivered_at, NOW()) WHERE id = ?")
       ->execute([$qa ? 'qa_review' : 'delivered', $qa ? null : date('Y-m-d H:i:s'), $job['id']]);
    $title = $m ? $m['title'] : 'Teslim';
    job_event((int)$job['id'], 'delivered', 'Teslim gönderildi: ' . $title, ['new' => $note !== '' ? $note : $url, 'milestone_id' => $m['id'] ?? null, 'visibility' => $qa ? 'freelancer' : 'all']);
    if (!$qa) {
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} · \"{$title}\" teslim edildi. İnceleyip onaylayabilirsiniz.", "/platform/job.php?id={$job['id']}", (int)$job['id']);
    }
    return true;
}

function job_qa_decision(array $job, int $delivery_id, bool $approve, string $feedback = ''): void {
    global $db;
    $d = $db->prepare("SELECT * FROM platform_deliveries WHERE id = ? AND job_id = ?");
    $d->execute([$delivery_id, $job['id']]);
    $d = $d->fetch();
    $m = $d && $d['milestone_id'] ? get_milestone((int)$d['milestone_id']) : null;
    $title = $m['title'] ?? 'Teslim';
    if ($approve && $m && (milestone_internal($m) || (int)$m['needs_delivery'] === 0)) {
        // Ajansa yansımayan iç ek iş veya yerinde iş: ekip onayı yeterli
        $db->prepare("UPDATE platform_deliveries SET status = 'sent', reviewed_at = NOW(), feedback = ? WHERE id = ? AND job_id = ?")->execute([$feedback ?: null, $delivery_id, $job['id']]);
        job_event((int)$job['id'], 'qa_approved', 'Kalite kontrolden geçti: ' . $title, ['new' => $feedback !== '' ? $feedback : null, 'milestone_id' => (int)$m['id'], 'visibility' => 'freelancer']);
        milestone_approve($job, $m, 'staff');
        return;
    }
    if ($approve) {
        $db->prepare("UPDATE platform_deliveries SET status = 'sent', reviewed_at = NOW(), feedback = ? WHERE id = ? AND job_id = ?")->execute([$feedback ?: null, $delivery_id, $job['id']]);
        $db->prepare("UPDATE platform_jobs SET status = 'delivered', delivered_at = NOW() WHERE id = ?")->execute([$job['id']]);
        job_event((int)$job['id'], 'qa_approved', 'Kalite kontrolden geçti: ' . $title, ['new' => $feedback !== '' ? $feedback : null, 'milestone_id' => $m['id'] ?? null, 'visibility' => 'freelancer']);
        job_event((int)$job['id'], 'delivered', 'Teslim edildi: ' . $title, ['milestone_id' => $m['id'] ?? null, 'visibility' => 'agency']);
        notify_contact_users($job['agency_contact_id'], "{$job['job_code']} · \"{$title}\" teslim edildi. İnceleyip onaylayabilirsiniz.", "/platform/job.php?id={$job['id']}", (int)$job['id']);
        if (!empty($job['assigned_user_id'])) {
            notify_user((int)$job['assigned_user_id'], "Teslimatınız kalite kontrolden geçti ve müşteriye iletildi: {$job['job_code']} · {$title}", "/platform/job.php?id={$job['id']}", (int)$job['id']);
        }
    } else {
        $db->prepare("UPDATE platform_deliveries SET status = 'qa_rejected', reviewed_at = NOW(), feedback = ? WHERE id = ? AND job_id = ?")->execute([$feedback, $delivery_id, $job['id']]);
        if ($m) $db->prepare("UPDATE platform_milestones SET status = 'revision' WHERE id = ?")->execute([$m['id']]);
        $db->prepare("UPDATE platform_jobs SET status = 'revision' WHERE id = ?")->execute([$job['id']]);
        job_event((int)$job['id'], 'qa_rejected', 'Kalite kontrol düzeltme istedi: ' . $title, ['new' => $feedback, 'milestone_id' => $m['id'] ?? null, 'visibility' => 'freelancer']);
        if (!empty($job['assigned_user_id'])) {
            notify_user((int)$job['assigned_user_id'], "Kalite kontrol düzeltme istedi ({$job['job_code']} · {$title}): " . mb_substr($feedback, 0, 140), "/platform/job.php?id={$job['id']}", (int)$job['id']);
        }
    }
}

function job_request_revision(array $job, string $feedback): void {
    global $db;
    $last = job_deliveries((int)$job['id'], ['sent']);
    $m = $last && $last[0]['milestone_id'] ? get_milestone((int)$last[0]['milestone_id']) : null;
    if ($last) {
        $db->prepare("UPDATE platform_deliveries SET status = 'revision', feedback = ?, reviewed_at = NOW() WHERE id = ?")->execute([$feedback, $last[0]['id']]);
    }
    if ($m) $db->prepare("UPDATE platform_milestones SET status = 'revision' WHERE id = ?")->execute([$m['id']]);
    $db->prepare("UPDATE platform_jobs SET status = 'revision', revision_count = revision_count + 1, delivered_at = NULL WHERE id = ?")->execute([$job['id']]);
    $n = (int)$job['revision_count'] + 1;
    job_event((int)$job['id'], 'revision', "Revizyon {$n}: " . ($m['title'] ?? 'Teslim'), ['new' => $feedback, 'milestone_id' => $m['id'] ?? null, 'visibility' => 'all']);
    if (!empty($job['assigned_user_id'])) {
        notify_user((int)$job['assigned_user_id'], "Revizyon istendi ({$job['job_code']}" . ($m ? ' · ' . $m['title'] : '') . "): " . mb_substr($feedback, 0, 140), "/platform/job.php?id={$job['id']}", (int)$job['id']);
    }
}

/**
 * İşi kapatır: açık aşamalar onaylanır (hakediş kaydı), bekleyen ek kalem
 * önerileri iptal edilir, ajansa satış faturası kesilir.
 */
function job_complete(array $job, ?int $agency_rating = null, string $agency_review = ''): void {
    global $db;
    $job_id = (int)$job['id'];

    if (!job_milestones($job_id)) {
        milestones_create_default($job_id);
    }
    // Bekleyen öneriler kapsama girmeden iptal
    foreach (job_milestones($job_id) as $m) {
        if (in_array($m['status'], ['proposed', 'pending_freelancer'], true)) {
            $db->prepare("UPDATE platform_milestones SET status = 'cancelled' WHERE id = ?")->execute([$m['id']]);
            $db->prepare("UPDATE platform_job_items SET status = 'cancelled' WHERE milestone_id = ?")->execute([$m['id']]);
            if ($m['status'] === 'pending_freelancer') {
                $db->prepare("UPDATE platform_jobs SET agency_price = GREATEST(0, agency_price - ?), freelancer_fee = GREATEST(0, freelancer_fee - ?) WHERE id = ?")->execute([(float)$m['agency_amount'], (float)$m['fee'], $job_id]);
            }
            job_event($job_id, 'extra', 'Ek kalem iş kapanırken iptal edildi: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'visibility' => milestone_internal($m) ? 'freelancer' : 'all', 'actor' => 'system', 'user_id' => null]);
        }
    }
    $job = get_job($job_id);
    $fl = $job['assigned_type'] === 'freelancer' && !empty($job['assigned_user_id']) ? (int)$job['assigned_user_id'] : null;
    foreach (job_milestones($job_id) as $m) {
        if (in_array($m['status'], ['open', 'in_review', 'revision'], true)) {
            $db->prepare("UPDATE platform_milestones SET status = 'approved', approved_at = NOW(), freelancer_user_id = ? WHERE id = ?")->execute([$fl, $m['id']]);
            job_event($job_id, 'milestone_approved', 'Aşama kapanışla onaylandı: ' . $m['title'], ['milestone_id' => (int)$m['id'], 'amount' => (float)$m['fee'], 'visibility' => milestone_internal($m) ? 'freelancer' : 'all']);
        }
        if (($m['status'] === 'approved' || in_array($m['status'], ['open', 'in_review', 'revision'], true)) && empty($m['purchase_invoice_id'])) {
            milestone_invoice($job, get_milestone((int)$m['id']));
        }
    }

    $db->prepare("UPDATE platform_deliveries SET status = 'approved', reviewed_at = NOW() WHERE job_id = ? AND status = 'sent'")->execute([$job_id]);
    $db->prepare("UPDATE platform_jobs SET status = 'completed', completed_at = NOW(), agency_rating = COALESCE(?, agency_rating), agency_review = COALESCE(?, agency_review) WHERE id = ?")
       ->execute([$agency_rating, $agency_review !== '' ? $agency_review : null, $job_id]);
    job_event($job_id, 'completed', 'İş tamamlandı', ['new' => $agency_rating ? "Ajans puanı {$agency_rating}/5" . ($agency_review !== '' ? " · {$agency_review}" : '') : null, 'visibility' => 'all']);

    $job = get_job($job_id);
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
        job_event($job_id, 'invoice', "Satış faturası: {$no}", ['amount' => (float)$tax['grand_total'], 'visibility' => 'agency', 'actor' => 'system', 'user_id' => null]);
    }

    if ($fl) {
        notify_user($fl, "{$job['job_code']} tamamlandı. Toplam hakedişiniz: " . format_money((float)$job['freelancer_fee']) . '.', '/platform/earnings.php', $job_id);
        recompute_freelancer_metrics($fl);
    }
}

function rate_freelancer(array $job, int $rating): void {
    global $db;
    $rating = max(1, min(5, $rating));
    $db->prepare("UPDATE platform_jobs SET freelancer_rating = ? WHERE id = ?")->execute([$rating, $job['id']]);
    job_event((int)$job['id'], 'rating', "Ekip değerlendirmesi: {$rating}/5", ['visibility' => 'freelancer']);
    if (!empty($job['assigned_user_id'])) {
        recompute_freelancer_metrics((int)$job['assigned_user_id']);
    }
}

/**
 * ====================================================================
 * KALICI SİLME (platform.delete)
 * ====================================================================
 */
function platform_delete_job(int $job_id, bool $with_invoices = false): bool {
    global $db;
    $job = get_job($job_id);
    if (!$job) {
        return false;
    }
    if ($with_invoices) {
        $ids = array_filter([(int)$job['sales_invoice_id'], (int)$job['purchase_invoice_id']]);
        $mi = $db->prepare("SELECT purchase_invoice_id FROM platform_milestones WHERE job_id = ? AND purchase_invoice_id IS NOT NULL");
        $mi->execute([$job_id]);
        foreach (array_unique([...$ids, ...array_map('intval', $mi->fetchAll(PDO::FETCH_COLUMN))]) as $iid) {
            delete_invoice_cascade($iid);
        }
    }
    foreach (['platform_job_items', 'platform_applications', 'platform_messages', 'platform_deliveries', 'platform_job_visible_to', 'platform_job_changes', 'platform_milestones', 'platform_job_issues'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE job_id = ?")->execute([$job_id]);
    }
    $db->prepare("DELETE FROM activity_log WHERE entity_type = 'job' AND entity_id = ?")->execute([$job_id]);
    $db->prepare("DELETE FROM platform_jobs WHERE id = ?")->execute([$job_id]);
    if ($job['assigned_type'] === 'freelancer' && !empty($job['assigned_user_id'])) {
        recompute_freelancer_metrics((int)$job['assigned_user_id']);
    }
    return true;
}

/**
 * Ajans / freelancer hesabını siler.
 * - Freelancer: aktif işleri havuza döner, başvuruları silinir.
 * - Ajans: işleri varsa $cascade_jobs olmadan silinmez.
 * Faturası/cari hareketi olan cari kart muhasebe geçmişi için korunur.
 */
function platform_delete_account(int $user_id, bool $cascade_jobs = false): array {
    global $db;
    $u = $db->prepare("SELECT u.*, r.role_slug FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    if (!$user || !in_array($user['role_slug'], ['agency', 'freelancer'], true)) {
        return [false, 'Hesap bulunamadı.'];
    }
    $contact_id = (int)$user['contact_id'];

    if ($user['role_slug'] === 'agency') {
        $jobs = $db->prepare("SELECT id FROM platform_jobs WHERE agency_contact_id = ?");
        $jobs->execute([$contact_id]);
        $ids = $jobs->fetchAll(PDO::FETCH_COLUMN);
        if ($ids && !$cascade_jobs) {
            return [false, 'Bu ajansın ' . count($ids) . ' işi var. Önce işleri silin veya "işleriyle birlikte sil" seçeneğini kullanın.'];
        }
        foreach ($ids as $jid) {
            platform_delete_job((int)$jid, false);
        }
        $db->prepare("DELETE FROM agency_profiles WHERE user_id = ?")->execute([$user_id]);
    } else {
        $active = $db->prepare("SELECT id FROM platform_jobs WHERE assigned_user_id = ? AND status IN ('" . implode("','", JOB_ACTIVE_STATUSES) . "')");
        $active->execute([$user_id]);
        foreach ($active->fetchAll(PDO::FETCH_COLUMN) as $jid) {
            $db->prepare("UPDATE platform_jobs SET status = 'open', assigned_type = NULL, assigned_user_id = NULL, assigned_at = NULL WHERE id = ?")->execute([$jid]);
            notify_staff('Freelancer hesabı silindiği için iş havuza döndü.', (int)$jid);
        }
        $db->prepare("UPDATE platform_jobs SET assigned_user_id = NULL WHERE assigned_user_id = ?")->execute([$user_id]);
        $db->prepare("DELETE FROM platform_applications WHERE user_id = ?")->execute([$user_id]);
        $db->prepare("DELETE FROM platform_job_visible_to WHERE user_id = ?")->execute([$user_id]);
        $db->prepare("DELETE FROM freelancer_profiles WHERE user_id = ?")->execute([$user_id]);
    }

    $db->prepare("DELETE FROM user_notification_state WHERE user_id = ?")->execute([$user_id]);
    $db->prepare("DELETE FROM activity_log WHERE target_user_id = ?")->execute([$user_id]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);

    $has_ledger = (int)$db->query("SELECT (SELECT COUNT(*) FROM invoices WHERE contact_id = {$contact_id}) + (SELECT COUNT(*) FROM transactions WHERE contact_id = {$contact_id})")->fetchColumn();
    if ($contact_id && !$has_ledger) {
        $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$contact_id]);
        return [true, 'Hesap ve cari kartı silindi.'];
    }
    return [true, 'Hesap silindi. Muhasebe kaydı olduğu için cari kartı korundu.'];
}

/**
 * ====================================================================
 * YARDIMCILAR
 * ====================================================================
 */
function render_stars(?float $rating): string {
    if ($rating === null) {
        return '<span class="text-muted">—</span>';
    }
    $full = (int)round($rating);
    return '<span class="stars" title="' . number_format($rating, 1, ',', '') . ' / 5">' . str_repeat('★', $full) . '<span class="stars-off">' . str_repeat('★', 5 - $full) . '</span></span> <span class="num text-muted">' . number_format($rating, 1, ',', '') . '</span>';
}

function is_safe_url(string $url): bool {
    return (bool)filter_var($url, FILTER_VALIDATE_URL) && (bool)preg_match('#^https?://#i', $url);
}

function qty_label(float $q): string {
    return rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',');
}
