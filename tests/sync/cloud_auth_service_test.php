<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/ArrayBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';

class CloudAuthServiceTest extends TestCase
{
    public function testValidatesHmacWithProtectedSecretProviderValue(): void
    {
        $branchUuid = '11111111-1111-1111-1111-111111111111';
        $secret = 'dummy-local-secret';
        $timestamp = (string) time();
        $nonce = 'nonce-1';
        $body = '{"event":"order_saved"}';
        $signature = CloudAuthService::sign($secret, $timestamp, $nonce, $body);

        $provider = new ArrayBranchSecretProvider([$branchUuid => $secret]);
        $result = (new CloudAuthService())->verifyRequest($provider, $branchUuid, $timestamp, $nonce, $body, $signature);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['reason']);
    }

    public function testSecretHashIsNotTreatedAsSigningKey(): void
    {
        $branchUuid = '22222222-2222-2222-2222-222222222222';
        $secret = 'dummy-local-secret';
        $timestamp = (string) time();
        $nonce = 'nonce-2';
        $body = '{"event":"order_paid"}';
        $signature = CloudAuthService::sign($secret, $timestamp, $nonce, $body);
        $hashOnlyProvider = new ArrayBranchSecretProvider([$branchUuid => hash('sha256', $secret)]);

        $result = (new CloudAuthService())->verifyRequest($hashOnlyProvider, $branchUuid, $timestamp, $nonce, $body, $signature);

        $this->assertFalse($result['ok']);
        $this->assertSame('signature_mismatch', $result['reason']);
    }

    public function testRejectsInactiveBranchExpiredTimestampAndTamperedBody(): void
    {
        $branchUuid = '33333333-3333-3333-3333-333333333333';
        $secret = 'dummy-local-secret';
        $now = time();
        $timestamp = (string) $now;
        $nonce = 'nonce-3';
        $body = '{"event":"menu_item_updated"}';
        $signature = CloudAuthService::sign($secret, $timestamp, $nonce, $body);
        $service = new CloudAuthService();

        $inactive = new ArrayBranchSecretProvider([$branchUuid => $secret], [$branchUuid => false]);
        $this->assertSame(
            'branch_inactive_or_secret_missing',
            $service->verifyRequest($inactive, $branchUuid, $timestamp, $nonce, $body, $signature, $now)['reason']
        );

        $active = new ArrayBranchSecretProvider([$branchUuid => $secret]);
        $this->assertSame(
            'timestamp_out_of_window',
            $service->verifyRequest($active, $branchUuid, (string) ($now - 1000), $nonce, $body, $signature, $now)['reason']
        );
        $this->assertSame(
            'signature_mismatch',
            $service->verifyRequest($active, $branchUuid, $timestamp, $nonce, '{"event":"tampered"}', $signature, $now)['reason']
        );
    }
}

class cloud_auth_service_test extends CloudAuthServiceTest
{
}
