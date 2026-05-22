<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchRegistryService.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncRuntimeCrypto.php';
require_once __DIR__ . '/../../classes/Sync/SyncRuntimeSettings.php';

class SyncRuntimeSettingsCloudAuthTest extends TestCase
{
    private const BRANCH_UUID = 'cccccccc-3333-4333-8333-cccccccccccc';
    private static $conn;
    private $oldKey;
    private $originalIdentity;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
        $db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

        self::$conn = @new mysqli($host, $user, $pass, $db, $port);
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        self::$conn->set_charset('utf8mb4');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        (new SyncSchemaManager())->apply(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }

        $this->oldKey = getenv(SyncRuntimeCrypto::ENV_KEY);
        putenv(SyncRuntimeCrypto::ENV_KEY . '=phpunit-sync-runtime-key');
        $this->originalIdentity = self::$conn->query("SELECT * FROM sync_branch_identity WHERE id = 1 LIMIT 1")->fetch_assoc() ?: null;
        self::$conn->query("DELETE FROM sync_runtime_settings WHERE setting_key LIKE 'POSMAIN_%'");
        self::$conn->query("DELETE FROM sync_branch_identity WHERE id = 1");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::BRANCH_UUID . "'");
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            self::$conn->query("DELETE FROM sync_runtime_settings WHERE setting_key LIKE 'POSMAIN_%'");
            self::$conn->query("DELETE FROM sync_branch_identity WHERE id = 1");
            if ($this->originalIdentity) {
                $stmt = self::$conn->prepare("
                    INSERT INTO sync_branch_identity (
                        id, branch_uuid, branch_name, pos_tenant, pos_branch, cloud_base_url, current_menu_version
                    ) VALUES (1, ?, ?, ?, ?, ?, ?)
                ");
                $branchUuid = (string) $this->originalIdentity['branch_uuid'];
                $branchName = $this->originalIdentity['branch_name'];
                $posTenant = $this->originalIdentity['pos_tenant'] === null ? null : (int) $this->originalIdentity['pos_tenant'];
                $posBranch = $this->originalIdentity['pos_branch'] === null ? null : (int) $this->originalIdentity['pos_branch'];
                $cloudBaseUrl = $this->originalIdentity['cloud_base_url'];
                $menuVersion = (int) ($this->originalIdentity['current_menu_version'] ?? 0);
                $stmt->bind_param('ssiisi', $branchUuid, $branchName, $posTenant, $posBranch, $cloudBaseUrl, $menuVersion);
                $stmt->execute();
                $stmt->close();
            }
            self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::BRANCH_UUID . "'");
        }
        if ($this->oldKey === false) {
            putenv(SyncRuntimeCrypto::ENV_KEY);
        } else {
            putenv(SyncRuntimeCrypto::ENV_KEY . '=' . $this->oldKey);
        }
    }

    public function testUiSavedBranchSettingsBecomeConfigOverrides(): void
    {
        (new SyncRuntimeSettings())->save(self::$conn, [
            'role' => 'branch',
            'POSMAIN_BRANCH_UUID' => self::BRANCH_UUID,
            'POSMAIN_CLOUD_BASE_URL' => 'https://shop.example.test',
            'POSMAIN_BRANCH_SYNC_SECRET' => 'ui-branch-secret',
            'POSMAIN_SYNC_OUTBOX_ENABLED' => '1',
            'POSMAIN_BRANCH_SYNC_ENABLED' => '1',
            'POSMAIN_SYNC_WORKER_ENABLED' => '1',
            'POSMAIN_MENU_SYNC_ENABLED' => '1',
        ]);

        $overrides = (new SyncRuntimeSettings())->fetchConfigOverrides(self::$conn);

        $this->assertSame('branch', $overrides['role']);
        $this->assertSame(self::BRANCH_UUID, $overrides['branch']['uuid']);
        $this->assertSame('https://shop.example.test', $overrides['branch']['cloud_base_url']);
        $this->assertSame('ui-branch-secret', $overrides['sync']['branch_secret']);
        $this->assertTrue($overrides['sync']['branch_sync_enabled']);
        $this->assertTrue($overrides['sync']['menu_sync_enabled']);
    }

    public function testUiRegisteredEncryptedCloudBranchSecretValidatesHmac(): void
    {
        (new CloudBranchRegistryService())->register(self::$conn, [
            'branch_uuid' => self::BRANCH_UUID,
            'secret' => 'ui-cloud-secret',
            'status' => 'active',
            'cloud_base_url' => 'https://shop.example.test',
            'require_encryption' => true,
        ]);

        $row = self::$conn->query("SELECT sync_secret_hash, sync_secret_encrypted FROM cloud_branches WHERE branch_uuid = '" . self::BRANCH_UUID . "'")->fetch_assoc();
        $this->assertSame(hash('sha256', 'ui-cloud-secret'), $row['sync_secret_hash']);
        $this->assertNotEmpty($row['sync_secret_encrypted']);
        $this->assertStringNotContainsString('ui-cloud-secret', $row['sync_secret_encrypted']);

        $body = '{"events":[]}';
        $timestamp = (string) time();
        $nonce = 'phpunit-nonce';
        $signature = CloudAuthService::sign('ui-cloud-secret', $timestamp, $nonce, $body);
        $provider = DatabaseBranchSecretProvider::fromConfig(self::$conn, [
            'sync' => ['cloud_branch_secrets' => []],
            'branch' => ['uuid' => ''],
        ]);

        $result = (new CloudAuthService())->verifyRequest($provider, self::BRANCH_UUID, $timestamp, $nonce, $body, $signature);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['reason']);
    }
}

class sync_runtime_settings_cloud_auth_test extends SyncRuntimeSettingsCloudAuthTest
{
}
