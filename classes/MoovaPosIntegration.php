<?php

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
                moova_device_token_hash CHAR(64) NOT NULL,
                moova_device_token_last4 VARCHAR(16) DEFAULT NULL,
                pos_tenant INT(11) NOT NULL DEFAULT 0,
                pos_branch INT(11) NOT NULL DEFAULT 0,
                widget_url VARCHAR(255) NOT NULL DEFAULT 'https://withmoova.com/pos-widget',
                locale VARCHAR(16) NOT NULL DEFAULT 'ar',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
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

        self::ensureColumn(
            $conn,
            'moova_pos_shop_links',
            'moova_device_token',
            "ALTER TABLE moova_pos_shop_links ADD COLUMN moova_device_token VARCHAR(191) DEFAULT NULL AFTER moova_branch_id"
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

        $stmt = $conn->prepare("
            SELECT u.userrole, COALESCE(r.show_users, 0) AS show_users
            FROM users u
            LEFT JOIN usr_pwrs r ON r.id = u.userrole
            WHERE u.id = ?
              AND u.isdeleted != 1
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        return (int) ($row['show_users'] ?? 0) === 1 || (int) ($row['userrole'] ?? 0) === 1;
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

        if ($token === '' || $widgetUrl === '') {
            throw new InvalidArgumentException('MISSING_REQUIRED_FIELDS');
        }

        $tokenHash = self::hashDeviceToken($token);
        $last4 = substr($token, -4);
        $status = 'active';

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
                moova_shop_id, moova_branch_id, moova_device_token,
                moova_device_token_hash, moova_device_token_last4,
                pos_tenant, pos_branch, widget_url, locale, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $shopIdValue = $shopId === '' ? null : $shopId;
        $insertStmt->bind_param(
            "sssssiisss",
            $shopIdValue,
            $branchId,
            $token,
            $tokenHash,
            $last4,
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
            'moova_device_token_last4' => $last4,
            'pos_tenant' => $tenant,
            'pos_branch' => $branch,
            'widget_url' => $widgetUrl,
            'locale' => $locale,
            'status' => $status,
        ];
    }

    public static function disconnectScope(mysqli $conn, array $scope)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);

        $stmt = $conn->prepare("
            DELETE FROM moova_pos_shop_links
            WHERE pos_tenant = ?
              AND pos_branch = ?
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
}
