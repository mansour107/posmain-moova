<?php

/**
 * Triggers a POS-authoritative Moova shop menu reconcile after integration save.
 * Moova (cofe_order) fetches the full sellable catalog from moova_menu_sync_payload.php,
 * deactivates extras, and upserts missing items/modifiers/prices.
 */
class MoovaPosMenuReconcileService
{
    private const REGISTER_PATH = '/api/integrations/pos/local-bridge/menu-endpoints/register';
    private const RECONCILE_PATH = '/api/integrations/pos/local-bridge/menu-sync/reconcile';
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 45;

    public function reconcileAfterIntegrationSave(array $savedLink, string $deviceToken, string $posOrigin): array
    {
        $token = trim($deviceToken);
        $moovaOrigin = $this->originFromWidgetUrl((string) ($savedLink['widget_url'] ?? ''));
        $publicOrigin = trim($posOrigin);
        if ($token === '' || $moovaOrigin === '' || $publicOrigin === '') {
            return [
                'attempted' => false,
                'ok' => false,
                'reason' => 'missing_origin_or_token',
            ];
        }
        if (!function_exists('curl_init')) {
            return [
                'attempted' => false,
                'ok' => false,
                'reason' => 'curl_unavailable',
            ];
        }

        $base = rtrim($publicOrigin, '/');
        $body = [
            'publicOrigin' => $publicOrigin,
            'fetchMenuUrl' => $base . '/ajax/moova_menu_sync_payload.php',
            'menuSyncMode' => 'full',
            'source' => 'posmain_integration_save',
        ];

        $registerResult = $this->postJson(
            rtrim($moovaOrigin, '/') . self::REGISTER_PATH,
            $token,
            $publicOrigin,
            $body
        );
        if ($registerResult['ok']) {
            return $this->finalizeResult($registerResult, $moovaOrigin, $publicOrigin, 'register');
        }

        $reconcileResult = $this->postJson(
            rtrim($moovaOrigin, '/') . self::RECONCILE_PATH,
            $token,
            $publicOrigin,
            [
                'source' => 'posmain_integration_save',
                'force' => true,
            ]
        );

        return $this->finalizeResult($reconcileResult, $moovaOrigin, $publicOrigin, 'reconcile');
    }

    private function finalizeResult(array $result, string $moovaOrigin, string $posOrigin, string $phase): array
    {
        $report = $this->extractReport($result['body']);
        $summary = $this->summarizeReport($report);

        return [
            'attempted' => true,
            'ok' => $result['ok'],
            'phase' => $phase,
            'statusCode' => $result['statusCode'],
            'posOrigin' => $posOrigin,
            'moovaOrigin' => $moovaOrigin,
            'retryable' => !$result['ok'] && $result['statusCode'] >= 500,
            'report' => $report,
            'summary' => $summary,
            'message' => $result['ok']
                ? $this->buildSuccessMessage($summary)
                : $this->buildFailureMessage($result),
        ];
    }

    private function postJson(string $url, string $deviceToken, string $posOrigin, array $body): array
    {
        $encodedBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedBody)) {
            return [
                'ok' => false,
                'statusCode' => 0,
                'body' => [],
                'error' => 'payload_encode_failed',
            ];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $deviceToken,
            'X-Pos-Device-Token: ' . $deviceToken,
            'X-Pos-Widget-Origin: ' . $posOrigin,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = $responseBody === false ? curl_error($ch) : '';
        if (PHP_VERSION_ID < 80000 && function_exists('curl_close')) {
            curl_close($ch);
        }

        $parsed = [];
        if (is_string($responseBody) && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        $ok = $statusCode >= 200 && $statusCode < 300
            && (($parsed['ok'] ?? null) === true || ($parsed['success'] ?? null) === true);

        if (!$ok) {
            error_log('[Moova POS] menu reconcile failed: url=' . $url . ' status=' . $statusCode . ' error=' . $curlError);
        }

        return [
            'ok' => $ok,
            'statusCode' => $statusCode,
            'body' => $parsed,
            'error' => $curlError,
        ];
    }

    private function originFromWidgetUrl(string $widgetUrl): string
    {
        $parts = parse_url($widgetUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function extractReport(array $body): array
    {
        $candidates = [
            $body['sync']['report'] ?? null,
            $body['report'] ?? null,
            $body['sync']['summary']['report'] ?? null,
            $body['sync']['lastDirectImportSummary']['report'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function summarizeReport(array $report): array
    {
        return [
            'itemsCreated' => (int) ($report['itemsCreated'] ?? 0),
            'itemsUpdated' => (int) ($report['itemsUpdated'] ?? 0),
            'itemsDeactivated' => (int) ($report['itemsDeactivated'] ?? 0),
            'categoriesCreated' => (int) ($report['categoriesCreated'] ?? 0),
            'categoriesDeactivated' => (int) ($report['categoriesDeactivated'] ?? 0),
        ];
    }

    private function buildSuccessMessage(array $summary): string
    {
        return sprintf(
            'تمت مزامنة المنيو من الـ POS (%d إضافة، %d تحديث، %d إزالة).',
            $summary['itemsCreated'],
            $summary['itemsUpdated'],
            $summary['itemsDeactivated']
        );
    }

    private function buildFailureMessage(array $result): string
    {
        $body = $result['body'] ?? [];
        $message = trim((string) ($body['message'] ?? $body['error'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return 'تعذر مزامنة المنيو مع متجر Moova الآن. حاول مرة أخرى أو افتح شاشة البيع لإكمال المزامنة.';
    }
}
