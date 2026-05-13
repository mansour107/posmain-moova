<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tools/cloud_register_branch.php';

class CloudRegisterBranchTest extends TestCase
{
    private const BRANCH_UUID = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
    private static $conn;

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

        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        }
    }

    public function testRegistersBranchWithSecretHashOnly(): void
    {
        $result = cloudRegisterBranch(self::$conn, [
            'branch-uuid' => self::BRANCH_UUID,
            'name' => 'Runtime Branch',
            'tenant' => '7',
            'branch' => '9',
            'secret' => 'runtime-secret',
        ]);

        $this->assertSame(self::BRANCH_UUID, $result['branch_uuid']);
        $this->assertSame(hash('sha256', 'runtime-secret'), $result['sync_secret_hash']);
        $this->assertSame('runtime-secret', $result['branch_env']['POSMAIN_BRANCH_SYNC_SECRET']);

        $row = $this->fetchBranch();
        $this->assertSame('Runtime Branch', $row['branch_name']);
        $this->assertSame(7, (int) $row['pos_tenant']);
        $this->assertSame(9, (int) $row['pos_branch']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(hash('sha256', 'runtime-secret'), $row['sync_secret_hash']);
        $this->assertNotSame('runtime-secret', $row['sync_secret_hash']);
    }

    public function testRegisterUpdatesExistingBranchAndCanDisableIt(): void
    {
        cloudRegisterBranch(self::$conn, [
            'branch-uuid' => self::BRANCH_UUID,
            'name' => 'Old Name',
            'tenant' => '1',
            'branch' => '2',
            'secret' => 'old-secret',
        ]);
        cloudRegisterBranch(self::$conn, [
            'branch-uuid' => self::BRANCH_UUID,
            'name' => 'New Name',
            'tenant' => '3',
            'branch' => '4',
            'secret' => 'new-secret',
            'disabled' => true,
        ]);

        $row = $this->fetchBranch();
        $this->assertSame('New Name', $row['branch_name']);
        $this->assertSame(3, (int) $row['pos_tenant']);
        $this->assertSame(4, (int) $row['pos_branch']);
        $this->assertSame('disabled', $row['status']);
        $this->assertSame(hash('sha256', 'new-secret'), $row['sync_secret_hash']);
    }

    public function testRejectsInvalidInputBeforeWriting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        cloudRegisterBranch(self::$conn, [
            'branch-uuid' => 'not-a-uuid',
            'secret' => 'runtime-secret',
        ]);
    }

    public function testPrintsBranchEnvironmentLines(): void
    {
        $result = cloudRegisterBranch(self::$conn, [
            'branch-uuid' => self::BRANCH_UUID,
            'name' => 'Runtime Branch',
            'tenant' => '7',
            'branch' => '9',
            'secret' => 'runtime-secret',
        ]);

        ob_start();
        cloudRegisterPrintResult($result);
        $output = ob_get_clean();

        $this->assertStringContainsString('POSMAIN_ROLE=', $output);
        $this->assertStringContainsString('POSMAIN_BRANCH_UUID=', $output);
        $this->assertStringContainsString('POSMAIN_BRANCH_SYNC_SECRET=', $output);
        $this->assertStringContainsString(hash('sha256', 'runtime-secret'), $output);
    }

    private function fetchBranch(): array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_branches
            WHERE branch_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }
}

class cloud_register_branch_test extends CloudRegisterBranchTest
{
}
