/**
 * Çerez onay penceresi (bkz. includes/legal.php → legal_cookie_notice)
 * Tercih "cookie_consent" çerezinde ve tarayıcıda saklanır, sunucuya kaydedilir.
 * Biçim: v<sürüm>.a<0|1>.m<0|1>.<onay kimliği>
 * window.cookieConsent → { analytics: bool, marketing: bool } (ileride analitik
 * betikleri yalnızca izin varsa yüklemek için kullanılır)
 */
(function () {
    var C = window.CC_CFG || { v: 1 };
    var box = document.getElementById('cc');
    if (!box) return;
    var prefs = document.getElementById('cc-prefs');
    var an = document.getElementById('cc-analytics');
    var mk = document.getElementById('cc-marketing');

    function read() {
        var m = document.cookie.match(/(?:^|;\s*)cookie_consent=([^;]+)/);
        var v = m ? decodeURIComponent(m[1]) : null;
        if (!v) { try { v = localStorage.getItem('cookie_consent'); } catch (e) {} }
        if (!v) return null;
        var p = v.split('.');
        if (p.length < 4 || p[0] !== 'v' + C.v) return null;
        return { analytics: p[1] === 'a1', marketing: p[2] === 'm1', id: p[3] };
    }
    function rid() {
        var a = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    function save(analytics, marketing) {
        var old = read();
        var id = old ? old.id : rid();
        var val = 'v' + C.v + '.a' + (analytics ? 1 : 0) + '.m' + (marketing ? 1 : 0) + '.' + id;
        document.cookie = 'cookie_consent=' + encodeURIComponent(val) + '; Max-Age=' + (180 * 86400) + '; Path=/; SameSite=Lax' + (C.secure ? '; Secure' : '');
        try { localStorage.setItem('cookie_consent', val); } catch (e) {}
        window.cookieConsent = { analytics: !!analytics, marketing: !!marketing };
        try {
            var body = new URLSearchParams({ id: id, v: C.v, analytics: analytics ? 1 : 0, marketing: marketing ? 1 : 0, page: location.pathname });
            fetch(C.url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
        } catch (e) {}
        hide();
        window.dispatchEvent(new CustomEvent('cookie-consent', { detail: window.cookieConsent }));
    }
    function show(withPrefs) {
        var cur = read();
        an.checked = !!(cur && cur.analytics);
        mk.checked = !!(cur && cur.marketing);
        box.hidden = false;
        togglePrefs(!!withPrefs);
        requestAnimationFrame(function () { box.classList.add('is-in'); });
    }
    function hide() { box.classList.remove('is-in'); box.hidden = true; }
    function togglePrefs(on) {
        prefs.hidden = !on;
        document.getElementById('cc-save').hidden = !on;
        document.getElementById('cc-prefs-btn').hidden = on;
    }
    box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-cc]');
        if (!b) return;
        var a = b.getAttribute('data-cc');
        if (a === 'all') save(true, true);
        else if (a === 'necessary') save(false, false);
        else if (a === 'save') save(an.checked, mk.checked);
        else if (a === 'prefs') togglePrefs(true);
    });
    window.openCookiePrefs = function () { show(true); };

    var cur = read();
    window.cookieConsent = cur ? { analytics: cur.analytics, marketing: cur.marketing } : { analytics: false, marketing: false };
    if (!cur) show(false);
    if (location.hash === '#cerez-tercihleri') show(true);
})();
