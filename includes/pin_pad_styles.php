<?php
/**
 * Premium shared styles for local PIN authentication surfaces.
 */
?>
<style>
:root {
    --ppm-ink: #0f172a;
    --ppm-muted: #64748b;
    --ppm-line: #e2e8f0;
    --ppm-soft: #f8fafc;
    --ppm-accent: #942C21;
    --ppm-accent-2: #be3e31;
    --ppm-danger: #991b1b;
    --ppm-danger-bg: #fef2f2;
    --ppm-ok: #059669;
}
.ppm-page {
    min-height: 100vh;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(1200px 600px at 10% -10%, rgba(148,44,33,.18), transparent 55%),
        radial-gradient(900px 500px at 100% 0%, rgba(15,23,42,.12), transparent 50%),
        linear-gradient(160deg, #0b1220 0%, #1e293b 55%, #0f172a 100%);
    font-family: 'IBM Plex Sans Arabic', 'Inter', system-ui, sans-serif;
    color: var(--ppm-ink);
}
.ppm-shell { width: min(420px, 94vw); }
.ppm-card {
    background: rgba(255,255,255,.96);
    border: 1px solid rgba(255,255,255,.55);
    border-radius: 24px;
    padding: 2rem 1.5rem 1.5rem;
    box-shadow: 0 24px 60px rgba(2,6,23,.35);
    backdrop-filter: blur(18px);
}
.ppm-brand {
    width: 72px; height: auto; display: block; margin: 0 auto 1rem;
    filter: drop-shadow(0 6px 12px rgba(0,0,0,.12));
}
.ppm-title {
    margin: 0;
    text-align: center;
    font-size: 1.55rem;
    font-weight: 700;
    letter-spacing: -.02em;
}
.ppm-sub {
    margin: .4rem 0 0;
    text-align: center;
    color: var(--ppm-muted);
    font-size: .95rem;
}
.ppm-role-hint {
    margin: .55rem 0 0;
    text-align: center;
    color: #0f172a;
    font-size: .92rem;
    font-weight: 700;
    line-height: 1.35;
}
.ppm-dots {
    display: flex;
    justify-content: center;
    gap: .85rem;
    margin: 1.4rem 0 1.35rem;
    direction: ltr;
}
.ppm-dot {
    width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid #cbd5e1; background: #fff;
    transition: background .15s ease, border-color .15s ease, transform .15s ease;
}
.ppm-dot.filled {
    background: var(--ppm-accent);
    border-color: var(--ppm-accent);
    transform: scale(1.08);
}
.ppm-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .75rem;
}
.ppm-key {
    border: none;
    border-radius: 16px;
    min-height: 64px;
    min-width: 64px;
    font-size: 1.35rem;
    font-weight: 650;
    background: var(--ppm-soft);
    color: var(--ppm-ink);
    cursor: pointer;
    transition: transform .1s ease, background .15s ease, box-shadow .15s ease;
}
.ppm-key:hover { background: #eef2f7; }
.ppm-key:active { transform: scale(.97); }
.ppm-key:focus-visible {
    outline: 3px solid rgba(148,44,33,.35);
    outline-offset: 2px;
}
.ppm-key.action { background: #fee2e2; color: var(--ppm-accent); font-size: 1rem; }
.ppm-key.enter {
    background: linear-gradient(135deg, var(--ppm-accent), var(--ppm-accent-2));
    color: #fff;
    box-shadow: 0 10px 24px rgba(148,44,33,.28);
}
.ppm-error {
    background: var(--ppm-danger-bg);
    color: var(--ppm-danger);
    border: 1px solid #fecaca;
    border-radius: 12px;
    padding: .75rem;
    margin: 1rem 0 0;
    text-align: center;
    font-size: .92rem;
}
.ppm-error.is-hidden,
.is-hidden,
.visually-hidden {
    position: absolute !important;
    width: 1px; height: 1px;
    padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0,0,0,0);
    white-space: nowrap; border: 0;
}
.ppm-error.is-hidden { position: static !important; display: none !important; width: auto; height: auto; }
.ppm-banner {
    position: sticky; top: 0; z-index: 1050;
    background: linear-gradient(90deg, #7f1d1d, #991b1b);
    color: #fff;
    padding: .7rem 1rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    box-shadow: 0 8px 24px rgba(127,29,29,.25);
}
.ppm-banner a { color: #fff; font-weight: 700; text-decoration: underline; }
.ppm-banner button {
    border: 1px solid rgba(255,255,255,.35);
    background: transparent; color: #fff;
    border-radius: 999px; padding: .25rem .75rem; cursor: pointer;
}
.ppm-busy .ppm-key { pointer-events: none; opacity: .7; }
.ppm-modal {
    position: fixed;
    inset: 0;
    z-index: 10060;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    font-family: 'IBM Plex Sans Arabic', 'Inter', system-ui, sans-serif;
}
.ppm-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, .72);
    backdrop-filter: blur(6px);
}
.ppm-modal__dialog {
    position: relative;
    width: min(420px, 94vw);
    z-index: 1;
}
.ppm-modal__dialog .ppm-shell { width: 100%; }
.ppm-modal__cancel {
    display: block;
    width: 100%;
    margin-top: .85rem;
    border: 0;
    border-radius: 14px;
    padding: .85rem 1rem;
    background: rgba(255,255,255,.92);
    color: #475569;
    font-weight: 600;
    font-size: .95rem;
    cursor: pointer;
    box-shadow: 0 10px 28px rgba(2,6,23,.2);
}
.ppm-modal__cancel:hover { color: #0f172a; }
.ppm-workspace-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}
.ppm-workspace-card {
    display: block;
    text-decoration: none;
    background: #fff;
    border: 1px solid var(--ppm-line);
    border-radius: 18px;
    padding: 1.5rem 1rem;
    text-align: center;
    color: var(--ppm-ink);
    box-shadow: 0 10px 30px rgba(15, 23, 42, .08);
    transition: transform .15s ease, box-shadow .15s ease;
}
.ppm-workspace-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 36px rgba(15, 23, 42, .12);
    color: var(--ppm-ink);
}
.ppm-workspace-card i { color: var(--ppm-accent); font-size: 1.75rem; margin-bottom: .65rem; }
@media (max-width: 480px) {
    .ppm-card { padding: 1.5rem 1rem 1.25rem; border-radius: 20px; }
    .ppm-key { min-height: 58px; font-size: 1.2rem; }
}
@media (prefers-reduced-motion: reduce) {
    .ppm-key, .ppm-dot, .ppm-workspace-card { transition: none !important; }
}

/* POS terminal unlock — cooler slate/teal, distinct from primary login terracotta */
.ppm-page--pos-unlock {
    background:
        radial-gradient(1000px 520px at 85% -15%, rgba(14, 165, 233, .16), transparent 55%),
        radial-gradient(800px 480px at 0% 100%, rgba(45, 212, 191, .10), transparent 50%),
        linear-gradient(165deg, #07111f 0%, #0f2744 48%, #0a1628 100%);
}
.ppm-page--pos-unlock .ppm-card {
    border: 1px solid rgba(148, 163, 184, .28);
    background: rgba(248, 250, 252, .97);
    box-shadow: 0 28px 64px rgba(2, 12, 27, .45);
}
.ppm-page--pos-unlock .ppm-eyebrow {
    display: inline-block;
    margin: 0 auto .65rem;
    padding: .28rem .7rem;
    border-radius: 999px;
    background: rgba(14, 165, 233, .12);
    color: #0369a1;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .04em;
}
.ppm-page--pos-unlock .ppm-title {
    font-size: 1.4rem;
    font-weight: 750;
}
.ppm-page--pos-unlock .ppm-dot.filled {
    background: #0ea5e9;
    border-color: #0284c7;
}
.ppm-page--pos-unlock .ppm-key:focus-visible {
    outline-color: rgba(14, 165, 233, .45);
}
.ppm-page--pos-unlock .ppm-key.action {
    background: #e0f2fe;
    color: #0369a1;
}
.ppm-page--pos-unlock .ppm-key.enter {
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
    box-shadow: 0 10px 24px rgba(2, 132, 199, .32);
}
</style>
