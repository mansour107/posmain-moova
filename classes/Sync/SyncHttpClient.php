<?php

class SyncHttpClient
{
    public function postJson(string $url, string $body, array $headers, int $connectTimeoutMs, int $timeoutMs): array
    {
        if (function_exists('curl_init')) {
            return $this->postWithCurl($url, $body, $headers, $connectTimeoutMs, $timeoutMs);
        }

        return $this->postWithStreams($url, $body, $headers, $timeoutMs);
    }

    public function get(string $url, array $headers, int $connectTimeoutMs, int $timeoutMs): array
    {
        if (function_exists('curl_init')) {
            return $this->getWithCurl($url, $headers, $connectTimeoutMs, $timeoutMs);
        }

        return $this->getWithStreams($url, $headers, $timeoutMs);
    }

    private function getWithCurl(string $url, array $headers, int $connectTimeoutMs, int $timeoutMs): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $connectTimeoutMs),
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
        ]);

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        return $this->formatResponse($responseBody, $status, $error);
    }

    private function getWithStreams(string $url, array $headers, int $timeoutMs): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => max(1, $timeoutMs / 1000),
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : [];
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match)) {
            $status = (int) $match[1];
        }

        return $this->formatResponse($responseBody, $status, $responseBody === false ? 'HTTP request failed' : '');
    }

    private function postWithCurl(string $url, string $body, array $headers, int $connectTimeoutMs, int $timeoutMs): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $connectTimeoutMs),
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
        ]);

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        return $this->formatResponse($responseBody, $status, $error);
    }

    private function postWithStreams(string $url, string $body, array $headers, int $timeoutMs): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => max(1, $timeoutMs / 1000),
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : [];
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match)) {
            $status = (int) $match[1];
        }

        return $this->formatResponse($responseBody, $status, $responseBody === false ? 'HTTP request failed' : '');
    }

    private function formatResponse($responseBody, int $status, string $error): array
    {
        $json = is_string($responseBody) ? json_decode($responseBody, true) : null;

        return [
            'ok' => $responseBody !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'json' => is_array($json) ? $json : null,
            'error' => $responseBody === false ? $error : ($status >= 200 && $status < 300 ? '' : (string) $responseBody),
        ];
    }
}
