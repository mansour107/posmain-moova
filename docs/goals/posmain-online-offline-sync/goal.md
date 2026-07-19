# POSMAIN lean reliable offline/cloud sync

## Outcome

Make POSMAIN production-ready as an offline-first shop system:

- Shop-to-cloud synchronization is automatic and reliable.
- Cloud-to-shop recovery is manual, guarded and resource-bounded.
- Managers can monitor the shop remotely from hosted data.
- A lost local installation can recover authoritative current state without clearing a working shop or replaying unnecessary full history.
- All logically required business domains are covered, including items, orders, payments, money tracking, shifts, drawers, logs, staff, customers, fulfillment, inventory, accounting, recipes, production and purchasing.

## Operating model

- Local POS remains authoritative while operating offline.
- Accepted local changes upload automatically when connectivity returns.
- Cloud retains complete history and current projections.
- Normal recovery restores current masters, open work, active operational state and recent closed history, then applies post-checkpoint changes.
- Older history is viewed remotely or hydrated separately at low priority when explicitly required.

## Non-negotiable safety

- No automatic reverse restore.
- No restore into a non-empty live shop unless a future entity-scoped conflict workflow is explicitly designed and certified.
- No stale event may overwrite newer state.
- Recovery must be resumable, bounded to one writer, small pages and bounded memory/response sizes.
- Large-volume testing runs on hosted/staging infrastructure, never on limited shop hardware or this Mac.
- Deploy hosted compatibility first, then client changes, with backup, dry-run and health checks.

## Current position

Forward synchronization is deployed and active for Focus House. Recovery-v2 resource limits and signed checkpoint work are locally implemented but not fully verified or deployed. The compact current state and exact remaining steps live in `state.yaml`.
