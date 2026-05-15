# Moova Live E2E Receipt - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T10:40:00Z

## Runtime Unblock

The persistent LaunchAgent `com.codex.cofe-order-3001` was booted out for the test window because it starts Moova from `/Users/Shared/cofe_order_runtime/.env.local` with `NODE_ENV=test`. In that mode, `lib/redis.js` returns no Redis client, while `/readyz` still requires Redis when `REDIS_URL` is set.

A foreground Moova runtime was started with the same local env values but `NODE_ENV=production`, `OUTBOX_WORKER_V2_ENABLED=0`, `START_WORKER=false`, and push disabled. Startup checks reported database and Redis healthy.

## Readiness Evidence

- `curl http://127.0.0.1:3001/readyz`: HTTP 200, `{"ok":true,"database":true,"redis":true}`.
- `curl http://127.0.0.1:3001/pos-widget`: HTTP 200, POS widget HTML served.
- `php tools/moova_local_topology_check.php`: `ok: true`, POS HTTP ok, Moova readyz ok, Moova widget ok, Docker dependencies healthy, POS DB active link visible.
- `node scripts/seed-posmain-local-shop.js`: refreshed the local Moova shop/device/menu seed for POSMAIN using the known device token ending `c3cb`.

## Live Order Create Through POS Widget

Created Moova order `87195d0d-2dea-481b-b400-a56b4d4ccbbb` through the live Moova API for seeded item `TEMP TEST شاي`.

Moova pending endpoint showed one matching draft:

- Draft `085bf2ea-8d39-4227-a627-45e4edcb585e`
- Status `pending_confirmation`
- Total `1800` cents

Browser-driven POS widget evidence:

- POS page `pos_barcode.php` loaded authenticated as `omar`.
- Widget showed one pending table order in Arabic.
- Clicking `تأكيد` opened the required notes review.
- Clicking `تأكيد الملاحظات وطلب الأوردر` accepted the order through the POS iframe host path.

Post-accept evidence:

- Moova order status: `CONFIRMED`.
- Moova draft status: `ACKED`.
- Draft provider order id: `101`.
- Moova pending endpoint returned empty `drafts` and `commands`.
- POS `moova_pos_order_links` linked Moova order to POS order `101` with `provider_status=updated`.
- POS `fat_details` added row `556` for item `2`, qty `1`, price `18`, at `2026-05-14 10:35:55`.

## Live Cancel Through POS Widget

Submitted patron cancellation request for the same Moova order using client status token `dab4ccf8-f860-435c-a68e-6a1e04f2b717`.

Browser-driven POS widget evidence:

- Widget showed `طلب إلغاء أوردر`, order `101`, item `TEMP TEST شاي`.
- Clicking `تأكيد الإلغاء` applied the cancellation through `ajax/moova_change_order.php`.

Post-cancel evidence:

- Moova order status: `DISMISSED`.
- Moova dismissed reason: `Cancelled by customer`.
- Moova bridge command status: `COMPLETED`.
- POS `moova_pos_order_change_links` row `213` has `change_type=cancel`, `provider_status=cancelled`, `syncStatus=applied`.
- POS `moova_pos_order_links` provider status became `cancelled`.
- POS `fat_details` row `556` was marked `isdeleted=1`, `qty_out=0`, `det_value=0`.
- Moova pending endpoint returned empty `drafts` and `commands`.

## Remaining Caveat

This proves live create and cancel can pass under a Redis-capable foreground Moova runtime. It does not remove the persistent runtime blocker: the installed LaunchAgent still starts the old test-mode command unless its configuration is updated.

After the foreground test session was stopped, the original LaunchAgent was restored. The persistent service again listened on port `3001`, and `/readyz` returned `{"ok":false,"database":true,"redis":false}`.
