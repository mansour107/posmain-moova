<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';

class BranchIdentityTest extends TestCase
{
    private static $conn;
    private $originalRow;

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

        $this->originalRow = (new SyncBranchIdentity())->find(self::$conn);
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    }

    protected function tearDown(): void
    {
        if (!self::$conn) {
            return;
        }

        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');

        if ($this->originalRow) {
            $stmt = self::$conn->prepare("
                INSERT INTO sync_branch_identity (
                    id,
                    branch_uuid,
                    branch_name,
                    pos_tenant,
                    pos_branch,
                    cloud_base_url,
                    current_menu_version,
                    created_at,
                    updated_at
                ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $branchUuid = (string) $this->originalRow['branch_uuid'];
            $branchName = $this->originalRow['branch_name'];
            $posTenant = $this->nullableInt($this->originalRow['pos_tenant']);
            $posBranch = $this->nullableInt($this->originalRow['pos_branch']);
            $cloudBaseUrl = $this->originalRow['cloud_base_url'];
            $currentMenuVersion = (int) $this->originalRow['current_menu_version'];
            $createdAt = (string) $this->originalRow['created_at'];
            $updatedAt = (string) $this->originalRow['updated_at'];
            $stmt->bind_param(
                'ssiisiss',
                $branchUuid,
                $branchName,
                $posTenant,
                $posBranch,
                $cloudBaseUrl,
                $currentMenuVersion,
                $createdAt,
                $updatedAt
            );
            $stmt->execute();
            $stmt->close();
        }
    }

    public function testEnsureInsertsConfiguredBranchIdentity(): void
    {
        $service = new SyncBranchIdentity();
        $identity = $service->ensure(self::$conn, [
            'branch' => [
                'uuid' => '11111111-2222-4333-8444-555555555555',
                'name' => 'PHPUnit Branch',
                'pos_tenant' => 7,
                'pos_branch' => 9,
                'cloud_base_url' => 'https://cloud.example.test',
            ],
        ]);
        $current = $service->current(self::$conn);

        $this->assertSame('11111111-2222-4333-8444-555555555555', $identity['branch_uuid']);
        $this->assertSame($identity['branch_uuid'], $current['branch_uuid']);
        $this->assertSame('PHPUnit Branch', $identity['branch_name']);
        $this->assertSame(7, (int) $identity['pos_tenant']);
        $this->assertSame(9, (int) $identity['pos_branch']);
        $this->assertSame('https://cloud.example.test', $identity['cloud_base_url']);
        $this->assertSame(0, (int) $identity['current_menu_version']);
    }

    public function testStoredBranchUuidCanBeUpdatedWhenConfigured(): void
    {
        $service = new SyncBranchIdentity();
        $service->ensure(self::$conn, [
            'branch' => [
                'uuid' => '22222222-2222-4222-8222-222222222222',
            ],
        ]);

        $updated = $service->ensure(self::$conn, [
            'branch' => [
                'uuid' => '33333333-3333-4333-8333-333333333333',
            ],
        ]);

        $this->assertSame('33333333-3333-4333-8333-333333333333', $updated['branch_uuid']);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $service->current(self::$conn)['branch_uuid']);
    }

    public function testMissingConfiguredUuidGeneratesAndPersistsStableIdentity(): void
    {
        $service = new SyncBranchIdentity();

        $first = $service->ensure(self::$conn, [
            'branch' => [
                'name' => 'Generated Test Branch',
            ],
        ]);
        $second = $service->ensure(self::$conn, ['branch' => []]);

        $this->assertTrue(SyncBranchIdentity::isUuid($first['branch_uuid']));
        $this->assertSame($first['branch_uuid'], $second['branch_uuid']);
        $this->assertSame('Generated Test Branch', $second['branch_name']);
    }

    public function testAppConfigAndDbBootstrapDoNotDependOnWebSessionConnect(): void
    {
        $config = posmain_app_config([
            'database' => [
                'port' => 3307,
            ],
            'branch' => [
                'uuid' => '44444444-4444-4444-8444-444444444444',
            ],
        ]);

        $this->assertSame('branch', $config['role']);
        $this->assertSame('Africa/Cairo', $config['timezone']);
        $this->assertSame(3307, $config['database']['port']);
        $this->assertSame('44444444-4444-4444-8444-444444444444', $config['branch']['uuid']);
        $this->assertTrue(function_exists('posmain_db_connect'));
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

class branch_identity_test extends BranchIdentityTest
{
}
