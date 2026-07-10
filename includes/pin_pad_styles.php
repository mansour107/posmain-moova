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
.ppm-offline-banner {
    display: none;
    margin: 0 0 .85rem;
    background: #fff7ed;
    color: #9a3412;
    border: 1px solid #fed7aa;
    border-radius: 12px;
    padding: .65rem .85rem;
    text-align: center;
    font-size: .9rem;
}
.ppm-offline-banner.is-visible { display: block; }
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
</style>
