<?php

if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/../includes/http_gone.php';
}

$modernMode = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (
        $argument === '--preflight'
        || $argument === '--verbose'
        || str_starts_with($argument, '--ref=')
        || str_starts_with($argument, '--output=')
    ) {
        $modernMode = true;
        break;
    }
}

if ($modernMode) {
    require_once __DIR__ . '/../classes/Release/ReleaseArtifactBuilder.php';

    $modernRoot = realpath(__DIR__ . '/..');
    if ($modernRoot === false) {
        fwrite(STDERR, "Unable to resolve repository root.\n");
        exit(2);
    }

    $modernOptions = getopt('', ['ref::', 'output::', 'preflight', 'verbose']);
    $modernRef = trim((string) ($modernOptions['ref'] ?? 'HEAD'));
    $modernOutput = trim((string) ($modernOptions['output'] ?? ''));

    try {
        $modernPolicy = ReleaseArtifactPolicy::fromRepository($modernRoot);
        $modernBuilder = new ReleaseArtifactBuilder($modernRoot, $modernPolicy);
        if (isset($modernOptions['preflight']) || $modernOutput === '') {
            $modernResult = $modernBuilder->preflight($modernRef);
            $modernPayload = $modernResult;
            if (!isset($modernOptions['verbose'])) {
                unset($modernPayload['included'], $modernPayload['excluded']);
            }
            echo json_encode($modernPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            exit($modernResult['ok'] ? 0 : 3);
        }

        $modernResult = $modernBuilder->build($modernRef, $modernOutput);
        echo json_encode($modernResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'release-artifact-error: ' . $exception->getMessage() . "\n");
        exit(2);
    }
}

/**
 * Build a Commercial V1 release artifact from tracked source using the release
 * packaging policy. Release-affecting uncommitted or untracked files are hard
 * failures so the manifest can be reproduced from source_commit.
 *
 * Usage:
 *   php tools/build_release_artifact.php --out=var/release/posmain-rc
 *   php tools/build_release_artifact.php --out=var/release/posmain-rc --json
 */

$options = getopt('', ['out:', 'json', 'help']);
if (isset($options['help']) || empty($options['out'])) {
    echo "Usage: php tools/build_release_artifact.php --out=DIR [--json]\n";
    exit(isset($options['help']) ? 0 : 1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "ROOT_RESOLVE_FAILED\n");
    exit(1);
}

/** @var array $policy */
$policy = require $root . '/config/release_artifact_policy.php';
$outDir = $options['out'];
if ($outDir[0] !== '/') {
    $outDir = $root . '/' . ltrim($outDir, '/');
}

/**
 * @param list<string> $patterns
 */
function posmain_release_name_matches(string $name, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $policy
 */
function posmain_release_is_denied(string $relative, array $policy): bool
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    foreach ($policy['deny_path_exact'] as $exact) {
        if ($relative === ltrim(str_replace('\\', '/', (string) $exact), '/')) {
            return true;
        }
    }
    foreach ($policy['deny_path_prefixes'] as $prefix) {
        $prefix = ltrim(str_replace('\\', '/', (string) $prefix), '/');
        if ($prefix !== '' && str_starts_with($relative, $prefix)) {
            return true;
        }
    }
    $base = basename($relative);
    if (posmain_release_name_matches($base, $policy['deny_name_globs'])) {
        return true;
    }

    return false;
}

/**
 * @param array<string,mixed> $policy
 */
function posmain_release_is_allowed(string $relative, array $policy): bool
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if (posmain_release_is_denied($relative, $policy)) {
        return false;
    }

    if (!str_contains($relative, '/')) {
        return posmain_release_name_matches($relative, $policy['allow_root_globs']);
    }

    $top = explode('/', $relative, 2)[0];
    return in_array($top, $policy['allow_directories'], true);
}

$selected = [];
$deniedHits = [];
$trackedRaw = shell_exec(
    'git -C ' . escapeshellarg($root) . ' ls-files -z --cached 2>/dev/null'
);
if (!is_string($trackedRaw)) {
    fwrite(STDERR, "TRACKED_SOURCE_LIST_FAILED\n");
    exit(1);
}

foreach (explode("\0", $trackedRaw) as $relative) {
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || !is_file($root . '/' . $relative)) {
        continue;
    }
    if (posmain_release_is_denied($relative, $policy)) {
        // Denied paths must not be selected. Track only if they would otherwise
        // look like publishable app files (root/php or allow dirs).
        $top = explode('/', $relative, 2)[0];
        $looksPublishable = !str_contains($relative, '/')
            || in_array($top, $policy['allow_directories'], true);
        if ($looksPublishable) {
            $deniedHits[] = $relative;
        }
        continue;
    }
    if (posmain_release_is_allowed($relative, $policy)) {
        $selected[] = $relative;
    }
}

sort($selected);
$deniedHits = array_values(array_unique($deniedHits));
sort($deniedHits);

$gitCommit = trim((string) shell_exec(
    'git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'
));
$gitBranch = trim((string) shell_exec(
    'git -C ' . escapeshellarg($root) . ' branch --show-current 2>/dev/null'
));

$worktreeDiffCode = 0;
$indexDiffCode = 0;
exec('git -C ' . escapeshellarg($root) . ' diff --quiet HEAD -- 2>/dev/null', $unusedWorktreeOutput, $worktreeDiffCode);
exec('git -C ' . escapeshellarg($root) . ' diff --cached --quiet HEAD -- 2>/dev/null', $unusedIndexOutput, $indexDiffCode);

$untrackedPublishable = [];
$untrackedRaw = shell_exec(
    'git -C ' . escapeshellarg($root) . ' ls-files -z --others --exclude-standard 2>/dev/null'
);
if (is_string($untrackedRaw)) {
    foreach (explode("\0", $untrackedRaw) as $relative) {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative !== '' && posmain_release_is_allowed($relative, $policy)) {
            $untrackedPublishable[] = $relative;
        }
    }
}
$untrackedPublishable = array_values(array_unique($untrackedPublishable));
sort($untrackedPublishable);
$sourceTreeClean = $gitCommit !== ''
    && $worktreeDiffCode === 0
    && $indexDiffCode === 0
    && $untrackedPublishable === [];

if (is_dir($outDir)) {
    $purge = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($purge as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
}
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "OUT_DIR_CREATE_FAILED\n");
    exit(1);
}

foreach ($selected as $relative) {
    $source = $root . '/' . $relative;
    $target = $outDir . '/' . $relative;
    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "TARGET_DIR_CREATE_FAILED {$relative}\n");
        exit(1);
    }
    if (!copy($source, $target)) {
        fwrite(STDERR, "COPY_FAILED {$relative}\n");
        exit(1);
    }
}

// Hard verify: no prohibited exact path may exist inside the artifact.
$artifactProhibited = [];
foreach ($policy['deny_path_exact'] as $exact) {
    $exact = ltrim(str_replace('\\', '/', (string) $exact), '/');
    if ($exact !== '' && file_exists($outDir . '/' . $exact)) {
        $artifactProhibited[] = $exact;
    }
}

$manifest = [
    'created_at' => gmdate('c'),
    'source_root' => $root,
    'source_commit' => $gitCommit,
    'source_branch' => $gitBranch,
    'source_tree_clean' => $sourceTreeClean,
    'untracked_publishable_files' => $untrackedPublishable,
    'artifact_root' => $outDir,
    'file_count' => count($selected),
    'checksum_sha256' => null,
    'files' => [],
    'prohibited_excluded' => $deniedHits,
    'prohibited_present_in_artifact' => $artifactProhibited,
];

$hashContext = hash_init('sha256');
foreach ($selected as $relative) {
    $absolute = $outDir . '/' . $relative;
    $fileHash = hash_file('sha256', $absolute);
    $manifest['files'][$relative] = $fileHash;
    hash_update($hashContext, $relative . ':' . $fileHash . "\n");
}
$manifest['checksum_sha256'] = hash_final($hashContext);

$manifestPath = $outDir . '/RELEASE_MANIFEST.json';
file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

$ok = $artifactProhibited === [] && $sourceTreeClean;
$payload = [
    'ok' => $ok,
    'artifact_root' => $outDir,
    'file_count' => count($selected),
    'checksum_sha256' => $manifest['checksum_sha256'],
    'manifest' => $manifestPath,
    'source_commit' => $gitCommit,
    'source_branch' => $gitBranch,
    'source_tree_clean' => $sourceTreeClean,
    'untracked_publishable_files' => $untrackedPublishable,
    'prohibited_present_in_artifact' => $artifactProhibited,
];

$asJson = array_key_exists('json', $options);
if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo ($ok ? 'OK' : 'FAIL')
        . " files={$payload['file_count']} checksum={$payload['checksum_sha256']}\n";
    if (!$ok) {
        echo 'prohibited=' . implode(',', $artifactProhibited) . PHP_EOL;
        if (!$sourceTreeClean) {
            echo 'source_tree_clean=false' . PHP_EOL;
        }
    }
}

exit($ok ? 0 : 1);
