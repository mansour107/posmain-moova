# T205 rewrite validation decision

## Decision

Do not apply additive or rewrite migrations and do not promote code yet. Authorize one read-only hosted validation pass covering every exact rewrite label on every active tenant.

T204 proved backup usability, artifact parity, additive isolation, and reverse-policy safety. The remaining risk is data interpretation and DDL lock/rebuild behavior, not backup existence.

## Required validation contract

For every affected table/column and tenant, record without exposing business row contents:

1. Current column type, precision, scale, nullability, default, enum definition, indexes/FKs, exact row count, data bytes and index bytes.
2. Decimal targets:
   - target precision/scale and whether the change is a strict capacity widening;
   - NULL count;
   - maximum absolute value and whether every value fits the target integer capacity;
   - count and maximum delta for values that would change under `ROUND(value, target_scale)`.
3. NULL-normalization labels:
   - exact affected count;
   - aggregate consistency indicators for the related order totals, sufficient to distinguish legacy missing-zero from contradictory financial data;
   - any nonzero count remains an explicit data-repair decision in the next Judge rather than being silently accepted.
4. Enum targets:
   - current definition and counts by existing value;
   - prove the target enum is a strict superset and no existing value would coerce to empty/another value.
5. Drawer movement nullability:
   - prove the target type preserves unsigned width and only relaxes nullability;
   - report current NULL/orphan/FK counts.
6. Operational DDL risk:
   - exact affected table sizes;
   - current long transactions, metadata-lock waiters, and tables in use;
   - MariaDB version/capability evidence and a conservative rebuild/lock classification for each ALTER family. Do not execute exploratory ALTERs.

## Fail-closed rules

- Block if any decimal value exceeds target capacity or has material target-scale rounding.
- Block if an enum value is outside the proposed target set.
- Block if drawer movement references are orphaned or the target loses type attributes.
- Block automatic approval of any NULL-to-zero update; report counts for Judge interpretation.
- Block online apply if large-table rebuild/lock behavior is uncertain or active transactions/locks are present.

## Rollback and maintenance implication

The verified T198 backup is a usable full-database rollback source, but restore is not an instant transaction rollback. Rewrite migration plus code promotion must therefore occur in a declared maintenance boundary with writes/workers stopped, fresh backups, pre/post hashes and reconciliation. Additive-only creation may be separated, but promotion must wait until the required rewrite contract is satisfied.
