# POSMAIN automatic update deployment

The management-page updater separates the unprivileged web request from Git, backup, migration, and recovery work. PHP-FPM only creates and reads job records. Fixed root-owned wrappers run the release check and workers as the `posmain` deploy user.

## Install the fixed wrappers

```sh
install -m 0755 deploy/production/posmain-update-check.sh /usr/local/bin/posmain-update-check
install -m 0755 deploy/production/posmain-update-worker.sh /usr/local/bin/posmain-update-worker
install -m 0755 deploy/production/posmain-update-recovery-worker.sh /usr/local/bin/posmain-update-recovery-worker
install -m 0755 deploy/production/posmain-update-runtime-reload.sh /usr/local/bin/posmain-update-runtime-reload
visudo -cf deploy/production/sudoers-posmain-update.example
install -m 0440 deploy/production/sudoers-posmain-update.example /etc/sudoers.d/posmain-update
install -d -o posmain -g www-data -m 0770 /var/www/posmain/current/var/update_jobs
touch /var/www/posmain/current/var/update_jobs/update.lock
chown posmain:www-data /var/www/posmain/current/var/update_jobs/update.lock
chmod 0644 /var/www/posmain/current/var/update_jobs/update.lock
```

Configure these values in the application environment:

```dotenv
POSMAIN_UPDATE_RUN_AS=posmain
POSMAIN_UPDATE_GIT_CHECK_WRAPPER=/usr/local/bin/posmain-update-check
POSMAIN_UPDATE_WORKER_WRAPPER=/usr/local/bin/posmain-update-worker
POSMAIN_UPDATE_RECOVERY_WORKER_WRAPPER=/usr/local/bin/posmain-update-recovery-worker
POSMAIN_UPDATE_PHP_FPM_RELOAD_CMD=sudo -n /usr/local/bin/posmain-update-runtime-reload
```

The deploy user must own the checkout and have non-interactive access to the configured Git remote. The web user needs write access only to `var/update_jobs` and the maintenance flag location, not to the checkout or `.git`.

## Release and host gates

Before enabling the button, verify:

- the checkout is on `POSMAIN_UPDATE_GIT_BRANCH` and is clean;
- the local commit is strictly behind the configured remote branch, not ahead or diverged;
- `version.txt` and `version.json` agree in the release commit;
- every active shop database passes backup and restore privilege preflight;
- the PHP-FPM reload command is non-interactive and succeeds as the deploy user;
- all four wrappers and the sudoers policy are installed;
- the health endpoint is healthy before update testing.

Use a disposable/test shop for the first full rehearsal. The updater enters maintenance mode, drains requests, backs up every discovered database, fast-forwards to one verified commit, migrates in fresh PHP processes, reloads runtime, verifies databases/release/health, and only then removes the backups. Any failure after backup triggers reverse-order database restore and exact code rollback. Maintenance remains enabled if recovery cannot be verified.

Do not clean a dirty checkout or replace a diverged branch automatically. Resolve that host state explicitly before enabling automatic updates.
