<?php

require_once __DIR__ . '/DTO/RecipeCostContext.php';
require_once __DIR__ . '/RecipeAvailabilityService.php';
require_once __DIR__ . '/RecipeCostService.php';

class RecipeEditorPreviewService
{
    private $costs;
    private $availability;

    public function __construct(
        ?RecipeCostService $costs = null,
        ?RecipeAvailabilityService $availability = null
    ) {
        $this->costs = $costs ?: new RecipeCostService();
        $this->availability = $availability ?: new RecipeAvailabilityService();
    }

    public function preview(mysqli $conn, int $recipeId, array $context = [], bool $includeCost = true): array
    {
        $preview = [
            'cost' => null,
            'availability' => $this->availability
                ->previewForRecipe($conn, $recipeId, $this->availabilityContext($context))
                ->toArray(),
        ];

        if ($includeCost) {
            $preview['cost'] = $this->costs
                ->calculateRecipeCost($conn, $recipeId, new RecipeCostContext($this->costContext($context)))
                ->toArray();
        }

        return $preview;
    }

    private function costContext(array $context): array
    {
        return [
            'pos_tenant' => max(0, (int) ($context['pos_tenant'] ?? 0)),
            'pos_branch' => max(0, (int) ($context['pos_branch'] ?? 0)),
            'branch_uuid' => trim((string) ($context['branch_uuid'] ?? '')) ?: null,
            'store_id' => max(0, (int) ($context['store_id'] ?? 0)),
            'order_type' => $this->token($context['order_type'] ?? 'takeaway', ['any', 'dine_in', 'takeaway', 'delivery'], 'takeaway'),
            'channel' => $this->token($context['channel'] ?? 'pos', ['any', 'pos', 'table', 'moova', 'cofe', 'api'], 'pos'),
            'costing_method' => $this->token($context['costing_method'] ?? null, [
                'item_cost_price',
                'moving_average',
                'last_purchase',
                'manual_snapshot',
            ], null),
            'calculated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function availabilityContext(array $context): array
    {
        return [
            'pos_tenant' => max(0, (int) ($context['pos_tenant'] ?? 0)),
            'pos_branch' => max(0, (int) ($context['pos_branch'] ?? 0)),
            'branch_uuid' => trim((string) ($context['branch_uuid'] ?? '')) ?: null,
            'store_id' => max(0, (int) ($context['store_id'] ?? 0)),
            'order_type' => $this->token($context['order_type'] ?? 'takeaway', ['any', 'dine_in', 'takeaway', 'delivery'], 'takeaway'),
            'channel' => $this->token($context['channel'] ?? 'pos', ['any', 'pos', 'table', 'moova', 'cofe', 'api'], 'pos'),
            'safety_stock' => $this->decimal($context['safety_stock'] ?? '0'),
        ];
    }

    private function token($value, array $allowed, ?string $default): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $token = strtolower(trim((string) $value));
        $token = str_replace(['-', ' '], '_', $token);

        return in_array($token, $allowed, true) ? $token : $default;
    }

    private function decimal($value): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+(\.\d{1,8})?$/', $text)) {
            return $text;
        }

        return '0';
    }
}
