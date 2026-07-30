# Silent printing operations

## Release switch

Silent printing is intentionally disabled by default.

- `POSMAIN_PRINT_MODE=legacy` keeps the existing browser print dialog.
- `POSMAIN_PRINT_MODE=silent` replaces `window.print()` with durable server-side dispatch.
- The mode is deployment configuration only. It is displayed read-only in the printer center and cannot be changed from the UI.

Do not enable silent mode until printer routes are configured, the reliable print-job schema is installed, and the physical-printer acceptance gate below has passed.

## Printer center

Owners and managers with `printers.manage` use **Printers and order routes** from the main sidebar.

Each active printer has:

- a role: receipt, kitchen, label, or other;
- a transport: local file simulator or direct network;
- one or more functions: receipt, kitchen ticket, report, label, or document;
- for kitchen tickets, either all categories or an explicit category list;
- a paper width of 58 mm or 80 mm.

Kitchen routing is fail-closed. Every line in a kitchen ticket must match at least one active route before any print job is created. A category may intentionally be assigned to multiple printers when duplicate kitchen copies are required.

The simulator key is a safe folder name, not an arbitrary filesystem path. The base simulator directory is controlled only by `POSMAIN_PRINT_SIMULATOR_DIR`.

## Delivery model

One user print intent receives one browser request key. The server derives one idempotency key per target printer and function. Repeated delivery of the same request returns the existing durable jobs.

The browser collapses simultaneous clicks. If the HTTP response is lost, it retains the request key so the cashier retry resolves to the original jobs. The file simulator also recognizes an already accepted job and returns its existing delivery receipt instead of writing another artifact.

Jobs move through `queued`, `printed`, `failed`, or `cancelled`. A worker claim has a bounded lease so two terminals cannot deliver the same queued job concurrently.

Direct dispatch attempts delivery during the user request. Retry-safe failures that occurred before any network bytes were written remain queued. Run the worker regularly to process those jobs:

```sh
php tools/print_worker.php --limit=50
```

Production must run that command through a supervised scheduler or service and alert when queued or failed jobs exceed the operating threshold.

## Simulator proof

For local testing:

1. Set `POSMAIN_PRINT_MODE=silent`.
2. Set `POSMAIN_PRINT_SIMULATOR_DIR` to a writable, test-only directory.
3. Create file printers in the printer center.
4. Assign receipt and kitchen routes. Divide kitchen categories between at least two simulator printers.
5. Use each printer card's test action.
6. Print a real local test receipt and a kitchen order containing lines from both category groups.
7. Confirm one `.txt`, one `.bin`, and one `.json` delivery receipt per durable print job.
8. Confirm each kitchen text artifact contains only the categories assigned to that printer.
9. Double-click Print and confirm only one durable job and one artifact per target printer.
10. Repeat a claimed job after simulated response loss and confirm the simulator reports `replayed: true` without another artifact.

Simulator acceptance proves routing, rendering, persistence, idempotency, and worker behavior. It does not prove a physical printer's character encoding, paper width, cutter, cash-drawer pulse, network stability, or firmware behavior.

## Physical-printer acceptance gate

Network delivery uses raw ESC/POS over TCP, normally port 9100. Before silent mode is enabled at a shop:

1. Test every configured printer on the shop LAN from the actual POS host.
2. Print Arabic and English item names, modifiers, notes, quantities, totals, and long receipts.
3. Verify 58/80 mm layout, wrapping, cutter behavior, and any required cash-drawer pulse.
4. Disconnect the printer before connect, during delivery, and immediately after delivery.
5. Confirm a pre-connect failure queues one safe retry.
6. Confirm a partial/uncertain write is marked failed for manager review and is never automatically reprinted.
7. Verify receipt, food, drinks, report, and label routes with the exact production printer models.
8. Run at least 100 mixed prints, including rapid double-clicks and two terminals printing simultaneously, with no duplicate or missing output.
9. Record the printer model, firmware, IP, route configuration, test order IDs, job IDs, and operator sign-off.

Any failed step is a no-go for silent mode. Leave `POSMAIN_PRINT_MODE=legacy` until corrected.

## Failure handling

- `PRINT_ROUTE_NOT_CONFIGURED`: no active printer serves the requested function.
- `PRINT_KOT_LINE_UNROUTED`: at least one kitchen line has no category route. No kitchen job is created.
- `PRINT_NETWORK_CONNECT_FAILED`: no bytes were accepted; the job may retry.
- `PRINT_NETWORK_DELIVERY_UNCERTAIN`: delivery may have partially reached the device. The job fails closed and requires a manager to inspect the printer before choosing Retry.
- `SILENT_PRINT_BROWSER_PRINTER_UNSUPPORTED`: a legacy browser printer cannot be used as a silent transport.

The printer center shows recent jobs, attempts, and retry actions. Operators must compare any uncertain job with physical output before retrying it.

## Compatibility and rollback

Changing back to `POSMAIN_PRINT_MODE=legacy` restores native browser dialogs without deleting printer configuration or print history. Existing print buttons and templates remain the entry points in both modes.

Do not delete print-job history during an incident. Preserve the database, simulator delivery receipts where used, application logs, and the affected physical output for reconciliation.
