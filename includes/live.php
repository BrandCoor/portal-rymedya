<?php
/**
 * ====================================================================
 * CANLI BİLDİRİM VE CANLI İŞ SAYFASI
 * ====================================================================
 * Paylaşımlı hostingde WebSocket olmadığından tarayıcı birkaç saniyede bir
 * /ajax/live.php'yi yoklar (sekme açık ve görünürken):
 *  - yeni bildirimler zil menüsüne düşer, köşede kısa bildirim çıkar
 *  - açık iş sayfasında bir gelişme olursa sayfa kendini tazeler
 *    (kullanıcı form dolduruyorsa tazelemez, "yenile" uyarısı gösterir)
 * Zil menüsü açıldığında bildirimler okundu sayılır.
 */

/** Yoklama aralığı (sn) */
const LIVE_POLL_SECONDS = 12;

/**
 * İşin "değişti mi" imzası. Erişimi olmayan kullanıcı için null.
 * $area: staff | agency | freelancer
 */
function live_job_sig(int $job_id, string $area, int $user_id, int $contact_id = 0): ?string {
    global $db;
    $st = $db->prepare("SELECT id, status, agency_contact_id, assigned_user_id, updated_at FROM platform_jobs WHERE id = ?");
    $st->execute([$job_id]);
    $j = $st->fetch();
    if (!$j) return null;
    if ($area === 'agency' && (int)$j['agency_contact_id'] !== $contact_id) return null;
    if ($area === 'freelancer' && (int)$j['assigned_user_id'] !== $user_id) {
        $a = $db->prepare("SELECT COUNT(*) FROM platform_applications WHERE job_id = ? AND user_id = ?");
        $a->execute([$job_id, $user_id]);
        if ((int)$a->fetchColumn() === 0 && !in_array($j['status'], ['published'], true)) return null;
    }
    $max = fn(string $sql) => (function () use ($db, $sql, $job_id) {
        $q = $db->prepare($sql);
        $q->execute([$job_id]);
        return (string)$q->fetchColumn();
    })();
    return md5(implode('|', [
        $j['status'], $j['updated_at'], (string)$j['assigned_user_id'],
        $max("SELECT COALESCE(MAX(id),0) FROM platform_job_changes WHERE job_id = ?"),
        $max("SELECT COALESCE(MAX(id),0) FROM platform_messages WHERE job_id = ?"),
        $max("SELECT COALESCE(MAX(id),0) FROM platform_deliveries WHERE job_id = ?"),
        $max("SELECT COALESCE(GROUP_CONCAT(CONCAT(id,':',status) ORDER BY id), '') FROM platform_milestones WHERE job_id = ?"),
        $max("SELECT COALESCE(SUM(quantity*1000+id),0) FROM platform_job_items WHERE job_id = ?"),
    ]));
}

/** Bildirim satırını istemciye gidecek biçime çevirir */
function live_item(array $n, string $fallback_link, bool $with_actor = false): array {
    // İşlemi yapanın adı yalnızca personele gider (ajans ve freelancer birbirini görmez)
    return [
        'id'     => (int)$n['id'],
        'text'   => (string)$n['message'],
        'link'   => BASE_URL . ($n['link'] ?: $fallback_link),
        'ago'    => time_ago($n['created_at']),
        'actor'  => $with_actor ? (string)($n['actor_name'] ?? '') : '',
        'unread' => !empty($n['is_unread']),
    ];
}

/**
 * Zil düğmesi + açılır menü (personel ve portal ortak).
 * $notif: get_notifications() / portal_notifications() sonucu
 */
function live_bell(array $notif, string $all_url, string $fallback_link, bool $show_actor = false): string {
    $items = array_map(fn($n) => live_item($n, $fallback_link, $show_actor), $notif['items']);
    $cfg = [
        'unread' => (int)$notif['unread'], 'items' => $items,
        'maxId' => $items ? max(array_column($items, 'id')) : 0,
        'actor' => $show_actor,
    ];
    ob_start(); ?>
    <div class="relative" x-data="notifBell(<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>)" @live-notif.window="push($event.detail)">
        <button type="button" @click="toggle()" class="icon-btn" aria-label="Bildirimler" :aria-expanded="open">
            <i data-lucide="bell"></i>
            <span class="dot-count" x-show="unread" x-text="badge()" <?= (int)$notif['unread'] > 0 ? '' : 'style="display:none"' ?>><?= (int)$notif['unread'] > 9 ? '9+' : (int)$notif['unread'] ?></span>
        </button>
        <div x-show="open" @click.away="open = false" x-cloak class="menu" style="right:0;top:42px;width:340px;max-width:calc(100vw - 24px);padding:0">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line-2)">
                <span class="h3">Bildirimler</span>
                <a href="<?= e($all_url) ?>" class="small link">Tümü</a>
            </div>
            <div style="max-height:360px;overflow-y:auto" class="divide">
                <p x-show="!items.length" class="small text-muted" style="padding:28px 14px;text-align:center">Yeni bildirim yok.</p>
                <template x-for="n in items" :key="n.id">
                    <a :href="n.link" class="notif-row" :class="n.unread && 'is-unread'">
                        <p class="small text-ink" style="line-height:1.45" x-text="n.text"></p>
                        <p class="xsmall text-muted" style="margin-top:3px" x-text="(showActor && n.actor ? n.actor + ' · ' : '') + n.ago"></p>
                    </a>
                </template>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

/**
 * Canlı yoklama betiği. $job_id verilirse iş sayfası değişince tazelenir.
 */
function live_script(?int $job_id = null, ?string $job_sig = null): string {
    $cfg = [
        'url' => BASE_URL . '/ajax/live.php', 'csrf' => csrf_token(), 'every' => LIVE_POLL_SECONDS,
        'job' => $job_id, 'sig' => $job_sig,
    ];
    return '<script>window.LIVE_CFG = ' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>'
         . '<script src="' . BASE_URL . '/assets/js/live.js?v=' . UI_ASSET_VERSION . '"></script>';
}
