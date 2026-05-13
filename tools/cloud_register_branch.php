<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';

if (!function_exists('cloudRegisterBranch')) {
    function cloudRegisterBranch(mysqli $conn, array $options): array
    {
        $branchUuid = trim((string) ($options['branch-uuid'] ?? ''));
        $secret = (string) ($options['secret'] ?? '');
        if (!SyncBranchIdentity::isUuid($branchUuid)) {
            throw new InvalidArgumentException('--branch-uuid must be a valid UUID.');
        }
        if ($secret === '') {
            throw new InvalidArgumentException('--secret is required.');
        }

        $branchName = cloudRegisterNullableString($options['name'] ?? null);
        $tenant = cloudRegisterNullableInt($options['tenant'] ?? null);
        $branch = cloudRegisterNullableInt($options['branch'] ?? null);
        $status = !empty($options['disabled']) ? 'disabled' : 'active';
        $secretHash = hash('sha256', $secret);

        (new SyncSchemaManager())->apply($conn);

        $stmt = $conn->prepare("
            INSERT INTO cloud_branches (
                branch_uuid,
                branch_name,
                pos_tenant,
                pos_branch,
                status,
                sync_secret_hash
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name),
                                    pos_tenant = VALUES(pos_tenant),
                                    pos_branch = VALUES(pos_branch),
                                    status = VALUES(status),
                                    sync_secret_hash = VALUES(sync_secret_hash),
                                    updated_at = NOW(6)
        ");
        $stmt->bind_param('ssiiss', $branchUuid, $branchName, $tenant, $branch, $status, $secretHash);
        $stmt->execute();
        $stmt->close();

        return [
            'branch_uuid' => $branchUuid,
            'branch_name' => $branchName,
            'pos_tenant' => $tenant,
            'pos_branch' => $branch,
            'status' => $status,
            'sync_secret_hash' => $secretHash,
            'branch_env' => [
                'POSMAIN_ROLE' => 'branch',
                'POSMAIN_BRANCH_UUID' => $branchUuid,
                'POSMAIN_BRANCH_NAME' => $branchName ?: '',
                'POSMAIN_POS_TENANT' => $tenant === null ? '' : (string) $tenant,
                'POSMAIN_POS_BRANCH' => $branch === null ? '' : (string) $branch,
                'POSMAIN_BRANCH_SYNC_SECRET' => $secret,
            ],
        ];
    }
}

if (!function_exists('cloudRegisterPrintResult')) {
    function cloudRegisterPrintResult(array $result): void
    {
        echo "Registered cloud branch {$result['branch_uuid']} ({$result['status']}).\n";
        echo "Stored sync_secret_hash: {$result['sync_secret_hash']}\n";
        echo "Branch install environment:\n";
        foreach ($result['branch_env'] as $key => $value) {
            echo $key . '=' . cloudRegisterShellValue((string) $value) . "\n";
        }
    }
}

if (!function_exists('cloudRegisterShellValue')) {
    function cloudRegisterShellValue(string $value): string
    {
        if ($value === '') {
            return "''";
        }

        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }
}

if (!function_exists('cloudRegisterNullableString')) {
    function cloudRegisterNullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('cloudRegisterNullableInt')) {
    function cloudRegisterNullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $options = getopt('', ['branch-uuid:', 'name::', 'tenant::', 'branch::', 'secret:', 'disabled']);
    try {
        cloudRegisterPrintResult(cloudRegisterBranch(posmain_db_connect(), $options));
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        fwrite(
            STDERR,
            "Usage: php tools/cloud_register_branch.php --branch-uuid=<uuid> --name='Branch A' --tenant=1 --branch=1 --secret=<secret> [--disabled]\n"
        );
        exit(1);
    }
}
