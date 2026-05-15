# POSMAIN Pilot Daily Review Template

Create one copy per pilot service day. Keep completed copies with the shop evidence packet, not in git if they contain customer names, payment details, staff names, logs, screenshots, or live credentials.

## Service Day

| Field | Value |
| --- | --- |
| Date |
| Branch/shop |
| Reviewer |
| Service window |
| Release commit/version |
| POS devices active |
| Moova mode |
| Backup file for the day |

## Daily Metrics

| Metric | Count/value | Evidence source | Notes |
| --- | ---: | --- | --- |
| Order count |  | Z report / order report |  |
| Failed transactions |  | cashier log / app logs |  |
| Payment mismatches |  | Z report vs cash/card/wallet totals |  |
| Cancelled/voided orders |  | manager approval / order audit |  |
| Printer failures |  | cashier report / print queue |  |
| Table stuck incidents |  | tables screen / order status audit |  |
| Stock mismatches |  | stock sample audit |  |
| Moova failed events |  | Moova widget / local logs |  |
| Moova duplicate events |  | Moova idempotency/order links |  |
| Cashier complaints |  | support log |  |
| Average POS response time |  | browser/tool measurement |  |

## Reconciliation

| Check | Pass/Fail | Evidence | Notes |
| --- | --- | --- | --- |
| Z report matches cash total within accepted variance |  |  |  |
| Z report matches card total within accepted variance |  |  |  |
| Z report matches wallet total within accepted variance |  |  |  |
| Receipt totals match sampled orders |  |  |  |
| No duplicate order caused by retry |  |  |  |
| No duplicate payment caused by retry |  |  |  |
| No negative unexpected remaining amount |  |  |  |
| No table remains occupied after paid/cancelled order |  |  |  |
| Backup file exists and is readable |  |  |  |

## Incidents

| Time | Severity | Area | Description | Customer impact | Action taken | Owner | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
|  |  | POS / payment / table / printer / stock / Moova / other |  |  |  |  |  |

Severity guide:

- Critical: data loss, duplicate charge/payment, missing sale, unrecoverable cashier outage.
- High: wrong totals, blocked payments, stuck tables during service, repeated printer failure.
- Medium: workaround needed but sales continued.
- Low: cosmetic issue, training issue, or single recoverable delay.

## Cashier Feedback

| Question | Notes |
| --- | --- |
| What slowed the cashier team today? |
| Which step needed developer or manager help? |
| Which screen or receipt confused staff or customers? |
| What should be trained before tomorrow? |

## Daily Decision

| Field | Value |
| --- | --- |
| Continue pilot tomorrow? `yes` / `hold` |
| Hold reason |
| Required fix before next service day |
| Owner |
| Review completed at |

