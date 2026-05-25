<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Recipe/RecipeTransactionRetryService.php';

class RecipeTransactionRetryServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';

        self::$conn = @new mysqli($host, $user, $pass, '', $port);
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$dbName = 'posmain_recipe_transaction_retry_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');
        self::$conn->query('CREATE TABLE retry_probe (id INT NOT NULL PRIMARY KEY, note VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$conn && self::$dbName) {
            self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
            self::$conn->close();
        }
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }
        self::$conn->query('DELETE FROM retry_probe');
    }

    public function testRetryableTransactionRollsBackAndRetries(): void
    {
        $service = new RecipeTransactionRetryService();
        $attempts = 0;

        $result = $service->run(self::$conn, function (mysqli $conn, int $attempt) use (&$attempts): string {
            $attempts++;
            $conn->query("INSERT INTO retry_probe (id, note) VALUES (1, 'rolled-back-attempt')");
            if ($attempt === 1) {
                throw new mysqli_sql_exception('Deadlock found when trying to get lock; try restarting transaction', 1213);
            }
            $conn->query("INSERT INTO retry_probe (id, note) VALUES (2, 'committed-attempt')");

            return 'ok';
        }, 2, 0);

        $rows = $this->rows();
        $this->assertSame('ok', $result);
        $this->assertSame(2, $attempts);
        $this->assertSame([
            ['id' => '1', 'note' => 'rolled-back-attempt'],
            ['id' => '2', 'note' => 'committed-attempt'],
        ], $rows);
    }

    public function testNonRetryableTransactionDoesNotRetry(): void
    {
        $service = new RecipeTransactionRetryService();
        $attempts = 0;

        try {
            $service->run(self::$conn, function (mysqli $conn) use (&$attempts): void {
                $attempts++;
                $conn->query("INSERT INTO retry_probe (id, note) VALUES (1, 'must-rollback')");
                throw new RuntimeException('validation failed');
            }, 3, 0);
            $this->fail('Expected non-retryable exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('validation failed', $exception->getMessage());
        }

        $this->assertSame(1, $attempts);
        $this->assertSame([], $this->rows());
    }

    public function testRetryableClassificationCoversMysqlDeadlockAndTimeoutErrors(): void
    {
        $service = new RecipeTransactionRetryService();

        $this->assertTrue($service->isRetryable(new mysqli_sql_exception('Deadlock found', 1213)));
        $this->assertTrue($service->isRetryable(new mysqli_sql_exception('Lock wait timeout exceeded', 1205)));
        $this->assertTrue($service->isRetryable(new RuntimeException('try restarting transaction')));
        $this->assertFalse($service->isRetryable(new RuntimeException('validation failed')));
    }

    private function rows(): array
    {
        $result = self::$conn->query('SELECT id, note FROM retry_probe ORDER BY id');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }
}
