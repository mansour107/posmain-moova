# T197 Staged Release Decision

## Decision

Create fresh, private backups and a fully isolated hosted release candidate, then run tenant-by-tenant migration dry-runs and stop. Do not apply migrations, promote code, change configuration, restart services, or drain queues in this slice.

## Why this is the smallest safe mutation

The hosted web root is shared by all router tenants. Even though Focus House is the target, promoting a new shared checkout can affect `kody2`, `posmain_shop2`, Focus House, and the active QA tenant. Staging can be isolated, but the rollback boundary must therefore cover the router/config and every active tenant before a later promotion.

The local release also consists of local `main` plus the goal working-tree changes. A whole-directory copy is unsafe because it could include ignored secrets, logs, uploads, backups or editor artifacts. The artifact must start from `git archive HEAD` and overlay only the exact tracked modifications and untracked files reported by Git. A manifest must record every path and SHA-256.

## T198 contract

### Allowed remote writes

- New mode-0600 timestamped SQL dumps under `/var/backups/posmain/` for the router and every active tenant.
- A mode-0600 backup of the live `.env` and a redacted metadata receipt; values must never be printed.
- One new isolated directory under `/var/www/posmain/staging/` containing the release candidate, manifest and dry-run reports.

No existing backup, release, config, service, symlink, queue or database row may be modified.

### Backup gates

- Use credentials already available to the running application without putting passwords on a command line or in output.
- Dump router plus all active router-shop databases with single-transaction, routines, triggers and utf8mb4.
- Require nonzero size, exit code zero, SHA-256, mode 0600, timestamp and enough free disk.
- Do not delete older backups in this task.

### Artifact gates

- Base: exact local `HEAD` archive.
- Overlay: only `git diff --name-only` tracked paths plus `git ls-files --others --exclude-standard` untracked paths.
- Exclude ignored files, `.git`, `.env*`, credentials, uploads, logs, backups, vendor caches and build output unless tracked in `HEAD`.
- Record base commit, dirty-overlay path list, file hashes and final archive hash.
- Upload/extract only under the new staging directory; never write `/var/www/posmain/current`.

### Read-only staging checks

- PHP lint every changed PHP runtime/tool file in the artifact.
- Confirm key sync file hashes equal local release hashes and the current live root remains unchanged.
- Load live configuration without copying or printing secret values.
- Run current staged migration dry-run separately against every active tenant database.
- Run router upgrade discovery read-only.
- Classify every pending statement as additive, rewrite or destructive.
- Confirm cloud pull and reverse publish would resolve false in the intended production/branch profiles; do not edit the current env yet.

## Hard stop before later apply/promotion

T198 must stop and report if any dump fails, artifact paths escape the manifest, staging touches the live root, a migration is destructive/data-rewriting, a checksum conflicts, router/tenant identity is ambiguous, or config cannot be validated without exposing a secret.

Even on a green T198 result, migration apply and code promotion require the next Judge. Branch-worker provisioning, initial bulk seed, the nine pending table events and the seven superseded dead rows remain untouched.

