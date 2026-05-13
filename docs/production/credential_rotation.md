# POSMAIN Credential Rotation Requirement

Phase 1 treats any committed or locally shared DB credential as compromised.

## What Was Fixed In Repo

- `config/database.php` now delegates to environment-backed DB bootstrap instead of containing raw credentials.
- `.env.example` is a safe template and must not contain real secrets.
- `.gitignore` keeps `.env` and machine-specific config out of source control while allowing `.env.example`.

## Required Operator Action

Rotate credentials outside this repository for any environment where old committed values could have worked:

1. Create a new least-privilege app DB user.
2. Update the private deployment secret store or `.env`.
3. Restart PHP/web services.
4. Confirm login and POS open against the new user.
5. Disable or drop the old user.
6. Record the rotation in the private operator log, not in git.

## Do Not Commit

- Real DB passwords.
- Branch sync secrets.
- Status tokens.
- Moova device tokens.
- SQL dumps from live systems.
- Backup files.

## Verification

Use source scans for the Phase 1 surfaces:

```bash
rg -n "POSMAIN_DB_PASS=.*[^=]$|IDENTIFIED BY '[^R]" config includes print index.php .env.example docs/production
```

The command should not reveal real credentials. Search privately for any known leaked strings without writing them into repo docs.
