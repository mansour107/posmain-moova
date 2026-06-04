<?php

require_once __DIR__ . '/MoovaPosMenuPayloadBuilder.php';

/**
 * Triggers a POS-authoritative Moova shop menu reconcile after integration save.
 * Builds the menu on POS and pushes it to Moova so cloud/local Moova never has to reach a private POS URL.
 */
class MoovaPosMenuReconcileService
{
    public const SYNC_MODE_INLINE_MENU = 'inline-menu-push-v2';

    private const REGISTER_PATH = '/api/integrations/pos/local-bridge/menu-endpoints/register';
    private const RECONCILE_PATH = '/api/integrations/pos/local-bridge/menu-sync/reconcile';
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 60;

    public function reconcileAfterIntegrationSave(mysqli $conn, array $savedLink, string $deviceToken, string $posOrigin): array
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

        try {
            $menuPayload = (new MoovaPosMenuPayloadBuilder())->buildForLink($conn, $savedLink);
        } catch (Throwable $e) {
            error_log('[Moova POS] local menu build failed: ' . $e->getMessage());
            return [
                'attempted' => true,
                'ok' => false,
                'reason' => 'local_menu_build_failed',
                'message' => 'تعذر تجهيز منيو الـ POS للمزامنة: ' . $e->getMessage(),
            ];
        }

        $itemCount = count($menuPayload['menu']['items'] ?? []);
        if ($itemCount < 1) {
            return [
                'attempted' => true,
                'ok' => false,
                'reason' => 'empty_menu',
                'message' => 'لا توجد أصناف قابلة للبيع في الـ POS لمزامنتها مع Moova.',
            ];
        }

        $base = rtrim($publicOrigin, '/');
        $body = [
            'publicOrigin' => $publicOrigin,
            'fetchMenuUrl' => $base . '/ajax/moova_menu_sync_payload.php',
            'categoriesUrl' => $base . '/api/categories.php',
            'itemsUrl' => $base . '/api/items.php',
            'menuSyncMode' => 'full',
            'source' => 'posmain_integration_save',
            'catalogVersion' => $menuPayload['catalogVersion'] ?? $menuPayload['fingerprint'] ?? null,
            'fingerprint' => $menuPayload['fingerprint'] ?? $menuPayload['catalogVersion'] ?? null,
            'menu' => $menuPayload['menu'] ?? ['categories' => [], 'items' => []],
            'rawPayload' => $menuPayload['rawPayload'] ?? [
                'source' => 'posmain_integration_save',
                'priceUnit' => 'minor',
                'priceUnitScale' => 100,
                'currency' => 'EGP',
                'posPriceUnit' => 'major',
            ],
            'summary' => $menuPayload['summary'] ?? null,
        ];

        $registerResult = $this->postJson(
            rtrim($moovaOrigin, '/') . self::REGISTER_PATH,
            $token,
            $publicOrigin,
            $body
        );
        if ($registerResult['ok']) {
            return $this->finalizeResult($registerResult, $moovaOrigin, $publicOrigin, 'register', $itemCount);
        }

        $reconcileResult = $this->postJson(
            rtrim($moovaOrigin, '/') . self::RECONCILE_PATH,
            $token,
            $publicOrigin,
            [
                'source' => 'posmain_integration_save',
                'force' => true,
                'catalogVersion' => $body['catalogVersion'],
                'fingerprint' => $body['fingerprint'],
                'menu' => $body['menu'],
                'rawPayload' => $body['rawPayload'],
                'summary' => $body['summary'],
            ]
        );

        return $this->finalizeResult($reconcileResult, $moovaOrigin, $publicOrigin, 'reconcile', $itemCount);
    }

    private function finalizeResult(array $result, string $moovaOrigin, string $posOrigin, string $phase, int $itemCount): array
    {
        $result['phase'] = $phase;
        $report = $this->extractReport($result['body']);
        $summary = $this->summarizeReport($report);

        return [
            'attempted' => true,
            'ok' => $result['ok'],
            'phase' => $phase,
            'statusCode' => $result['statusCode'],
            'posOrigin' => $posOrigin,
            'moovaOrigin' => $moovaOrigin,
            'itemCount' => $itemCount,
            'syncMode' => self::SYNC_MODE_INLINE_MENU,
            'retryable' => !$result['ok'] && ($result['statusCode'] >= 500 || $result['statusCode'] === 404),
            'report' => $report,
            'summary' => $summary,
            'moova' => $result['ok'] ? null : [
                'error' => $result['body']['error'] ?? null,
                'message' => $result['body']['message'] ?? null,
                'code' => $result['body']['code'] ?? null,
                'details' => $result['body']['details'] ?? null,
            ],
            'message' => $result['ok']
                ? $this->buildSuccessMessage($summary, $itemCount)
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
            error_log('[Moova POS] menu reconcile failed: url=' . $url . ' status=' . $statusCode
                . ' error=' . $curlError
                . ' body=' . substr(is_string($responseBody) ? $responseBody : '', 0, 500));
        }

        return [
            'ok' => $ok,
            'statusCode' => $statusCode,
            'body' => $parsed,
            'rawBody' => is_string($responseBody) ? $responseBody : '',
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

    private function buildSuccessMessage(array $summary, int $itemCount): string
    {
        return sprintf(
            'تمت مزامنة %d صنف من الـ POS إلى Moova (%d إضافة، %d تحديث، %d إزالة).',
            $itemCount,
            $summary['itemsCreated'],
            $summary['itemsUpdated'],
            $summary['itemsDeactivated']
        );
    }

    private function buildFailureMessage(array $result): string
    {
        $body = $result['body'] ?? [];
        $statusCode = (int) ($result['statusCode'] ?? 0);
        $phase = trim((string) ($result['phase'] ?? ''));

        if ($statusCode === 404) {
            if ($phase === 'reconcile') {
                return 'خدمة Moova على Render لا تحتوي مسار reconcile (404). تأكد أن آخر cofe_order من GitHub مُنشَر على withmoova.com وليس نسخة قديمة.';
            }
            return 'خدمة Moova لا تدعم مزامنة المنيو (404). انشر آخر cofe_order على Render ثم أعد المحاولة.';
        }

        $message = trim((string) ($body['message'] ?? $body['error'] ?? ''));
        if ($message !== '' && stripos($message, 'Failed to reconcile local bridge menu') === false) {
            return $message;
        }
        if ($message !== '' && stripos($message, 'Failed to register local bridge menu') === false) {
            return $message;
        }

        $code = trim((string) ($body['code'] ?? ''));
        if ($code === 'POS_BRANCH_LINK_NOT_FOUND') {
            return 'لم يُعثر على ربط فرع POS في Moova لهذا التوكن. من Moova Admin: فعّل Menu sync على فرع الـ POS (فرع واحد فقط لكل اتصال).';
        }
        if ($code === 'INVALID_REQUEST_PAYLOAD') {
            return 'طلب المزامنة مرفوض من Moova (بيانات غير صالحة). حدّث cofe_order على Render وPOS معاً.';
        }
        if ($code === 'POS_MENU_IMPORT_FAILED') {
            return 'فشل استيراد المنيو داخل Moova: ' . trim((string) ($body['message'] ?? $body['error'] ?? 'خطأ غير معروف'));
        }
        if ($code === 'POS_MENU_RECONCILE_FAILED' || $code === 'POS_MENU_DIRECT_FETCH_FAILED') {
            return 'Moova لم يستطع سحب المنيو من عنوان الـ POS. يجب أن يرسل POS المنيو مباشرة (تحديث posmain) أو ضبط POSMAIN_MOOVA_POS_PUBLIC_ORIGIN.';
        }

        if ($phase === 'register') {
            return 'فشل تسجيل مزامنة المنيو على Moova (HTTP ' . $statusCode . '). تحقق من التوكن وربط الفرع في Moova Admin.';
        }

        return 'فشلت مزامنة المنيو على Moova (HTTP ' . $statusCode . '). انشر cofe_order + posmain المحدثين ثم أعد حفظ الربط.';
    }
}
