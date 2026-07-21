<?php

require_once __DIR__ . '/Sync/SyncRuntimeCrypto.php';

class MoovaPosIntegration
{
    public static function ensureSchema(mysqli $conn)
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS moova_pos_shop_links (
                id INT(11) NOT NULL AUTO_INCREMENT,
                moova_shop_id VARCHAR(128) DEFAULT NULL,
                moova_branch_id VARCHAR(128) NOT NULL,
                moova_device_token VARCHAR(191) DEFAULT NULL,
                moova_device_token_encrypted TEXT DEFAULT NULL,
                moova_device_token_hash CHAR(64) NOT NULL,
                moova_device_token_last4 VARCHAR(16) DEFAULT NULL,
                moova_connection_id VARCHAR(128) DEFAULT NULL,
                moova_branch_link_id VARCHAR(128) DEFAULT NULL,
                pairing_id VARCHAR(128) DEFAULT NULL,
                pos_instance_uuid CHAR(36) DEFAULT NULL,
                moova_shop_name VARCHAR(191) DEFAULT NULL,
                moova_branch_name VARCHAR(191) DEFAULT NULL,
                pos_tenant INT(11) NOT NULL DEFAULT 0,
                pos_branch INT(11) NOT NULL DEFAULT 0,
                widget_url VARCHAR(255) NOT NULL DEFAULT 'https://withmoova.com/pos-widget',
                locale VARCHAR(16) NOT NULL DEFAULT 'ar',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_pair_verified_at DATETIME DEFAULT NULL,
                last_catalog_fingerprint CHAR(64) DEFAULT NULL,
                last_catalog_synced_at DATETIME DEFAULT NULL,
                last_catalog_error TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_moova_token_branch_status (moova_device_token_hash, moova_branch_id, status),
                UNIQUE KEY uq_pos_scope_status (pos_tenant, pos_branch, status),
                KEY idx_moova_pos_scope_status (pos_tenant, pos_branch, status),
                KEY idx_moova_token_status (moova_device_token_hash, status),
                KEY idx_moova_branch_status (moova_branch_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS moova_catalog_sync_outbox (
                id BIGINT(20) NOT NULL AUTO_INCREMENT,
                shop_link_id INT(11) NOT NULL,
                pos_tenant INT(11) NOT NULL DEFAULT 0,
                pos_branch INT(11) NOT NULL DEFAULT 0,
                requested_fingerprint CHAR(64) DEFAULT NULL,
                state VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts INT(11) NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_error TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_moova_catalog_link (shop_link_id),
                KEY idx_moova_catalog_ready (state, available_at),
                KEY idx_moova_catalog_scope (pos_tenant, pos_branch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS moova_pos_table_links (
                id INT(11) NOT NULL AUTO_INCREMENT,
                moova_branch_id VARCHAR(128) NOT NULL,
                moova_table_id VARCHAR(128) NOT NULL,
                pos_tenant INT(11) NOT NULL DEFAULT 0,
                pos_branch INT(11) NOT NULL DEFAULT 0,
                pos_table_id INT(11) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_moova_table_scope (moova_branch_id, moova_table_id, pos_tenant, pos_branch),
                KEY idx_pos_table_scope (pos_tenant, pos_branch, pos_table_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS moova_pos_order_links (
                id INT(11) NOT NULL AUTO_INCREMENT,
                idempotency_key VARCHAR(191) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                moova_order_id VARCHAR(191) DEFAULT NULL,
                moova_branch_id VARCHAR(128) NOT NULL,
                pos_tenant INT(11) NOT NULL DEFAULT 0,
                pos_branch INT(11) NOT NULL DEFAULT 0,
                pos_order_id INT(11) DEFAULT NULL,
                provider_status VARCHAR(32) NOT NULL DEFAULT 'processing',
                last_pos_state_hash CHAR(64) DEFAULT NULL,
                last_pos_state_payload LONGTEXT,
                request_payload LONGTEXT,
                response_payload LONGTEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_moova_idempotency_scope (pos_tenant, pos_branch, idempotency_key),
                KEY idx_moova_order_pos_order (pos_order_id),
                KEY idx_moova_order_branch (moova_branch_id, pos_tenant, pos_branch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS moova_pos_order_change_links (
                id INT(11) NOT NULL AUTO_INCREMENT,
                idempotency_key VARCHAR(191) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                moova_order_id VARCHAR(191) NOT NULL,
                moova_request_event_id VARCHAR(191) DEFAULT NULL,
                change_type VARCHAR(20) NOT NULL,
                moova_branch_id VARCHAR(128) NOT NULL,
                pos_tenant INT(11) NOT NULL DEFAULT 0,
                pos_branch INT(11) NOT NULL DEFAULT 0,
                pos_order_id INT(11) DEFAULT NULL,
                provider_status VARCHAR(32) NOT NULL DEFAULT 'processing',
                request_payload LONGTEXT,
                response_payload LONGTEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_moova_change_idempotency_scope (pos_tenant, pos_branch, idempotency_key),
                KEY idx_moova_change_order_scope (moova_order_id, pos_tenant, pos_branch),
                KEY idx_moova_change_pos_order (pos_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS moova_pos_order_lines (
                id INT(11) NOT NULL AUTO_INCREMENT,
                moova_order_id VARCHAR(191) NOT NULL,
                pos_order_id INT(11) NOT NULL,
                fat_detail_id INT(11) NOT NULL,
                item_id INT(11) NOT NULL,
                qty_out DOUBLE NOT NULL DEFAULT 0,
                price DOUBLE NOT NULL DEFAULT 0,
                discount DOUBLE NOT NULL DEFAULT 0,
                det_value DOUBLE NOT NULL DEFAULT 0,
                line_hash CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                pos_tenant INT(11) NOT NULL DEFAULT 0,
                pos_branch INT(11) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_moova_line_order_scope (moova_order_id, pos_tenant, pos_branch, status),
                KEY idx_moova_line_pos_order (pos_order_id, status),
                KEY idx_moova_line_detail (fat_detail_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::ensureColumn(
            $conn,
            'moova_pos_shop_links',
            'moova_device_token',
            "ALTER TABLE moova_pos_shop_links ADD COLUMN moova_device_token VARCHAR(191) DEFAULT NULL AFTER moova_branch_id"
        );
        foreach ([
            'moova_device_token_encrypted' => "ALTER TABLE moova_pos_shop_links ADD COLUMN moova_device_token_encrypted TEXT DEFAULT NULL AFTER moova_device_token",
            'moova_connection_id' => "ALTER TABLE moova_pos_shop_links ADD COLUMN moova_connection_id VARCHAR(128) DEFAULT NULL AFTER moova_device_token_last4",
            'moova_branch_link_id' => "ALTER TABLE moova_pos_shop_links ADD COLUMN moova_branch_link_id VARCHAR(128) DEFAULT NULL AFTER moova_connection_id",
            'pairing_id' => "ALTER TABLE moova_pos_shop_links ADD COLUMN pairing_id VARCHAR(128) DEFAULT NULL AFTER moova_branch_link_id",
            'pos_instance_uuid' => "ALTER TABLE moova_pos_shop_links ADD COLUMN pos_instance_uuid CHAR(36) DEFAULT NULL AFTER pairing_id",
            'moova_shop_name' => "ALTER TABLE moova_pos_shop_links ADD COLUMN moova_shop_name VARCHAR(191) DEFAULT NULL AFTER pos_instance_uuid",
            'moova_branch_name' => "ALTER TABLE moova_pos_shop_links ADD COLUMN moova_branch_name VARCHAR(191) DEFAULT NULL AFTER moova_shop_name",
            'last_pair_verified_at' => "ALTER TABLE moova_pos_shop_links ADD COLUMN last_pair_verified_at DATETIME DEFAULT NULL AFTER status",
            'last_catalog_fingerprint' => "ALTER TABLE moova_pos_shop_links ADD COLUMN last_catalog_fingerprint CHAR(64) DEFAULT NULL AFTER last_pair_verified_at",
            'last_catalog_synced_at' => "ALTER TABLE moova_pos_shop_links ADD COLUMN last_catalog_synced_at DATETIME DEFAULT NULL AFTER last_catalog_fingerprint",
            'last_catalog_error' => "ALTER TABLE moova_pos_shop_links ADD COLUMN last_catalog_error TEXT DEFAULT NULL AFTER last_catalog_synced_at",
        ] as $column => $sql) {
            self::ensureColumn($conn, 'moova_pos_shop_links', $column, $sql);
        }
        self::ensureColumn(
            $conn,
            'moova_pos_order_links',
            'last_pos_state_hash',
            "ALTER TABLE moova_pos_order_links ADD COLUMN last_pos_state_hash CHAR(64) DEFAULT NULL AFTER provider_status"
        );
        self::ensureColumn(
            $conn,
            'moova_pos_order_links',
            'last_pos_state_payload',
            "ALTER TABLE moova_pos_order_links ADD COLUMN last_pos_state_payload LONGTEXT AFTER last_pos_state_hash"
        );
        self::ensureIndex(
            $conn,
            'moova_pos_shop_links',
            'idx_moova_token_status',
            "ALTER TABLE moova_pos_shop_links ADD INDEX idx_moova_token_status (moova_device_token_hash, status)"
        );
    }

    private static function ensureColumn(mysqli $conn, $table, $column, $alterSql)
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($result && $result->num_rows > 0) {
            return;
        }

        $conn->query($alterSql);
    }

    private static function ensureIndex(mysqli $conn, $table, $index, $alterSql)
    {
        $table = $conn->real_escape_string($table);
        $index = $conn->real_escape_string($index);
        $result = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
        if ($result && $result->num_rows > 0) {
            return;
        }

        $conn->query($alterSql);
    }

    public static function hashDeviceToken($token)
    {
        return hash('sha256', trim((string) $token));
    }

    public static function maskDeviceToken($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return '';
        }

        $last4 = substr($token, -4);
        return '•••• ' . $last4;
    }

    public static function normalizeProviderItemId($providerItemId)
    {
        $providerItemId = trim((string) $providerItemId);
        if ($providerItemId === '') {
            return '';
        }

        if (preg_match('/^pos-item-(\d+)$/i', $providerItemId, $matches)) {
            return (string) $matches[1];
        }

        return $providerItemId;
    }

    public static function upsertTableLink(mysqli $conn, array $scope, $moovaBranchId, $moovaTableId, $posTableId)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $moovaBranchId = trim((string) $moovaBranchId);
        $moovaTableId = trim((string) $moovaTableId);
        $posTableId = (int) $posTableId;

        if ($moovaTableId === '' || $posTableId < 1) {
            return false;
        }

        $status = 'active';
        $stmt = $conn->prepare("
            INSERT INTO moova_pos_table_links (
                moova_branch_id, moova_table_id, pos_tenant, pos_branch, pos_table_id, status
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                pos_table_id = VALUES(pos_table_id),
                status = 'active',
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param('ssiiis', $moovaBranchId, $moovaTableId, $tenant, $branch, $posTableId, $status);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    public static function getCurrentUserScope(mysqli $conn, $userId)
    {
        $userId = (int) $userId;
        if ($userId < 1) {
            return null;
        }

        $stmt = $conn->prepare("SELECT tenant, branch FROM users WHERE id = ? AND isdeleted != 1 LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'tenant' => (int) ($row['tenant'] ?? 0),
            'branch' => (int) ($row['branch'] ?? 0),
        ];
    }

    public static function findActiveLinkForUser(mysqli $conn, $userId)
    {
        $scope = self::getCurrentUserScope($conn, $userId);
        if (!$scope) {
            return null;
        }

        return self::findActiveLinkForScope($conn, $scope);
    }

    public static function findActiveLinkForScope(mysqli $conn, array $scope)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);

        $status = 'active';
        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_shop_links
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND status = ?
            LIMIT 1
        ");
        $stmt->bind_param("iis", $tenant, $branch, $status);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public static function findLatestLinkForScope(mysqli $conn, array $scope)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM moova_pos_shop_links WHERE pos_tenant = ? AND pos_branch = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public static function findActiveLinkByTokenAndBranch(mysqli $conn, $deviceToken, $branchId)
    {
        $hash = self::hashDeviceToken($deviceToken);
        $branchId = trim((string) $branchId);
        $status = 'active';

        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_shop_links
            WHERE moova_device_token_hash = ?
              AND moova_branch_id = ?
              AND status = ?
            LIMIT 1
        ");
        $stmt->bind_param("sss", $hash, $branchId, $status);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public static function findActiveLinkByToken(mysqli $conn, $deviceToken)
    {
        $hash = self::hashDeviceToken($deviceToken);
        $status = 'active';

        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_shop_links
            WHERE moova_device_token_hash = ?
              AND status = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param("ss", $hash, $status);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public static function userCanUseLink(mysqli $conn, $userId, array $link)
    {
        $scope = self::getCurrentUserScope($conn, $userId);
        if (!$scope) {
            return false;
        }

        return (int) $scope['tenant'] === (int) $link['pos_tenant']
            && (int) $scope['branch'] === (int) $link['pos_branch'];
    }

    public static function userCanManageIntegration(mysqli $conn, $userId)
    {
        $userId = (int) $userId;
        if ($userId < 1) {
            return false;
        }

        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/Security/PermissionService.php';
        }

        return (new PermissionService($conn))->check($userId, 'moova.manage');
    }

    public static function saveActiveLinkForScope(mysqli $conn, array $scope, array $data)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $shopId = trim((string) ($data['moova_shop_id'] ?? ''));
        $branchId = trim((string) ($data['moova_branch_id'] ?? ''));
        $token = trim((string) ($data['moova_device_token'] ?? ''));
        $widgetUrl = trim((string) ($data['widget_url'] ?? ''));
        $locale = trim((string) ($data['locale'] ?? 'ar'));

        if ($token === '' || $widgetUrl === '' || $branchId === '') {
            throw new InvalidArgumentException('MISSING_REQUIRED_FIELDS');
        }

        $crypto = new SyncRuntimeCrypto();
        if (!$crypto->available()) {
            throw new RuntimeException('TOKEN_ENCRYPTION_UNAVAILABLE');
        }
        $encryptedToken = $crypto->encrypt($token);

        $tokenHash = self::hashDeviceToken($token);
        $last4 = substr($token, -4);
        $status = 'active';
        $connectionId = trim((string) ($data['moova_connection_id'] ?? ''));
        $branchLinkId = trim((string) ($data['moova_branch_link_id'] ?? ''));
        $pairingId = trim((string) ($data['pairing_id'] ?? ''));
        $instanceUuid = trim((string) ($data['pos_instance_uuid'] ?? ''));
        $shopName = trim((string) ($data['moova_shop_name'] ?? ''));
        $branchName = trim((string) ($data['moova_branch_name'] ?? ''));
        if ($connectionId === '' || $branchLinkId === '' || $pairingId === '' || !self::isUuid($instanceUuid)) {
            throw new InvalidArgumentException('INVALID_PAIRING_RESPONSE');
        }

        $deleteOutboxStmt = $conn->prepare("
            DELETE q FROM moova_catalog_sync_outbox q
            INNER JOIN moova_pos_shop_links l ON l.id = q.shop_link_id
            WHERE l.pos_tenant = ?
              AND l.pos_branch = ?
        ");
        $deleteOutboxStmt->bind_param("ii", $tenant, $branch);
        $deleteOutboxStmt->execute();
        $deleteOutboxStmt->close();

        $deleteStmt = $conn->prepare("
            DELETE FROM moova_pos_shop_links
            WHERE pos_tenant = ?
              AND pos_branch = ?
        ");
        $deleteStmt->bind_param("ii", $tenant, $branch);
        $deleteStmt->execute();
        $deleteStmt->close();

        $insertStmt = $conn->prepare("
            INSERT INTO moova_pos_shop_links (
                moova_shop_id, moova_branch_id, moova_device_token, moova_device_token_encrypted,
                moova_device_token_hash, moova_device_token_last4,
                moova_connection_id, moova_branch_link_id, pairing_id, pos_instance_uuid,
                moova_shop_name, moova_branch_name,
                pos_tenant, pos_branch, widget_url, locale, status
            ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $shopIdValue = $shopId === '' ? null : $shopId;
        $insertStmt->bind_param(
            "sssssssssssiisss",
            $shopIdValue,
            $branchId,
            $encryptedToken,
            $tokenHash,
            $last4,
            $connectionId,
            $branchLinkId,
            $pairingId,
            $instanceUuid,
            $shopName,
            $branchName,
            $tenant,
            $branch,
            $widgetUrl,
            $locale,
            $status
        );
        $insertStmt->execute();
        $id = (int) $conn->insert_id;
        $insertStmt->close();

        return [
            'id' => $id,
            'moova_shop_id' => $shopIdValue,
            'moova_branch_id' => $branchId,
            'moova_connection_id' => $connectionId,
            'moova_branch_link_id' => $branchLinkId,
            'pairing_id' => $pairingId,
            'pos_instance_uuid' => $instanceUuid,
            'moova_shop_name' => $shopName,
            'moova_branch_name' => $branchName,
            'moova_device_token_last4' => $last4,
            'pos_tenant' => $tenant,
            'pos_branch' => $branch,
            'widget_url' => $widgetUrl,
            'locale' => $locale,
            'status' => $status,
        ];
    }

    public static function deviceTokenForLink(array $link): string
    {
        $encrypted = trim((string) ($link['moova_device_token_encrypted'] ?? ''));
        if ($encrypted !== '') {
            return (new SyncRuntimeCrypto())->decrypt($encrypted);
        }

        // Read-only compatibility for installations created before encrypted
        // credential storage. The next successful pairing replaces this value.
        return trim((string) ($link['moova_device_token'] ?? ''));
    }

    public static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($value)) === 1;
    }

    public static function enqueueCatalogSync(mysqli $conn, array $link, ?string $fingerprint = null): void
    {
        $stmt = $conn->prepare("
            INSERT INTO moova_catalog_sync_outbox (
                shop_link_id, pos_tenant, pos_branch, requested_fingerprint, state, attempts, available_at, last_error
            ) VALUES (?, ?, ?, ?, 'pending', 0, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                requested_fingerprint = VALUES(requested_fingerprint),
                state = 'pending', attempts = 0, available_at = NOW(), last_error = NULL,
                updated_at = CURRENT_TIMESTAMP
        ");
        $linkId = (int) ($link['id'] ?? 0);
        $tenant = (int) ($link['pos_tenant'] ?? 0);
        $branch = (int) ($link['pos_branch'] ?? 0);
        $stmt->bind_param('iiis', $linkId, $tenant, $branch, $fingerprint);
        $stmt->execute();
        $stmt->close();
    }

    public static function markAllCatalogLinksDirty(mysqli $conn): void
    {
        self::ensureSchema($conn);
        $rows = $conn->query("SELECT * FROM moova_pos_shop_links WHERE status = 'active'");
        while ($link = $rows->fetch_assoc()) {
            self::enqueueCatalogSync($conn, $link, null);
        }
    }

    public static function recordCatalogSyncResult(mysqli $conn, int $linkId, string $fingerprint, array $result): void
    {
        if (!empty($result['ok'])) {
            $stmt = $conn->prepare("UPDATE moova_pos_shop_links SET last_catalog_fingerprint = ?, last_catalog_synced_at = NOW(), last_catalog_error = NULL, last_pair_verified_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $fingerprint, $linkId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE moova_catalog_sync_outbox SET state = 'complete', last_error = NULL WHERE shop_link_id = ?");
            $stmt->bind_param('i', $linkId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $error = trim((string) ($result['message'] ?? $result['reason'] ?? 'Moova catalog sync failed'));
        $stmt = $conn->prepare("UPDATE moova_pos_shop_links SET last_catalog_error = ? WHERE id = ?");
        $stmt->bind_param('si', $error, $linkId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("UPDATE moova_catalog_sync_outbox SET state = 'pending', attempts = attempts + 1, available_at = DATE_ADD(NOW(), INTERVAL LEAST(300, POW(2, LEAST(attempts, 8))) SECOND), last_error = ? WHERE shop_link_id = ?");
        $stmt->bind_param('si', $error, $linkId);
        $stmt->execute();
        $stmt->close();
    }

    public static function disconnectScope(mysqli $conn, array $scope)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);

        $stmt = $conn->prepare("
            UPDATE moova_pos_shop_links
            SET status = 'inactive', updated_at = CURRENT_TIMESTAMP
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND status = 'active'
        ");
        $stmt->bind_param("ii", $tenant, $branch);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    public static function normalizePayloadForHash(array $payload)
    {
        $items = [];
        foreach (($payload['items'] ?? []) as $item) {
            $items[] = [
                'itemId' => trim((string) ($item['itemId'] ?? '')),
                'qty' => (float) ($item['qty'] ?? 0),
            ];
        }

        return [
            'cofeOrderId' => trim((string) ($payload['cofeOrderId'] ?? '')),
            'branchId' => trim((string) ($payload['branchId'] ?? '')),
            'tableNumber' => trim((string) ($payload['tableNumber'] ?? '')),
            'items' => $items,
        ];
    }

    public static function payloadHash(array $payload)
    {
        return hash(
            'sha256',
            json_encode(self::normalizePayloadForHash($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function normalizeChangePayloadForHash(array $payload)
    {
        $items = [];
        foreach (($payload['items'] ?? []) as $item) {
            $items[] = [
                'itemId' => trim((string) ($item['itemId'] ?? '')),
                'qty' => (float) ($item['qty'] ?? 0),
            ];
        }

        return [
            'action' => trim((string) ($payload['action'] ?? '')),
            'moovaOrderId' => trim((string) ($payload['moovaOrderId'] ?? $payload['orderId'] ?? '')),
            'requestEventId' => trim((string) ($payload['requestEventId'] ?? '')),
            'providerOrderId' => trim((string) ($payload['providerOrderId'] ?? '')),
            'providerReferenceId' => trim((string) ($payload['providerReferenceId'] ?? '')),
            'items' => $items,
        ];
    }

    public static function changePayloadHash(array $payload)
    {
        return hash(
            'sha256',
            json_encode(self::normalizeChangePayloadForHash($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
