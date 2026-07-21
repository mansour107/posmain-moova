# Delivery operations

POSMAIN treats delivery as an order-fulfillment workflow with a separate worker ledger. Delivery workers are operational profiles, not users: creating a worker never grants login or POS access.

## Cashier flow

1. Select **Delivery**, the customer, and a configured delivery area. The server resolves the area and its customer fee; posted fee text is not authoritative.
2. Select **Prepaid** or **Collect on delivery**.
3. Optionally assign an available in-house worker. Assignment may be deferred, but an in-house order cannot move to `picked_up` without one.
4. The delivery board advances the order through `pending → accepted → preparing → ready → picked_up → delivered`.
5. At delivery, COD must equal the invoice's remaining balance. The transaction then closes the receivable, records courier-held cash, snapshots worker earnings, and posts the delivery accounting entries.

External couriers use `courier_source=external`; they do not require an in-house worker profile.

## Worker compensation

A worker has one active compensation plan reference. Plans support:

- no base, daily, weekly, or monthly base pay;
- the full customer delivery fee;
- a fixed amount per delivered order;
- a percentage of the customer delivery fee;
- a worker rate per delivery area;
- optional pass-through driver tips.

Delivered orders create one immutable `delivery_order_financials` snapshot. Later plan changes therefore do not rewrite historical earnings. A plan that has already produced earnings is locked; create a new plan version and assign it to the worker for future deliveries.

The management workspace shows each worker's active orders, open delivered-order earnings, tips, COD held, and open net balance. Base pay, bonuses, and deductions are added only in a dated settlement preview so the calculation remains auditable.

## Settlements and accounting

Settlement preview calculates:

`delivery earnings + eligible base pay + tips + bonuses - deductions - COD held`

The result states whether the shop pays the worker, the worker pays the shop, or the balance is zero. Finalization requires an idempotency key, prevents overlapping finalized periods, closes only the displayed open order snapshots, and posts one balanced journal. Cash settlements also require an open drawer and create a linked paid-in/paid-out drawer movement.

Accounting uses dedicated accounts for delivery expense, worker payables, cash held by couriers, delivery-fee revenue, and delivery adjustments. The original invoice journal is reused for COD orders; delivery completion does not recognize sales revenue twice.

Only an administrator can reverse a settlement. Reversal is append-only: it posts a linked reversing journal, creates the inverse drawer movement for cash settlements, marks the settlement reversed, and reopens its order snapshots for a corrected settlement. A reason is mandatory.

## Permissions

- `delivery.assign`: choose or change a worker before pickup.
- `delivery.dispatch`: operate the delivery board.
- `delivery.workers.manage`: create and maintain worker profiles.
- `delivery.compensation.manage`: manage compensation plans.
- `delivery.settlements.manage`: preview and finalize settlements.
- `delivery.settlements.reverse`: administrator-only reversal.
- `delivery.reports.view`: view worker delivery balances and history.

Manager presets receive worker, compensation, settlement, and reporting permissions. Cashier presets receive assignment only. Existing legacy role flags remain as compatibility fallbacks until capability presets are synchronized.

## Data and sync

Authoritative fulfillment state remains in `order_fulfillment`. Assignment changes have append-only history in `delivery_assignments`; accounting snapshots and settlements live in their dedicated tables. All new delivery tables are registered as operational row-sync domains and participate in branch push, cloud mirroring, and restore export using the existing generic sync path.

Apply schema changes with `php tools/run_migrations.php`. The management API returns `DELIVERY_SCHEMA_MIGRATIONS_PENDING` until the delivery schema is complete.
