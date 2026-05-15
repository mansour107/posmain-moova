<?php

class ItemAvailabilityService
{
    public function setAvailability(
        mysqli $conn,
        int $itemId,
        bool $isAvailable,
        array $scope = [],
        ?string $reason = null,
        ?int $updatedBy = null
    ): array {
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? $scope['pos_tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? $scope['pos_branch'] ?? 0, 'BRANCH_INVALID');
        $channel = $this->channel($scope['channel'] ?? 'all');
        $reason = $this->nullableText($reason, 255);

        $isAvailableInt = $isAvailable ? 1 : 0;
        $stmt = $conn->prepare("
            INSERT INTO item_availability (
                item_id, tenant, branch, channel, is_available,
                unavailable_reason, updated_by, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_available = VALUES(is_available),
                unavailable_reason = VALUES(unavailable_reason),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $stmt->bind_param('iiisisi', $itemId, $tenant, $branch, $channel, $isAvailableInt, $reason, $updatedBy);
        $stmt->execute();
        $stmt->close();

        return $this->availabilityForItem($conn, $itemId, [
            'tenant' => $tenant,
            'branch' => $branch,
            'channel' => $channel,
        ]);
    }

    public function availabilityForItem(mysqli $conn, int $itemId, array $scope = []): array
    {
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? $scope['pos_tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? $scope['pos_branch'] ?? 0, 'BRANCH_INVALID');
        $channel = $this->channel($scope['channel'] ?? 'all');

        $row = $this->fetchAvailabilityRow($conn, $itemId, $tenant, $branch, $channel)
            ?: $this->fetchAvailabilityRow($conn, $itemId, $tenant, $branch, 'all');

        if (!$row) {
            return $this->defaultAvailability($itemId, $tenant, $branch, $channel);
        }

        return $this->formatAvailability($row, $tenant, $branch, $channel);
    }

    public function assertSellable(mysqli $conn, int $itemId, array $scope = []): array
    {
        $availability = $this->availabilityForItem($conn, $itemId, $scope);
        if (!$availability['is_available']) {
            throw new RuntimeException('ITEM_UNAVAILABLE');
        }

        return $availability;
    }

    public function decorateItems(mysqli $conn, array $items, array $scope = []): array
    {
        if (!$items) {
            return [];
        }

        $decorated = [];
        $availabilityByItem = $this->availabilityForItems($conn, $this->itemIds($items), $scope);
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $availability = $availabilityByItem[$itemId] ?? $this->availabilityForItem($conn, $itemId, $scope);
            $item['is_available'] = $availability['is_available'] ? 1 : 0;
            $item['unavailable_reason'] = $availability['unavailable_reason'];
            $item['availability_channel'] = $availability['channel'];
            $decorated[] = $item;
        }

        return $decorated;
    }

    public function availabilityForItems(mysqli $conn, array $itemIds, array $scope = []): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), function ($id) {
            return $id > 0;
        })));

        if (!$itemIds) {
            return [];
        }

        $tenant = $this->nonNegativeInt($scope['tenant'] ?? $scope['pos_tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? $scope['pos_branch'] ?? 0, 'BRANCH_INVALID');
        $channel = $this->channel($scope['channel'] ?? 'all');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types = str_repeat('i', count($itemIds)) . 'iis';
        $params = array_merge($itemIds, [$tenant, $branch, $channel]);

        $stmt = $conn->prepare("
            SELECT ia.*
            FROM item_availability ia
            JOIN (
                SELECT item_id, MAX(CASE WHEN channel = ? THEN 2 ELSE 1 END) AS priority
                FROM item_availability
                WHERE item_id IN ({$placeholders})
                  AND tenant = ?
                  AND branch = ?
                  AND channel IN (?, 'all')
                GROUP BY item_id
            ) chosen
              ON chosen.item_id = ia.item_id
             AND chosen.priority = CASE WHEN ia.channel = ? THEN 2 ELSE 1 END
            WHERE ia.item_id IN ({$placeholders})
              AND ia.tenant = ?
              AND ia.branch = ?
              AND ia.channel IN (?, 'all')
        ");

        $queryParams = array_merge(
            [$channel],
            $itemIds,
            [$tenant, $branch, $channel, $channel],
            $itemIds,
            [$tenant, $branch, $channel]
        );
        $queryTypes = 's' . str_repeat('i', count($itemIds)) . 'iis' . 's' . str_repeat('i', count($itemIds)) . 'iis';
        $stmt->bind_param($queryTypes, ...$queryParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $availability = [];
        foreach ($itemIds as $itemId) {
            $availability[$itemId] = $this->defaultAvailability($itemId, $tenant, $branch, $channel);
        }

        while ($row = $result->fetch_assoc()) {
            $itemId = (int) $row['item_id'];
            $availability[$itemId] = $this->formatAvailability($row, $tenant, $branch, $channel);
        }
        $stmt->close();

        return $availability;
    }

    private function fetchAvailabilityRow(mysqli $conn, int $itemId, int $tenant, int $branch, string $channel): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM item_availability
            WHERE item_id = ?
              AND tenant = ?
              AND branch = ?
              AND channel = ?
            LIMIT 1
        ");
        $stmt->bind_param('iiis', $itemId, $tenant, $branch, $channel);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function formatAvailability(array $row, int $tenant, int $branch, string $requestedChannel): array
    {
        return [
            'item_id' => (int) $row['item_id'],
            'tenant' => $tenant,
            'branch' => $branch,
            'channel' => (string) $row['channel'],
            'requested_channel' => $requestedChannel,
            'is_available' => (int) $row['is_available'] === 1,
            'unavailable_reason' => $row['unavailable_reason'] !== null ? (string) $row['unavailable_reason'] : null,
            'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function defaultAvailability(int $itemId, int $tenant, int $branch, string $channel): array
    {
        return [
            'item_id' => $itemId,
            'tenant' => $tenant,
            'branch' => $branch,
            'channel' => $channel,
            'requested_channel' => $channel,
            'is_available' => true,
            'unavailable_reason' => null,
            'updated_by' => null,
            'updated_at' => null,
        ];
    }

    private function itemIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) ($item['id'] ?? $item['item_id'] ?? 0);
        }

        return $ids;
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function nonNegativeInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function channel($value): string
    {
        $channel = strtolower(trim((string) $value));
        if ($channel === '') {
            return 'all';
        }

        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $channel)) {
            throw new InvalidArgumentException('CHANNEL_INVALID');
        }

        return $channel;
    }

    private function nullableText(?string $value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }
}
