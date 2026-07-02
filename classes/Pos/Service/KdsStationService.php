<?php

require_once __DIR__ . '/../../Sync/SchemaManager.php';

/**
 * Manages KDS stations, category routing, and worker assignment.
 *
 * A station represents one kitchen display screen. Menu categories
 * (item_group rows) are routed to exactly one station; anything not
 * mapped falls back to the default station so nothing is ever lost.
 */
class KdsStationService
{
    private SyncSchemaManager $schema;
    private array $scopeCache = [];

    public function __construct(?SyncSchemaManager $schema = null)
    {
        $this->schema = $schema ?: new SyncSchemaManager();
    }

    public function ensureSchema(mysqli $conn): void
    {
        $this->schema->applyKdsSchema($conn);
        $this->ensureDefaultStation($conn);
    }

    /**
     * Guarantees that a usable default station always exists so that a
     * brand-new install routes every order to a single board.
     */
    public function ensureDefaultStation(mysqli $conn): int
    {
        [$tenant, $branch] = $this->scope();

        $stmt = $conn->prepare("
            SELECT id FROM kds_stations
            WHERE tenant = ? AND branch = ? AND isdeleted = 0
            ORDER BY is_default DESC, sort_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return (int) $row['id'];
        }

        return $this->saveStation($conn, [
            'name' => 'المطبخ الرئيسي',
            'is_default' => 1,
            'is_active' => 1,
            'color' => '#e8a020',
        ]);
    }

    public function defaultStationId(mysqli $conn): int
    {
        [$tenant, $branch] = $this->scope();

        $stmt = $conn->prepare("
            SELECT id FROM kds_stations
            WHERE tenant = ? AND branch = ? AND isdeleted = 0 AND is_active = 1
            ORDER BY is_default DESC, sort_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            return (int) $row['id'];
        }

        return $this->ensureDefaultStation($conn);
    }

    public function listStations(mysqli $conn, bool $activeOnly = false): array
    {
        [$tenant, $branch] = $this->scope();
        $sql = "
            SELECT * FROM kds_stations
            WHERE tenant = ? AND branch = ? AND isdeleted = 0
        ";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $result = $stmt->get_result();
        $stations = [];
        while ($r = $result->fetch_assoc()) {
            $stations[] = $this->normalizeStation($r);
        }
        $stmt->close();

        return $stations;
    }

    public function getStation(mysqli $conn, int $stationId): ?array
    {
        [$tenant, $branch] = $this->scope();
        $stmt = $conn->prepare("
            SELECT * FROM kds_stations
            WHERE id = ? AND tenant = ? AND branch = ? AND isdeleted = 0
            LIMIT 1
        ");
        $stmt->bind_param('iii', $stationId, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->normalizeStation($row) : null;
    }

    public function getStationByUuid(mysqli $conn, string $uuid): ?array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }
        [$tenant, $branch] = $this->scope();
        $stmt = $conn->prepare("
            SELECT * FROM kds_stations
            WHERE uuid = ? AND tenant = ? AND branch = ? AND isdeleted = 0
            LIMIT 1
        ");
        $stmt->bind_param('sii', $uuid, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->normalizeStation($row) : null;
    }

    public function saveStation(mysqli $conn, array $data): int
    {
        [$tenant, $branch] = $this->scope();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('KDS_STATION_NAME_REQUIRED');
        }

        $color = $this->sanitizeColor((string) ($data['color'] ?? '#e8a020'));
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $autoComplete = !empty($data['auto_complete_on_paid']) ? 1 : 0;
        $warnAfter = max(0, (int) ($data['warn_after_seconds'] ?? 360));
        $lateAfter = max(0, (int) ($data['late_after_seconds'] ?? 720));
        $stationId = (int) ($data['id'] ?? 0);

        if ($stationId > 0) {
            $existing = $this->getStation($conn, $stationId);
            if (!$existing) {
                throw new InvalidArgumentException('KDS_STATION_NOT_FOUND');
            }
            $stmt = $conn->prepare("
                UPDATE kds_stations
                SET name = ?, color = ?, sort_order = ?, is_active = ?, is_default = ?,
                    auto_complete_on_paid = ?, warn_after_seconds = ?, late_after_seconds = ?
                WHERE id = ? AND tenant = ? AND branch = ?
            ");
            $stmt->bind_param(
                'ssiiiiiiiii',
                $name,
                $color,
                $sortOrder,
                $isActive,
                $isDefault,
                $autoComplete,
                $warnAfter,
                $lateAfter,
                $stationId,
                $tenant,
                $branch
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $uuid = $this->uuid();
            $routeToken = bin2hex(random_bytes(20));
            $stmt = $conn->prepare("
                INSERT INTO kds_stations
                    (uuid, name, color, route_token, sort_order, is_default, is_active,
                     auto_complete_on_paid, warn_after_seconds, late_after_seconds, tenant, branch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'ssssiiiiiiii',
                $uuid,
                $name,
                $color,
                $routeToken,
                $sortOrder,
                $isDefault,
                $isActive,
                $autoComplete,
                $warnAfter,
                $lateAfter,
                $tenant,
                $branch
            );
            $stmt->execute();
            $stationId = (int) $stmt->insert_id;
            $stmt->close();
        }

        if ($isDefault && $stationId > 0) {
            $stmt = $conn->prepare("
                UPDATE kds_stations SET is_default = 0
                WHERE tenant = ? AND branch = ? AND id <> ?
            ");
            $stmt->bind_param('iii', $tenant, $branch, $stationId);
            $stmt->execute();
            $stmt->close();
        }

        $this->guaranteeOneDefault($conn);

        return $stationId;
    }

    public function deleteStation(mysqli $conn, int $stationId): void
    {
        [$tenant, $branch] = $this->scope();
        $station = $this->getStation($conn, $stationId);
        if (!$station) {
            throw new InvalidArgumentException('KDS_STATION_NOT_FOUND');
        }

        $stmt = $conn->prepare("
            UPDATE kds_stations SET isdeleted = 1, is_active = 0, is_default = 0
            WHERE id = ? AND tenant = ? AND branch = ?
        ");
        $stmt->bind_param('iii', $stationId, $tenant, $branch);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM kds_station_categories WHERE station_id = ?");
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM kds_station_users WHERE station_id = ?");
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $stmt->close();

        $this->guaranteeOneDefault($conn);
    }

    // --- Category routing -------------------------------------------------

    /**
     * @return array<int,int> map of item_group_id => station_id
     */
    public function categoryMap(mysqli $conn): array
    {
        [$tenant, $branch] = $this->scope();
        $stmt = $conn->prepare("
            SELECT item_group_id, station_id
            FROM kds_station_categories
            WHERE tenant = ? AND branch = ?
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($r = $result->fetch_assoc()) {
            $map[(int) $r['item_group_id']] = (int) $r['station_id'];
        }
        $stmt->close();

        return $map;
    }

    /**
     * Routes a category to a station. Passing stationId <= 0 removes the
     * mapping (the category then falls back to the default station).
     */
    public function setCategoryStation(mysqli $conn, int $itemGroupId, int $stationId): void
    {
        if ($itemGroupId < 1) {
            throw new InvalidArgumentException('KDS_ITEM_GROUP_REQUIRED');
        }
        [$tenant, $branch] = $this->scope();

        if ($stationId < 1) {
            $stmt = $conn->prepare("
                DELETE FROM kds_station_categories
                WHERE item_group_id = ? AND tenant = ? AND branch = ?
            ");
            $stmt->bind_param('iii', $itemGroupId, $tenant, $branch);
            $stmt->execute();
            $stmt->close();
            return;
        }

        if (!$this->getStation($conn, $stationId)) {
            throw new InvalidArgumentException('KDS_STATION_NOT_FOUND');
        }

        $stmt = $conn->prepare("
            INSERT INTO kds_station_categories (station_id, item_group_id, tenant, branch)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE station_id = VALUES(station_id)
        ");
        $stmt->bind_param('iiii', $stationId, $itemGroupId, $tenant, $branch);
        $stmt->execute();
        $stmt->close();
    }

    // --- Worker assignment ------------------------------------------------

    public function assignUser(mysqli $conn, int $stationId, int $userId): void
    {
        if ($stationId < 1 || $userId < 1) {
            throw new InvalidArgumentException('KDS_ASSIGNMENT_INVALID');
        }
        if (!$this->getStation($conn, $stationId)) {
            throw new InvalidArgumentException('KDS_STATION_NOT_FOUND');
        }
        $stmt = $conn->prepare("
            INSERT IGNORE INTO kds_station_users (station_id, user_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param('ii', $stationId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function unassignUser(mysqli $conn, int $stationId, int $userId): void
    {
        $stmt = $conn->prepare("DELETE FROM kds_station_users WHERE station_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $stationId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function userIdsForStation(mysqli $conn, int $stationId): array
    {
        $stmt = $conn->prepare("SELECT user_id FROM kds_station_users WHERE station_id = ?");
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($r = $result->fetch_assoc()) {
            $ids[] = (int) $r['user_id'];
        }
        $stmt->close();

        return $ids;
    }

    /**
     * Returns true when the station has at least one explicit worker
     * assignment (i.e. access is restricted to assigned workers).
     */
    public function stationHasAssignments(mysqli $conn, int $stationId): bool
    {
        $stmt = $conn->prepare("SELECT 1 FROM kds_station_users WHERE station_id = ? LIMIT 1");
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * A worker may open a station when the station has no explicit
     * assignment list, or when the worker is on that list. Admins are
     * handled by the caller (they may always open any station).
     */
    public function userCanAccessStation(mysqli $conn, int $stationId, int $userId): bool
    {
        if (!$this->stationHasAssignments($conn, $stationId)) {
            return true;
        }
        if ($userId < 1) {
            return false;
        }
        $stmt = $conn->prepare("SELECT 1 FROM kds_station_users WHERE station_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $stationId, $userId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * Lists stations a given user is allowed to open. Admins see all
     * active stations; non-admins see unrestricted stations plus those
     * they are explicitly assigned to.
     */
    public function stationsForUser(mysqli $conn, int $userId, bool $isAdmin): array
    {
        $stations = $this->listStations($conn, true);
        if ($isAdmin) {
            return $stations;
        }

        $allowed = [];
        foreach ($stations as $station) {
            if ($this->userCanAccessStation($conn, (int) $station['id'], $userId)) {
                $allowed[] = $station;
            }
        }

        return $allowed;
    }

    public function categories(mysqli $conn): array
    {
        $result = $conn->query("SELECT id, gname FROM item_group WHERE isdeleted = 0 ORDER BY gname ASC");
        $rows = [];
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = [
                    'id' => (int) $r['id'],
                    'name' => (string) ($r['gname'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    // --- Helpers ----------------------------------------------------------

    private function guaranteeOneDefault(mysqli $conn): void
    {
        [$tenant, $branch] = $this->scope();
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c FROM kds_stations
            WHERE tenant = ? AND branch = ? AND isdeleted = 0 AND is_default = 1
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        if ($count >= 1) {
            return;
        }

        $stmt = $conn->prepare("
            UPDATE kds_stations SET is_default = 1
            WHERE tenant = ? AND branch = ? AND isdeleted = 0
            ORDER BY is_active DESC, sort_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $stmt->close();
    }

    private function normalizeStation(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'color' => (string) $row['color'],
            'route_token' => (string) $row['route_token'],
            'sort_order' => (int) $row['sort_order'],
            'is_default' => (int) $row['is_default'] === 1,
            'is_active' => (int) $row['is_active'] === 1,
            'auto_complete_on_paid' => (int) $row['auto_complete_on_paid'] === 1,
            'warn_after_seconds' => (int) $row['warn_after_seconds'],
            'late_after_seconds' => (int) $row['late_after_seconds'],
        ];
    }

    private function sanitizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color)) {
            return $color;
        }

        return '#e8a020';
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function scope(): array
    {
        if ($this->scopeCache) {
            return $this->scopeCache;
        }

        $tenant = 0;
        $branch = 0;
        if (function_exists('posmain_config')) {
            $config = posmain_config();
            $branchCfg = is_array($config['branch'] ?? null) ? $config['branch'] : [];
            $tenant = (int) ($branchCfg['pos_tenant'] ?? 0);
            $branch = (int) ($branchCfg['pos_branch'] ?? 0);
        }

        return $this->scopeCache = [$tenant, $branch];
    }
}
