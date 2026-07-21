<?php

class DeliveryZoneService
{
    public function resolvePostedZone(mysqli $conn, array $request): array
    {
        $tenant = max(0, (int) ($request['tenant'] ?? $request['pos_tenant'] ?? $_SESSION['pos_tenant'] ?? 0));
        $branch = max(0, (int) ($request['branch'] ?? $request['pos_branch'] ?? $_SESSION['pos_branch'] ?? 0));
        $zoneId = (int) ($request['delivery_zone_id'] ?? 0);
        if ($zoneId > 0) {
            $zone = $this->findActiveZoneById($conn, $zoneId, $tenant, $branch);
            if (!$zone) {
                throw new InvalidArgumentException('DELIVERY_ZONE_INVALID');
            }

            return [
                'delivery_zone_id' => $zoneId,
                'delivery_zone_name' => (string) $zone['name'],
                'delivery_fee' => (float) $zone['fee'],
            ];
        }

        $zoneName = trim((string) ($request['delivery_zone_name'] ?? ''));
        if ($zoneName !== '') {
            $zone = $this->findActiveZoneByName($conn, $zoneName, $tenant, $branch);
            if ($zone) {
                return [
                    'delivery_zone_id' => (int) $zone['id'],
                    'delivery_zone_name' => (string) $zone['name'],
                    'delivery_fee' => (float) $zone['fee'],
                ];
            }
        }

        // Once a branch has configured zones, the server owns both the selected
        // area and its fee. This prevents a stale or tampered cashier payload
        // from silently introducing a manual delivery charge. Branches with no
        // zone configuration retain the legacy manual-fee fallback.
        if ($this->hasActiveZones($conn, $tenant, $branch)) {
            throw new InvalidArgumentException('DELIVERY_ZONE_INVALID');
        }

        return [
            'delivery_zone_id' => null,
            'delivery_zone_name' => $zoneName,
            'delivery_fee' => max(0, (float) ($request['delivery_fee'] ?? 0)),
        ];
    }

    private function findActiveZoneById(mysqli $conn, int $zoneId, int $tenant, int $branch): ?array
    {
        if (!$this->tableExists($conn)) {
            return null;
        }

        $stmt = $conn->prepare('SELECT id, name, fee FROM delivery_zones WHERE id = ? AND is_active = 1 AND tenant = ? AND branch = ? LIMIT 1');
        $stmt->bind_param('iii', $zoneId, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function findActiveZoneByName(mysqli $conn, string $zoneName, int $tenant, int $branch): ?array
    {
        if (!$this->tableExists($conn)) {
            return null;
        }

        $stmt = $conn->prepare('SELECT id, name, fee FROM delivery_zones WHERE name = ? AND is_active = 1 AND tenant = ? AND branch = ? LIMIT 1');
        $stmt->bind_param('sii', $zoneName, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'delivery_zones'");

        return $result && $result->num_rows > 0;
    }

    private function hasActiveZones(mysqli $conn, int $tenant, int $branch): bool
    {
        if (!$this->tableExists($conn)) {
            return false;
        }

        $stmt = $conn->prepare('SELECT id FROM delivery_zones WHERE is_active = 1 AND tenant = ? AND branch = ? LIMIT 1');
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $found;
    }
}
