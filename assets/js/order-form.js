/**
 * İş formu: canlı tutar ve termin (aynı gün / acil iş) değerlendirmesi.
 * Sunucudaki assess_lead_time() ve price_order() kurallarının aynısıdır;
 * nihai kontrol her zaman sunucuda yapılır.
 *
 * cfg: { svc, qty, rules: { block, warn, sameDay, rush }, start, deadline, remote, rushAck,
 *        lockedRush (bool, isteğe bağlı), keepDates (isteğe bağlı: { start, deadline }) }
 */
function orderForm(cfg) {
    return {
        svc: cfg.svc || {},
        qty: cfg.qty || {},
        rules: cfg.rules,
        start: cfg.start || '',
        deadline: cfg.deadline || '',
        remote: !!cfg.remote,
        rushAck: !!cfg.rushAck,
        keep: cfg.keepDates || null,
        wasRush: !!cfg.wasRush,
        lead: { level: 'ok', message: '' },
        init() {
            for (const id in this.svc) { if (this.qty[id] === undefined) this.qty[id] = 0; }
            this.assess();
        },
        inc(id) { this.qty[id] = Math.min(999, (parseFloat(this.qty[id]) || 0) + 1); this.recalc(); },
        dec(id) { this.qty[id] = Math.max(0, (parseFloat(this.qty[id]) || 0) - 1); this.recalc(); },
        recalc() { this.assess(); },
        lines() {
            const out = [];
            for (const id in this.qty) {
                const q = parseFloat(this.qty[id]) || 0;
                if (q > 0 && this.svc[id]) out.push({ id, name: this.svc[id].name, unit: this.svc[id].unit, qty: q, total: q * this.svc[id].price });
            }
            return out;
        },
        isRush() { return this.lead.level === 'warn' || (this.lead.level === 'kept' && this.wasRush); },
        subtotal() { return this.lines().reduce((s, l) => s + l.total, 0); },
        rushFee() { return this.isRush() ? Math.round(this.subtotal() * this.rules.rush) / 100 : 0; },
        total() { return this.subtotal() + this.rushFee(); },
        serviceLead() { let m = 0; for (const l of this.lines()) m = Math.max(m, this.svc[l.id].lead || 0); return m; },
        datesUnchanged() { return this.keep && this.keep.start === this.start && this.keep.deadline === this.deadline; },
        assess() {
            // Düzenlemede tarihler değişmediyse mevcut termin korunur
            if (this.datesUnchanged()) { this.lead = { level: 'kept', message: '' }; return; }
            const ref = this.start || this.deadline;
            if (!ref) { this.lead = { level: 'ok', message: '' }; return; }
            const block = Math.max(this.rules.block, this.serviceLead());
            const warn = Math.max(this.rules.warn, block);
            const now = new Date();
            const today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            const hours = (new Date(ref + 'T09:00:00') - now) / 36e5;
            const what = this.start ? 'Başlangıç' : 'Teslim';
            if (ref < today) { this.lead = { level: 'block', message: what + ' tarihi geçmiş bir gün olamaz.' }; return; }
            if (ref === today && this.rules.sameDay) { this.lead = { level: 'block', message: 'Aynı gün başlayan işler kabul edilmiyor. Lütfen daha ileri bir tarih seçin.' }; return; }
            if (hours < block) {
                this.lead = { level: 'block', message: block > this.rules.block ? 'Seçtiğiniz hizmetler için en az ' + block + ' saat önceden iş girişi gerekir.' : 'İşler en az ' + block + ' saat önceden girilmelidir.' };
                return;
            }
            if (hours < warn) {
                this.lead = { level: 'warn', message: 'Bu iş ' + warn + ' saatten kısa sürede başlıyor ve acil iş olarak işlenecek.' + (this.rules.rush > 0 ? ' Tutara %' + this.rules.rush + ' acil iş farkı eklenir.' : '') };
                return;
            }
            this.lead = { level: 'ok', message: '' };
        },
        money(v) { return v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺'; },
        fmtQty(q) { return Number.isInteger(q) ? q : q.toLocaleString('tr-TR'); }
    };
}
