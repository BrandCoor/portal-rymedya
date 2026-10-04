<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - AJANS & FREELANCER PORTALI ORTAK DÜZEN
 * ====================================================================
 */

function platform_nav(string $role): array {
    return $role === 'agency'
        ? [
            'index'   => ['Genel bakış', 'layout-grid', '/platform/index.php'],
            'new'     => ['Yeni sipariş', 'plus', '/platform/job_new.php'],
            'jobs'    => ['Siparişlerim', 'briefcase', '/platform/jobs.php'],
            'finance' => ['Ekstre', 'receipt-text', '/modules/contacts/statement_print.php'],
            'profile' => ['Hesap', 'circle-user-round', '/platform/profile.php'],
        ]
        : [
            'index'       => ['Genel bakış', 'layout-grid', '/platform/index.php'],
            'pool'        => ['İş havuzu', 'radar', '/platform/pool.php'],
            'jobs'        => ['İşlerim', 'briefcase', '/platform/jobs.php'],
            'performance' => ['Performans', 'gauge', '/platform/performance.php'],
            'earnings'    => ['Kazanç', 'wallet', '/platform/earnings.php'],
        ];
}

function platform_header(string $title, string $active = ''): void {
    $role  = portal_role();
    $cu    = $_SESSION['client_user'] ?? [];
    $notif = portal_notifications((int)($_SESSION['client_user_id'] ?? 0), 8);
    $nav   = platform_nav($role);
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <?php ui_head($title); ?>
</head>
<body>
<header class="portal-top">
    <div class="portal-top-inner">
        <a href="<?= BASE_URL ?>/platform/index.php" style="display:flex;align-items:center;gap:10px;flex-shrink:0">
            <span class="brand-mark">RY</span>
            <span style="line-height:1.15">
                <span style="display:block;font-weight:600;font-size:13.5px">RY Medya</span>
                <span class="xsmall text-muted"><?= $role === 'agency' ? 'Ajans' : 'Freelancer' ?></span>
            </span>
        </a>

        <nav class="portal-nav">
            <?php foreach ($nav as $k => [$label, $icon, $href]): ?>
                <a href="<?= BASE_URL . $href ?>" class="<?= $active === $k ? 'is-active' : '' ?>" <?= $k === 'finance' ? 'target="_blank"' : '' ?>><i data-lucide="<?= $icon ?>"></i><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div style="margin-left:auto;display:flex;align-items:center;gap:6px">
            <?php if ($role === 'agency'): ?>
                <a href="<?= BASE_URL ?>/platform/job_new.php" class="btn btn-accent btn-sm hidden md:inline-flex"><i data-lucide="plus"></i>Yeni sipariş</a>
            <?php endif; ?>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="icon-btn" aria-label="Bildirimler">
                    <i data-lucide="bell"></i>
                    <?php if ($notif['unread'] > 0): ?><span class="dot-count"><?= $notif['unread'] > 9 ? '9+' : $notif['unread'] ?></span><?php endif; ?>
                </button>
                <div x-show="open" @click.away="open = false" x-cloak class="menu" style="right:0;top:42px;width:340px;padding:0">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line-2)">
                        <span class="h3">Bildirimler</span>
                        <a href="<?= BASE_URL ?>/platform/notifications.php" class="small link">Tümü</a>
                    </div>
                    <div style="max-height:360px;overflow-y:auto" class="divide">
                        <?php if (empty($notif['items'])): ?>
                            <p class="small text-muted" style="padding:28px 14px;text-align:center">Yeni bildirim yok.</p>
                        <?php else: foreach ($notif['items'] as $n): ?>
                            <a href="<?= BASE_URL . e($n['link'] ?: '/platform/notifications.php') ?>" style="display:block;padding:11px 14px;<?= $n['is_unread'] ? 'background:#FBF8F4' : '' ?>">
                                <p class="small text-ink" style="line-height:1.45"><?= e($n['message']) ?></p>
                                <p class="xsmall text-muted" style="margin-top:3px"><?= time_ago($n['created_at']) ?></p>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" style="display:flex;align-items:center;gap:8px;padding:3px;border-radius:9px" class="hover:bg-slate-100">
                    <?= ui_avatar($cu['company_name'] ?? $cu['full_name'] ?? '') ?>
                </button>
                <div x-show="open" @click.away="open = false" x-cloak class="menu" style="right:0;top:44px">
                    <div style="padding:8px 9px 10px">
                        <p class="small" style="font-weight:500"><?= e($cu['company_name'] ?? $cu['full_name'] ?? '') ?></p>
                        <p class="xsmall text-muted"><?= e($cu['email'] ?? '') ?></p>
                    </div>
                    <div class="menu-sep"></div>
                    <a href="<?= BASE_URL ?>/platform/profile.php" class="menu-item"><i data-lucide="circle-user-round"></i>Hesap ve profil</a>
                    <a href="<?= BASE_URL ?>/platform/notifications.php" class="menu-item"><i data-lucide="bell"></i>Bildirimler</a>
                    <div class="menu-sep"></div>
                    <a href="<?= BASE_URL ?>/client/logout.php" class="menu-item"><i data-lucide="log-out"></i>Çıkış yap</a>
                </div>
            </div>
        </div>
    </div>
</header>

<nav class="mobile-tabbar">
    <?php foreach ($nav as $k => [$label, $icon, $href]): ?>
        <a href="<?= BASE_URL . $href ?>" class="<?= $active === $k ? 'is-active' : '' ?>"><i data-lucide="<?= $icon ?>"></i><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<main class="portal-main">
    <?= display_flash() ?>
    <?php
}

function platform_footer(): void {
    ?>
</main>
<footer class="xsmall text-faint" style="max-width:1240px;margin:0 auto;padding:0 24px 32px;display:flex;justify-content:space-between">
    <span>&copy; <?= date('Y') ?> <?= e(get_setting('company_name', 'RY Medya Prodüksiyon')) ?></span>
    <span>Prodüksiyon platformu</span>
</footer>
<?php ui_icons_init(); ?>
</body>
</html>
    <?php
}

/**
 * Sipariş / iş satırı (listelerde)
 */
function platform_job_row(array $j, string $perspective): void {
    $price = $perspective === 'freelancer' ? $j['freelancer_fee'] : ($j['agency_price'] ?? $j['budget']);
    $late = !empty($j['deadline']) && $j['deadline'] < date('Y-m-d') && !in_array($j['status'], ['completed', 'cancelled'], true);
    ?>
    <a href="<?= BASE_URL ?>/platform/job.php?id=<?= (int)$j['id'] ?>" class="card card-hover" style="display:flex;align-items:center;gap:16px;padding:14px 16px">
        <span class="empty-icon" style="margin:0;width:38px;height:38px;flex-shrink:0"><i data-lucide="<?= job_category_icon($j['category']) ?>"></i></span>
        <span style="min-width:0;flex:1">
            <span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="code-tag"><?= e($j['job_code']) ?></span>
                <?= job_status_badge($j['status'], $perspective) ?>
                <?php if ((int)($j['is_rush'] ?? 0) === 1): ?><?= ui_badge('Acil', 'accent') ?><?php endif; ?>
            </span>
            <span style="display:block;font-weight:600;margin-top:4px" class="truncate"><?= e($j['title']) ?></span>
            <span class="xsmall text-muted" style="display:flex;gap:14px;flex-wrap:wrap;margin-top:3px">
                <span><?= e(job_category_label($j['category'])) ?></span>
                <span><?= (int)$j['is_remote'] === 1 ? 'Uzaktan' : e($j['location_city'] ?: '—') ?></span>
                <?php if (!empty($j['start_date'])): ?><span>Başlangıç <?= format_date($j['start_date']) ?></span><?php endif; ?>
                <?php if (!empty($j['deadline'])): ?><span style="<?= $late ? 'color:var(--danger);font-weight:500' : '' ?>">Teslim <?= format_date($j['deadline']) ?></span><?php endif; ?>
            </span>
        </span>
        <?php if ($price !== null): ?>
        <span style="text-align:right;flex-shrink:0">
            <span class="money" style="display:block;font-size:15px"><?= format_money((float)$price, $j['currency']) ?></span>
            <span class="xsmall text-muted"><?= $perspective === 'freelancer' ? 'hakediş' : ($j['agency_price'] !== null ? 'KDV hariç' : 'bütçe') ?></span>
        </span>
        <?php endif; ?>
        <i data-lucide="chevron-right" style="width:16px;height:16px;color:var(--faint);flex-shrink:0"></i>
    </a>
    <?php
}

/**
 * Mesaj kutusu
 */
function platform_message_box(array $messages, string $self_type, string $placeholder, string $action = 'message'): void {
    ?>
    <div class="thread" style="max-height:420px;overflow-y:auto;padding:2px 2px 10px">
        <?php if (empty($messages)): ?>
            <p class="small text-muted" style="text-align:center;padding:20px 0">Henüz mesaj yok.</p>
        <?php else: foreach ($messages as $m): $mine = $m['sender_type'] === $self_type; ?>
            <div class="bubble <?= $mine ? 'is-me' : 'is-them' ?>">
                <div class="bubble-meta"><?= e($mine ? 'Siz' : $m['sender_name']) ?> · <?= time_ago($m['created_at']) ?></div><?= e($m['message']) ?>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <form method="POST" action="" style="display:flex;gap:8px;align-items:flex-end;margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <textarea name="message" rows="2" required placeholder="<?= e($placeholder) ?>" class="textarea" style="flex:1"></textarea>
        <button type="submit" class="btn btn-primary btn-icon" aria-label="Gönder"><i data-lucide="arrow-up"></i></button>
    </form>
    <?php
}

/**
 * Sipariş kalemleri tablosu
 */
function platform_items_table(array $items, string $perspective, array $job): void {
    if (!$items) {
        return;
    }
    $show_agency = $perspective !== 'freelancer';
    ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Hizmet</th><th class="r">Miktar</th><th class="r"><?= $show_agency ? 'Birim fiyat' : 'Birim ücret' ?></th><th class="r">Tutar</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it):
                $unit = $show_agency ? (float)$it['agency_unit_price'] : (float)$it['freelancer_unit_fee']; ?>
                <tr>
                    <td><?= e($it['name']) ?></td>
                    <td class="r num"><?= qty_label((float)$it['quantity']) ?> <?= e($it['unit']) ?></td>
                    <td class="r num"><?= format_money($unit, $job['currency']) ?></td>
                    <td class="r money"><?= format_money($unit * (float)$it['quantity'], $job['currency']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ((int)$job['is_rush'] === 1 && $show_agency && (float)$job['rush_fee'] > 0): ?>
                <tr><td colspan="3" class="text-muted">Acil iş farkı</td><td class="r money"><?= format_money((float)$job['rush_fee'], $job['currency']) ?></td></tr>
            <?php endif; ?>
            <?php if (!$show_agency):
                $base = array_sum(array_map(fn($i) => (float)$i['freelancer_unit_fee'] * (float)$i['quantity'], $items));
                $bonus = (float)$job['freelancer_fee'] - $base;
                if (abs($bonus) > 0.009): ?>
                <tr><td colspan="3" class="text-muted"><?= $bonus > 0 ? 'Acil iş primi / ücret düzeltmesi' : 'Ücret düzeltmesi' ?></td><td class="r money"><?= format_money($bonus, $job['currency']) ?></td></tr>
            <?php endif; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
