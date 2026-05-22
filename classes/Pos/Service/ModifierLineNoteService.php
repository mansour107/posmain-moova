<?php

class ModifierLineNoteService
{
    public function saveLineCustomizations(
        mysqli $conn,
        int $orderId,
        int $detailId,
        int $itemId,
        array $selectedOptions,
        array $notes = [],
        array $context = []
    ): array {
        $orderId = $this->positiveInt($orderId, 'ORDER_ID_REQUIRED');
        $detailId = $this->positiveInt($detailId, 'DETAIL_ID_REQUIRED');
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');

        if (!$this->modifiersEnabled($context)) {
            return [
                'success' => false,
                'code' => 'MODIFIERS_DISABLED',
                'enabled' => false,
                'modifiers' => [],
                'notes' => [],
                'modifier_total' => '0.000',
            ];
        }

        $preview = $this->previewLineModifiers($conn, $itemId, $selectedOptions, $context);
        $validated = $preview['modifiers'];
        $lineNotes = $this->normalizeNotes($notes, $context);

        $this->replacePersistedModifiers($conn, $orderId, $detailId, $validated);
        $this->replacePersistedNotes($conn, $orderId, $detailId, $lineNotes);

        return [
            'success' => true,
            'code' => 'OK',
            'enabled' => true,
            'order_id' => $orderId,
            'detail_id' => $detailId,
            'item_id' => $itemId,
            'modifiers' => $validated,
            'notes' => $lineNotes,
            'modifier_total' => $preview['modifier_total'],
        ];
    }

    public function previewLineModifiers(mysqli $conn, int $itemId, array $selectedOptions, array $context = []): array
    {
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');

        if (!$this->modifiersEnabled($context)) {
            return [
                'success' => false,
                'code' => 'MODIFIERS_DISABLED',
                'enabled' => false,
                'modifiers' => [],
                'modifier_total' => '0.000',
            ];
        }

        $groups = $this->modifierGroupsForItem($conn, $itemId);
        $selections = $this->normalizeSelections($selectedOptions);
        $options = $this->modifierOptionsForItem($conn, $itemId, array_keys($selections));
        $validated = $this->validateSelections($groups, $options, $selections);

        return [
            'success' => true,
            'code' => 'OK',
            'enabled' => true,
            'item_id' => $itemId,
            'modifiers' => $validated,
            'modifier_total' => $this->formatDecimal($this->modifierTotal($validated)),
        ];
    }

    public function fetchLineCustomizations(mysqli $conn, int $orderId, int $detailId): array
    {
        $orderId = $this->positiveInt($orderId, 'ORDER_ID_REQUIRED');
        $detailId = $this->positiveInt($detailId, 'DETAIL_ID_REQUIRED');

        return [
            'order_id' => $orderId,
            'detail_id' => $detailId,
            'modifiers' => $this->fetchPersistedModifiers($conn, $orderId, $detailId),
            'notes' => $this->fetchPersistedNotes($conn, $orderId, $detailId),
        ];
    }

    private function modifiersEnabled(array $context): bool
    {
        if (array_key_exists('modifiers_enabled', $context)) {
            return (bool) $context['modifiers_enabled'];
        }

        if (function_exists('posmain_app_config')) {
            $config = posmain_app_config();
            return !empty($config['features']['modifiers']);
        }

        $value = getenv('POSMAIN_ENABLE_MODIFIERS');
        if ($value === false || $value === '') {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function modifierGroupsForItem(mysqli $conn, int $itemId): array
    {
        $stmt = $conn->prepare("
            SELECT
                mg.id,
                mg.selection_min,
                mg.selection_max,
                mg.is_required,
                mg.sort_order
            FROM item_modifier_groups img
            JOIN modifier_groups mg ON mg.id = img.group_id
            WHERE img.item_id = ?
              AND mg.is_active = 1
            ORDER BY img.sort_order, mg.sort_order, mg.id
        ");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $result = $stmt->get_result();

        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[(int) $row['id']] = [
                'group_id' => (int) $row['id'],
                'selection_min' => max(0, (int) $row['selection_min']),
                'selection_max' => max(0, (int) $row['selection_max']),
                'is_required' => (int) $row['is_required'] === 1,
            ];
        }
        $stmt->close();

        return $groups;
    }

    private function modifierOptionsForItem(mysqli $conn, int $itemId, array $optionIds): array
    {
        if (!$optionIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
        $stmt = $conn->prepare("
            SELECT
                mo.id,
                mo.group_id,
                mo.name_ar,
                mo.name_en,
                mo.price_delta
            FROM modifier_options mo
            JOIN modifier_groups mg ON mg.id = mo.group_id
            JOIN item_modifier_groups img ON img.group_id = mg.id AND img.item_id = ?
            WHERE mo.id IN ({$placeholders})
              AND mo.is_active = 1
              AND mg.is_active = 1
            ORDER BY mg.sort_order, mo.sort_order, mo.id
        ");
        $types = 'i' . str_repeat('i', count($optionIds));
        $params = array_merge([$itemId], $optionIds);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $options = [];
        while ($row = $result->fetch_assoc()) {
            $options[(int) $row['id']] = [
                'option_id' => (int) $row['id'],
                'group_id' => (int) $row['group_id'],
                'name_ar' => (string) $row['name_ar'],
                'name_en' => $row['name_en'] !== null ? (string) $row['name_en'] : null,
                'price_delta' => (float) $row['price_delta'],
            ];
        }
        $stmt->close();

        return $options;
    }

    private function normalizeSelections(array $selectedOptions): array
    {
        $normalized = [];
        foreach ($selectedOptions as $selection) {
            if (is_array($selection)) {
                $optionId = $this->positiveInt($selection['option_id'] ?? $selection['id'] ?? 0, 'MODIFIER_OPTION_REQUIRED');
                $qty = $this->positiveDecimal($selection['qty'] ?? $selection['quantity'] ?? 1, 'MODIFIER_QTY_INVALID');
            } else {
                $optionId = $this->positiveInt($selection, 'MODIFIER_OPTION_REQUIRED');
                $qty = 1.0;
            }

            if (!isset($normalized[$optionId])) {
                $normalized[$optionId] = 0.0;
            }
            $normalized[$optionId] += $qty;
        }

        return $normalized;
    }

    private function validateSelections(array $groups, array $options, array $selections): array
    {
        $countsByGroup = [];
        foreach ($selections as $optionId => $qty) {
            if (!isset($options[$optionId])) {
                throw new InvalidArgumentException('MODIFIER_OPTION_INVALID');
            }

            $groupId = (int) $options[$optionId]['group_id'];
            if (!isset($groups[$groupId])) {
                throw new InvalidArgumentException('MODIFIER_GROUP_INVALID');
            }

            if (!isset($countsByGroup[$groupId])) {
                $countsByGroup[$groupId] = 0.0;
            }
            $countsByGroup[$groupId] += $qty;
        }

        foreach ($groups as $groupId => $group) {
            $selectedQty = $countsByGroup[$groupId] ?? 0.0;
            $minimum = $group['is_required'] ? max(1, $group['selection_min']) : $group['selection_min'];
            $maximum = $group['selection_max'];

            if ($selectedQty < $minimum) {
                throw new InvalidArgumentException('MODIFIER_SELECTION_MIN');
            }

            if ($maximum > 0 && $selectedQty > $maximum) {
                throw new InvalidArgumentException('MODIFIER_SELECTION_MAX');
            }
        }

        $validated = [];
        foreach ($selections as $optionId => $qty) {
            $option = $options[$optionId];
            $priceDelta = (float) $option['price_delta'];
            $validated[] = [
                'modifier_group_id' => (int) $option['group_id'],
                'modifier_option_id' => (int) $optionId,
                'qty' => $this->formatDecimal($qty),
                'price_delta' => $this->formatDecimal($priceDelta),
                'line_delta' => $this->formatDecimal($qty * $priceDelta),
                'name_ar' => $option['name_ar'],
                'name_en' => $option['name_en'],
            ];
        }

        return $validated;
    }

    private function normalizeNotes(array $notes, array $context): array
    {
        $createdBy = $this->optionalPositiveInt($context['user_id'] ?? $context['created_by'] ?? null);
        $normalized = [];
        foreach ($notes as $note) {
            if (is_array($note)) {
                $type = $this->noteType($note['note_type'] ?? $note['type'] ?? 'kitchen');
                $text = $this->requiredText($note['note_text'] ?? $note['text'] ?? '', 500, 'NOTE_TEXT_REQUIRED');
                $noteCreatedBy = $this->optionalPositiveInt($note['created_by'] ?? $createdBy);
            } else {
                $type = 'kitchen';
                $text = $this->requiredText($note, 500, 'NOTE_TEXT_REQUIRED');
                $noteCreatedBy = $createdBy;
            }

            $normalized[] = [
                'note_type' => $type,
                'note_text' => $text,
                'created_by' => $noteCreatedBy,
            ];
        }

        return $normalized;
    }

    private function replacePersistedModifiers(mysqli $conn, int $orderId, int $detailId, array $modifiers): void
    {
        $delete = $conn->prepare("DELETE FROM order_line_modifiers WHERE order_id = ? AND detail_id = ?");
        $delete->bind_param('ii', $orderId, $detailId);
        $delete->execute();
        $delete->close();

        if (!$modifiers) {
            return;
        }

        $insert = $conn->prepare("
            INSERT INTO order_line_modifiers (
                order_id, detail_id, modifier_group_id, modifier_option_id, qty, price_delta
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($modifiers as $modifier) {
            $groupId = (int) $modifier['modifier_group_id'];
            $optionId = (int) $modifier['modifier_option_id'];
            $qty = (string) $modifier['qty'];
            $priceDelta = (string) $modifier['price_delta'];
            $insert->bind_param('iiiiss', $orderId, $detailId, $groupId, $optionId, $qty, $priceDelta);
            $insert->execute();
        }
        $insert->close();
    }

    private function replacePersistedNotes(mysqli $conn, int $orderId, int $detailId, array $notes): void
    {
        $delete = $conn->prepare("DELETE FROM order_line_notes WHERE order_id = ? AND detail_id = ?");
        $delete->bind_param('ii', $orderId, $detailId);
        $delete->execute();
        $delete->close();

        if (!$notes) {
            return;
        }

        $insert = $conn->prepare("
            INSERT INTO order_line_notes (
                order_id, detail_id, note_type, note_text, created_by
            )
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($notes as $note) {
            $type = $note['note_type'];
            $text = $note['note_text'];
            $createdBy = $note['created_by'];
            $insert->bind_param('iissi', $orderId, $detailId, $type, $text, $createdBy);
            $insert->execute();
        }
        $insert->close();
    }

    private function fetchPersistedModifiers(mysqli $conn, int $orderId, int $detailId): array
    {
        $stmt = $conn->prepare("
            SELECT
                olm.modifier_group_id,
                olm.modifier_option_id,
                olm.qty,
                olm.price_delta,
                mo.name_ar,
                mo.name_en
            FROM order_line_modifiers olm
            LEFT JOIN modifier_options mo ON mo.id = olm.modifier_option_id
            WHERE olm.order_id = ?
              AND olm.detail_id = ?
            ORDER BY olm.id
        ");
        $stmt->bind_param('ii', $orderId, $detailId);
        $stmt->execute();
        $result = $stmt->get_result();

        $modifiers = [];
        while ($row = $result->fetch_assoc()) {
            $qty = (float) $row['qty'];
            $priceDelta = (float) $row['price_delta'];
            $modifiers[] = [
                'modifier_group_id' => (int) $row['modifier_group_id'],
                'modifier_option_id' => (int) $row['modifier_option_id'],
                'qty' => $this->formatDecimal($qty),
                'price_delta' => $this->formatDecimal($priceDelta),
                'line_delta' => $this->formatDecimal($qty * $priceDelta),
                'name_ar' => $row['name_ar'] !== null ? (string) $row['name_ar'] : null,
                'name_en' => $row['name_en'] !== null ? (string) $row['name_en'] : null,
            ];
        }
        $stmt->close();

        return $modifiers;
    }

    private function fetchPersistedNotes(mysqli $conn, int $orderId, int $detailId): array
    {
        $stmt = $conn->prepare("
            SELECT note_type, note_text, created_by
            FROM order_line_notes
            WHERE order_id = ?
              AND detail_id = ?
            ORDER BY id
        ");
        $stmt->bind_param('ii', $orderId, $detailId);
        $stmt->execute();
        $result = $stmt->get_result();

        $notes = [];
        while ($row = $result->fetch_assoc()) {
            $notes[] = [
                'note_type' => (string) $row['note_type'],
                'note_text' => (string) $row['note_text'],
                'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            ];
        }
        $stmt->close();

        return $notes;
    }

    private function modifierTotal(array $modifiers): float
    {
        $total = 0.0;
        foreach ($modifiers as $modifier) {
            $total += (float) $modifier['line_delta'];
        }

        return $total;
    }

    private function noteType($value): string
    {
        $type = strtolower(trim((string) $value));
        if (!in_array($type, ['kitchen', 'cashier', 'customer'], true)) {
            throw new InvalidArgumentException('NOTE_TYPE_INVALID');
        }

        return $type;
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function optionalPositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function positiveDecimal($value, string $code): float
    {
        $value = (float) $value;
        if ($value <= 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function requiredText($value, int $maxLength, string $code): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($code);
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function formatDecimal($value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
