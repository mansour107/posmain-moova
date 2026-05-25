<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeReservationService.php';
require_once __DIR__ . '/../classes/Recipe/Repository/StockReservationRepository.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['apply', 'dry-run', 'json', 'limit:', 'now:', 'help']);
if (isset($options['help'])) {
    recipeExpireReservationsUsage(STDOUT);
    exit(0);
}

$apply = isset($options['apply']);
$dryRun = isset($options['dry-run']) || !$apply;
if ($apply && isset($options['dry-run'])) {
    fwrite(STDERR, "Use either --apply or --dry-run, not both.\n");
    exit(1);
}

$limit = isset($options['limit']) ? (int) $options['limit'] : 500;
if ($limit < 1) {
    fwrite(STDERR, "--limit must be greater than 0.\n");
    exit(1);
}

try {
    $now = isset($options['now'])
        ? new DateTimeImmutable((string) $options['now'])
        : new DateTimeImmutable('now');
} catch (Throwable $exception) {
    fwrite(STDERR, "Invalid --now value: " . $exception->getMessage() . "\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = posmain_db_connect();
$conn->begin_transaction();

try {
    if ($dryRun) {
        $expired = (new StockReservationRepository())->findExpiredReserved($conn, $now->format('Y-m-d H:i:s'), $limit);
        $payload = [
            'ok' => true,
            'mode' => 'dry_run',
            'now' => $now->format('Y-m-d H:i:s'),
            'limit' => $limit,
            'would_expire' => count($expired),
            'reservation_ids' => recipeExpireReservationIds($expired),
            'movement_ids' => [],
        ];
        $conn->rollback();
    } else {
        $result = (new RecipeReservationService())->expireReservations($conn, $now, $limit);
        $payload = [
            'ok' => true,
            'mode' => 'apply',
            'now' => $now->format('Y-m-d H:i:s'),
            'limit' => $limit,
            'expired' => count($result->reservationIds),
            'reservation_ids' => $result->reservationIds,
            'movement_ids' => $result->movementIds,
        ];
        $conn->commit();
    }
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, "Failed to expire recipe reservations: " . $exception->getMessage() . "\n");
    exit(1);
}

if (isset($options['json'])) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($dryRun) {
    echo 'Dry-run: would expire ' . (int) $payload['would_expire'] . ' recipe reservation(s).';
    echo " Use --apply to write expiration status and release movements.\n";
    exit(0);
}

echo 'Expired ' . (int) $payload['expired'] . ' recipe reservation(s).';
echo ' Release movement(s): ' . count($payload['movement_ids']) . ".\n";

function recipeExpireReservationsUsage($stream): void
{
    fwrite($stream, "Usage: php tools/recipe_expire_reservations.php [--dry-run|--apply] [--json] [--limit=500] [--now=\"2026-05-24 12:00:00\"]\n");
    fwrite($stream, "Dry-run is the default. Without --apply, the tool only lists expired recipe reservations and writes nothing.\n");
}

function recipeExpireReservationIds(array $reservations): array
{
    return array_map(static function (array $reservation): int {
        return (int) $reservation['id'];
    }, $reservations);
}
