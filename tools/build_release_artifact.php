<?php

require_once __DIR__ . '/../classes/Release/ReleaseArtifactBuilder.php';

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(2);
}

$options = getopt('', ['ref::', 'output::', 'preflight', 'verbose', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php tools/build_release_artifact.php --preflight [--verbose] [--ref=<commit>]\n";
    echo "  php tools/build_release_artifact.php --output=<directory> [--ref=<commit>]\n";
    exit(0);
}

$ref = trim((string) ($options['ref'] ?? 'HEAD'));
$output = trim((string) ($options['output'] ?? ''));

try {
    $policy = ReleaseArtifactPolicy::fromRepository($root);
    $builder = new ReleaseArtifactBuilder($root, $policy);
    if (isset($options['preflight']) || $output === '') {
        $result = $builder->preflight($ref);
        $outputResult = $result;
        if (!isset($options['verbose'])) {
            unset($outputResult['included'], $outputResult['excluded']);
        }
        echo json_encode($outputResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($result['ok'] ? 0 : 3);
    }

    $result = $builder->build($ref, $output);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'release-artifact-error: ' . $exception->getMessage() . "\n");
    exit(2);
}
