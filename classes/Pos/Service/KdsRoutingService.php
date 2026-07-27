<?php

require_once __DIR__ . '/KdsStationService.php';

/**
 * Routes order lines to KDS stations based on each item's category
 * (myitems.group1 -> item_group). Categories with no explicit station
 * mapping fall back to the default station, so a line is never dropped.
 */
class KdsRoutingService
{
    private KdsStationService $stations;
    private array $columnCache = [];

    public function __construct(?KdsStationService $stations = null)
    {
        $this->stations = $stations ?: new KdsStationService();
    }

    /**
     * @param array $lines KOT payload lines (each with detail_id, item_id, ...)
     * @return array<int,array{station_id:int,lines:array}> keyed by station id
     */
    public function routeLines(mysqli $conn, array $lines): array
    {
        $defaultStationId = $this->stations->defaultStationId($conn);
        $categoryMap = $this->stations->categoryMap($conn);

        $itemIds = [];
        foreach ($lines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            if ($itemId > 0) {
                $itemIds[$itemId] = true;
            }
        }
        $itemGroups = $this->itemGroupMap($conn, array_keys($itemIds));

        $routed = [];
        foreach ($lines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $snapshotGroupId = (int) ($line['item_group_id'] ?? 0);
            $groupId = $snapshotGroupId > 0
                ? $snapshotGroupId
                : ($itemId > 0 ? (int) ($itemGroups[$itemId] ?? 0) : 0);
            $stationId = $groupId > 0 && isset($categoryMap[$groupId])
                ? (int) $categoryMap[$groupId]
                : $defaultStationId;

            if ($stationId < 1) {
                $stationId = $defaultStationId;
            }

            if (!isset($routed[$stationId])) {
                $routed[$stationId] = ['station_id' => $stationId, 'lines' => []];
            }

            $line['item_group_id'] = $groupId > 0 ? $groupId : null;
            $routed[$stationId]['lines'][] = $line;
        }

        return $routed;
    }

    /**
     * @param int[] $itemIds
     * @return array<int,int> item_id => group1
     */
    private function itemGroupMap(mysqli $conn, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static function ($id) {
            return $id > 0;
        })));
        if (!$itemIds) {
            return [];
        }
        if (!$this->columnExists($conn, 'myitems', 'group1')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types = str_repeat('i', count($itemIds));
        $stmt = $conn->prepare("SELECT id, group1 FROM myitems WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$itemIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($r = $result->fetch_assoc()) {
            $map[(int) $r['id']] = (int) ($r['group1'] ?? 0);
        }
        $stmt->close();

        return $map;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $cacheKey = spl_object_id($conn) . ':' . $table . ':' . $column;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $this->columnCache[$cacheKey] = ((int) ($row['c'] ?? 0)) > 0;
    }
}
