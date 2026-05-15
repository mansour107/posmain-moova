# Moova Live Extra E2E Receipt - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T10:55:00Z

## Runtime

The extra live Moova tests ran under the same temporary foreground runtime described in `moova-live-e2e-2026-05-14.md`: `/Users/Shared/cofe_order_runtime/server.js` with `NODE_ENV=production`, Redis enabled, workers/push disabled, and no implementation edits.

After these tests, the foreground process was stopped and `com.codex.cofe-order-3001` was restored. The persistent service again listened on port `3001`, and `/readyz` returned `{"ok":false,"database":true,"redis":false}`.

## Live Decline

Created Moova order `2d286f7a-ffe9-4eaf-a279-aeafad0babc7` with client status token `69c1af1a-ed09-49ac-a72a-ad435d354730`.

Browser-driven POS widget evidence:

- Widget showed seeded item `TEMP TEST مياه` for table order review.
- Clicking `رفض` opened the required decline modal.
- Submitting reason `POSMAIN strict test decline reason` completed the cashier decline flow.

Post-decline evidence:

- Moova order status became `DISMISSED`.
- Dismissed reason was `POSMAIN strict test decline reason`.
- Draft `b00440a8-bf0b-4f4d-8902-7f614137b991` became `DECLINED`.
- Pending endpoint returned empty.
- No POS `moova_pos_order_links` row was created for that Moova order, as expected for pre-POS-creation decline.

## Live Edit

A carried-over edit candidate `5210adfd-5377-4bce-af05-1b72d81bebda` was confirmed through the widget, but its later patron edit request returned `{"error":"Edit window has expired."}`. That was treated as a valid stale/expired-window response, not as edit success.

A fresh edit candidate was then created:

- Moova order `7241be85-c2c5-4d28-8b85-6b5b679dba53`
- Client status token `0e24a1f7-ec14-4742-8963-74d494a70fbb`
- Initial item `TEMP TEST شاي`
- Fresh pending draft `9b092760-aef2-4ad4-912f-c4a8f1114cb3`

Browser-driven POS widget evidence:

- Widget showed one pending table order with `TEMP TEST شاي`.
- Clicking `تأكيد` required notes review.
- Clicking `تأكيد الملاحظات وطلب الأوردر` confirmed it.

Post-confirm evidence:

- Moova order status became `CONFIRMED`.
- Draft `9b092760-aef2-4ad4-912f-c4a8f1114cb3` became `ACKED`.
- POS link row `250` mapped Moova order to POS order `101` with `provider_status=updated`.

Submitted a patron edit to replace the item with `TEMP TEST عصير مانجو`.

Browser-driven POS widget evidence:

- Widget showed `طلب تعديل أوردر`, `أوردر 101`, item `TEMP TEST عصير مانجو`.
- Clicking `تأكيد التعديل` cleared the queue.

Post-edit evidence:

- Moova recorded `order.edit.requested` event `dce8021d-c25d-422e-824f-772f0e1ca2ae`.
- Moova recorded `order.edit.accepted` and `order.edited`.
- Moova order total became `4500.00`, with item `TEMP TEST عصير مانجو`.
- POS bridge command `1d64cfce-b674-442b-8d76-15fd370a4af1` completed with action `edit` and `syncStatus=applied`.
- POS `moova_pos_order_change_links` row `214` has `change_type=edit`, `provider_status=edited`, and `syncStatus=applied`.
- POS `fat_details` row `558` inserted item `3`, qty `1`, price `45`, det value `45`.

## Live Cancel After Edit

Submitted a patron cancellation request for the edited order.

Browser-driven POS widget evidence:

- Widget showed `طلب إلغاء أوردر`, `أوردر 101`, item `TEMP TEST عصير مانجو`.
- Clicking `تأكيد الإلغاء` cleared the queue.

Post-cancel evidence:

- Moova order status became `DISMISSED`.
- Dismissed reason was `Cancelled by customer`.
- Moova recorded cancel request event `c53382c8-4c54-4616-a4a3-d6e513824794`, `order.cancel.accepted`, and `order.cancelled_by_customer`.
- POS bridge command `2e748743-0913-4682-bef4-55357c66f5d3` completed with action `cancel` and `syncStatus=applied`.
- POS `moova_pos_order_change_links` row `215` has `change_type=cancel`, `provider_status=cancelled`, and `syncStatus=applied`.
- POS order link row `250` ended with `provider_status=cancelled`.
- POS `fat_details` row `558` was marked `isdeleted=1`, `qty_out=0`, `det_value=0`.

## Remaining Caveats

- These extra live tests prove decline, edit, and cancel-after-edit under a Redis-capable foreground runtime.
- They do not fix the persistent LaunchAgent runtime, which still reports `redis=false`.
- They do not clear the existing scripted blockers: JS syntax, pending migration, Moova contract/write-surface tests, and minimal-fixture schema smoke failures.
- Real stale-state conflict and offline/unreachable recovery were not exercised against the live POS widget.
