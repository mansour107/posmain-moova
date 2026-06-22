<?php

class DeliveryZoneService
{
    public function resolvePostedZone(mysqli $conn, array $request): array
    {
        $zoneId = (int) ($request['delivery_zone_id'] ?? 0);
        if ($zoneId > 0) {
            $zone = $this->findActiveZoneById($conn, $zoneId);
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
            $zone = $this->findActiveZoneByName($conn, $zoneName);
            if ($zone) {
                return [
                    'delivery_zone_id' => (int) $zone['id'],
                    'delivery_zone_name' => (string) $zone['name'],
                    'delivery_fee' => (float) $zone['fee'],
                ];
            }
        }

        return [
            'delivery_zone_id' => null,
            'delivery_zone_name' => $zoneName,
            'delivery_fee' => max(0, (float) ($request['delivery_fee'] ?? 0)),
        ];
    }

    private function findActiveZoneById(mysqli $conn, int $zoneId): ?array
    {
        if (!$this->tableExists($conn)) {
            return null;
        }

        $stmt = $conn->prepare('SELECT id, name, fee FROM delivery_zones WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('i', $zoneId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function findActiveZoneByName(mysqli $conn, string $zoneName): ?array
    {
        if (!$this->tableExists($conn)) {
            return null;
        }

        $stmt = $conn->prepare('SELECT id, name, fee FROM delivery_zones WHERE name = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('s', $zoneName);
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
}
