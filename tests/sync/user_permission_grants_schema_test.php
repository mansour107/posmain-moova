<?php

$schemaPath = __DIR__ . '/../../classes/Sync/SchemaManager.php';
$source = file_get_contents($schemaPath);
userPermSchemaAssert(is_string($source), 'unable to read SchemaManager');

foreach ([
    'user_permission_grants',
    'userPermissionGrantsSql',
    'permission_mode',
    "ENUM('role_only','role_with_overrides')",
    'idx_users_userrole',
] as $snippet) {
    userPermSchemaAssert(strpos($source, $snippet) !== false, 'SchemaManager missing ' . $snippet);
}

$grantService = __DIR__ . '/../../classes/Security/UserPermissionGrantService.php';
userPermSchemaAssert(is_file($grantService), 'UserPermissionGrantService missing');

echo "user-permission-grants-schema-ok\n";

function userPermSchemaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
