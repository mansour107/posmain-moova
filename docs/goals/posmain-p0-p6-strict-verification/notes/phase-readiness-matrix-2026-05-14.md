# Phase Readiness Matrix - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:19:18Z

Update note: this matrix is superseded for migration status by `current-blocker-refresh-and-moova-worker-tests-2026-05-14.md` and `fresh-completion-audit-2026-05-14.md`. Current `kody2` now has `order_fulfillment`, and `tools/run_migrations.php --dry-run` reports `0 pending sync schema change(s)`.

This matrix separates older phase-board completion receipts from the stricter current-state audit. A phase board marked `done` means that phase tranche was previously completed. It does not override fresh failures found by the P0-P6 strict verification run.

## Summary Decision

POSMAIN cannot be certified as fully complete or bug-free across phases 0 through 6 today.

The strongest current evidence is:

- Phase boards P0, P1, P2, P3, P4, P5, and P6 all have prior `done` status and final audit receipts.
- The current strict run has real passing evidence for PHP syntax, default PHPUnit with skips, most standalone/sync tests, authenticated GUI smoke, disposable GUI write flows, and live Moova flows under a temporary healthy runtime.
- The current strict run also has reproducible blockers: `js/pos_auto_lock.js` syntax, persistent Moova `redis=false`, Moova contract/write-inventory test failures, Moova widget degraded-state UX, and Phase 4 legacy schema fixture failures. The earlier pending `order_fulfillment` migration blocker has since cleared on current `kody2`.

## Phase Matrix

| Phase | Prior Board Status | Current Strict Evidence | Current Blockers / Limits | Strict Readiness |
| --- | --- | --- | --- | --- |
| Phase 0 - production readiness | `done`; final board says branch/baseline, write-surface map, D-class guardrails, offline prototype gating, and verification passed. | Strict run recovered/verified local POS HTTP 200, PHP syntax passed, backup dry-run passed, write-path audit tool ran. | Write-surface inventory has one current test failure around Moova bridge classification, which affects ownership/audit confidence more than the original Phase 0 guardrail slice. | Not independently red, but whole-system certification remains blocked. |
| Phase 1 - production foundation | `done`; final board says config/secrets, exposure hardening, session/password foundations, migration tracking, health/status, backup tooling, and backup/restore rehearsal completed. | Strict run verified backup dry-run and POS HTTP 200; migration runner works and now reports 0 pending sync schema changes on current `kody2`. | No fresh Phase 1 migration-tooling blocker remains, but whole-system certification remains blocked by non-Phase-1 issues. | Not independently red, but whole-system certification remains blocked. |
| Phase 2 - POS state | `done`; final board says active POS/table/payment/cancel/Moova endpoints use idempotency or canonical mutation service entrypoints, including Moova through `PosOrderMutationService`. | Disposable GUI write run proved save order, cash payment/receipt, table save, and shift close on a cloned DB. Targeted takeaway service test passed and classified the extra `ot_head` row as expected receipt voucher behavior. | Moova widget source-contract test currently expects endpoint-level `Moova*ApplyService` calls, while Phase 2 final state intentionally says endpoints use `PosOrderMutationService` wrappers. This needs an owner/Judge decision: stale contract vs endpoint implementation change. | Partially verified, blocked by contract-alignment decision. |
| Phase 3 - security hardening | `done`; final board says central auth/CSRF, permissions, audit/throttle schema/services, route guards, validators, SQL/upload hardening, login throttling, and safe errors are in place. Separate migration-apply board is also `done`. | Strict GUI smoke authenticated successfully; security migration apply board reports security tables applied and verified. | No fresh Phase 3-specific blocker was reproduced in the strict run. Browser write flows on live `kody2` stayed safety-gated, so this is not a full adversarial security retest. | Not independently red, but whole-system certification remains blocked. |
| Phase 4 - mid-scale product | `done`; final board says table move/merge, item availability, line notes/modifiers/KOT, payment/drawer/print/report/nutrition foundations, and Phase 4 verification passed. | Strict GUI smoke covered POS/table/item pages, and disposable GUI write covered table save and shift close. | Two direct schema smoke tests fail because Phase 4 legacy upgrade SQL uses `AFTER table_case` / `AFTER table_id` anchors that minimal fixture schemas lack. `js/pos_auto_lock.js` syntax is also a current frontend blocker near the POS surface. | Blocked until schema fixture compatibility and JS syntax are fixed or explicitly reclassified. |
| Phase 5 - Moova reliability | `done`; final board says local pilot tranche completed for mode decision, direct/queued behavior, token visibility, delivery foundation, cashier UX, and scenario docs. | Strict run proved live create/confirm/cancel/decline/edit/cancel-after-edit under a temporary Redis-capable foreground Moova runtime; visible full device token was present on integration page; unreachable proxy returned `MOOVA_UNREACHABLE`; later checks show current `kody2` has `order_fulfillment` and Moova branch ack/poll/apply worker tests passed on a disposable full clone. | Persistent LaunchAgent Moova runtime still returns `/readyz` 503 with `redis=false`; Moova widget contract and write-inventory tests still fail; offline widget panel can settle into empty-queue copy while service is unreachable. | Blocked. This is the heaviest current readiness gap. |
| Phase 6 - pilot QA | `done`; final board says seed tool, E2E command/matrix, load/concurrency checks, go-live checklist, daily review, and exit criteria docs completed. | Strict run exercised a substantial local browser/manual E2E subset and disposable write flows; live Moova E2E passed under temporary healthy runtime. | Phase 6 cannot be called pilot-ready while Phase 5 runtime/migration blockers, JS syntax, and contract/schema failures remain. Real current-DB mutating pilot actions were intentionally avoided except through disposable clone. | Blocked for pilot readiness. |

## Current Blockers By Phase Ownership

- Cross-cutting POS/frontend: `js/pos_auto_lock.js` fails syntax parsing.
- Phase 4/schema compatibility: minimal fixture migrations fail on missing `table_id` and `table_case` anchors.
- Phase 5/Moova: persistent `/readyz` is degraded with `redis=false`; Moova widget contract and write inventory are not green; offline widget state has a UX gap. The earlier `order_fulfillment` pending-migration blocker is cleared in the current runtime state.
- Phase 6/pilot readiness: depends on clearing the above blockers and then rerunning the strict pilot/E2E matrix.

## What Is Safe To Say Now

- The system has strong local evidence for many core cashier and Moova flows.
- The strict audit was useful and found real issues.
- The phases are not currently certifiable as "complete with no bugs" because current blockers reproduce.

## What Would Change The Verdict

The strict readiness verdict can move from blocked to pass only after an approved fix tranche:

1. Fix or reclassify the JS syntax failure and rerun the JS/browser POS checks.
2. Resolve the Moova facade-vs-endpoint contract decision and make contract/write-inventory tests green.
3. Fix Phase 4 legacy schema fixture compatibility or document and adjust fixture prerequisites.
4. Make the persistent Moova LaunchAgent runtime healthy with Redis enabled, then rerun Moova topology and live E2E.
5. Rerun the Phase 6 pilot/E2E checklist against the corrected current state.
