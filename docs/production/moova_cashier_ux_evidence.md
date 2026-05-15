# Moova Cashier UX Evidence

Phase 5 keeps the existing Moova navbar bell and makes the cashier-facing failure states explicit.

## Covered States

- Pending badge: the bell badge still uses the combined pending new-order and edit/cancel command count.
- Sound and mute: the widget still stores mute state in local storage and loops the notification sound only while pending work exists.
- Customer review: the detail modal now renders structured customer name, phone, and address before the cashier confirms.
- Decline reason: rejecting a new order, edit, or cancellation requires a typed reason.
- Stale edit/cancel: `POS_ORDER_LINES_CHANGED` and idempotency conflicts show a cashier-readable stale-order message in Arabic and English.
- Invalid token/link: missing, invalid, unlinked, or unauthorized Moova device-token states show manager-actionable messages.
- Unreachable services: Moova/POS reachability codes still map to visible cashier messages instead of console-only failures.

## Compatibility

The widget still sends new orders through the direct-widget bridge. It now forwards structured customer and delivery fields to the parent POS frame, which lets `OrderFulfillmentService` persist them when the Phase 5 schema has been applied.

No cashier payment, table occupancy, stock, or order total behavior changes in this slice.
