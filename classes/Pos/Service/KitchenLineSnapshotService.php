<?php

/**
 * Captures the exact persisted order-line kitchen contract at first KOT send.
 *
 * The JSON snapshot is immutable for a (order, fat_details row). KDS refresh,
 * reconnect, print and sync consumers therefore never have to reconstruct a
 * sent ticket from mutable menu configuration.
 */
class KitchenLineSnapshotService
{
    public const VERSION = 1;

    /**
     * @param array<int,array<string,mixed>> $candidateLines
     * @return array<int,array<string,mixed>>
     */
    public function captureForOrder(mysqli $conn, int $orderId, array $candidateLines): array
    {
        if (!$this->tableExists($conn)) {
            throw new RuntimeException('KITCHEN_SNAPSHOT_SCHEMA_NOT_READY');
        }
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }

        foreach (array_values($candidateLines) as $position => $line) {
            $payload = $this->normalizeLine($line, $position);
            $json = $this->encode($payload);
            $hash = hash('sha256', $json);
            $detailId = (int) $payload['detail_id'];
            $displayOrder = (int) $payload['display_order'];
            $version = self::VERSION;

            $stmt = $conn->prepare("
                INSERT INTO order_line_kitchen_snapshots
                    (order_id, detail_id, display_order, snapshot_version, snapshot_hash, payload_json)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE id = id
            ");
            $stmt->bind_param('iiiiss', $orderId, $detailId, $displayOrder, $version, $hash, $json);
            $stmt->execute();
            $stmt->close();
        }

        $snapshots = $this->fetchForOrder($conn, $orderId);
        if (count($snapshots) !== count($candidateLines)) {
            throw new RuntimeException('KITCHEN_SNAPSHOT_LINE_COUNT_MISMATCH');
        }

        return $snapshots;
    }

    /**
     * Return an existing sent snapshot for print/read consumers, or null when
     * the order has never been sent to the kitchen.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public function existingForOrder(mysqli $conn, int $orderId): ?array
    {
        if (!$this->tableExists($conn)) {
            return null;
        }
        $lines = $this->fetchForOrder($conn, $orderId);

        return $lines ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchForOrder(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare("
            SELECT s.detail_id, s.display_order, s.snapshot_version, s.snapshot_hash, s.payload_json
            FROM order_line_kitchen_snapshots s
            INNER JOIN fat_details fd
                ON fd.id = s.detail_id
               AND fd.fatid = s.order_id
               AND fd.isdeleted = 0
            WHERE s.order_id = ?
            ORDER BY s.display_order ASC, s.id ASC
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                $stmt->close();
                throw new RuntimeException('KITCHEN_SNAPSHOT_JSON_INVALID');
            }
            $normalized = $this->normalizeLine($decoded, (int) $row['display_order']);
            $json = $this->encode($normalized);
            $hash = hash('sha256', $json);
            if (!hash_equals((string) $row['snapshot_hash'], $hash)) {
                $stmt->close();
                throw new RuntimeException('KITCHEN_SNAPSHOT_HASH_MISMATCH');
            }
            if ((int) $row['snapshot_version'] !== self::VERSION) {
                $stmt->close();
                throw new RuntimeException('KITCHEN_SNAPSHOT_VERSION_UNSUPPORTED');
            }
            $normalized['snapshot_version'] = self::VERSION;
            $normalized['snapshot_hash'] = $hash;
            $normalized['kitchen_line_key'] = $this->lineKey($normalized);
            $lines[] = $normalized;
        }
        $stmt->close();

        return $lines;
    }

    /** @return array<string,mixed> */
    private function normalizeLine(array $line, int $position): array
    {
        $detailId = (int) ($line['detail_id'] ?? 0);
        $itemId = (int) ($line['item_id'] ?? 0);
        $name = trim((string) ($line['name'] ?? ''));
        $qty = (float) ($line['qty'] ?? 0);
        if ($detailId < 1 || $itemId < 1 || $name === '' || !is_finite($qty) || $qty <= 0) {
            throw new RuntimeException('KITCHEN_SNAPSHOT_LINE_INVALID');
        }

        $modifiers = [];
        foreach (($line['modifiers'] ?? []) as $modifierPosition => $modifier) {
            if (!is_array($modifier)) {
                throw new RuntimeException('KITCHEN_SNAPSHOT_MODIFIER_INVALID');
            }
            $labelAr = trim((string) ($modifier['name_ar'] ?? ''));
            $labelEn = trim((string) ($modifier['name_en'] ?? ''));
            $modifierQty = (float) ($modifier['qty'] ?? 0);
            if (($labelAr === '' && $labelEn === '') || !is_finite($modifierQty) || $modifierQty <= 0) {
                throw new RuntimeException('KITCHEN_SNAPSHOT_MODIFIER_INVALID');
            }
            $modifiers[] = [
                'display_order' => (int) $modifierPosition,
                'modifier_group_id' => (int) ($modifier['modifier_group_id'] ?? 0),
                'modifier_option_id' => (int) ($modifier['modifier_option_id'] ?? $modifier['option_id'] ?? 0),
                'qty' => $this->decimal($modifierQty, 3),
                'price_delta' => $this->decimal($modifier['price_delta'] ?? 0, 3),
                'line_delta' => $this->decimal($modifier['line_delta'] ?? ($modifierQty * (float) ($modifier['price_delta'] ?? 0)), 3),
                'name_ar' => $labelAr !== '' ? $labelAr : null,
                'name_en' => $labelEn !== '' ? $labelEn : null,
            ];
        }

        $notes = [];
        foreach (($line['notes'] ?? []) as $notePosition => $note) {
            if (!is_array($note)) {
                throw new RuntimeException('KITCHEN_SNAPSHOT_NOTE_INVALID');
            }
            $text = trim((string) ($note['note_text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('KITCHEN_SNAPSHOT_NOTE_INVALID');
            }
            $notes[] = [
                'display_order' => (int) $notePosition,
                'note_type' => (string) ($note['note_type'] ?? 'kitchen'),
                'note_text' => $text,
                'created_by' => isset($note['created_by']) ? (int) $note['created_by'] : null,
            ];
        }

        $preparation = [];
        foreach (($line['preparation_values'] ?? []) as $preparationPosition => $value) {
            if (!is_array($value)) {
                throw new RuntimeException('KITCHEN_SNAPSHOT_PREPARATION_INVALID');
            }
            $code = trim((string) ($value['code'] ?? ''));
            $label = trim((string) ($value['label_ar'] ?? ''));
            $selected = $value['value'] ?? null;
            if ($code === '' || $label === '' || filter_var($selected, FILTER_VALIDATE_INT) === false || (int) $selected < 0) {
                throw new RuntimeException('KITCHEN_SNAPSHOT_PREPARATION_INVALID');
            }
            $preparation[] = [
                'display_order' => (int) $preparationPosition,
                'code' => $code,
                'label_ar' => $label,
                'value' => (int) $selected,
                'max_value' => (int) ($value['max_value'] ?? 0),
            ];
        }

        return [
            'detail_id' => $detailId,
            'item_id' => $itemId,
            'item_group_id' => isset($line['item_group_id']) ? (int) $line['item_group_id'] : null,
            'display_order' => max(0, $position),
            'name' => $name,
            'qty' => $this->decimal($qty, 3),
            'price' => $this->decimal($line['price'] ?? 0, 2),
            'line_total' => $this->decimal($line['line_total'] ?? 0, 2),
            'legacy_notes' => trim((string) ($line['legacy_notes'] ?? '')) ?: null,
            'modifiers' => $modifiers,
            'notes' => $notes,
            'preparation_values' => $preparation,
        ];
    }

    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new RuntimeException('KITCHEN_SNAPSHOT_JSON_ENCODE_FAILED');
        }

        return $json;
    }

    private function lineKey(array $payload): string
    {
        $identity = [
            'item_id' => (int) $payload['item_id'],
            'display_order' => (int) $payload['display_order'],
            'name' => (string) $payload['name'],
            'legacy_notes' => $payload['legacy_notes'] ?? null,
            'modifiers' => $payload['modifiers'] ?? [],
            'notes' => $payload['notes'] ?? [],
            'preparation_values' => $payload['preparation_values'] ?? [],
        ];

        return hash('sha256', $this->encode($identity));
    }

    private function decimal($value, int $scale): string
    {
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new RuntimeException('KITCHEN_SNAPSHOT_NUMBER_INVALID');
        }

        return number_format($number, $scale, '.', '');
    }

    private function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'order_line_kitchen_snapshots'");

        return $result && $result->num_rows > 0;
    }
}
