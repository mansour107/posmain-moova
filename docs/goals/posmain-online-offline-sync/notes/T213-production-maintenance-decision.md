# T213 production maintenance decision

## Decision

Do not mutate production yet. The corrected artifact, backup rehearsal, data audits, direction policy, and hosted topology are otherwise ready, and the user's existing request authorizes the eventual deployment. One narrow safety blocker remains: the migration runner cannot apply an exact reviewed rewrite label set.

`SyncMigrationPlan` rejects every nonempty `--labels=` request when `--scope=all`; `--scope=additive` correctly rejects rewrite labels. The only current command capable of applying the 35 reviewed rewrites is therefore an unlabeled broad `--scope=all` apply. That violates the exact-label production boundary and could silently include a newly introduced pending statement.

Authorize one local-only Worker to add a reviewed exact-label scope. Do not deploy, restage, back up, migrate, promote, stop services, or change workers in that Worker.

## Required exact-label contract

- Add a scope intended for an operator-reviewed label set, named `reviewed`.
- `--scope=reviewed` requires a nonempty, duplicate-free `--labels=` list for dry-run and apply.
- Every requested label must currently be pending.
- It may select additive or rewrite statements, but must reject ambiguous statements.
- Destructive statements remain subject to the existing backup plus `--allow-destructive` gate; the new scope must not weaken it.
- Selection order must remain the planner's canonical pending order, not caller order.
- Selected checksum preflight, migration-ledger start/applied recording, backup requirement, and post-migration behavior remain unchanged.
- Dry-run output must state pending, selected, and deferred counts and list all deferred labels.
- Existing additive scope behavior and its label requirement remain unchanged.

## Approved later maintenance shape

After the runner correction passes and is freshly restaged, a later Judge may authorize one bounded live Worker in this order:

1. Create fresh just-in-time router, four-tenant, live-config, and code/worktree backups; hash, permission-check, and restore-rehearse the small tenant backup before any write.
2. Produce a deployable Git commit/branch for the exact reviewed code without committing Goal Maker receipts, push it, and prove runtime file hashes match the staged artifact.
3. Preflight that the live checkout can fast-forward/checkout without overwriting ignored `.env`, `uploads`, `logs`, `backup`, or `var`; protect those runtime paths and tighten `.env` read permissions without exposing its contents.
4. Enter a short maintenance window by stopping PHP-FPM only after backups and preflights pass; nginx may remain available for a static/maintenance response if already configured safely.
5. Apply the exact 113 additive labels per tenant using the staged runner and each tenant's fresh backup.
6. Recompute pending state and require it to equal exactly the audited 35 rewrite labels per tenant; apply those labels with the new reviewed scope and the same fresh backup.
7. Verify zero pending sync migrations, exact target definitions, preserved historical NULL counts, bounded six-decimal profit conversion, ledgers/checksums, and database integrity before code promotion.
8. Promote the exact reviewed Git commit while preserving ignored runtime paths; verify configuration resolves hosted role with automatic pull/publish off.
9. Run CLI bootstrap, syntax/contract smoke checks, restart PHP-FPM, and require public health, login/read-only pages, sync receive/report endpoints, services, and queue/worker direction checks to pass.
10. If any pre-enable check fails, restore code and databases while PHP-FPM remains stopped. If a post-enable check fails, stop PHP-FPM immediately and use the fresh code/database backups, accounting for any writes after enable before rollback.

The hosted checkout is a physical clean Git directory on `main`, 1,024 committed file changes behind the local base. Its ignored runtime state includes `.env`, 6.1 MB uploads, 60 KB logs, 12 MB backups, and 1.2 MB `var`; deployment must preserve these paths. Nginx points directly at `/var/www/posmain/current`, and PHP 8.5 FPM is the only related system service found. These facts favor a clean reviewed Git commit/checkout with explicit ignored-runtime protection rather than copying an uncommitted full tree over production.
