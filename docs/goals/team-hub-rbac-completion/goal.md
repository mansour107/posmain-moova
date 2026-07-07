# Team Hub + RBAC Completion Tranche

## Objective

Finish the remaining Team Hub / RBAC modernization plan: thin AJAX dispatcher, legacy route retirement, changed-file PHP lint in CI, full verification, and Playwright coverage — while keeping admin/owner PIN reveal on Team Hub cards.

## Goal Kind

`specific`

## Current Tranche

1. Fix any syntax/regression blockers (e.g. `add_account.php`)
2. Add `scripts/lint_changed_php.sh` + contract test in security pack
3. Refactor `ajax/team_hub.php` into thin dispatcher via `TeamHubMutationService`
4. Retire `do/doadd_role.php` mega-form (redirect to Team Hub)
5. Add PHP + Playwright tests for admin PIN reveal vs non-admin masking
6. Run security pack, parity check, migration dry-run, and available PHPUnit/Playwright suites

## Non-Negotiable Constraints

- Admin/owner sessions may view staff PINs on Team Hub cards (product decision)
- Non-admin `users.manage` holders see masked PINs only
- Security contract pack must stay green
- No `SyncSchemaManager::apply()` on normal page loads
- Do not drop `usr_pwrs` columns automatically in this tranche (use prune tool manually after parity)

## Stop Rule

Stop when Judge audit passes: blockers fixed, dispatcher refactored, lint contract in pack, tests pass, verification receipts recorded.

## Canonical Board

`docs/goals/team-hub-rbac-completion/state.yaml`

## Run Command

```text
/goal Follow docs/goals/team-hub-rbac-completion/goal.md
```
