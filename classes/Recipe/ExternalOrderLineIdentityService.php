<?php

require_once __DIR__ . '/DTO/RecipeScope.php';
require_once __DIR__ . '/Repository/ExternalOrderLineMapRepository.php';

class ExternalOrderLineIdentityService
{
    private ExternalOrderLineMapRepository $maps;

    public function __construct(?ExternalOrderLineMapRepository $maps = null)
    {
        $this->maps = $maps ?: new ExternalOrderLineMapRepository();
    }

    public function registerLine(
        mysqli $conn,
        RecipeScope $scope,
        string $sourceChannel,
        string $externalOrderId,
        array $line,
        int $lineIndex,
        array $localLine = []
    ): array {
        $sourceChannel = $this->sourceChannel($sourceChannel);
        $externalOrderId = $this->requiredToken($externalOrderId, 'external order id');
        $itemId = $this->positiveInt($this->firstValue($line, ['item_id', 'itemId', 'provider_item_id', 'providerItemId', 'id']));
        if ($itemId < 1) {
            throw new InvalidArgumentException('External order line item id is required.');
        }

        $variantId = $this->positiveInt($this->firstValue($line, ['variant_id', 'variantId', 'variant_item_id', 'variantItemId']));
        $modifiers = $this->modifiers($line);
        $modifiersHash = $this->modifiersHash($modifiers);
        $externalLineId = $this->externalLineId($line, $lineIndex, $itemId, $variantId, $modifiersHash);
        $idempotencyKey = $this->idempotencyKey($scope, $sourceChannel, $externalOrderId, $externalLineId);
        $modifiersJson = $this->encodeJson($modifiers);

        $mappingId = $this->maps->upsertMapping($conn, [
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'source_channel' => $sourceChannel,
            'external_order_id' => $externalOrderId,
            'external_line_id' => $externalLineId,
            'external_event_uuid' => $this->nullableString($this->firstValue($line, ['event_uuid', 'eventUuid', 'source_event_uuid'])),
            'order_id' => $this->nullablePositiveInt($localLine['order_id'] ?? null),
            'fat_detail_id' => $this->nullablePositiveInt($localLine['fat_detail_id'] ?? null),
            'order_line_uuid' => $this->nullableString($localLine['order_line_uuid'] ?? null),
            'item_id' => $itemId,
            'variant_id' => $variantId > 0 ? $variantId : null,
            'modifiers_hash' => $modifiersHash,
            'modifiers_json' => $modifiersJson,
            'line_status' => (string) ($localLine['line_status'] ?? 'active'),
            'idempotency_key' => $idempotencyKey,
        ]);

        $mapping = $this->maps->findMapping(
            $conn,
            $scope->posTenant,
            $scope->posBranch,
            $sourceChannel,
            $externalOrderId,
            $externalLineId
        );

        return [
            'mapping_id' => $mappingId,
            'mapping' => $mapping,
            'source_channel' => $sourceChannel,
            'external_order_id' => $externalOrderId,
            'external_line_id' => $externalLineId,
            'item_id' => $itemId,
            'variant_id' => $variantId > 0 ? $variantId : null,
            'modifiers_hash' => $modifiersHash,
            'modifiers_json' => $modifiersJson,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    public function externalLineId(array $line, int $lineIndex, int $itemId, ?int $variantId, string $modifiersHash): string
    {
        $explicit = $this->nullableString($this->firstValue($line, [
            'external_line_id',
            'externalLineId',
            'line_id',
            'lineId',
            'provider_line_id',
            'providerLineId',
            'cart_line_id',
            'cartLineId',
        ]));
        if ($explicit !== null) {
            return substr($explicit, 0, 128);
        }

        if (!array_key_exists('itemId', $line) && !array_key_exists('item_id', $line)) {
            $fallbackId = $this->nullableString($line['id'] ?? null);
            if ($fallbackId !== null) {
                return substr($fallbackId, 0, 128);
            }
        }

        $variantToken = $variantId && $variantId > 0 ? (string) $variantId : 'none';

        return substr(
            'line:' . max(0, $lineIndex) . ':item:' . $itemId . ':variant:' . $variantToken . ':mods:' . substr($modifiersHash, 0, 16),
            0,
            128
        );
    }

    public function modifiersHash(array $modifiers): string
    {
        return hash('sha256', $this->encodeJson($this->canonicalize($modifiers)));
    }

    private function modifiers(array $line): array
    {
        foreach (['modifiers', 'modifier_options', 'selected_modifiers', 'options'] as $key) {
            if (isset($line[$key]) && is_array($line[$key])) {
                return $line[$key];
            }
        }

        return [];
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            $items = array_map([$this, 'canonicalize'], $value);
            usort($items, function ($left, $right): int {
                return strcmp($this->encodeJson($left), $this->encodeJson($right));
            });

            return $items;
        }

        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private function idempotencyKey(RecipeScope $scope, string $sourceChannel, string $externalOrderId, string $externalLineId): string
    {
        return substr(
            'external-line:' . $scope->posTenant . ':' . $scope->posBranch . ':' . $sourceChannel . ':' .
            hash('sha256', $externalOrderId . ':' . $externalLineId),
            0,
            191
        );
    }

    private function sourceChannel(string $sourceChannel): string
    {
        $sourceChannel = strtolower(trim($sourceChannel));
        if (!in_array($sourceChannel, ['moova', 'cofe', 'api', 'sync'], true)) {
            throw new InvalidArgumentException('Unsupported external line source channel.');
        }

        return $sourceChannel;
    }

    private function requiredToken(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }

        return substr($value, 0, 128);
    }

    private function firstValue(array $source, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                return $source[$key];
            }
        }

        return null;
    }

    private function positiveInt($value): int
    {
        if (is_string($value) && preg_match('/(\d+)$/', $value, $matches)) {
            $value = $matches[1];
        }

        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function nullablePositiveInt($value): ?int
    {
        $int = $this->positiveInt($value);

        return $int > 0 ? $int : null;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode external order line identity JSON.');
        }

        return $json;
    }
}
