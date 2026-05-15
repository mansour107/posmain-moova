# T001 Target And Pending SQL

Read-only Scout result for applying Phase 3 security migrations.

- Target config command with `POSMAIN_DB_PORT=3307` reported `env=local`, `production_mode=0`, `db_host=127.0.0.1`, `db_port=3307`, `db_name=kody2`, and `db_user=root`.
- `tools/run_migrations.php` requires explicit `--dry-run` or `--apply`. Apply requires either a readable `--backup-file` or `--confirm-no-backup` for local/dev only.
- Dry-run reported two pending additive statements: `security_audit_log` and `failed_login_attempts`.
- No destructive statement was present in the pending dry-run output.
