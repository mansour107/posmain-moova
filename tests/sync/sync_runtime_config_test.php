<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SyncRuntimeCrypto.php';
require_once __DIR__ . '/../../classes/Sync/SyncRuntimeDbConfigFile.php';

class SyncRuntimeConfigTest extends TestCase
{
    private $oldKey;
    private $oldKeyFile;
    private $oldFile;
    private $oldDisabled;
    private string $testKeyFile;

    protected function setUp(): void
    {
        $this->oldKey = getenv(SyncRuntimeCrypto::ENV_KEY);
        $this->oldKeyFile = getenv(SyncRuntimeCrypto::KEY_FILE_ENV);
        $this->oldFile = getenv('POSMAIN_RUNTIME_CONFIG_FILE');
        $this->oldDisabled = getenv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG');
        $this->testKeyFile = sys_get_temp_dir() . '/posmain-runtime-key-' . bin2hex(random_bytes(4)) . '.key';
        putenv(SyncRuntimeCrypto::KEY_FILE_ENV . '=' . $this->testKeyFile);
        putenv(SyncRuntimeCrypto::ENV_KEY . '=phpunit-runtime-config-key');
        putenv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG');
    }

    protected function tearDown(): void
    {
        @unlink($this->testKeyFile);
        $this->restoreEnv(SyncRuntimeCrypto::ENV_KEY, $this->oldKey);
        $this->restoreEnv(SyncRuntimeCrypto::KEY_FILE_ENV, $this->oldKeyFile);
        $this->restoreEnv('POSMAIN_RUNTIME_CONFIG_FILE', $this->oldFile);
        $this->restoreEnv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG', $this->oldDisabled);
    }

    public function testEncryptsAndDecryptsRuntimeSecret(): void
    {
        $crypto = new SyncRuntimeCrypto();
        $encrypted = $crypto->encrypt('branch-secret-value');

        $this->assertStringStartsWith('v1:', $encrypted);
        $this->assertNotSame('branch-secret-value', $encrypted);
        $this->assertSame('branch-secret-value', $crypto->decrypt($encrypted));
        $this->assertSame('', $crypto->decrypt($crypto->encrypt('')));
    }

    public function testRuntimeEncryptionKeyFileCanBootstrapCrypto(): void
    {
        putenv(SyncRuntimeCrypto::ENV_KEY);
        unset($_ENV[SyncRuntimeCrypto::ENV_KEY]);

        $crypto = new SyncRuntimeCrypto();
        $path = $crypto->saveKeyMaterial(SyncRuntimeCrypto::generateKeyMaterial(), $this->testKeyFile);

        $this->assertSame($this->testKeyFile, $path);
        $this->assertFileExists($this->testKeyFile);
        $this->assertSame($this->testKeyFile, $crypto->keySource());

        putenv(SyncRuntimeCrypto::ENV_KEY);
        unset($_ENV[SyncRuntimeCrypto::ENV_KEY]);

        $fromFile = new SyncRuntimeCrypto();
        $encrypted = $fromFile->encrypt('secret from ui key');

        $this->assertStringStartsWith('v1:', $encrypted);
        $this->assertSame('secret from ui key', $fromFile->decrypt($encrypted));
        $this->assertSame($this->testKeyFile, $fromFile->keySource());
    }

    public function testRuntimeDbConfigFileStoresEncryptedPasswordAndCanBeBypassed(): void
    {
        $path = sys_get_temp_dir() . '/posmain-runtime-config-' . bin2hex(random_bytes(4)) . '.json';
        putenv('POSMAIN_RUNTIME_CONFIG_FILE=' . $path);

        $service = new SyncRuntimeDbConfigFile();
        $service->save([
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'kody2',
            'user' => 'posmain',
            'pass' => 'db-secret',
            'charset' => 'utf8mb4',
        ]);

        $raw = (string) file_get_contents($path);
        $this->assertStringContainsString('pass_encrypted', $raw);
        $this->assertStringNotContainsString('db-secret', $raw);
        $this->assertSame('db-secret', $service->load($path)['database']['pass']);

        putenv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG=1');
        $this->assertSame([], $service->load($path));

        @unlink($path);
    }

    private function restoreEnv(string $name, $value): void
    {
        if ($value === false) {
            putenv($name);
            unset($_ENV[$name]);
            return;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

class sync_runtime_config_test extends SyncRuntimeConfigTest
{
}
