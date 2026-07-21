<?php

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeCostLeakAuditService.php';

/**
 * Builds the same menu payload as ajax/moova_menu_sync_payload.php for server-side push to Moova.
 */
class MoovaPosMenuPayloadBuilder
{
    public function buildForLink(mysqli $conn, array $link): array
    {
        if (!defined('MOOVA_MENU_SYNC_LIBRARY_ONLY')) {
            define('MOOVA_MENU_SYNC_LIBRARY_ONLY', true);
        }
        require_once __DIR__ . '/../../ajax/moova_menu_sync_payload.php';

        $fingerprint = moova_menu_sync_fingerprint($conn);
        $catalogVersion = (string) ($fingerprint['fingerprint'] ?? '');
        $built = moova_menu_sync_build_menu($conn, $catalogVersion, $link);

        $payload = [
            'success' => true,
            'catalogVersion' => $built['catalogVersion'] ?? $catalogVersion,
            'fingerprint' => $catalogVersion,
            'menu' => $built['menu'] ?? ['categories' => [], 'items' => [], 'tables' => []],
            'rawPayload' => $built['rawPayload'] ?? [
                'source' => 'posmain_local_menu',
                'catalogVersion' => $catalogVersion,
                'priceUnit' => 'minor',
                'priceUnitScale' => 100,
                'currency' => 'EGP',
                'posPriceUnit' => 'major',
            ],
            'summary' => $built['rawPayload']['counts'] ?? null,
        ];

        $config = function_exists('posmain_app_config') ? posmain_app_config() : [];
        $flags = new RecipeFeatureFlags($config);

        return (new RecipeCostLeakAuditService())->sanitizePayload($payload, 'moova-facing api', $flags);
    }
}
