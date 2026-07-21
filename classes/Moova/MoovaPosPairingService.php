<?php

/**
 * Claims a Moova-generated device token for exactly one local POS scope.
 * Moova resolves all remote identifiers; the POS never accepts shop/branch ids
 * or a widget URL from the browser.
 */
class MoovaPosPairingService
{
    private const PAIR_PATH = '/api/integrations/pos/local-bridge/pair/claim';
    private const RELEASE_PATH = '/api/integrations/pos/local-bridge/pair/release';

    public function claim(string $deviceToken, array $scope, string $posInstanceUuid, string $posOrigin, string $locale = 'ar'): array
    {
        $origin = $this->moovaOrigin();
        if ($origin === '' || !function_exists('curl_init')) {
            throw new RuntimeException('MOOVA_PAIRING_UNAVAILABLE');
        }

        $body = [
            'posInstanceUuid' => $posInstanceUuid,
            'posTenant' => (int) ($scope['tenant'] ?? 0),
            'posBranch' => (int) ($scope['branch'] ?? 0),
            'publicOrigin' => $posOrigin !== '' ? $posOrigin : null,
            'locale' => in_array($locale, ['ar', 'en'], true) ? $locale : 'ar',
            'capabilities' => [
                'catalogPush' => true,
                'authoritativeSnapshot' => true,
                'tableOrders' => true,
                'deliveryOrders' => true,
                'cashierDecision' => true,
                'idempotency' => true,
            ],
        ];
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('PAIRING_PAYLOAD_ENCODE_FAILED');
        }

        $ch = curl_init($origin . self::PAIR_PATH);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $deviceToken,
            'X-Pos-Device-Token: ' . $deviceToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = $raw === false ? curl_error($ch) : '';
        if (PHP_VERSION_ID < 80000 && function_exists('curl_close')) {
            curl_close($ch);
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            $code = is_array($decoded) ? trim((string) ($decoded['code'] ?? $decoded['error'] ?? '')) : '';
            $message = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
            throw new RuntimeException(($code !== '' ? $code : 'MOOVA_PAIRING_FAILED') . ($message !== '' ? ': ' . $message : ($curlError !== '' ? ': ' . $curlError : '')));
        }

        $pairing = is_array($decoded['pairing'] ?? null) ? $decoded['pairing'] : [];
        foreach (['pairingId', 'shopId', 'connectionId', 'branchLinkId', 'branchId', 'widgetUrl'] as $required) {
            if (trim((string) ($pairing[$required] ?? '')) === '') {
                throw new RuntimeException('INVALID_PAIRING_RESPONSE: ' . $required);
            }
        }

        return $pairing;
    }

    public function release(string $deviceToken, string $posInstanceUuid): void
    {
        $origin = $this->moovaOrigin();
        if ($origin === '' || !function_exists('curl_init')) {
            throw new RuntimeException('MOOVA_PAIRING_UNAVAILABLE');
        }
        $encoded = json_encode(['posInstanceUuid' => $posInstanceUuid], JSON_UNESCAPED_SLASHES);
        $ch = curl_init($origin . self::RELEASE_PATH);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $deviceToken,
            'X-Pos-Device-Token: ' . $deviceToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            $code = is_array($decoded) ? trim((string) ($decoded['code'] ?? $decoded['error'] ?? '')) : '';
            throw new RuntimeException($code !== '' ? $code : 'MOOVA_PAIRING_RELEASE_FAILED');
        }
    }

    public function moovaOrigin(): string
    {
        $configured = getenv('POSMAIN_MOOVA_BASE_URL');
        $value = $configured !== false && trim((string) $configured) !== ''
            ? trim((string) $configured)
            : 'https://withmoova.com';
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            return '';
        }
        return $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
