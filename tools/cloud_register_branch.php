<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/CloudBranchRegistryService.php';

if (!function_exists('cloudRegisterBranch')) {
    function cloudRegisterBranch(mysqli $conn, array $options): array
    {
        $result = (new CloudBranchRegistryService())->register($conn, $options);
        $result['branch_env']['POSMAIN_BRANCH_NAME'] = $result['branch_name'] ?: '';
        $result['branch_env']['POSMAIN_POS_TENANT'] = $result['pos_tenant'] === null ? '' : (string) $result['pos_tenant'];
        $result['branch_env']['POSMAIN_POS_BRANCH'] = $result['pos_branch'] === null ? '' : (string) $result['pos_branch'];

        return $result;
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
