# Moova Delivery Foundation

Phase 5 keeps existing Moova table-order behavior intact and adds structured fulfillment metadata beside the legacy POS order header.

## Decision

Use the additive `order_fulfillment` table for Phase 5 instead of adding delivery columns to `ot_head`.

Reasons:

- `ot_head` is a high-risk legacy write surface used by cashier save, payment, split, merge, and reporting flows.
- A side table lets Moova QR/table orders and future Moova delivery orders be distinguished without changing invoice math.
- The side table can be backfilled or rebuilt from Moova order links if reporting needs change.

## Stored Fields

`order_fulfillment` stores one row per POS order:

- `order_channel`: `cashier`, `moova_qr`, `moova_delivery`, and other planned channel values.
- `fulfillment_type`: `table`, `delivery`, `takeaway`, `pickup`, and other planned fulfillment values.
- `external_provider` and `external_order_id`: Moova/provider identity.
- `customer_name`, `customer_phone`, `customer_address`: structured delivery customer data.
- `delivery_zone`, `delivery_fee`, `delivery_status`, `promised_at`: delivery lifecycle metadata.
- `metadata_json`: small provider context such as branch, table, notes, and idempotency key.

## Moova Mapping

Moova new-order apply now calls `OrderFulfillmentService` after the POS order is created or when an idempotent duplicate is replayed.

- QR/table orders default to `order_channel=moova_qr` and `fulfillment_type=table`.
- Delivery-like payloads default to `order_channel=moova_delivery`, `fulfillment_type=delivery`, and `delivery_status=pending`.
- Customer and address fields are copied from direct-widget and queued-worker payload shapes through `MoovaLocalIngestService` before apply.

The write is intentionally optional if a shop has not run the Phase 5 schema yet. Missing `order_fulfillment` does not block the cashier order, but applying the schema is required before delivery reporting can rely on these fields.

## Compatibility

This tranche does not change POS totals, stock movement, payment state, table occupancy, or Moova idempotency links.

The delivery foundation is ready for reports to split:

- cashier/table orders
- Moova QR/table orders
- future Moova delivery orders

Full dispatch, driver lifecycle, and delivery-only order creation remain outside Phase 5.
