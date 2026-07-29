<?php

class InventoryFeatureFlags
{
    private const MODES = ['off', 'shadow', 'bridge', 'live'];

    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) ($this->inventoryConfig()['ledger_mode'] ?? 'off')));
        $mode = str_replace(['-', ' '], '_', $mode);

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public function isEnabled(): bool
    {
        return $this->mode() !== 'off'
            || $this->isQuantityTrackingEnabled()
            || $this->configuredBool('reservations')
            || $this->configuredBool('accounting')
            || $this->configuredBool('availability')
            || $this->configuredBool('sync');
    }

    public function isShadowMode(): bool
    {
        return $this->mode() === 'shadow';
    }

    public function canWriteLedger(): bool
    {
        return in_array($this->mode(), ['bridge', 'live'], true);
    }

    /**
     * Authoritative quantity tracking is a product capability, not financial
     * ledger accounting. Legacy configurations keep bridge/live semantics.
     */
    public function isQuantityTrackingEnabled(): bool
    {
        $config = $this->inventoryConfig();
        if (array_key_exists('quantity_tracking', $config)) {
            return $this->boolValue($config['quantity_tracking']);
        }

        return $this->canWriteLedger() || $this->legacyRecipeRequiresQuantityTracking();
    }

    public function canWriteQuantityLedger(): bool
    {
        $this->assertAccountingQuantityDependency();

        return $this->isQuantityTrackingEnabled();
    }

    public function canWriteShadowLedger(): bool
    {
        return in_array($this->mode(), ['shadow', 'bridge', 'live'], true);
    }

    public function canCaptureInventoryMovements(): bool
    {
        $this->assertAccountingQuantityDependency();

        return $this->isShadowMode() || $this->isQuantityTrackingEnabled();
    }

    public function shouldMirrorLegacyStock(): bool
    {
        return $this->isEnabled() && $this->boolValue($this->inventoryConfig()['legacy_mirror'] ?? false);
    }

    public function isStrictStockEnabled(): bool
    {
        return $this->isEnabled() && $this->boolValue($this->inventoryConfig()['strict_stock'] ?? false);
    }

    public function isReservationEnabled(): bool
    {
        return $this->isEnabled() && $this->boolValue($this->inventoryConfig()['reservations'] ?? false);
    }

    public function isAccountingEnabled(): bool
    {
        return $this->configuredBool('accounting');
    }

    public function isAvailabilityEnabled(): bool
    {
        return $this->isEnabled() && $this->boolValue($this->inventoryConfig()['availability'] ?? false);
    }

    public function isSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->boolValue($this->inventoryConfig()['sync'] ?? false);
    }

    public function canExposeCostsToPayload(string $payloadClass): bool
    {
        return $this->isEnabled()
            && trim($payloadClass) !== ''
            && $this->boolValue($this->inventoryConfig()['cost_public_payloads'] ?? false);
    }

    public function config(): array
    {
        return $this->inventoryConfig();
    }

    public function appConfig(): array
    {
        return $this->config;
    }

    private function inventoryConfig(): array
    {
        return is_array($this->config['inventory'] ?? null) ? $this->config['inventory'] : [];
    }

    private function configuredBool(string $key): bool
    {
        return $this->boolValue($this->inventoryConfig()[$key] ?? false);
    }

    private function assertAccountingQuantityDependency(): void
    {
        if ($this->isAccountingEnabled() && !$this->isQuantityTrackingEnabled()) {
            throw new RuntimeException('INVENTORY_ACCOUNTING_REQUIRES_QUANTITY_TRACKING');
        }
    }

    /**
     * Compatibility adapter for shops configured before inventory capabilities
     * were split. A write-capable legacy recipe mode historically mutated stock,
     * so it is mapped onto the authoritative quantity ledger. An explicit
     * inventory.quantity_tracking=false always wins in
     * isQuantityTrackingEnabled() and therefore fails active recipe writes
     * closed instead of silently using the legacy repository path.
     */
    private function legacyRecipeRequiresQuantityTracking(): bool
    {
        $recipe = is_array($this->config['recipe'] ?? null) ? $this->config['recipe'] : [];
        if (!$this->boolValue($recipe['enabled'] ?? false)) {
            return false;
        }

        $mode = strtolower(trim((string) ($recipe['mode'] ?? 'off')));
        $mode = str_replace(['-', ' '], '_', $mode);

        return in_array($mode, [
            'shadow',
            'reserve_only',
            'consume_pilot',
            'accounting_pilot',
            'availability_pilot',
            'full',
        ], true);
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
