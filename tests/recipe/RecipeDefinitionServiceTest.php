<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeVersioningService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeAuditRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeLineRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/RecipeRepository.php';

class RecipeDefinitionServiceTest extends TestCase
{
    private static $conn;
    private static $dbName;
    private static $itemCounter = 8000;

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
        self::$dbName = 'posmain_recipe_definition_' . getmypid();
        self::$conn->query('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$conn->query('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');

        (new SyncSchemaManager())->apply(self::$conn);
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
    }

    public function testFeatureFlagsMustAllowRecipeDefinitionWrites(): void
    {
        $service = new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $service->createDraft(self::$conn, [
            'sellable_item_id' => $this->nextItemId(),
            'recipe_name' => 'Disabled recipe',
        ], $this->actor(['admin']));
    }

    public function testCanCreateDraftAddLineApproveActivateAndAudit(): void
    {
        $service = $this->service();
        $itemId = $this->nextItemId();
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);

        $recipe = $service->createDraft(self::$conn, [
            'sellable_item_id' => $itemId,
            'recipe_name' => 'Cheeseburger',
        ], $actor);
        $line = $service->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => 2001,
            'qty_per_yield' => '1.000000',
        ], $actor);
        $approved = $service->approve(self::$conn, (int) $recipe['id'], $actor);
        $active = $service->activate(self::$conn, (int) $recipe['id'], $actor);
        $resolved = $service->getActiveRecipeForItem(self::$conn, $itemId, 0, 0);
        $auditRows = (new RecipeAuditRepository())->findForRecipe(self::$conn, 0, 0, (int) $recipe['id']);

        $this->assertSame('active', $active['status']);
        $this->assertSame((int) $recipe['id'], (int) $resolved['id']);
        $this->assertSame((int) $line['recipe_id'], (int) $recipe['id']);
        $this->assertSame((int) $actor->userId, (int) $approved['approved_by']);
        $this->assertGreaterThanOrEqual(4, count($auditRows));
    }

    public function testCannotActivateInvalidRecipeWithoutRequiredStockLine(): void
    {
        $service = $this->service();
        $recipe = $service->createDraft(self::$conn, [
            'sellable_item_id' => $this->nextItemId(),
            'recipe_name' => 'Invalid recipe',
        ], $this->actor(['recipe.manage', 'recipe.approve']));

        $this->expectException(RuntimeException::class);
        $service->activate(self::$conn, (int) $recipe['id'], $this->actor(['recipe.approve']));
    }

    public function testActiveRecipesAreImmutableAndCloneCreatesNewDraftVersion(): void
    {
        $service = $this->service();
        $actor = $this->actor(['recipe.manage', 'recipe.approve']);
        $itemId = $this->nextItemId();
        $active = $this->createActiveRecipe($service, $itemId, $actor);

        try {
            $service->addLine(self::$conn, (int) $active['id'], [
                'ingredient_item_id' => 2002,
                'qty_per_yield' => '1.000000',
            ], $actor);
            $this->fail('Expected active recipe immutability to reject line changes.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('draft', $exception->getMessage());
        }

        $versioning = new RecipeVersioningService($service);
        $draft = $versioning->cloneActiveAsDraft(self::$conn, (int) $active['id'], $actor);
        $draftLines = (new RecipeLineRepository())->findLinesByRecipeId(self::$conn, (int) $draft['id']);

        $this->assertSame('draft', $draft['status']);
        $this->assertSame(2, (int) $draft['version_number']);
        $this->assertCount(1, $draftLines);

        $service->addLine(self::$conn, (int) $draft['id'], [
            'ingredient_item_id' => 2003,
            'qty_per_yield' => '0.500000',
        ], $actor);
        $newActive = $service->activate(self::$conn, (int) $draft['id'], $actor);
        $old = (new RecipeRepository())->findHeaderById(self::$conn, (int) $active['id']);

        $this->assertSame('active', $newActive['status']);
        $this->assertSame('archived', $old['status']);
    }

    public function testEditAndApprovePermissionsAreRequired(): void
    {
        $service = $this->service();

        $this->expectException(RuntimeException::class);
        $service->createDraft(self::$conn, [
            'sellable_item_id' => $this->nextItemId(),
            'recipe_name' => 'No permission recipe',
        ], $this->actor([]));
    }

    private function createActiveRecipe(RecipeDefinitionService $service, int $itemId, RecipeActorContext $actor): array
    {
        $recipe = $service->createDraft(self::$conn, [
            'sellable_item_id' => $itemId,
            'recipe_name' => 'Recipe ' . $itemId,
        ], $actor);
        $service->addLine(self::$conn, (int) $recipe['id'], [
            'ingredient_item_id' => 2001,
            'qty_per_yield' => '1.000000',
        ], $actor);

        return $service->activate(self::$conn, (int) $recipe['id'], $actor);
    }

    private function service(): RecipeDefinitionService
    {
        return new RecipeDefinitionService(new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'shadow',
            ],
        ]));
    }

    private function actor(array $permissions): RecipeActorContext
    {
        return new RecipeActorContext(77, 0, 0, null, $permissions, '127.0.0.1', 'phpunit');
    }

    private function nextItemId(): int
    {
        return ++self::$itemCounter;
    }
}
