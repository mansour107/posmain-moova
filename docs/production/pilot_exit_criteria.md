# POSMAIN Pilot Exit Criteria

The branch can move from controlled pilot to wider mid-scale rollout only when the criteria below are proven with real service-day evidence. Local tests, demo seed data, and staging rehearsals are necessary preparation, but they do not replace live pilot evidence.

## Required Evidence Packet

Keep the evidence packet outside git when it contains private shop data. It should include:

- The release commit and deployed package identifier.
- The completed `docs/production/pilot_go_live_checklist.md`.
- Seven completed copies of `docs/production/pilot_daily_review_template.md`.
- Backup file paths and restore rehearsal notes.
- Z reports and cash/card/wallet reconciliation summaries.
- Sample stock audit results.
- Receipt/order total samples.
- Incident log and resolution notes.
- Moova cashier acceptance evidence if Moova was enabled.

## Exit Criteria

| Criterion | Pass condition | Evidence |
| --- | --- | --- |
| Seven consecutive service days without critical data loss | No missing orders, missing payments, unrecoverable stock corruption, or restore failure for 7 consecutive service days | Daily review packet and incident log |
| Z report matches cash/card/wallet totals within accepted variance | Cash, card, and wallet totals reconcile to the operator-approved variance every pilot day | Z report and tender reconciliation |
| No duplicate order or payment caused by retry | Repeated submit, network retry, refresh, and Moova duplicate delivery do not create duplicate POS orders or duplicate payments | Idempotency/order audit and incident log |
| Stock movement passes sample audit | Sampled sold items have reasonable stock movement and no unexplained negative or missing quantity | Stock sample worksheet |
| Receipt totals match orders | Sampled receipt/KOT/order totals match item lines, modifiers, discounts, tax, fees, and payments | Receipt/order sample set |
| Cashiers can use it without developer intervention | Cashier team completes normal service using documented support/escalation, not developer-led repair | Training record and daily feedback |
| Backup/restore remains valid | Backup exists for each service day and at least one restore rehearsal remains valid for the release | Backup/restore evidence |

## Automatic Hold Conditions

Do not expand beyond controlled pilot while any of these are open:

- Critical data loss or unreconciled duplicate payment.
- Z report mismatch outside accepted variance without documented correction.
- Table stuck occupied after paid/cancelled order more than once without a verified fix.
- Repeated printer failure that prevents normal service.
- Cashier flow requires developer intervention during normal service.
- Backup file is missing, unreadable, or restore rehearsal is stale.
- Moova duplicate, stale edit/cancel, or token/link issue remains unresolved when Moova is enabled.

## Expansion Decision

| Field | Value |
| --- | --- |
| Branch/shop |
| Pilot date range |
| Release commit |
| Consecutive clean service days |
| Accepted payment variance |
| Critical incidents open |
| Backup/restore approved by |
| Operations approval |
| Technical approval |

Decision: `expand` / `extend pilot` / `hold`

Decision notes:
