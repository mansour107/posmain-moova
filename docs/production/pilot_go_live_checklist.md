# POSMAIN Pilot Go-Live Checklist

Use this checklist before the first real shop service day. A branch is not ready for pilot go-live until every required item has an owner, timestamp, and evidence path outside git when it contains secrets, customer data, or shop-specific credentials.

This checklist depends on:

- `docs/production/deployment_profile.md`
- `docs/production/backup_restore_runbook.md`
- `docs/production/active_route_map.md`
- `docs/production/moova_mode_decision.md`
- `docs/production/moova_reliability_scenarios.md`
- `tools/branch_go_live_readiness.php`
- `tools/seed_demo_restaurant.php`

## Required Commands

Run these on staging or an approved local pilot rehearsal environment first:

```bash
php tools/run_migrations.php --dry-run
POSMAIN_ENV=test POSMAIN_PRODUCTION_MODE=0 php tools/seed_demo_restaurant.php --dry-run
POSMAIN_ENV=test POSMAIN_PRODUCTION_MODE=0 php tools/seed_demo_restaurant.php --apply --reset-demo --with-moova-dummy
php tools/backup_database.php --output=/var/backups/posmain/posmain-$(date +%Y%m%d-%H%M%S).sql
php tools/branch_go_live_readiness.php --backup-file=/absolute/path/to/verified-backup.sql
```

If Moova queued automatic apply is enabled, also pass the fresh cashier acceptance evidence file to `tools/branch_go_live_readiness.php`.

## Release And Configuration

| Item | Required evidence |
| --- | --- |
| Release branch clean | `git status --short` reviewed; only intended release files present |
| Release commit identified | Commit hash and deployment package path recorded |
| Production mode enabled | `POSMAIN_ENV=production` and `POSMAIN_PRODUCTION_MODE=1` confirmed |
| Branch identity configured | `POSMAIN_BRANCH_UUID`, `POSMAIN_BRANCH_NAME`, `POSMAIN_POS_TENANT`, and `POSMAIN_POS_BRANCH` recorded |
| Disruptive features intentionally set | Cloud sync, KDS, modifiers, nutrition, AI analytics, ETA e-receipt, and Moova mode flags reviewed |
| Migrations applied in staging | `php tools/run_migrations.php --dry-run` shows no unexpected destructive change |

## Backup And Restore

| Item | Required evidence |
| --- | --- |
| Backup file created | Absolute backup path, byte size, and timestamp recorded |
| Backup contains required tables | `document_counters`, Moova link tables, and sync tables included when enabled |
| Restore rehearsal done | Backup restored into staging or disposable DB, not over production |
| Post-restore smoke done | Login, POS open, last orders, payments, stock sample, journals, tables, and health checked |
| Daily backup scheduled | Job schedule, destination, retention, and owner recorded |
| Rollback plan documented | Exact restore target, maintenance-window rule, and operator approval path recorded |

## Web And Security Controls

| Item | Required evidence |
| --- | --- |
| Production guard enabled | Debug/setup/repair routes denied in production mode |
| Debug routes denied | D-class routes from `docs/production/active_route_map.md` checked |
| Upload PHP blocked | `uploads/.htaccess` and web-server rule checked |
| Private paths denied | `.git`, `backup`, `logs`, `db`, `tools`, `tests`, `docs`, `.env`, SQL, log, and backup files denied |
| Least privilege DB user | App user has only required app permissions; backup/restore uses separate operator credentials |
| Secrets outside web root | Real `.env`, branch-worker env, Moova tokens, and backup paths are not browser-readable |

## POS Device And Cashier Flow

| Item | Required evidence |
| --- | --- |
| POS devices tested | Every cashier/waiter device can reach the local branch URL |
| Login tested | Admin, manager, cashier, and waiter accounts verified |
| POS lock/unlock tested | Cashier lock and unlock works without losing cart state |
| Takeaway sale tested | Paid cash sale creates one order, one payment, and a matching receipt total |
| Table order tested | Save table, add item, partial payment, split payment, and cancel unpaid table tested |
| Manager approval tested | Void/cancel approval path tested with manager credentials |
| Drawer and shift tested | Open shift, close shift, and Z close totals recorded |
| Printer tested | Receipt view and KOT view print on the pilot hardware |
| Cashier training done | Training attendance, known fallback process, and support contact shared |

## Moova Pilot Gate

Use `direct_widget` mode for the first mid-scale pilot unless a later signed-off evidence packet explicitly enables queued worker apply.

| Item | Required evidence |
| --- | --- |
| Moova disabled or direct-widget configured | `docs/production/moova_mode_decision.md` flags reviewed |
| Dummy link tested in staging | `tools/seed_demo_restaurant.php --apply --reset-demo --with-moova-dummy` run against test data |
| Real link verified privately | Real token/link checked outside git |
| Accept and decline tested | Cashier can accept and decline representative orders |
| Duplicate/stale events checked | Phase 5 Moova reliability tests or cashier acceptance evidence attached |

## Support And Monitoring

| Item | Required evidence |
| --- | --- |
| Support process defined | Support contact, hours, escalation path, and incident log location recorded |
| Logs monitored | Health/log review owner and frequency recorded |
| Daily review template prepared | `docs/production/pilot_daily_review_template.md` copied for the branch |
| Exit criteria accepted | `docs/production/pilot_exit_criteria.md` reviewed with the operator |

## Go-Live Decision

| Field | Value |
| --- | --- |
| Branch/shop |
| Release commit |
| Backup path |
| Restore rehearsal path |
| Readiness command result |
| Moova mode |
| Pilot start date |
| Operator approval |
| Technical approval |
| Support owner |

Decision: `go` / `hold`

Hold reason and next action:
