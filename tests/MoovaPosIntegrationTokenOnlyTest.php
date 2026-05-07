<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/MoovaPosIntegration.php';

class MoovaPosIntegrationTokenOnlyTest extends TestCase
{
    private static $conn;
    private $scope;

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

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        MoovaPosIntegration::ensureSchema(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }

        $this->scope = [
            'tenant' => 990001,
            'branch' => random_int(100000, 999999),
        ];
    }

    protected function tearDown(): void
    {
        if (!self::$conn || !$this->scope) {
            return;
        }

        $tenant = (int) $this->scope['tenant'];
        $branch = (int) $this->scope['branch'];
        self::$conn->query("DELETE FROM moova_pos_shop_links WHERE pos_tenant = {$tenant} AND pos_branch = {$branch}");
    }

    public function test_active_link_can_be_saved_and_resolved_by_device_token_only(): void
    {
        $token = 'token-only-test-' . bin2hex(random_bytes(8));

        MoovaPosIntegration::saveActiveLinkForScope(self::$conn, $this->scope, [
            'moova_device_token' => $token,
            'widget_url' => 'https://withmoova.com/pos-widget',
            'locale' => 'ar',
        ]);

        $byToken = MoovaPosIntegration::findActiveLinkByToken(self::$conn, $token);
        $this->assertNotNull($byToken);
        $this->assertSame('', (string) $byToken['moova_branch_id']);
        $this->assertSame((int) $this->scope['tenant'], (int) $byToken['pos_tenant']);
        $this->assertSame((int) $this->scope['branch'], (int) $byToken['pos_branch']);

        $this->assertNull(MoovaPosIntegration::findActiveLinkByTokenAndBranch(self::$conn, $token, 'branch-not-required'));
    }
}
