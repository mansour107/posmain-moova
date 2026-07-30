# POSMAIN commercial V1 local operational proof — 2026-07-29

## Decision and scope

This packet records local product-code and isolated-test-shop evidence for the
commercial V1 remediation plan. It does not certify a standing shop, hosted
environment, physical device fleet, or production deployment.

Local implementation decision: **the required product kernels and local
certification gates are green enough to prepare a clean release candidate and
enter hardware acceptance. Production launch remains no-go until the external
gates at the end of this document are closed against that immutable
candidate.**

All database-changing proof used generated fixtures or the isolated local
`posmain_qa_shiftfix_20260729` shop. No standing/customer/hosted database was
used or repaired.

Governing product policy used by the proof:

- tax is disabled and certified financial fixtures require zero tax;
- an authorized refund operator may select any active tender;
- `manual_external` refunds are settled by the authenticated operator's
  declaration, do not require an external reference, and do not affect the
  cash drawer;
- stock, recipes, and ledger accounting are independently optional;
- sales remain permissive at zero or negative stock;
- a tracked zero, missing, or negative balance is displayed to the cashier as
  `0` without changing the stored balance;
- recipe refunds are waste by default and restore stock only through an
  explicit `return_to_stock` decision.

## Real browser journeys and durable assertions

The local browser campaign ran against `http://127.0.0.1:8013` with
`posmain_qa_shiftfix_20260729`. The visible workflow was cross-checked with the
database rather than accepted from browser success alone.

| Journey | Visible result | Durable proof |
| --- | --- | --- |
| Cash takeaway | payment completed and receipt opened | paid takeaway orders, one active payment per completed sale, printed receipt job |
| Mixed tender | cashier completed one payment with two tenders | order `7`: net `48.00`; payments `cash=20.00`, `bank=28.00`; no duplicate payment |
| Dine-in/table | table selected, order paid, table released | orders `10`, `12`, and `18`: type `table`, paid/completed, net `48.00`, remaining `0.0000`, mutation version `2` |
| Delivery | customer and address accepted, fee included, sale completed | order `20`: type `delivery`, item total `52.0000`, net/payment `62.00`; fulfillment row contains test customer, prepaid mode, and `10.000` delivery fee |
| Authorized flexible refund | operator selected manual-bank refund without external reference | credit note `1` for order `20`, amount `12.00`, posted; payment refund is `manual_external`, `settled`, `external_reference=NULL`, declared by user `29`; order mutation version advanced to `3` |
| Zero-stock item | the item showed `0` and remained sellable | order `23`: paid/completed takeaway for `48.00`; one cash payment; `sale_direct` movement exists; raw inventory behavior was not corrected by the display |
| Duplicate click/retry | repeated finalization returned the committed result rather than another sale | the selected journey retained one order/payment/receipt set; atomic pack independently proves same-key replay and conflicting-payload rejection |
| Shift close | browser reported one order and `48.00` sales, then zero-sale close reported no sales | drawer session `2`: opening `467.000`, expected/counted `515.000`, difference `0.000`, closed; session `3`: opening/expected/counted `515.000`, difference `0.000`, closed |
| Role denial | unauthorized operational/admin route was visibly denied | denial contracts prove no mutation; security pack covers the complete classified route surface |
| Receipt/KDS | receipts opened and KDS received tickets from the real orders | receipt jobs for orders `1,3,5,7,10,12,18,20,23` are `printed` with one attempt; KDS tickets exist for the same real sale flow. Completion/retry semantics are additionally covered by the KDS service tests; physical output remains external |

The latest automatic shift-close outbox proof is:

- drawer movement event `22`, pending;
- drawer-session events `23`–`26`, versions `1`–`4`, pending;
- shift-close event `27`, version `2`, pending.

This proves that current complete schemas capture drawer/shift mutations in the
outbox even when the transport worker is disabled. A partial legacy schema
without both `sync_outbox` and `sync_branch_identity` retains its prior
behavior; explicit certified sync configuration still fails closed.

## Certification and regression results

All commands below were run locally after the final drawer/shift outbox change:

- security contract pack: `36/36`;
- financial pack: `20/20`;
- atomic mutation pack: `13/13`;
- commercial lifecycle pack: `15/15`;
- Stage 2 inventory/recipe/COGS pre-certification pack: `39/39`;
- master-data convergence pack: `7/7`;
- financial certification gate: all reconciliation differences `0`, no
  blockers, certified mode true;
- order-creation certification: green;
- sync reliability pack: offline failure retained one pending event, retry
  advanced it to acknowledged/applied without duplicate mutation;
- shift financial-integrity, shift receipt scope, shift-close atomic rollback,
  KDS ticket, order print payload, and print-job/reprint service tests: green.

The Stage 2 pack covers independent optional capability modes, exact
moving-average quantity/value updates, concurrent receipt/sale/transfer,
quantity-only outbox behavior, mapping-specific accounting failures, and the
recipe sale/refund/reversal truth table. It does not certify a particular
shop's catalog mappings, opening count, or historical reconciliation.

## Migration, recovery, and error behavior

- Empty-schema migration provisioning completed and recorded one applied ledger
  row per statement.
- A simulated interruption after DDL but before the migration-ledger completion
  resumed without repeating DDL.
- A changed migration checksum failed before the pending DDL.
- The additive/reviewed migration planner rejected ambiguous labels and
  destructive operations without the required opt-in/backup boundary.
- Exact interrupted branch restore resumed only with its bound snapshot,
  backup, profile, and checkpoint.
- The isolated restored QA database has `226` tables, `18` orders, `9`
  payments, `1` refund, `12` stock movements, `19` journal heads, and `21`
  outbox events.
- Backup file
  `/private/tmp/posmain-qa-commercial-v1-20260729.sql` has SHA-256
  `cf552a35a5f0aebaa322f65e45ddf6eceaf7e1bd89b6abd5d259fbd63431d55d`.
  Source/restore table counts and checksums matched during the restore drill.
- The restored representative clone migration dry-run reported `0` pending
  schema changes and the application health smoke returned HTTP `200`.
- Production error contracts hide raw PHP/SQL exceptions, retain server-side
  logging, attach support references, and report unsafe authentication
  configuration through readiness without leaking secrets.

The restore completed well inside one hour in this local environment. This does
not demonstrate the one-hour RTO or 15-minute RPO on the target branch server;
off-device encrypted scheduling and transaction-log retention remain
deployment work.

## Release spine result

The fail-closed release policy and deterministic builder tests are green. Two
artifacts built from the same fixture commit were byte-identical, and dirty or
untracked files could not enter the artifact.

The real `HEAD` preflight correctly remains blocked:

```text
source_commit=07d2a856fa1cda09303f9d36e60e962c11847797
blocker=dependency_lock_missing: composer.lock
```

`composer.lock` exists in the reviewed working tree, but it is not present in
the immutable source commit. The user explicitly prohibited commits and shared
history changes in this tranche, so no truthful final artifact or
certification receipt can be issued yet.

## Remaining no-go gates

Production use remains blocked until all of the following are completed against
one immutable candidate:

1. Review the complete dirty worktree, commit only intended release files,
   build the deterministic artifact from that commit, and issue a certification
   receipt bound to its artifact hash, migration checksums, schema fingerprint,
   branch/tenant identity, and passed gate versions.
2. Run fresh-install and representative-upgrade smoke on the exact packaged
   artifact, not the mutable development tree.
3. Certify the actual receipt printer, kitchen printer/KDS display, cash drawer,
   and two-till network. Prove disconnect/reconnect, visible retry, controlled
   reprint, and drawer pulse authorization without financial/stock duplication.
4. Configure and exercise target monitoring/alerts, encrypted off-device
   backups, 15-minute RPO retention, and one-hour bare-server RTO.
5. Reconcile the launch shop's own tenders, mappings, opening drawer, opening
   stock, catalog/recipe identities, and historical upgrade data. This is
   shop-launch work, not a product-code backfill in this packet.
6. Obtain the business/legal approval to operate with tax disabled.
7. Run the bounded one-branch/two-till/seven-day pilot. Stop immediately for
   duplicate or lost money/order effects, unexplained drawer variance,
   stock/COGS divergence, unauthorized mutation, silent sync divergence,
   unrecoverable device failure, or failed backup.

Until those gates pass, the correct overall verdict is **not production-ready;
locally ready for clean-RC preparation and controlled hardware/pilot
certification**.
