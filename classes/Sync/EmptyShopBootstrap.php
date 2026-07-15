<?php

require_once __DIR__ . '/../PasswordService.php';
require_once __DIR__ . '/SchemaManager.php';

class EmptyShopBootstrap
{
    public function bootstrap(mysqli $conn, array $options = []): array
    {
        if (!$this->databaseIsEmpty($conn)) {
            throw new RuntimeException('EMPTY_SHOP_BOOTSTRAP_REQUIRES_EMPTY_DATABASE');
        }

        $sqlFile = trim((string) ($options['schema_file'] ?? ''));
        if ($sqlFile === '') {
            $sqlFile = dirname(__DIR__, 2) . '/db/DB.sql';
        }
        if (!is_file($sqlFile)) {
            throw new RuntimeException('Shop schema file is missing: ' . $sqlFile);
        }

        $username = trim((string) ($options['admin_username'] ?? $options['username'] ?? 'admin'));
        $password = (string) ($options['admin_password'] ?? $options['password'] ?? '1234');
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Admin username and password are required for a new shop.');
        }

        $conn->query('SET FOREIGN_KEY_CHECKS=0');
        $this->importSchemaOnly($conn, $sqlFile);
        $conn->query('SET FOREIGN_KEY_CHECKS=1');

        $userId = $this->seedAdminUser($conn, $username, $password);
        $this->seedAdminRole($conn);
        $this->seedRoleTemplates($conn);
        $this->seedSettings($conn);
        (new SyncSchemaManager())->apply($conn);

        // Local PIN main-auth bootstrap (no-op when mode is password / hosted).
        try {
            require_once __DIR__ . '/../Security/LocalSecurityBootstrapService.php';
            require_once __DIR__ . '/../../config/app_config.php';
            if (function_exists('posmain_is_pin_main_auth') && posmain_is_pin_main_auth()) {
                (new LocalSecurityBootstrapService())->ensureLocalBootstrap($conn, $userId);
            }
        } catch (Throwable $ignored) {
            error_log('Local PIN bootstrap skipped: ' . $ignored->getMessage());
        }

        return [
            'admin_user_id' => $userId,
            'admin_username' => $username,
            'schema_file' => $sqlFile,
            'empty_shop' => true,
        ];
    }

    private function databaseIsEmpty(mysqli $conn): bool
    {
        $result = $conn->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );

        return (int) ($result->fetch_assoc()['c'] ?? 0) === 0;
    }

    public function importSchemaOnly(mysqli $conn, string $filePath): void
    {
        $lines = file($filePath);
        if ($lines === false) {
            throw new RuntimeException('Unable to read schema file.');
        }

        $query = '';
        $delimiter = ';';
        $inMulti = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!$inMulti && str_starts_with($line, '/*')) {
                if (!str_contains($line, '*/')) {
                    $inMulti = true;
                }
                continue;
            }
            if ($inMulti) {
                if (str_contains($line, '*/')) {
                    $inMulti = false;
                }
                continue;
            }
            if (str_starts_with($line, '--') || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(.+)$/i', $line, $matches)) {
                $delimiter = trim($matches[1]);
                continue;
            }

            $query .= $line . ' ';
            if (!str_ends_with($line, $delimiter)) {
                continue;
            }

            $exec = substr(trim($query), 0, -strlen($delimiter));
            $query = '';
            if ($exec === '') {
                continue;
            }
            if (preg_match('/^\s*INSERT\s+INTO\s+/i', $exec) === 1) {
                continue;
            }

            $conn->query($exec);
        }
    }

    private function seedAdminUser(mysqli $conn, string $username, string $plainPassword): int
    {
        $hash = PasswordService::hashPassword($plainPassword);
        $stmt = $conn->prepare('
            INSERT INTO users (id, uname, password, usertype, userrole, isdeleted, is_waiter, img, tenant, branch)
            VALUES (1, ?, ?, 2, 1, 0, 0, "", 0, 0)
            ON DUPLICATE KEY UPDATE
                password = VALUES(password),
                isdeleted = 0,
                usertype = VALUES(usertype),
                userrole = VALUES(userrole)
        ');
        $stmt->bind_param('ss', $username, $hash);
        $stmt->execute();
        $stmt->close();

        $lookup = $conn->prepare('SELECT id FROM users WHERE uname = ? AND isdeleted = 0 LIMIT 1');
        $lookup->bind_param('s', $username);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();

        return (int) ($row['id'] ?? 1);
    }

    private function seedAdminRole(mysqli $conn): void
    {
        $existing = $conn->query("SELECT id FROM usr_pwrs WHERE id = 1 LIMIT 1");
        if ($existing && $existing->num_rows > 0) {
            return;
        }

        $columns = [];
        $values = [];
        $result = $conn->query('SHOW COLUMNS FROM usr_pwrs');
        while ($row = $result->fetch_assoc()) {
            $field = (string) ($row['Field'] ?? '');
            $type = strtolower((string) ($row['Type'] ?? ''));
            if ($field === '' || in_array($field, ['crtime', 'mdtime'], true)) {
                continue;
            }

            $columns[] = '`' . str_replace('`', '``', $field) . '`';
            if ($field === 'id') {
                $values[] = '1';
                continue;
            }
            if ($field === 'rollname') {
                $values[] = "'admin'";
                continue;
            }
            if ($field === 'info') {
                $values[] = "''";
                continue;
            }
            if ($field === 'isdeleted') {
                $values[] = '0';
                continue;
            }
            if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'double') !== false) {
                $values[] = '1';
                continue;
            }
            if (strpos($type, 'char') !== false || strpos($type, 'text') !== false) {
                $values[] = "''";
            }
        }

        if (!$columns) {
            throw new RuntimeException('Unable to seed admin role permissions.');
        }

        $conn->query(
            'INSERT INTO usr_pwrs (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
        );
    }

    private function seedRoleTemplates(mysqli $conn): void
    {
        $templates = ['Owner', 'Manager', 'Cashier', 'Kitchen', 'Waiter'];
        foreach ($templates as $name) {
            $escaped = $conn->real_escape_string($name);
            $existing = $conn->query("SELECT id FROM usr_pwrs WHERE rollname = '{$escaped}' AND COALESCE(isdeleted, 0) = 0 LIMIT 1");
            if ($existing && $existing->num_rows > 0) {
                continue;
            }
            $conn->query("INSERT INTO usr_pwrs (rollname, info, isdeleted) VALUES ('{$escaped}', 'Role template (configure permissions)', 0)");
        }
    }

    private function seedSettings(mysqli $conn): void
    {
        $existing = $conn->query('SELECT id FROM settings WHERE id = 1 LIMIT 1');
        if ($existing && $existing->num_rows > 0) {
            $conn->query("
                UPDATE settings
                   SET company_name = '',
                       company_add = '',
                       company_email = '',
                       company_tel = '',
                       edit_pass = '',
                       lic = '',
                       updateline = '',
                       acc_rent = 0,
                       startdate = NULL,
                       enddate = NULL,
                       def_pos_client = NULL,
                       def_pos_store = NULL,
                       def_pos_employee = NULL,
                       def_pos_fund = NULL,
                       logo = NULL,
                       show_all_tasks = NULL,
                       pos_type = 'barcode',
                       pos_has_password = 1,
                       isdeleted = 0
                 WHERE id = 1
            ");
            return;
        }

        $conn->query("
            INSERT INTO settings (
                id, company_name, company_add, company_email, company_tel, edit_pass, lic, updateline,
                acc_rent, startdate, enddate, lang, bodycolor, isdeleted, tenant, branch, pos_type, pos_has_password
            ) VALUES (
                1, '', '', '', '', '', '', '',
                0, NULL, NULL, 'ar', '#f0f0f0', 0, 0, 0, 'barcode', 1
            )
        ");
    }
}
