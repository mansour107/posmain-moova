# T231 recovery-v2 contract receipt

## Outcome

Implemented the additive recovery-v2 request contract without changing the existing v1 signature or the automatic branch-to-cloud worker.

## Bounded behavior

- Manual recovery selects `cloud_snapshot` explicitly instead of `source=auto` choosing the full historical inbox.
- The hosted service captures one accepted-inbox checkpoint and returns it on every page; the client rejects a changed version, profile, source, or checkpoint.
- Default page size is 25, hard maximum is 100, and multi-page reads pause 50 ms by default.
- Response bodies are capped at 8 MiB and streamed only up to that bound for cURL and PHP streams.
- Transient HTTP retry remains bounded to 1-8 attempts; oversized responses fail without retry.
- Apply reruns reuse the dry-run checkpoint, so the signed safety manifest cannot silently move to a newer recovery boundary.

## Compatibility

- A request with no `contract_version` still produces the exact v1 signed JSON body and retains v1 `source=auto` behavior.
- Recovery v2 is additive and fail-closed; unsupported profiles, sources, versions, negative cursors/checkpoints, and pages over 100 are rejected.
- Deployment must publish the hosted endpoint before any shop uses the v2 restore client.

## Verification

- PHP lint passed for every changed PHP file.
- `php tests/sync/branch_restore_resource_contract_test.php` passed.
- `php tests/sync/branch_restore_contract_test.php` passed.
- `git diff --check` passed.
- Docker daemon remained off; no database, replay, full suite, or deployment was started.

## Remaining production work

The v2 contract is not yet a complete recovery workflow. The next slice must make the snapshot operationally bounded (current masters, open work, active cash/shift state, recent closed history) and persist a resumable per-phase cursor before hosted proof or deployment.
