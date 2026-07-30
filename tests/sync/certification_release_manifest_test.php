<?php

require_once __DIR__ . '/../../classes/Release/CertificationReceipt.php';

function certificationManifestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/posmain-certification-manifest-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('unable to create manifest fixture');
}

try {
    $runtimePath = $directory . '/runtime.php';
    file_put_contents($runtimePath, "<?php echo 'runtime';\n", LOCK_EX);
    $contents = file_get_contents($runtimePath);
    $core = [
        'schema' => 'posmain.release-artifact.v1',
        'policy_version' => 1,
        'source_commit' => str_repeat('a', 40),
        'source_commit_time' => '2026-07-29T08:00:00+00:00',
        'dependency_locks' => [],
        'file_count' => 1,
        'files' => [[
            'path' => 'runtime.php',
            'size' => strlen((string) $contents),
            'sha256' => hash('sha256', (string) $contents),
        ]],
    ];
    $manifest = $core + [
        'manifest_sha256' => hash(
            'sha256',
            (string) json_encode($core, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ),
    ];
    $manifestPath = $directory . '/release-manifest.json';
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );

    $verified = CertificationReceipt::verifyReleaseManifest($manifestPath);
    certificationManifestAssert(
        $verified['manifest_sha256'] === $manifest['manifest_sha256'],
        'valid extracted artifact tree should match its manifest'
    );

    file_put_contents($runtimePath, "<?php echo 'tampered';\n", LOCK_EX);
    try {
        CertificationReceipt::verifyReleaseManifest($manifestPath);
        throw new RuntimeException('tampered runtime file should have failed');
    } catch (RuntimeException $exception) {
        certificationManifestAssert(
            str_starts_with($exception->getMessage(), 'CERTIFICATION_RELEASE_FILE_MISMATCH:'),
            'tampered file must report an exact mismatch'
        );
    }

    $pathTraversal = $manifest;
    $pathTraversal['files'][0]['path'] = '../runtime.php';
    $pathTraversalCore = $pathTraversal;
    unset($pathTraversalCore['manifest_sha256']);
    $pathTraversal['manifest_sha256'] = hash(
        'sha256',
        (string) json_encode($pathTraversalCore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    file_put_contents(
        $manifestPath,
        json_encode($pathTraversal, JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
    try {
        CertificationReceipt::verifyReleaseManifest($manifestPath);
        throw new RuntimeException('path traversal should have failed');
    } catch (RuntimeException $exception) {
        certificationManifestAssert(
            $exception->getMessage() === 'CERTIFICATION_RELEASE_MANIFEST_PATH_INVALID',
            'manifest path traversal must fail before file access'
        );
    }
} finally {
    @unlink($directory . '/runtime.php');
    @unlink($directory . '/release-manifest.json');
    @rmdir($directory);
}

echo "certification-release-manifest-ok\n";
