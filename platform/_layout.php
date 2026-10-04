<?php
/**
 * ====================================================================
 * RY MEDYA PLATFORM - AJANS & FREELANCER PORTALI ORTAK DÜZEN
 * ====================================================================
 */

function platform_header(string $title, string $active = ''): void {
    $role  = portal_role();
    $cu    = $_SESSION['client_user'] ?? [];
    $notif = portal_notifications((int)($_SESSION['client_user_id'] ?? 0), 8);
    $nav = $role === 'agency'
        ? [
            'index'   => ['Panel', 'layout-dashboard', '/platform/index.php'],
            'new'     => ['Yeni İş Talebi', 'plus-circle', '/platform/job_new.php'],
            'jobs'    => ['İşlerim', 'briefcase', '/platform/jobs.php'],
            'finance' => ['Hesap Ekstresi', 'receipt', '/modules/contacts/statement_print.php'],
            'profile' => ['Profil', 'user-cog', '/platform/profile.php'],
        ]
        : [
            'index'    => ['Panel', 'layout-dashboard', '/platform/index.php'],
            'pool'     => ['İş Havuzu', 'radar', '/platform/pool.php'],
            'jobs'     => ['İşlerim', 'briefcase', '/platform/jobs.php'],
            'earnings' => ['Kazançlarım', 'wallet', '/platform/earnings.php'],
            'profile'  => ['Profil', 'user-cog', '/platform/profile.php'],
        ];
    $accent = $role === 'agency' ? 'indigo' : 'emerald';
    ?>
<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> | RY Medya Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-full font-sans antialiased text-slate-800" x-data="{ menu: false }">
    <header class="bg-slate-900 text-white sticky top-0 z-40">
        <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
            <a href="<?= BASE_URL ?>/platform/index.php" class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-<?= $accent ?>-600 flex items-center justify-center"><i data-lucide="clapperboard" class="w-5 h-5"></i></div>
                <div class="leading-tight">
                    <span class="text-sm font-black">RY MEDYA</span>
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-<?= $accent ?>-300"><?= $role === 'agency' ? 'Ajans Paneli' : 'Freelancer Paneli' ?></span>
                </div>
            </a>
            <nav class="hidden md:flex items-center gap-1">
                <?php foreach ($nav as $k => [$label, $icon, $href]): ?>
                    <a href="<?= BASE_URL . $href ?>" <?= $k === 'finance' ? 'target="_blank"' : '' ?>
                       class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold <?= $active === $k ? 'bg-white/15 text-white' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                        <i data-lucide="<?= $icon ?>" class="w-4 h-4"></i><?= $label ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="flex items-center gap-2">
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="relative p-2 rounded-xl hover:bg-white/10" title="Bildirimler">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                        <?php if ($notif['unread'] > 0): ?>
                            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-600 text-[10px] font-bold flex items-center justify-center"><?= $notif['unread'] > 9 ? '9+' : $notif['unread'] ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-80 bg-white text-slate-800 rounded-2xl shadow-xl ring-1 ring-black/5 overflow-hidden">
                        <div class="px-4 py-3 border-b border-slate-100 flex justify-between text-xs">
                            <strong>Bildirimler</strong>
                            <a href="<?= BASE_URL ?>/platform/notifications.php" class="font-bold text-<?= $accent ?>-600">Tümü</a>
                        </div>
                        <div class="max-h-80 overflow-y-auto divide-y divide-slate-100">
                            <?php if (empty($notif['items'])): ?>
                                <p class="px-4 py-6 text-center text-xs text-slate-400">Bildirim yok.</p>
                            <?php else: foreach ($notif['items'] as $n): ?>
                                <a href="<?= BASE_URL . e($n['link'] ?: '/platform/notifications.php') ?>" class="block px-4 py-3 hover:bg-slate-50 <?= $n['is_unread'] ? 'bg-' . $accent . '-50/70' : '' ?>">
                                    <p class="text-xs leading-snug"><?= e($n['message']) ?></p>
                                    <p class="text-[10px] text-slate-400 mt-1"><?= time_ago($n['created_at']) ?></p>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <span class="hidden sm:block text-xs text-slate-300 max-w-[160px] truncate"><?= e($cu['company_name'] ?? $cu['full_name'] ?? '') ?></span>
                <a href="<?= BASE_URL ?>/client/logout.php" class="p-2 rounded-xl hover:bg-rose-600" title="Çıkış"><i data-lucide="log-out" class="w-5 h-5"></i></a>
                <button @click="menu = !menu" class="md:hidden p-2 rounded-xl hover:bg-white/10"><i data-lucide="menu" class="w-5 h-5"></i></button>
            </div>
        </div>
        <nav x-show="menu" x-cloak class="md:hidden border-t border-white/10 px-4 py-2 space-y-1">
            <?php foreach ($nav as $k => [$label, $icon, $href]): ?>
                <a href="<?= BASE_URL . $href ?>" class="flex items-center gap-2 px-3 py-2 rounded-xl text-sm <?= $active === $k ? 'bg-white/15' : 'text-slate-300' ?>"><i data-lucide="<?= $icon ?>" class="w-4 h-4"></i><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <main class="max-w-6xl mx-auto px-4 py-6 sm:py-8">
        <?= display_flash() ?>
    <?php
}

function platform_footer(): void {
    ?>
    </main>
    <footer class="max-w-6xl mx-auto px-4 pb-8 text-center text-[11px] text-slate-400">
        &copy; <?= date('Y') ?> <?= e(get_setting('company_name', 'RY Medya Prodüksiyon')) ?> · Prodüksiyon İş Platformu
    </footer>
    <script>lucide.createIcons();</script>
</body>
</html>
    <?php
}

/**
 * İş kartı (havuz ve listelerde)
 */
function platform_job_card(array $j, string $perspective, bool $show_agency = true): void {
    ?>
    <a href="<?= BASE_URL ?>/platform/job.php?id=<?= (int)$j['id'] ?>" class="block p-4 bg-white rounded-2xl border border-slate-200 hover:border-slate-400 hover:shadow-md transition">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-[10px] font-bold text-slate-500"><?= e($j['job_code']) ?></span>
                    <?= job_status_badge($j['status'], $perspective) ?>
                    <span class="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full"><?= e(job_category_label($j['category'])) ?></span>
                </div>
                <h3 class="text-sm font-bold text-slate-900 mt-1.5"><?= e($j['title']) ?></h3>
                <p class="text-[11px] text-slate-500 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                    <?php if ($show_agency && !empty($j['agency_name'])): ?><span>🏢 <?= e($j['agency_name']) ?></span><?php endif; ?>
                    <span>📍 <?= (int)$j['is_remote'] === 1 ? 'Uzaktan' : e($j['location_city'] ?: '-') ?></span>
                    <?php if (!empty($j['start_date'])): ?><span>🎬 <?= format_date($j['start_date']) ?></span><?php endif; ?>
                    <?php if (!empty($j['deadline'])): ?><span>⏰ Teslim: <?= format_date($j['deadline']) ?></span><?php endif; ?>
                </p>
            </div>
            <div class="text-right flex-shrink-0">
                <?php if ($perspective === 'freelancer' && $j['freelancer_fee'] !== null): ?>
                    <span class="text-base font-black text-emerald-600"><?= format_money($j['freelancer_fee'], $j['currency']) ?></span>
                    <span class="block text-[10px] text-slate-400">Hakediş</span>
                <?php elseif ($perspective === 'agency'): ?>
                    <?php $price = $j['agency_price'] ?? $j['budget']; ?>
                    <?php if ($price !== null): ?>
                        <span class="text-base font-black text-slate-900"><?= format_money($price, $j['currency']) ?></span>
                        <span class="block text-[10px] text-slate-400"><?= $j['agency_price'] !== null ? 'Fiyat (KDV hariç)' : 'Bütçeniz' ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php
}

/**
 * Mesajlaşma kutusu
 */
function platform_message_box(array $messages, string $form_action, string $self_type, string $placeholder): void {
    ?>
    <div class="space-y-3 max-h-96 overflow-y-auto pr-1 mb-3">
        <?php if (empty($messages)): ?>
            <p class="text-xs text-slate-400 text-center py-4">Henüz mesaj yok.</p>
        <?php else: foreach ($messages as $m): $mine = $m['sender_type'] === $self_type; ?>
            <div class="flex <?= $mine ? 'justify-end' : 'justify-start' ?>">
                <div class="max-w-[80%] px-3.5 py-2.5 rounded-2xl text-xs <?= $mine ? 'bg-slate-900 text-white rounded-br-sm' : 'bg-slate-100 text-slate-800 rounded-bl-sm' ?>">
                    <p class="font-bold text-[10px] mb-0.5 <?= $mine ? 'text-slate-300' : 'text-slate-500' ?>"><?= e($m['sender_name']) ?> · <?= time_ago($m['created_at']) ?></p>
                    <p class="whitespace-pre-line"><?= e($m['message']) ?></p>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <form method="POST" action="" class="flex gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= e($form_action) ?>">
        <textarea name="message" rows="2" required placeholder="<?= e($placeholder) ?>" class="flex-1 p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs"></textarea>
        <button type="submit" class="px-4 bg-slate-900 hover:bg-slate-700 text-white rounded-xl text-xs font-bold"><i data-lucide="send" class="w-4 h-4"></i></button>
    </form>
    <?php
}
