<?php

class DeliveryClientService
{
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', trim($phone));
    }

    public function findByPhone(mysqli $conn, string $phone): ?array
    {
        $phone = $this->normalizePhone($phone);
        if ($phone === '') {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT id, client_name, phone, address
             FROM delivery_clients
             WHERE phone = ? AND isdeleted = 0
             LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('DELIVERY_CLIENT_LOOKUP_FAILED: ' . $conn->error);
        }
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['client_name'],
            'phone' => (string) $row['phone'],
            'address' => (string) $row['address'],
        ];
    }

    public function upsertByPhone(mysqli $conn, string $phone, string $name, string $address): array
    {
        $phone = $this->normalizePhone($phone);
        $name = trim($name);
        $address = trim($address);

        if ($phone === '' || $name === '' || $address === '') {
            throw new InvalidArgumentException('DELIVERY_CLIENT_FIELDS_REQUIRED');
        }

        $stmt = $conn->prepare(
            'INSERT INTO delivery_clients (client_name, phone, address)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                client_name = VALUES(client_name),
                address = VALUES(address),
                isdeleted = 0,
                updated_at = CURRENT_TIMESTAMP'
        );
        if (!$stmt) {
            throw new RuntimeException('DELIVERY_CLIENT_UPSERT_FAILED: ' . $conn->error);
        }
        $stmt->bind_param('sss', $name, $phone, $address);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('DELIVERY_CLIENT_UPSERT_FAILED: ' . $error);
        }
        $stmt->close();

        $client = $this->findByPhone($conn, $phone);
        if (!$client) {
            throw new RuntimeException('DELIVERY_CLIENT_UPSERT_VERIFY_FAILED');
        }

        return [
            'success' => true,
            'client_id' => $client['id'],
            'name' => $client['name'],
            'phone' => $client['phone'],
            'address' => $client['address'],
        ];
    }
}
