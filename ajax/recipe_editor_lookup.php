<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../classes/Recipe/RecipeEditorLookupService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
if (!posmain_recipe_lookup_can_view($conn)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'code' => 'PERMISSION_DENIED',
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $lookup = new RecipeEditorLookupService();
    $type = strtolower(trim((string) ($_GET['type'] ?? 'items')));
    $query = trim((string) ($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));

    if ($type === 'components') {
        $items = $lookup->searchComponents(
            $conn,
            $query,
            max(0, (int) ($_GET['pos_tenant'] ?? 0)),
            max(0, (int) ($_GET['pos_branch'] ?? 0)),
            max(0, (int) ($_GET['exclude_recipe_id'] ?? 0)),
            $limit
        );
    } elseif ($type === 'sub_recipes') {
        $items = $lookup->searchSubRecipes(
            $conn,
            $query,
            max(0, (int) ($_GET['pos_tenant'] ?? 0)),
            max(0, (int) ($_GET['pos_branch'] ?? 0)),
            max(0, (int) ($_GET['exclude_recipe_id'] ?? 0)),
            $limit
        );
    } elseif ($type === 'modifier_options') {
        $items = $lookup->searchModifierOptions($conn, $query, $limit);
    } else {
        $items = $lookup->searchItems(
            $conn,
            $query,
            strtolower(trim((string) ($_GET['kind'] ?? 'any'))),
            $limit
        );
    }

    echo json_encode([
        'success' => true,
        'type' => $type,
        'count' => count($items),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code' => 'LOOKUP_FAILED',
        'message' => 'Recipe lookup failed.',
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
}

function posmain_recipe_lookup_can_view(mysqli $conn): bool
{
    return auth_guard_has_permission('menu.edit', $conn)
        || auth_guard_has_permission('inventory.edit', $conn)
        || auth_guard_has_permission('accounting.view', $conn);
}
