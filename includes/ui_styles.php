<?php
/**
 * ====================================================================
 * EK BİLEŞEN STİLLERİ (sayfaya gömülü)
 * ====================================================================
 * Yasal sayfalar, sözleşme onayları, çerez penceresi, canlı bildirimler,
 * profil ve kalem düzenleme stilleri. ui_head() içinde <style> olarak
 * basılır; böylece PHP dosyaları yüklendiğinde stiller de birlikte gelir
 * (ayrı bir CSS dosyasının sunucuya yüklenmesini beklemez).
 */

function ui_extra_css(): string {
    return <<<'CSS'
/* ---------- Kimlik ekranları: kart + alt bağlantılar alt alta ---------- */
.auth-main { flex-direction: column; }
.auth-main > .auth-card { flex-shrink: 0; }
.auth-legal { width: 100%; max-width: 400px; margin-top: 32px; padding-top: 18px; border-top: 1px solid var(--line-2); font-size: 12px; color: var(--faint); }
.auth-card[style*="520px"] + .auth-legal { max-width: 520px; }
.auth-legal .legal-links { justify-content: center; }
@media (max-width: 640px) { .auth-main { padding: 28px 16px; } }

/* ---------- Yasal bağlantı satırı ---------- */
.legal-links { display: flex; flex-wrap: wrap; gap: 6px 16px; line-height: 1.5; }
.legal-links a, .link-quiet { color: inherit; text-decoration: none; white-space: nowrap; }
.legal-links a:hover, .link-quiet:hover { color: var(--ink); text-decoration: underline; text-underline-offset: 3px; }
.portal-foot { max-width: 1240px; margin: 0 auto; padding: 20px 24px 32px; display: flex; flex-wrap: wrap; gap: 10px 28px; justify-content: space-between; align-items: center; font-size: 12px; color: var(--faint); border-top: 1px solid var(--line-2); }
.portal-foot .legal-links { flex: 1; justify-content: center; }
@media (max-width: 768px) { .portal-foot { flex-direction: column; text-align: center; padding: 20px 16px 96px; } }

/* ---------- Yasal metin sayfası ---------- */
.lg { min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); }
.lg-top { position: sticky; top: 0; z-index: 30; background: rgba(255,255,255,.92); backdrop-filter: saturate(1.4) blur(8px); border-bottom: 1px solid var(--line); }
.lg-top-in { max-width: 1160px; margin: 0 auto; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.lg-brand { display: flex; align-items: center; gap: 10px; color: inherit; text-decoration: none; }
.lg-hero { border-bottom: 1px solid var(--line); background: linear-gradient(180deg, #fff, var(--bg)); }
.lg-hero-in { max-width: 1160px; margin: 0 auto; padding: 40px 24px 32px; }
.lg-hero h1 { font-size: 30px; line-height: 1.15; font-weight: 650; letter-spacing: -0.03em; color: var(--ink); max-width: 760px; }
.lg-hero .eyebrow { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.lg-hero .eyebrow::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--accent); }
.lg-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin-top: 14px; font-size: 12.5px; color: var(--muted); }
.lg-meta .pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 99px; background: #fff; border: 1px solid var(--line); }
.lg-meta svg { width: 14px; height: 14px; }
.lg-body { flex: 1; width: 100%; max-width: 1160px; margin: 0 auto; padding: 28px 24px 56px; display: grid; gap: 28px; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 960px) { .lg-body { grid-template-columns: 280px minmax(0, 1fr); align-items: start; } }
.lg-side { display: none; }
@media (min-width: 960px) { .lg-side { display: flex; flex-direction: column; gap: 16px; position: sticky; top: 76px; max-height: calc(100vh - 96px); overflow-y: auto; } }
.lg-box { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 10px; }
.lg-box-title { font-size: 11px; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); padding: 6px 10px 8px; }
.lg-nav a { display: block; padding: 7px 10px; border-radius: 8px; font-size: 13px; line-height: 1.35; color: var(--ink-2); text-decoration: none; }
.lg-nav a:hover { background: var(--surface-3); color: var(--ink); }
.lg-nav a.is-active { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
.lg-toc a { display: block; padding: 5px 10px; font-size: 12.5px; color: var(--muted); text-decoration: none; border-left: 2px solid transparent; }
.lg-toc a:hover { color: var(--ink); border-left-color: var(--line); }
.lg-jump { display: block; width: 100%; }
@media (min-width: 960px) { .lg-jump { display: none; } }
.lg-doc { min-width: 0; background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 28px 20px; box-shadow: var(--shadow-1); }
@media (min-width: 640px) { .lg-doc { padding: 40px 48px; } }
.lg-prose { max-width: 740px; font-size: 15px; line-height: 1.75; color: #2B2B30; overflow-wrap: anywhere; }
.lg-prose > p:first-child { font-size: 15.5px; color: var(--ink-2); }
.lg-prose h2 { font-size: 18px; line-height: 1.35; font-weight: 650; letter-spacing: -0.015em; color: var(--ink); margin: 36px 0 12px; padding-top: 24px; border-top: 1px solid var(--line-2); scroll-margin-top: 84px; }
.lg-prose h2:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
.lg-prose h3 { font-size: 15px; font-weight: 600; color: var(--ink); margin: 22px 0 8px; }
.lg-prose p { margin: 0 0 12px; }
.lg-prose ul { margin: 4px 0 16px; padding-left: 0; list-style: none; }
.lg-prose li { position: relative; padding-left: 20px; margin: 6px 0; }
.lg-prose li::before { content: ''; position: absolute; left: 4px; top: .72em; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); opacity: .7; }
.lg-prose strong { color: var(--ink); font-weight: 600; }
.lg-cards { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 640px) { .lg-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.lg-card { display: flex; gap: 14px; align-items: flex-start; padding: 18px; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; color: inherit; text-decoration: none; transition: border-color .15s, box-shadow .15s, transform .15s; }
.lg-card:hover { border-color: #CFCDC6; box-shadow: 0 10px 28px -16px rgba(21,21,23,.3); transform: translateY(-1px); }
.lg-card .ic { width: 38px; height: 38px; flex-shrink: 0; display: grid; place-items: center; border-radius: 10px; background: var(--accent-soft); color: var(--accent); }
.lg-card .ic svg { width: 18px; height: 18px; }
.lg-card strong { display: block; font-size: 14.5px; font-weight: 600; color: var(--ink); }
.lg-card span { display: block; font-size: 12.5px; color: var(--muted); margin-top: 3px; }
.lg-foot { border-top: 1px solid var(--line); background: #fff; }
.lg-foot-in { max-width: 1160px; margin: 0 auto; padding: 20px 24px; display: flex; flex-wrap: wrap; gap: 10px 24px; justify-content: space-between; align-items: center; font-size: 12px; color: var(--faint); }
@media (max-width: 640px) {
  .lg-top-in, .lg-hero-in, .lg-body, .lg-foot-in { padding-left: 16px; padding-right: 16px; }
  .lg-hero-in { padding-top: 28px; padding-bottom: 22px; }
  .lg-hero h1 { font-size: 24px; }
  .lg-prose { font-size: 14.5px; }
  .lg-foot-in { flex-direction: column; text-align: center; padding-bottom: 96px; }
}
@media print {
  .lg { background: #fff; }
  .lg-top, .lg-side, .lg-jump, .lg-foot, .no-print { display: none !important; }
  .lg-hero { background: #fff; border: 0; }
  .lg-hero-in, .lg-body { padding: 0; }
  .lg-body { display: block; }
  .lg-doc { border: 0; box-shadow: none; padding: 0; }
  .lg-prose { max-width: none; font-size: 11pt; }
}

/* ---------- Sözleşme onay kutuları (kayıt, onay ekranı, kartla ödeme) ---------- */
.consent { border: 1px solid var(--line); border-radius: 12px; background: var(--surface); overflow: hidden; }
.consent-head { padding: 12px 14px; border-bottom: 1px solid var(--line-2); background: var(--surface-2); font-size: 12px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
.consent-row { display: flex; gap: 12px; align-items: flex-start; padding: 13px 14px; cursor: pointer; font-size: 13px; line-height: 1.5; color: var(--ink-2); transition: background .15s; }
.consent-row + .consent-row { border-top: 1px solid var(--line-2); }
.consent-row:hover { background: var(--surface-2); }
.consent-row input { width: 18px; height: 18px; margin-top: 1px; flex-shrink: 0; accent-color: var(--ink); }
.consent-row .tag { display: inline-block; margin-left: 4px; padding: 1px 7px; border-radius: 99px; font-size: 11px; font-weight: 500; vertical-align: 1px; }
.consent-row .tag.req { background: var(--accent-soft); color: var(--accent); }
.consent-row .tag.opt { background: var(--surface-3); color: var(--muted); }
.consent-row a { color: var(--ink); font-weight: 500; text-decoration: underline; text-underline-offset: 3px; text-decoration-color: #CFCDC6; }
.consent-row a:hover { text-decoration-color: var(--ink); }
.consent-row:has(input:checked) { background: #FCFBF9; }

/* ---------- Çerez onay penceresi ---------- */
.cc { position: fixed; left: 20px; bottom: 20px; z-index: 90; width: 440px; max-width: calc(100vw - 40px); opacity: 0; transform: translateY(14px); transition: opacity .25s, transform .25s; }
.cc.is-in { opacity: 1; transform: none; }
.cc[hidden] { display: none; }
.cc-card { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 30px 70px -24px rgba(21,21,23,.5), 0 2px 8px rgba(21,21,23,.06); padding: 20px; color: #26262A; }
.cc-title { display: flex; align-items: center; gap: 10px; font-weight: 650; font-size: 15.5px; color: var(--ink); margin-bottom: 8px; }
.cc-title::before { content: ''; width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px; background: var(--accent-soft) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23D2462F' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5'/%3E%3Cpath d='M8.5 8.5v.01M16 15.5v.01M12 12v.01M11 17v.01M7 14v.01'/%3E%3C/svg%3E") center/17px no-repeat; }
.cc-text { font-size: 13px; line-height: 1.6; color: var(--muted); }
.cc-text a { color: var(--ink); text-decoration: underline; text-underline-offset: 3px; }
.cc-prefs { margin-top: 14px; display: grid; gap: 8px; }
.cc-prefs[hidden] { display: none; }
.cc-row { display: flex; gap: 12px; justify-content: space-between; align-items: center; padding: 11px 12px; border: 1px solid var(--line-2); border-radius: 10px; font-size: 13px; cursor: pointer; background: var(--surface-2); }
.cc-row strong { color: var(--ink); font-weight: 600; }
.cc-row small { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; line-height: 1.45; }
.cc-row input { width: 18px; height: 18px; accent-color: var(--accent); flex-shrink: 0; }
.cc-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 16px; }
.cc-actions [hidden] { display: none; }
.cc-actions .btn { width: 100%; justify-content: center; height: 36px; }
.cc-actions [data-cc="prefs"], .cc-actions [data-cc="save"] { grid-column: 1 / -1; order: 3; height: 30px; }
@media (max-width: 520px) { .cc { left: 10px; right: 10px; bottom: 10px; width: auto; max-width: none; } .cc-card { padding: 16px; border-radius: 14px; } }
@media print { .cc { display: none !important; } }

/* ---------- Canlı bildirimler ---------- */
.notif-row { display: block; padding: 11px 14px; color: inherit; text-decoration: none; transition: background .3s; }
.notif-row:hover { background: var(--surface-2); }
.notif-row.is-unread { background: #FBF8F4; box-shadow: inset 2px 0 0 var(--accent); }
.live-toasts { position: fixed; right: 16px; top: 68px; z-index: 80; display: flex; flex-direction: column; gap: 8px; width: 360px; max-width: calc(100vw - 32px); }
.live-toast { display: flex; gap: 10px; align-items: flex-start; padding: 13px 12px 13px 14px; background: var(--surface); border: 1px solid var(--line); border-left: 3px solid var(--accent); border-radius: 10px; box-shadow: 0 14px 34px -14px rgba(21,21,23,.38); font-size: 13px; line-height: 1.45; color: #26262A; text-decoration: none; animation: live-in .25s ease-out; transition: opacity .35s, transform .35s; }
.live-toast span { flex: 1; min-width: 0; }
.live-toast button { border: 0; background: none; font-size: 18px; line-height: 1; color: var(--faint); cursor: pointer; padding: 0 2px; }
.live-toast.is-out { opacity: 0; transform: translateX(16px); }
@keyframes live-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
.live-banner { position: fixed; left: 50%; top: 14px; transform: translateX(-50%); z-index: 81; display: flex; gap: 12px; align-items: center; padding: 8px 8px 8px 16px; background: #151517; color: #F4F4F5; border-radius: 10px; font-size: 13px; box-shadow: 0 12px 32px -12px rgba(0,0,0,.45); max-width: calc(100vw - 32px); }

/* ---------- Profil ---------- */
.pbar { height: 8px; background: var(--line-2); border-radius: 99px; overflow: hidden; }
.pbar span { display: block; height: 100%; background: var(--accent); border-radius: 99px; transition: width .4s; }
.sticky-save { position: sticky; bottom: 12px; z-index: 5; display: flex; justify-content: flex-end; }
.sticky-save .btn { box-shadow: 0 10px 24px -10px rgba(21,21,23,.45); }
@media (max-width: 640px) { .sticky-save { bottom: 76px; } .sticky-save .btn { width: 100%; justify-content: center; } }

/* ---------- Mobilde tablo → kart ---------- */
@media (max-width: 640px) {
  .table-stack thead { display: none; }
  .table-stack, .table-stack tbody, .table-stack tr, .table-stack td { display: block; width: 100%; }
  .table-stack tr { padding: 12px 14px; border-bottom: 1px solid var(--line-2); }
  .table-stack td { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 5px 0 !important; border: 0 !important; text-align: right; }
  .table-stack td::before { content: attr(data-label); font-size: 12px; color: var(--muted); text-align: left; flex-shrink: 0; }
  .table-stack td[data-label=""]::before, .table-stack td:not([data-label])::before { content: none; }
  .table-stack td.ts-full { display: block; text-align: left; }
  .table-stack td.ts-full::before { display: block; margin-bottom: 4px; }
  .table-stack td .input, .table-stack td .input-group { max-width: 170px; margin-left: 0 !important; }
  .table-stack td.ts-full .input { max-width: none; width: 100%; }
  .table-stack tfoot { display: block; }
}

/* ---------- Hizmet seçim kartları (iş formu) mobilde ---------- */
@media (max-width: 640px) {
  .option-card:has(.stepper-input) { flex-wrap: wrap; row-gap: 10px; }
  .option-card:has(.stepper-input) > div:first-child { flex: 1 1 100% !important; }
  .option-card:has(.stepper-input) > div:nth-child(2) { margin-right: auto !important; text-align: left !important; }
}
CSS;
}
