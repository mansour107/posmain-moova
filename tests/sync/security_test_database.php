<?php

final class SecurityTestDatabase
{
    private mysqli $admin;
    private string $databaseName;
    private bool $closed = false;

    private function __construct(mysqli $admin, string $databaseName)
    {
        $this->admin = $admin;
        $this->databaseName = $databaseName;
    }

    public static function create(): self
    {
        if (getenv('POSMAIN_SECURITY_TEST_DISPOSABLE') !== '1') {
            throw new RuntimeException('SECURITY_TEST_DISPOSABLE_MARKER_REQUIRED');
        }

        $host = trim((string) (getenv('POSMAIN_SECURITY_TEST_DB_HOST') ?: '127.0.0.1'));
        if (!in_array(strtolower($host), ['127.0.0.1', 'localhost', 'mysql'], true)) {
            throw new RuntimeException('SECURITY_TEST_LOCAL_DATABASE_REQUIRED');
        }

        $port = (int) (getenv('POSMAIN_SECURITY_TEST_DB_PORT') ?: ($host === 'mysql' ? 3306 : 3307));
        $user = (string) (getenv('POSMAIN_SECURITY_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('POSMAIN_SECURITY_TEST_DB_PASS') ?: '');
        $databaseName = 'posmain_security_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::assertDisposableName($databaseName);

        $admin = new mysqli($host, $user, $pass, '', $port);
        if ($admin->connect_errno) {
            throw new RuntimeException('SECURITY_TEST_DATABASE_CONNECT_FAILED: ' . $admin->connect_error);
        }
        $admin->set_charset('utf8mb4');
        if (!$admin->query(
            'CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
        )) {
            throw new RuntimeException('SECURITY_TEST_DATABASE_CREATE_FAILED: ' . $admin->error);
        }

        putenv('POSMAIN_DB_HOST=' . $host);
        putenv('POSMAIN_DB_PORT=' . $port);
        putenv('POSMAIN_DB_USER=' . $user);
        putenv('POSMAIN_DB_PASS=' . $pass);
        putenv('POSMAIN_DB_NAME=' . $databaseName);

        $fixture = new self($admin, $databaseName);
        register_shutdown_function(static function () use ($fixture): void {
            $fixture->close();
        });

        return $fixture;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function connect(): mysqli
    {
        $config = [
            'host' => (string) getenv('POSMAIN_DB_HOST'),
            'port' => (int) getenv('POSMAIN_DB_PORT'),
            'user' => (string) getenv('POSMAIN_DB_USER'),
            'pass' => (string) getenv('POSMAIN_DB_PASS'),
        ];
        $conn = new mysqli(
            $config['host'],
            $config['user'],
            $config['pass'],
            $this->databaseName,
            $config['port']
        );
        $conn->set_charset('utf8mb4');

        return $conn;
    }

    /**
     * Build only the schema required by the permission override runtime proof.
     *
     * @param list<string> $legacyRoleColumns
     */
    public function provisionPermissionSchema(mysqli $conn, array $legacyRoleColumns): void
    {
        $roleColumns = [];
        foreach ($legacyRoleColumns as $column) {
            $column = trim((string) $column);
            if ($column === '' || !preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
                throw new RuntimeException('SECURITY_TEST_INVALID_ROLE_COLUMN');
            }
            $roleColumns[$column] = true;
        }

        $extraRoleSql = '';
        foreach (array_keys($roleColumns) as $column) {
            $extraRoleSql .= ', `' . $column . '` TINYINT(1) NOT NULL DEFAULT 0';
        }

        $statements = [
            'CREATE TABLE usr_pwrs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                rollname VARCHAR(190) NOT NULL,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                role_key VARCHAR(64) NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0'
                    . $extraRoleSql .
                ', PRIMARY KEY (id),
                UNIQUE KEY uq_usr_pwrs_role_key (role_key)
            ) ENGINE=InnoDB',
            "CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                uname VARCHAR(190) NOT NULL,
                password VARCHAR(255) NOT NULL,
                usertype INT NOT NULL DEFAULT 0,
                userrole INT NOT NULL DEFAULT 0,
                img VARCHAR(255) NOT NULL DEFAULT '',
                permission_mode VARCHAR(32) NOT NULL DEFAULT 'role_only',
                isdeleted TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB",
            'CREATE TABLE role_capabilities (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(190) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                limit_value DECIMAL(18,6) NULL,
                is_unlimited TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uq_role_capability (role_id, permission_key)
            ) ENGINE=InnoDB',
            'CREATE TABLE user_permission_grants (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(190) NOT NULL,
                effect VARCHAR(16) NOT NULL,
                limit_value DECIMAL(18,6) NULL,
                is_unlimited TINYINT(1) NOT NULL DEFAULT 1,
                tenant INT NOT NULL DEFAULT 0,
                branch INT NOT NULL DEFAULT 0,
                expires_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_permission_grant (user_id, permission_key, tenant, branch)
            ) ENGINE=InnoDB',
            'CREATE TABLE app_settings (
                setting_key VARCHAR(190) NOT NULL,
                setting_value TEXT NULL,
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB',
        ];

        foreach ($statements as $statement) {
            if (!$conn->query($statement)) {
                throw new RuntimeException('SECURITY_TEST_SCHEMA_FAILED: ' . $conn->error);
            }
        }
    }

    public function provisionDrawerGuardSchema(mysqli $conn): void
    {
        $statements = [
            "CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                uname VARCHAR(190) NOT NULL,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB",
            "CREATE TABLE drawer_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                uuid CHAR(36) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                tenant INT NOT NULL DEFAULT 0,
                branch INT NOT NULL DEFAULT 0,
                fund_account_id INT NULL,
                opened_at DATETIME NOT NULL,
                opened_by INT UNSIGNED NOT NULL,
                opening_cash DECIMAL(18,6) NOT NULL DEFAULT 0,
                closed_at DATETIME NULL,
                closed_by INT NULL,
                expected_cash DECIMAL(18,6) NULL,
                counted_cash DECIMAL(18,6) NULL,
                difference DECIMAL(18,6) NULL,
                status VARCHAR(16) NOT NULL,
                notes VARCHAR(500) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_drawer_uuid (uuid)
            ) ENGINE=InnoDB",
            "INSERT INTO users (uname, isdeleted) VALUES ('drawer_fixture_user', 0)",
            "INSERT INTO drawer_sessions (
                uuid, user_id, tenant, branch, fund_account_id, opened_at,
                opened_by, opening_cash, closed_at, closed_by, status, notes
            ) VALUES (
                '00000000-0000-4000-8000-000000000001', 1, 0, 0, NULL,
                NOW(), 1, 0, NOW(), 1, 'closed', 'fixture adoption row'
            )",
        ];

        foreach ($statements as $statement) {
            if (!$conn->query($statement)) {
                throw new RuntimeException('SECURITY_TEST_DRAWER_SCHEMA_FAILED: ' . $conn->error);
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        self::assertDisposableName($this->databaseName);
        $this->admin->query('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
        $this->admin->close();
    }

    private static function assertDisposableName(string $databaseName): void
    {
        if (!preg_match('/^posmain_security_test_[0-9]+_[a-f0-9]{8}$/', $databaseName)) {
            throw new RuntimeException('SECURITY_TEST_DATABASE_NAME_REFUSED');
        }
    }
}
