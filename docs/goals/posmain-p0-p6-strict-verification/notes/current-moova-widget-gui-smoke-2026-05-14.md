# Current Moova Widget GUI Smoke - 2026-05-14

## Purpose

Fresh browser-level inspection of the Moova widget as embedded in the live POS page, without saving orders, confirming payments, applying migrations, or editing code.

## Setup

Target: `http://127.0.0.1:8010/pos_barcode.php`

The in-app browser pane was not available for this continuation, so this smoke used a fallback browser automation context. That context did not already have a POS session, so it logged in as `omar`, then unlocked the POS screen with the local POS code.

## POS Page Evidence

After unlock:

- URL: `http://127.0.0.1:8010/pos_barcode.php`
- Title: `نظام الإدارة`
- Cashier visible: `الكاشير: omar`
- Shift visible: `الشيفت مفتوح`
- Widget iframe present: `#cofe-pos-widget`
- Iframe title: `Cofe POS Widget`
- Iframe source: `moova_pos_widget.php`
- Iframe size: about `74 x 38`

## Widget Evidence

Frame URL:

```text
http://127.0.0.1:8010/moova_pos_widget.php
```

Before opening the bell:

```text
تشغيل صوت الإشعارات
الطلبات المعلّقة
بانتظار الطلبات
```

Badge:

```text
٠
```

After opening the bell panel:

```text
تشغيل صوت الإشعارات
الطلبات المعلّقة
بانتظار الطلبات
لا توجد طلبات بانتظار الموافقة
القائمة فارغة الآن. ستظهر طلبات نقاط البيع الجديدة هنا تلقائياً.
```

Browser console errors captured during this smoke:

```json
[]
```

## Current Moova Runtime Health

Immediately after the widget smoke:

```sh
curl -s -o /tmp/moova-widget-smoke-readyz.json -w '%{http_code}\n' http://127.0.0.1:3001/readyz && cat /tmp/moova-widget-smoke-readyz.json
```

Result:

```text
503
{"ok":false,"database":true,"redis":false}
```

## Verdict

The embedded widget iframe loads and opens cleanly from the cashier page, with no browser console errors in this smoke.

However, while the persistent Moova runtime is degraded (`redis=false`), the cashier-facing panel shows an empty queue message rather than a clear service-degraded or reconnect state. This preserves the earlier offline/degraded-widget UX blocker.

This strengthens GUI evidence but does not clear the overall strict P0-P6 certification.

## Safety Notes

- No order was saved.
- No payment was confirmed.
- No shift was closed.
- No migration was applied.
- No implementation files, tests, or runtime configs were edited.
