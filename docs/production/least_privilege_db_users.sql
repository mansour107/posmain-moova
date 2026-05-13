-- POSMAIN least-privilege database users.
-- Replace database name, host, and passwords before running in a private operator session.
-- Do not commit real passwords.

-- Application user: normal web/POS runtime.
CREATE USER IF NOT EXISTS 'posmain_app_user'@'%' IDENTIFIED BY 'REPLACE_WITH_PRIVATE_APP_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
ON `kody2`.* TO 'posmain_app_user'@'%';

-- Migration user: used only by CLI migration/maintenance windows.
CREATE USER IF NOT EXISTS 'posmain_migration_user'@'%' IDENTIFIED BY 'REPLACE_WITH_PRIVATE_MIGRATION_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE, ALTER, DROP, INDEX, TRIGGER, REFERENCES
ON `kody2`.* TO 'posmain_migration_user'@'%';

-- Backup user: read-only dump access.
CREATE USER IF NOT EXISTS 'posmain_backup_user'@'%' IDENTIFIED BY 'REPLACE_WITH_PRIVATE_BACKUP_PASSWORD';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES
ON `kody2`.* TO 'posmain_backup_user'@'%';

FLUSH PRIVILEGES;
