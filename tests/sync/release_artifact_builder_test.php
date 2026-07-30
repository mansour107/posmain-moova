<?php

require_once __DIR__ . '/../../classes/Release/ReleaseArtifactBuilder.php';

function releaseBuilderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function releaseBuilderRun(string $cwd, array $arguments): string
{
    $pipes = [];
    $process = proc_open(
        array_merge(['git', '-C', $cwd], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    releaseBuilderAssert(is_resource($process), 'unable to start fixture Git');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    releaseBuilderAssert($status === 0, 'fixture Git failed: ' . trim((string) $stderr));

    return (string) $stdout;
}

function releaseBuilderRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }
    rmdir($directory);
}

$fixture = sys_get_temp_dir() . '/posmain-release-builder-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700, true);

try {
    mkdir($fixture . '/api', 0700, true);
    mkdir($fixture . '/assets', 0700, true);
    mkdir($fixture . '/config', 0700, true);
    file_put_contents($fixture . '/.htaccess', "Options -Indexes\n");
    file_put_contents($fixture . '/index.php', "<?php echo 'ok';\n");
    file_put_contents($fixture . '/api/health.php', "<?php echo '{}';\n");
    file_put_contents($fixture . '/assets/app.js', "console.log('committed');\n");
    file_put_contents($fixture . '/config/app.php', "<?php return ['mode' => 'committed'];\n");
    file_put_contents($fixture . '/composer.json', "{}\n");
    file_put_contents($fixture . '/composer.lock', "{}\n");
    file_put_contents($fixture . '/package.json', "{}\n");
    file_put_contents($fixture . '/package-lock.json', "{}\n");

    releaseBuilderRun($fixture, ['init', '-q']);
    releaseBuilderRun($fixture, ['config', 'user.email', 'release-test@posmain.invalid']);
    releaseBuilderRun($fixture, ['config', 'user.name', 'POSMAIN Release Test']);
    releaseBuilderRun($fixture, ['add', '.']);
    releaseBuilderRun($fixture, ['commit', '-q', '-m', 'fixture']);

    // Neither tracked dirt nor untracked files may enter a commit-derived build.
    file_put_contents($fixture . '/assets/app.js', "console.log('dirty');\n");
    file_put_contents($fixture . '/debug_passwords.txt', "must-not-ship\n");

    $config = [
        'version' => 1,
        'endpoint_directories' => ['api'],
        'endpoint_internal_files' => [],
        'root_runtime_files' => ['.htaccess'],
        'runtime_prefixes' => ['assets/', 'config/'],
        'runtime_exact_files' => [],
        'runtime_library_prefixes' => [],
        'dependency_manifests' => [
            'composer.json' => 'composer.lock',
            'package.json' => 'package-lock.json',
        ],
        'prohibited_prefixes' => ['tests/', 'tools/'],
        'prohibited_basename_patterns' => [
            '/(^|[._-])(debug|fix|repair|setup|test)([._-]|$)/i',
        ],
    ];
    $policy = new ReleaseArtifactPolicy(
        $config,
        ['index.php' => ['public' => true]],
        ['api/health.php' => ['endpoint_auth' => true]]
    );
    $builder = new ReleaseArtifactBuilder($fixture, $policy);
    $first = $builder->build('HEAD', $fixture . '/out-one');
    $second = $builder->build('HEAD', $fixture . '/out-two');

    releaseBuilderAssert(
        $first['artifact_sha256'] === $second['artifact_sha256'],
        'identical committed source must produce byte-identical artifacts'
    );
    releaseBuilderAssert(
        hash_file('sha256', $first['manifest_path']) === hash_file('sha256', $second['manifest_path']),
        'identical committed source must produce byte-identical manifests'
    );

    $zip = new ZipArchive();
    releaseBuilderAssert($zip->open($first['artifact_path']) === true, 'unable to inspect release ZIP');
    releaseBuilderAssert($zip->locateName('debug_passwords.txt') === false, 'untracked file entered artifact');
    releaseBuilderAssert(
        $zip->getFromName('assets/app.js') === "console.log('committed');\n",
        'dirty tracked content entered artifact'
    );
    releaseBuilderAssert($zip->locateName('release-manifest.json') !== false, 'manifest missing from ZIP');
    $zip->close();

    $manifest = json_decode((string) file_get_contents($first['manifest_path']), true);
    releaseBuilderAssert(is_array($manifest), 'manifest must be valid JSON');
    releaseBuilderAssert(($manifest['source_commit'] ?? '') === trim(releaseBuilderRun($fixture, ['rev-parse', 'HEAD'])), 'manifest commit mismatch');
    releaseBuilderAssert(($manifest['file_count'] ?? 0) === 5, 'manifest file count mismatch');
    releaseBuilderAssert(count($manifest['dependency_locks'] ?? []) === 2, 'dependency lock evidence missing');

    echo "release-artifact-builder-ok\n";
} finally {
    releaseBuilderRemove($fixture);
}
