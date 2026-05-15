<?php

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/NutritionProfileService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_nutrition_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new NutritionProfileService();
    $enabled = phase4NutritionEnvEnabled();

    if (!$enabled) {
        $result = $service->saveProfile($conn, 10, [
            'serving_qty' => 250,
            'calories_kcal' => 120,
        ], ['user_id' => 7]);
        phase4NutritionAssert($result['code'] === 'NUTRITION_DISABLED', 'disabled mode should return NUTRITION_DISABLED');
        phase4NutritionAssert(phase4NutritionCount($conn) === 0, 'disabled mode should not persist profile');
        echo "phase4-nutrition-profile-service-ok disabled db={$db}\n";
        return;
    }

    $saved = $service->saveProfile($conn, 10, [
        'serving_qty' => '250',
        'serving_unit_id' => 3,
        'calories_kcal' => '120.5',
        'protein_g' => '6.25',
        'carbs_g' => '18.5',
        'fat_g' => '2.75',
        'sugar_g' => '10.125',
        'fiber_g' => null,
        'sodium_mg' => '95',
        'allergens' => ['milk', 'nuts'],
        'dietary_flags' => ['vegetarian'],
        'data_source' => 'manual',
        'verified_at' => '2026-05-13 10:00:00',
    ], ['user_id' => 7]);
    phase4NutritionAssert($saved['success'] === true, 'enabled mode should save');
    $profile = $saved['profile'];
    phase4NutritionAssert($profile['item_id'] === 10, 'profile item expected');
    phase4NutritionAssert($profile['serving_qty'] === '250.000', 'serving qty should normalize');
    phase4NutritionAssert($profile['calories_kcal'] === '120.500', 'calories should normalize');
    phase4NutritionAssert($profile['fiber_g'] === null, 'nullable macro should remain null');
    phase4NutritionAssert($profile['allergens'] === ['milk', 'nuts'], 'allergens should round trip');
    phase4NutritionAssert($profile['dietary_flags'] === ['vegetarian'], 'dietary flags should round trip');
    phase4NutritionAssert($profile['verified_by'] === 7, 'verified_by should default from context user');
    phase4NutritionAssert($profile['verified_at'] === '2026-05-13 10:00:00', 'verified_at should persist');

    $totals = $service->nutritionForQty($profile, '500');
    phase4NutritionAssert($totals['factor'] === '2.000', 'factor should be proportional to serving qty');
    phase4NutritionAssert($totals['calories_kcal'] === '241.000', 'calorie total should scale');
    phase4NutritionAssert($totals['protein_g'] === '12.500', 'protein total should scale');
    phase4NutritionAssert($totals['fiber_g'] === null, 'null nutrition values should stay null');

    $updated = $service->saveProfile($conn, 10, [
        'serving_qty' => '100',
        'calories_kcal' => '50',
        'protein_g' => '2',
        'carbs_g' => '7',
        'fat_g' => '1.5',
        'allergens_json' => json_encode(['soy'], JSON_UNESCAPED_UNICODE),
        'dietary_flags_json' => json_encode(['vegan'], JSON_UNESCAPED_UNICODE),
        'data_source' => 'vendor',
        'verified_by' => 8,
    ], ['user_id' => 7]);
    phase4NutritionAssert($updated['profile']['id'] === $profile['id'], 'saveProfile should upsert by item_id');
    phase4NutritionAssert($updated['profile']['serving_qty'] === '100.000', 'updated serving qty expected');
    phase4NutritionAssert($updated['profile']['allergens'] === ['soy'], 'updated allergens expected');
    phase4NutritionAssert($updated['profile']['dietary_flags'] === ['vegan'], 'updated dietary flags expected');
    phase4NutritionAssert($updated['profile']['verified_by'] === 8, 'explicit verified_by should win');
    phase4NutritionAssert(phase4NutritionCount($conn) === 1, 'upsert should not duplicate nutrition rows');

    phase4NutritionExpectException(function () use ($service, $conn) {
        $service->saveProfile($conn, 11, ['serving_qty' => 0], ['nutrition_enabled' => true]);
    }, 'SERVING_QTY_INVALID');

    phase4NutritionExpectException(function () use ($service, $conn) {
        $service->saveProfile($conn, 11, [
            'serving_qty' => 1,
            'calories_kcal' => -1,
        ], ['nutrition_enabled' => true]);
    }, 'CALORIES_KCAL_INVALID');

    phase4NutritionExpectException(function () use ($service, $conn) {
        $service->saveProfile($conn, 11, [
            'serving_qty' => 1,
            'allergens_json' => '{bad',
        ], ['nutrition_enabled' => true]);
    }, 'ALLERGENS_INVALID');

    phase4NutritionExpectException(function () use ($service, $profile) {
        $service->nutritionForQty($profile, 0);
    }, 'QTY_INVALID');

    echo "phase4-nutrition-profile-service-ok enabled db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4NutritionEnvEnabled(): bool
{
    $config = posmain_app_config();
    return !empty($config['features']['nutrition']);
}

function phase4NutritionCount(mysqli $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM item_nutrition_profiles")->fetch_assoc()['c'];
}

function phase4NutritionExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4NutritionAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4NutritionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
