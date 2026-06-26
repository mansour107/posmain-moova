<?php

$root = dirname(__DIR__, 2);
$manifest = require $root . '/tests/personas/manifest.php';

personaManifestAssert(is_array($manifest), 'manifest should return an array');
personaManifestAssert(isset($manifest['personas']) && is_array($manifest['personas']), 'manifest should define personas');

$expectedPersonas = ['shared', 'cashier', 'waiter', 'manager', 'owner', 'sync_ops'];
foreach ($expectedPersonas as $personaId) {
    personaManifestAssert(isset($manifest['personas'][$personaId]), 'missing persona: ' . $personaId);
}

foreach ($manifest['personas'] as $personaId => $persona) {
    personaManifestAssert(is_array($persona), 'persona must be array: ' . $personaId);

    foreach ($persona['non_gui'] ?? [] as $test) {
        $path = $root . '/' . ltrim((string) $test['path'], '/');
        personaManifestAssert(is_file($path), $personaId . ' non_gui missing: ' . $test['path']);
        personaManifestAssert(!empty($test['id']), $personaId . ' non_gui test missing id');
    }

    foreach ($persona['tools'] ?? [] as $tool) {
        $path = $root . '/' . ltrim((string) $tool['path'], '/');
        personaManifestAssert(is_file($path), $personaId . ' tool missing: ' . $tool['path']);
    }

    foreach ($persona['gui'] ?? [] as $test) {
        $path = $root . '/' . ltrim((string) $test['spec'], '/');
        personaManifestAssert(is_file($path), $personaId . ' gui spec missing: ' . $test['spec']);
    }
}

personaManifestAssert(is_file($root . '/tools/run_persona_tests.php'), 'runner missing');
personaManifestAssert(is_file($root . '/playwright.config.ts'), 'playwright config missing');

echo "persona-manifest-contract-ok\n";

function personaManifestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
