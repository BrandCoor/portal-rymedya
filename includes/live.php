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
    <?= live_js_once() ?>
    <div class="relative" x-data="notifBell(<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>)" @live-notif.window="push($event.detail)">
        <button type="button" @click="toggle()" class="icon-btn" aria-label="Bildirimler" :aria-expanded="open">
            <i data-lucide="bell"></i>
            <span class="dot-count" x-show="unread" x-text="badge()" <?= (int)$notif['unread'] > 0 ? '' : 'style="display:none"' ?>><?= (int)$notif['unread'] > 9 ? '9+' : (int)$notif['unread'] ?></span>
        </button>
        <div x-show="open" @click.away="open = false" x-cloak class="menu" style="display:none;right:0;top:42px;width:340px;max-width:calc(100vw - 24px);padding:0">
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
    return '<script>window.LIVE_CFG = ' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>' . live_js_once();
}

/**
 * Zil bileşeni ve yoklama betiği sayfaya gömülü basılır (ayrı .js dosyası
 * gerektirmez). Sayfada bir kez; Alpine'dan önce tanımlanır.
 */
function live_js_once(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return "<script>\n" . LIVE_JS . "\n</script>";
}

const LIVE_JS = <<<'JS'
/**
 * Canlı bildirimler ve canlı iş sayfası (bkz. includes/live.php)
 * - notifBell: zil menüsü (Alpine). Açılınca bildirimler okundu sayılır.
 * - Yoklama: LIVE_CFG.every saniyede bir /ajax/live.php; sekme gizliyken durur.
 * - İş sayfası: imza değişirse sayfa tazelenir; kullanıcı form dolduruyorsa
 *   tazelemek yerine üstte "yenile" uyarısı çıkar.
 */
(function () {
    if (window.__liveLoaded) return;
    window.__liveLoaded = true;
    var C = {};
    var since = 0;

    window.notifBell = function (cfg) {
        return {
            open: false,
            unread: cfg.unread || 0,
            items: cfg.items || [],
            maxId: cfg.maxId || 0,
            showActor: !!cfg.actor,
            init: function () {
                since = Math.max(since, this.maxId);
                setTitle(this.unread);
            },
            badge: function () { return this.unread > 9 ? '9+' : String(this.unread); },
            toggle: function () {
                this.open = !this.open;
                if (this.open && this.unread > 0) this.markSeen();
                if (!this.open) this.items.forEach(function (n) { n.unread = false; });
            },
            markSeen: function () {
                var self = this;
                self.unread = 0;
                setTitle(0);
                post({ action: 'seen', up_to: self.maxId });
            },
            push: function (d) {
                var self = this;
                (d.items || []).forEach(function (n) {
                    if (self.items.some(function (x) { return x.id === n.id; })) return;
                    self.items.unshift(n);
                    self.maxId = Math.max(self.maxId, n.id);
                });
                self.items = self.items.slice(0, 15);
                self.unread = d.unread;
                if (self.open && self.unread > 0) self.markSeen();
                setTitle(self.unread);
            }
        };
    };

    var baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
    function setTitle(n) {
        document.title = (n > 0 ? '(' + (n > 9 ? '9+' : n) + ') ' : '') + baseTitle;
    }

    function post(data) {
        var body = new URLSearchParams(data);
        body.append('csrf_token', C.csrf || '');
        return fetch(C.url, { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
    }

    /* ---------- köşe bildirimi ---------- */
    function toast(n) {
        var box = document.getElementById('live-toasts');
        if (!box) {
            box = document.createElement('div');
            box.id = 'live-toasts';
            box.className = 'live-toasts';
            box.setAttribute('aria-live', 'polite');
            document.body.appendChild(box);
        }
        var a = document.createElement('a');
        a.className = 'live-toast';
        a.href = n.link;
        var p = document.createElement('span');
        p.textContent = n.text;
        var x = document.createElement('button');
        x.type = 'button';
        x.setAttribute('aria-label', 'Kapat');
        x.textContent = '×';
        x.onclick = function (e) { e.preventDefault(); a.remove(); };
        a.appendChild(p);
        a.appendChild(x);
        box.appendChild(a);
        while (box.children.length > 3) box.firstChild.remove();
        setTimeout(function () { a.classList.add('is-out'); setTimeout(function () { a.remove(); }, 400); }, 7000);
    }

    /* ---------- iş sayfası: kullanıcı form dolduruyor mu? ---------- */
    var dirty = false;
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t && t.closest && t.closest('form') && t.type !== 'search') dirty = true;
    });
    function busy() {
        if (dirty) return true;
        var a = document.activeElement;
        if (a && (a.tagName === 'TEXTAREA' || (a.tagName === 'INPUT' && a.type !== 'search' && a.type !== 'checkbox'))) return true;
        if (document.querySelector('[data-live-hold]')) return true;
        var modals = document.querySelectorAll('.modal-backdrop');
        for (var i = 0; i < modals.length; i++) { if (getComputedStyle(modals[i]).display !== 'none') return true; }
        return false;
    }
    function banner() {
        if (document.getElementById('live-banner')) return;
        var b = document.createElement('div');
        b.id = 'live-banner';
        b.className = 'live-banner';
        b.innerHTML = '<span>Bu işte yeni bir gelişme var.</span>';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-primary btn-sm';
        btn.textContent = 'Sayfayı yenile';
        btn.onclick = reload;
        b.appendChild(btn);
        document.body.appendChild(b);
    }
    function reload() {
        try { sessionStorage.setItem('live_scroll', location.pathname + location.search + '|' + window.scrollY); } catch (e) {}
        location.reload();
    }
    try {
        var s = sessionStorage.getItem('live_scroll');
        if (s) {
            sessionStorage.removeItem('live_scroll');
            var parts = s.split('|');
            if (parts[0] === location.pathname + location.search) {
                window.addEventListener('load', function () { window.scrollTo(0, parseInt(parts[1], 10) || 0); });
            }
        }
    } catch (e) {}

    /* ---------- yoklama ---------- */
    var timer = null, stopped = false, fails = 0;
    function tick() {
        if (stopped || document.hidden || !C.url) return schedule();
        var q = C.url + '?since=' + since + (C.job ? '&job=' + C.job : '');
        fetch(q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
            .then(function (res) {
                fails = 0;
                var d = res.d || {};
                if (res.status === 401 || d.auth === false) {
                    stopped = true;
                    if (d.expired) location.reload();   // oturum süresi doldu → giriş ekranına
                    return;
                }
                var fresh = (d.items || []).filter(function (n) { return n.id > since; });
                fresh.forEach(function (n) { since = Math.max(since, n.id); });
                window.dispatchEvent(new CustomEvent('live-notif', { detail: { unread: d.unread, items: fresh.slice().reverse() } }));
                fresh.slice(-3).forEach(toast);
                if (C.job && C.sig && d.sig && d.sig !== C.sig) {
                    C.sig = d.sig;
                    if (busy()) banner(); else reload();
                }
            })
            .catch(function () { fails++; })
            .then(schedule);
    }
    function schedule() {
        clearTimeout(timer);
        if (stopped) return;
        var wait = (C.every || 12) * 1000 * Math.min(5, 1 + fails);
        timer = setTimeout(tick, wait);
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { clearTimeout(timer); tick(); }
    });
    // Ayarlar sayfa sonunda (live_script) gelir; DOM hazır olunca okunur
    function boot() { C = window.LIVE_CFG || {}; if (C.url) schedule(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
JS;
