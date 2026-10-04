/**
 * Canlı bildirimler ve canlı iş sayfası (bkz. includes/live.php)
 * - notifBell: zil menüsü (Alpine). Açılınca bildirimler okundu sayılır.
 * - Yoklama: LIVE_CFG.every saniyede bir /ajax/live.php; sekme gizliyken durur.
 * - İş sayfası: imza değişirse sayfa tazelenir; kullanıcı form dolduruyorsa
 *   tazelemek yerine üstte "yenile" uyarısı çıkar.
 */
(function () {
    var C = window.LIVE_CFG || {};
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
    if (C.url) schedule();
})();
