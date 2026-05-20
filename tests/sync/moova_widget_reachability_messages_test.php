<?php

use PHPUnit\Framework\TestCase;

class MoovaWidgetReachabilityMessagesTest extends TestCase
{
    public function testProxyKeepsLegacyErrorAndAddsStructuredReachabilityFields(): void
    {
        $source = $this->source('moova_pos_proxy.php');

        $this->assertStringContainsString('function moova_proxy_reachability_error', $source);
        $this->assertStringContainsString("'error' => 'moova_unreachable'", $source);
        $this->assertStringContainsString("'code' => 'MOOVA_UNREACHABLE'", $source);
        $this->assertStringContainsString("'retryable' => true", $source);
        $this->assertStringContainsString("'details' => (string) \$details", $source);
        $this->assertStringContainsString('moova_proxy_json(502, moova_proxy_reachability_error($error))', $source);
    }

    public function testProxyRewritesDockerOnlyMoovaUrlsForBrowserBootstrap(): void
    {
        $source = $this->source('moova_pos_proxy.php');

        $this->assertStringContainsString('function moova_proxy_rewrite_browser_moova_urls', $source);
        $this->assertStringContainsString('function moova_proxy_browser_reachable_url', $source);
        $this->assertStringContainsString("strtolower((string) \$parts['host']) !== 'host.docker.internal'", $source);
        $this->assertStringContainsString("['baseUrl', 'websocketUrl', 'widgetUrl']", $source);
        $this->assertStringContainsString("\$parts['host'] = (string) \$originParts['host'];", $source);
        $this->assertStringContainsString('$responseBody = moova_proxy_rewrite_browser_moova_urls($responseBody, $widgetOrigin);', $source);
    }

    public function testProxyUsesForwardedOriginWhenWidgetOriginHeaderIsMissing(): void
    {
        $source = $this->source('moova_pos_proxy.php');

        $this->assertStringContainsString('function moova_proxy_current_origin', $source);
        $this->assertStringContainsString("moova_proxy_header('X-Forwarded-Proto')", $source);
        $this->assertStringContainsString("moova_proxy_header('X-Forwarded-Host')", $source);
        $this->assertStringContainsString("moova_proxy_header('X-Pos-Widget-Origin') ?: moova_proxy_current_origin()", $source);
    }

    public function testWidgetMapsReachabilityCodesToCashierMessages(): void
    {
        $source = $this->source('assets/moova-pos-widget/pos-widget.js');

        $this->assertStringContainsString('moovaUnreachable:', $source);
        $this->assertStringContainsString('posUnreachable:', $source);
        $this->assertStringContainsString('تعذر الاتصال بـ Moova', $source);
        $this->assertStringContainsString('تعذر الاتصال بنظام نقاط البيع', $source);
        $this->assertStringContainsString('function normalizeApiErrorPayload(payload, status)', $source);
        $this->assertStringContainsString("case 'MOOVA_UNREACHABLE':", $source);
        $this->assertStringContainsString("case 'POS_UNREACHABLE':", $source);
        $this->assertStringContainsString("return t('moovaUnreachable')", $source);
        $this->assertStringContainsString("return t('posUnreachable')", $source);
        $this->assertStringNotContainsString("payload && typeof payload.error === 'string' ? payload.error", $source);
    }

    public function testWidgetPreservesRawErrorPayloadForAckFailureDetails(): void
    {
        $source = $this->source('assets/moova-pos-widget/pos-widget.js');

        $this->assertStringContainsString('throw createWidgetError(errorInfo.message, response.status, errorInfo.code, payload)', $source);
        $this->assertStringContainsString('error.errorPayload = payload || null', $source);
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }
}

class moova_widget_reachability_messages_test extends MoovaWidgetReachabilityMessagesTest
{
}
