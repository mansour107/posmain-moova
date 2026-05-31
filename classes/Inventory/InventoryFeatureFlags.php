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
        return $this->mode() !== 'off';
    }

    public function isShadowMode(): bool
    {
        return $this->mode() === 'shadow';
    }

    public function canWriteLedger(): bool
    {
        return in_array($this->mode(), ['bridge', 'live'], true);
    }

    public function canWriteShadowLedger(): bool
    {
        return in_array($this->mode(), ['shadow', 'bridge', 'live'], true);
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
        return $this->isEnabled() && $this->boolValue($this->inventoryConfig()['accounting'] ?? false);
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

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
