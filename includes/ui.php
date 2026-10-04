<?php
/**
 * ====================================================================
 * ARAYÜZ YARDIMCILARI
 * ====================================================================
 * ui_head()  : tüm sayfalarda ortak <head> içeriği (yazı tipi, Tailwind
 *              yapılandırması, bileşen CSS'i, ikon ve Alpine kütüphaneleri)
 * ui_*()     : rozet, boş durum, avatar, puan halkası gibi küçük bileşenler
 */

const UI_ASSET_VERSION = '2026.10.11';

function ui_head(string $title, array $opts = []): void {
    $chart   = !empty($opts['chart']);
    $accent  = valid_hex_color(site_setting('brand_accent_color')) ? strtoupper(site_setting('brand_accent_color')) : '#D2462F';
    $sidebar = valid_hex_color(site_setting('brand_sidebar_color')) ? strtoupper(site_setting('brand_sidebar_color')) : '#121214';
    $hover   = color_shade($accent, -0.12);
    $soft    = color_shade($accent, 0.9);
    $suffix  = site_setting('brand_title_suffix');
    $favicon = site_image('brand_favicon');
    $mark    = mb_substr(site_setting('brand_mark_text'), 0, 1);
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= e($sidebar) ?>">
    <title><?= e($title) ?><?= $suffix !== '' ? ' · ' . e($suffix) : '' ?></title>
    <?php if ($favicon !== ''): ?>
    <link rel="icon" href="<?= e($favicon) ?>">
    <link rel="apple-touch-icon" href="<?= e($favicon) ?>">
    <?php else: ?>
    <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="' . $accent . '"/>' . ($mark !== '' ? '<text x="16" y="21.5" font-family="Arial,Helvetica,sans-serif" font-size="15" font-weight="700" fill="#fff" text-anchor="middle">' . htmlspecialchars($mark, ENT_XML1) . '</text>' : '<circle cx="16" cy="16" r="5" fill="#fff"/>') . '</svg>') ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Geist', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['"Geist Mono"', 'ui-monospace', 'monospace']
                    },
                    colors: {
                        // Eski sayfalardaki "brand" sınıfları mürekkep tonlarına bağlanır
                        brand: { 50: '#F4F3F0', 100: '#E9E8E3', 200: '#D6D4CD', 300: '#B9B7AF', 400: '#6B6A72', 500: '#3D3D43', 600: '#18181B', 700: '#000000', 800: '#000000', 900: '#000000' },
                        // Soğuk gri yerine sıcak nötr palet
                        slate: { 50: '#FAF9F7', 100: '#F1F0EC', 200: '#E4E2DD', 300: '#D1CFC8', 400: '#A3A2A8', 500: '#6E6D74', 600: '#55545B', 700: '#3D3D43', 800: '#26262A', 900: '#151517', 950: '#0E0E10' },
                        accent: { 50: '<?= $soft ?>', 100: '<?= color_shade($accent, 0.75) ?>', 500: '<?= $accent ?>', 600: '<?= $hover ?>', 700: '<?= color_shade($accent, -0.28) ?>' }
                    },
                    borderRadius: { 'lg': '8px', 'xl': '9px', '2xl': '11px', '3xl': '13px' },
                    boxShadow: {
                        'xs': '0 1px 2px rgba(21,21,23,.04)',
                        'sm': '0 1px 2px rgba(21,21,23,.05)',
                        'md': '0 4px 14px -6px rgba(21,21,23,.14)',
                        'lg': '0 10px 30px -12px rgba(21,21,23,.2)',
                        'xl': '0 16px 40px -16px rgba(21,21,23,.24)',
                        '2xl': '0 24px 60px -20px rgba(21,21,23,.3)'
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css?v=<?= UI_ASSET_VERSION ?>">
    <style><?= ui_extra_css() ?></style>
    <style>:root{--accent:<?= $accent ?>;--accent-hover:<?= $hover ?>;--accent-soft:<?= $soft ?>;--sidebar:<?= $sidebar ?>;--sidebar-2:<?= color_shade($sidebar, 0.06) ?>}.rec-dot{box-shadow:0 0 0 4px <?= $accent ?>2e}</style>
    <script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>
    <script defer src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"></script>
    <?php if ($chart): ?><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script><?php endif; ?>
    <?php
}

function ui_icons_init(): void {
    echo '<script>window.lucide && lucide.createIcons({ attrs: { "stroke-width": 1.75 } });</script>';
}

function ui_badge(string $text, string $tone = 'neutral', bool $dot = false): string {
    return '<span class="badge tone-' . e($tone) . ($dot ? ' has-dot' : '') . '">' . e($text) . '</span>';
}

function ui_icon(string $name, string $class = ''): string {
    return '<i data-lucide="' . e($name) . '"' . ($class ? ' class="' . e($class) . '"' : '') . '></i>';
}

function ui_empty(string $title, string $text = '', string $icon = 'inbox', string $action_html = ''): string {
    return '<div class="empty"><div class="empty-icon">' . ui_icon($icon) . '</div><p class="empty-title">' . e($title) . '</p>'
        . ($text !== '' ? '<p class="empty-text">' . e($text) . '</p>' : '')
        . ($action_html !== '' ? '<div style="margin-top:16px">' . $action_html . '</div>' : '') . '</div>';
}

function ui_initials(?string $name): string {
    $parts = preg_split('/\s+/u', trim((string)$name));
    $first = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $last, 'UTF-8') ?: '·';
}

function ui_avatar(?string $name, string $size = ''): string {
    return '<span class="avatar' . ($size ? ' avatar-' . $size : '') . '">' . e(ui_initials($name)) . '</span>';
}

/**
 * 0-100 arası puan halkası (performans karnesi)
 */
function ui_score_ring(?float $score, string $caption = 'puan', int $size = 112): string {
    $r = 46;
    $c = 2 * M_PI * $r;
    $v = $score === null ? 0 : max(0, min(100, $score));
    $color = $score === null ? '#D7D5CF' : ($v >= 85 ? '#1C7347' : ($v >= 70 ? '#151517' : ($v >= 55 ? '#C98A0F' : '#B3261E')));
    $dash = $c * $v / 100;
    return '<div class="score-ring" style="width:' . $size . 'px;height:' . $size . 'px"><svg width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100">'
        . '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="#ECEBE7" stroke-width="7"/>'
        . '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="7" stroke-linecap="round" stroke-dasharray="' . round($dash, 2) . ' ' . round($c, 2) . '"/>'
        . '</svg><div class="val"><div><b>' . ($score === null ? '—' : number_format($v, 0)) . '</b><span>' . e($caption) . '</span></div></div></div>';
}

/**
 * Yüzdelik metrik çubuğu
 */
function ui_meter(string $label, ?float $percent, string $suffix = '%'): string {
    $v = $percent === null ? null : max(0, min(100, $percent));
    $tone = $v === null ? '' : ($v >= 85 ? 'tone-success' : ($v >= 65 ? '' : ($v >= 45 ? 'tone-warning' : 'tone-danger')));
    return '<div class="meter"><div class="meter-row"><span class="text-ink-2">' . e($label) . '</span><span class="num text-ink">' . ($v === null ? '<span class="text-faint">veri yok</span>' : number_format($v, 0) . $suffix) . '</span></div>'
        . '<div class="progress ' . $tone . '"><span style="width:' . ($v ?? 0) . '%"></span></div></div>';
}

/**
 * Giriş / kayıt sayfalarının sol paneli. Metinler ayarlardan gelir:
 * {$prefix}_eyebrow, {$prefix}_quote, {$prefix}_points
 */
function ui_auth_aside(string $prefix): void {
    $bg = site_image('login_aside_image');
    ?>
    <aside class="auth-aside"<?= $bg !== '' ? ' style="background-image:linear-gradient(180deg,rgba(14,14,16,.78),rgba(14,14,16,.9)),url(\'' . e($bg) . '\');background-size:cover;background-position:center"' : '' ?>>
        <div class="frame-lines"></div>
        <div style="position:relative"><?= brand_html('dark') ?></div>
        <div style="position:relative">
            <?php if (site_setting($prefix . '_eyebrow') !== ''): ?>
                <p class="eyebrow" style="color:#8A8990;margin-bottom:18px"><span class="rec-dot" style="margin-right:10px"></span><?= e(site_setting($prefix . '_eyebrow')) ?></p>
            <?php endif; ?>
            <p class="auth-quote"><?= rich_text(site_setting($prefix . '_quote')) ?></p>
        </div>
        <div class="auth-points">
            <?php foreach (site_points($prefix . '_points') as $pt): ?>
                <div><i data-lucide="<?= e(preg_replace('/[^a-z0-9-]/', '', strtolower($pt[0] ?? '')) ?: 'circle') ?>"></i><span><?php if (($pt[1] ?? '') !== ''): ?><b><?= e($pt[1]) ?></b> <?php endif; ?><?= e($pt[2] ?? '') ?></span></div>
            <?php endforeach; ?>
        </div>
    </aside>
    <?php
}
