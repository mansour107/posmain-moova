<?php

require_once __DIR__ . '/../Infrastructure/DatabaseSessionHandler.php';
require_once __DIR__ . '/../Sync/SyncRuntimeCrypto.php';

class PosmainShopRouter
{
    public static function enabled(?array $config = null): bool
    {
        if ($config === null && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        return !empty($config['router']['enabled']);
    }

    public static function routerDatabaseConfig(?array $config = null): array
    {
        if ($config === null && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $db = $config['router']['database'] ?? [];
        return [
            'host' => trim((string) ($db['host'] ?? '')),
            'port' => (int) ($db['port'] ?? 3306),
            'name' => trim((string) ($db['name'] ?? '')),
            'user' => trim((string) ($db['user'] ?? '')),
            'pass' => (string) ($db['pass'] ?? ''),
            'charset' => trim((string) ($db['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        ];
    }

    public static function connectDatabase(array $db): mysqli
    {
        foreach (['host', 'name', 'user'] as $key) {
            if (trim((string) ($db[$key] ?? '')) === '') {
                throw new InvalidArgumentException('Router database ' . $key . ' is required.');
            }
        }

        $port = (int) ($db['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Router database port is invalid.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            (string) $db['host'],
            (string) $db['user'],
            (string) ($db['pass'] ?? ''),
            (string) $db['name'],
            $port
        );
        $conn->set_charset((string) (($db['charset'] ?? '') ?: 'utf8mb4'));

        return $conn;
    }

    public static function connectRouter(?array $config = null): mysqli
    {
        return self::connectDatabase(self::routerDatabaseConfig($config));
    }

    public static function normalizeAlias(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return '';
        }

        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($identifier, 'UTF-8')
            : strtolower($identifier);

        if (strpos($lower, '@') !== false) {
            return $lower;
        }

        $phone = preg_replace('/[\s\-\(\)\.]/', '', $identifier);
        if (is_string($phone) && preg_match('/^\+?[0-9]{6,20}$/', $phone) === 1) {
            return $phone;
        }

        $collapsed = preg_replace('/\s+/', ' ', $lower);
        return trim(is_string($collapsed) ? $collapsed : $lower);
    }

    public static function activeSessionShopId(?array $session = null): int
    {
        $session = $session ?? (isset($_SESSION) && is_array($_SESSION) ? $_SESSION : []);
        return isset($session['posmain_shop_id']) ? max(0, (int) $session['posmain_shop_id']) : 0;
    }

    public function install(mysqli $routerConn): array
    {
        $applied = [];
        foreach ($this->schemaStatements() as $label => $sql) {
            $routerConn->query($sql);
            $applied[] = $label;
        }
        foreach ($this->upgradeStatements($routerConn) as $label => $sql) {
            $routerConn->query($sql);
            $applied[] = $label;
        }

        return $applied;
    }

    public function upgradeStatements(mysqli $routerConn): array
    {
        $statements = [];
        if ($this->tableExists($routerConn, 'router_branch_routes')) {
            if (!$this->columnExists($routerConn, 'router_branch_routes', 'sync_secret_hash')) {
                $statements['router_branch_routes.add_sync_secret_hash'] = "
ALTER TABLE router_branch_routes
  ADD COLUMN sync_secret_hash CHAR(64) NULL AFTER status";
            }
            if (!$this->columnExists($routerConn, 'router_branch_routes', 'sync_secret_encrypted')) {
                $statements['router_branch_routes.add_sync_secret_encrypted'] = "
ALTER TABLE router_branch_routes
  ADD COLUMN sync_secret_encrypted TEXT NULL AFTER sync_secret_hash";
            }
            if (!$this->columnExists($routerConn, 'router_branch_routes', 'last_seen_at')) {
                $statements['router_branch_routes.add_last_seen_at'] = "
ALTER TABLE router_branch_routes
  ADD COLUMN last_seen_at DATETIME(6) NULL AFTER sync_secret_encrypted";
            }
        }

        return $statements;
    }

    public function schemaStatements(): array
    {
        return [
            'app_sessions' => DatabaseSessionHandler::schemaSql('app_sessions'),
            'security_audit_log' => $this->securityAuditLogSql(),
            'failed_login_attempts' => $this->failedLoginAttemptsSql(),
            'router_shops' => $this->routerShopsSql(),
            'router_login_aliases' => $this->routerLoginAliasesSql(),
            'router_branch_routes' => $this->routerBranchRoutesSql(),
        ];
    }

    public function registerShop(mysqli $routerConn, array $options): array
    {
        $this->install($routerConn);

        $slug = $this->normalizeSlug((string) ($options['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('Shop slug is required.');
        }

        $status = $this->normalizeStatus((string) ($options['status'] ?? 'active'));
        $displayName = $this->nullableString($options['display_name'] ?? $options['name'] ?? $slug, 255);
        $db = $this->normalizeDatabase($options['database'] ?? $options);
        $encryptedPass = $this->encryptDbPassword((string) $db['pass'], !empty($options['require_encryption']));
        $dbHost = $db['host'];
        $dbPort = $db['port'];
        $dbName = $db['name'];
        $dbUser = $db['user'];
        $dbCharset = $db['charset'];

        $stmt = $routerConn->prepare("
            INSERT INTO router_shops (
                slug, display_name, status, db_host, db_port, db_name, db_user, db_pass_encrypted, db_charset
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                status = VALUES(status),
                db_host = VALUES(db_host),
                db_port = VALUES(db_port),
                db_name = VALUES(db_name),
                db_user = VALUES(db_user),
                db_pass_encrypted = VALUES(db_pass_encrypted),
                db_charset = VALUES(db_charset),
                updated_at = CURRENT_TIMESTAMP(6)
        ");
        $stmt->bind_param(
            'ssssissss',
            $slug,
            $displayName,
            $status,
            $dbHost,
            $dbPort,
            $dbName,
            $dbUser,
            $encryptedPass,
            $dbCharset
        );
        $stmt->execute();
        $stmt->close();

        return $this->publicShop($this->findShopBySlug($routerConn, $slug));
    }

    public function addLoginAlias(mysqli $routerConn, array $options): array
    {
        $this->install($routerConn);
        $shopId = max(0, (int) ($options['shop_id'] ?? 0));
        if ($shopId < 1 || !$this->findShopById($routerConn, $shopId)) {
            throw new InvalidArgumentException('Active shop is required before adding a login alias.');
        }

        $aliasRaw = trim((string) ($options['alias'] ?? $options['identifier'] ?? ''));
        $aliasNormalized = self::normalizeAlias($aliasRaw);
        if ($aliasNormalized === '') {
            throw new InvalidArgumentException('Login alias is required.');
        }

        $targetUserId = $this->nullableInt($options['target_user_id'] ?? $options['user_id'] ?? null);
        $targetUname = $this->nullableString($options['target_uname'] ?? $options['uname'] ?? null, 191);
        if ($targetUserId === null && $targetUname === null) {
            $targetUname = $aliasRaw;
        }

        $status = $this->normalizeStatus((string) ($options['status'] ?? 'active'));

        $stmt = $routerConn->prepare("
            INSERT INTO router_login_aliases (
                shop_id, alias_raw, alias_normalized, target_user_id, target_uname, status
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ississ', $shopId, $aliasRaw, $aliasNormalized, $targetUserId, $targetUname, $status);
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                throw new InvalidArgumentException('Login alias already belongs to another shop.');
            }
            throw $e;
        } finally {
            $stmt->close();
        }

        return [
            'shop_id' => $shopId,
            'alias_raw' => $aliasRaw,
            'alias_normalized' => $aliasNormalized,
            'target_user_id' => $targetUserId,
            'target_uname' => $targetUname,
            'status' => $status,
        ];
    }

    public function addBranchRoute(mysqli $routerConn, array $options): array
    {
        return $this->pairBranchRoute($routerConn, $options);
    }

    public function pairBranchRoute(mysqli $routerConn, array $options): array
    {
        $this->install($routerConn);
        $shopId = max(0, (int) ($options['shop_id'] ?? 0));
        if ($shopId < 1 || !$this->findShopById($routerConn, $shopId)) {
            throw new InvalidArgumentException('Active shop is required before pairing a branch route.');
        }

        $branchUuid = strtolower(trim((string) ($options['branch_uuid'] ?? $options['branch-uuid'] ?? '')));
        if (!$this->isUuid($branchUuid)) {
            throw new InvalidArgumentException('Branch UUID must be a valid UUID.');
        }

        $status = $this->normalizeStatus((string) ($options['status'] ?? 'active'));
        $secret = (string) ($options['secret'] ?? $options['branch_secret'] ?? '');
        $secretHash = $secret !== '' ? hash('sha256', $secret) : null;
        $encryptedSecret = null;
        if ($secret !== '') {
            $requireEncryption = !empty($options['require_encryption']) || !empty($options['require_encryption']);
            $crypto = new SyncRuntimeCrypto();
            if ($requireEncryption && !$crypto->available()) {
                throw new RuntimeException(SyncRuntimeCrypto::ENV_KEY . ' is required before pairing router branch secrets.');
            }
            $encryptedSecret = $crypto->available() ? $crypto->encrypt($secret) : null;
        }

        $stmt = $routerConn->prepare("
            INSERT INTO router_branch_routes (
                shop_id,
                branch_uuid,
                status,
                sync_secret_hash,
                sync_secret_encrypted
            ) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                shop_id = VALUES(shop_id),
                status = VALUES(status),
                sync_secret_hash = VALUES(sync_secret_hash),
                sync_secret_encrypted = VALUES(sync_secret_encrypted),
                updated_at = CURRENT_TIMESTAMP(6)
        ");
        $stmt->bind_param('issss', $shopId, $branchUuid, $status, $secretHash, $encryptedSecret);
        $stmt->execute();
        $stmt->close();

        return [
            'shop_id' => $shopId,
            'branch_uuid' => $branchUuid,
            'status' => $status,
            'sync_secret_hash' => $secretHash,
            'secret_encrypted' => $encryptedSecret !== null,
        ];
    }

    public function resolveLoginAlias(mysqli $routerConn, string $identifier): ?array
    {
        $aliasNormalized = self::normalizeAlias($identifier);
        if ($aliasNormalized === '') {
            return null;
        }

        $stmt = $routerConn->prepare("
            SELECT a.id AS alias_id,
                   a.alias_raw,
                   a.alias_normalized,
                   a.target_user_id,
                   a.target_uname,
                   a.status AS alias_status,
                   s.*
              FROM router_login_aliases a
              INNER JOIN router_shops s ON s.id = a.shop_id
             WHERE a.alias_normalized = ?
               AND a.status = 'active'
               AND s.status = 'active'
             LIMIT 1
        ");
        $stmt->bind_param('s', $aliasNormalized);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function resolveBranchRoute(mysqli $routerConn, string $branchUuid): ?array
    {
        $branchUuid = strtolower(trim($branchUuid));
        if (!$this->isUuid($branchUuid)) {
            return null;
        }

        $stmt = $routerConn->prepare("
            SELECT r.id AS route_id,
                   r.branch_uuid,
                   r.status AS route_status,
                   s.*
              FROM router_branch_routes r
              INNER JOIN router_shops s ON s.id = r.shop_id
             WHERE r.branch_uuid = ?
               AND r.status = 'active'
               AND s.status = 'active'
             LIMIT 1
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function connectShopById(mysqli $routerConn, int $shopId): mysqli
    {
        $shop = $this->findShopById($routerConn, $shopId);
        if (!$shop) {
            throw new RuntimeException('Shop route is inactive or missing.');
        }

        return self::connectDatabase($this->databaseConfigFromShop($shop));
    }

    public function connectShopFromRoute(array $route): mysqli
    {
        return self::connectDatabase($this->databaseConfigFromShop($route));
    }

    public function databaseConfigFromShop(array $shop): array
    {
        return [
            'host' => (string) ($shop['db_host'] ?? ''),
            'port' => (int) ($shop['db_port'] ?? 3306),
            'name' => (string) ($shop['db_name'] ?? ''),
            'user' => (string) ($shop['db_user'] ?? ''),
            'pass' => $this->decryptDbPassword((string) ($shop['db_pass_encrypted'] ?? '')),
            'charset' => (string) (($shop['db_charset'] ?? '') ?: 'utf8mb4'),
        ];
    }

    public function validateShopConnection(mysqli $routerConn, int $shopId): array
    {
        $shop = $this->findShopById($routerConn, $shopId);
        if (!$shop) {
            throw new InvalidArgumentException('Shop is inactive or missing.');
        }

        $conn = $this->connectShopFromRoute($shop);
        $result = $conn->query('SELECT DATABASE() AS db_name, VERSION() AS version');
        $row = $result ? $result->fetch_assoc() : [];
        $conn->close();

        $stmt = $routerConn->prepare('UPDATE router_shops SET last_validated_at = CURRENT_TIMESTAMP(6) WHERE id = ?');
        $stmt->bind_param('i', $shopId);
        $stmt->execute();
        $stmt->close();

        return [
            'ok' => true,
            'shop' => $this->publicShop($shop),
            'database' => (string) ($row['db_name'] ?? ''),
            'server_version' => (string) ($row['version'] ?? ''),
        ];
    }

    public function findShopByDatabaseName(mysqli $routerConn, string $dbName): ?array
    {
        $dbName = trim($dbName);
        if ($dbName === '') {
            return null;
        }

        $stmt = $routerConn->prepare("
            SELECT *
              FROM router_shops
             WHERE db_name = ?
               AND status = 'active'
             LIMIT 1
        ");
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function listActiveShops(mysqli $routerConn): array
    {
        if (!$this->tableExists($routerConn, 'router_shops')) {
            return [];
        }

        $result = $routerConn->query("
            SELECT id, slug, display_name, status, db_name, updated_at
            FROM router_shops
            WHERE status = 'active'
            ORDER BY id ASC
        ");

        $shops = [];
        while ($row = $result->fetch_assoc()) {
            $shops[] = [
                'id' => (int) ($row['id'] ?? 0),
                'slug' => (string) ($row['slug'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'db_name' => (string) ($row['db_name'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $shops;
    }

    public function listBranchRoutes(mysqli $routerConn, int $limit = 100): array
    {
        $this->install($routerConn);
        $limit = max(1, min(500, $limit));
        $encryptedSelect = $this->columnExists($routerConn, 'router_branch_routes', 'sync_secret_encrypted')
            ? "CASE WHEN r.sync_secret_encrypted IS NULL OR r.sync_secret_encrypted = '' THEN 0 ELSE 1 END"
            : '0';
        $lastSeenSelect = $this->columnExists($routerConn, 'router_branch_routes', 'last_seen_at')
            ? 'r.last_seen_at'
            : 'NULL AS last_seen_at';

        $result = $routerConn->query("
            SELECT r.branch_uuid,
                   r.status,
                   {$encryptedSelect} AS has_encrypted_secret,
                   {$lastSeenSelect},
                   r.updated_at,
                   s.display_name AS shop_name,
                   s.db_name
            FROM router_branch_routes r
            INNER JOIN router_shops s ON s.id = r.shop_id
            ORDER BY r.updated_at DESC, r.id DESC
            LIMIT {$limit}
        ");

        $routes = [];
        while ($row = $result->fetch_assoc()) {
            $routes[] = [
                'branch_uuid' => (string) ($row['branch_uuid'] ?? ''),
                'branch_name' => (string) ($row['shop_name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'has_encrypted_secret' => !empty($row['has_encrypted_secret']),
                'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'shop_db_name' => (string) ($row['db_name'] ?? ''),
            ];
        }

        return $routes;
    }

    public function findShopById(mysqli $routerConn, int $shopId): ?array
    {
        $stmt = $routerConn->prepare("
            SELECT *
              FROM router_shops
             WHERE id = ?
               AND status = 'active'
             LIMIT 1
        ");
        $stmt->bind_param('i', $shopId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function findShopBySlug(mysqli $routerConn, string $slug): ?array
    {
        $slug = $this->normalizeSlug($slug);
        $stmt = $routerConn->prepare("
            SELECT *
              FROM router_shops
             WHERE slug = ?
             LIMIT 1
        ");
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function publicShop(?array $shop): array
    {
        if (!$shop) {
            return [];
        }

        return [
            'id' => (int) ($shop['id'] ?? 0),
            'slug' => (string) ($shop['slug'] ?? ''),
            'display_name' => (string) ($shop['display_name'] ?? ''),
            'status' => (string) ($shop['status'] ?? ''),
            'db_host' => (string) ($shop['db_host'] ?? ''),
            'db_port' => (int) ($shop['db_port'] ?? 3306),
            'db_name' => (string) ($shop['db_name'] ?? ''),
            'db_user' => (string) ($shop['db_user'] ?? ''),
            'db_charset' => (string) (($shop['db_charset'] ?? '') ?: 'utf8mb4'),
        ];
    }

    private function encryptDbPassword(string $password, bool $requireEncryption): ?string
    {
        if ($password === '' && !$requireEncryption) {
            return null;
        }

        $crypto = new SyncRuntimeCrypto();
        if (!$crypto->available()) {
            throw new RuntimeException(SyncRuntimeCrypto::ENV_KEY . ' is required before saving router shop database credentials.');
        }

        return $crypto->encrypt($password);
    }

    private function decryptDbPassword(string $encrypted): string
    {
        $encrypted = trim($encrypted);
        if ($encrypted === '') {
            return '';
        }

        return (new SyncRuntimeCrypto())->decrypt($encrypted);
    }

    private function normalizeDatabase(array $input): array
    {
        $db = [
            'host' => trim((string) ($input['db_host'] ?? $input['host'] ?? '')),
            'port' => (int) ($input['db_port'] ?? $input['port'] ?? 3306),
            'name' => trim((string) ($input['db_name'] ?? $input['name'] ?? '')),
            'user' => trim((string) ($input['db_user'] ?? $input['user'] ?? '')),
            'pass' => (string) ($input['db_pass'] ?? $input['pass'] ?? ''),
            'charset' => trim((string) ($input['db_charset'] ?? $input['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        ];

        foreach (['host', 'name', 'user'] as $key) {
            if ($db[$key] === '') {
                throw new InvalidArgumentException('Shop database ' . $key . ' is required.');
            }
        }

        if ($db['port'] < 1 || $db['port'] > 65535) {
            throw new InvalidArgumentException('Shop database port is invalid.');
        }

        return $db;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = trim(is_string($slug) ? $slug : '', '-_');
        return substr($slug, 0, 80);
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower(trim($status)) === 'disabled' ? 'disabled' : 'active';
    }

    private function nullableString($value, int $limit): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function tableExists(mysqli $routerConn, string $table): bool
    {
        $escaped = $routerConn->real_escape_string($table);
        $result = $routerConn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $routerConn, string $table, string $column): bool
    {
        $stmt = $routerConn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int) ($row['column_count'] ?? 0)) > 0;
    }

    private function routerShopsSql(): string
    {
        return "
CREATE TABLE IF NOT EXISTS router_shops (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(80) NOT NULL,
  display_name VARCHAR(255) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  db_host VARCHAR(255) NOT NULL,
  db_port INT UNSIGNED NOT NULL DEFAULT 3306,
  db_name VARCHAR(191) NOT NULL,
  db_user VARCHAR(191) NOT NULL,
  db_pass_encrypted TEXT NULL,
  db_charset VARCHAR(40) NOT NULL DEFAULT 'utf8mb4',
  last_validated_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_router_shops_slug (slug),
  KEY idx_router_shops_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function routerLoginAliasesSql(): string
    {
        return "
CREATE TABLE IF NOT EXISTS router_login_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id BIGINT UNSIGNED NOT NULL,
  alias_raw VARCHAR(191) NOT NULL,
  alias_normalized VARCHAR(191) NOT NULL,
  target_user_id BIGINT NULL,
  target_uname VARCHAR(191) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_router_login_alias (alias_normalized),
  KEY idx_router_login_alias_shop (shop_id, status),
  CONSTRAINT fk_router_login_alias_shop FOREIGN KEY (shop_id) REFERENCES router_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function routerBranchRoutesSql(): string
    {
        return "
CREATE TABLE IF NOT EXISTS router_branch_routes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id BIGINT UNSIGNED NOT NULL,
  branch_uuid CHAR(36) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_router_branch_uuid (branch_uuid),
  KEY idx_router_branch_shop (shop_id, status),
  CONSTRAINT fk_router_branch_shop FOREIGN KEY (shop_id) REFERENCES router_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function securityAuditLogSql(): string
    {
        return "
CREATE TABLE IF NOT EXISTS security_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(80) NOT NULL,
  user_id BIGINT NULL,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  target_type VARCHAR(80) NULL,
  target_id BIGINT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_security_audit_event_created (event_type, created_at),
  KEY idx_security_audit_user_created (user_id, created_at),
  KEY idx_security_audit_target (target_type, target_id),
  KEY idx_security_audit_tenant_branch (tenant, branch, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function failedLoginAttemptsSql(): string
    {
        return "
CREATE TABLE IF NOT EXISTS failed_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username_hash CHAR(64) NOT NULL,
  username VARCHAR(191) NULL,
  ip VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_failed_login_identity (username_hash, ip),
  KEY idx_failed_login_locked (locked_until),
  KEY idx_failed_login_last_failed (last_failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }
}
