<?php

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipePilotEvidenceService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'template',
    'validate',
    'list-markers',
    'json',
    'help',
    'file:',
    'output:',
    'force',
    'max-age-hours::',
    'pos-tenant::',
    'pos-branch::',
    'store-id::',
    'operator::',
    'note::',
]);

if (isset($options['help'])) {
    recipePilotEvidenceUsage();
    exit(0);
}

$flags = new RecipeFeatureFlags(posmain_app_config());
$service = new RecipePilotEvidenceService();

try {
    if (isset($options['list-markers'])) {
        $result = [
            'ok' => true,
            'mode' => $flags->mode(),
            'required' => $service->isRequired($flags),
            'required_mode' => $flags->mode(),
            'required_scope' => recipePilotEvidenceScopeFromOptions($options, $flags),
            'markers' => $service->requiredMarkers($flags),
            'details' => $service->requiredDetails($flags),
            'detail_token_requirements' => $service->requiredDetailTokenGroups($flags),
            'evidence_command_hints' => $service->evidenceCommandHints($flags),
            'checks' => $service->requiredChecks($flags),
            'runtime_proofs' => $service->requiredRuntimeProofs($flags),
        ];
    } elseif (isset($options['template'])) {
        $result = recipePilotEvidenceTemplate($service, $flags, $options);
    } elseif (isset($options['validate'])) {
        $file = trim((string) ($options['file'] ?? ''));
        $result = $service->validate(
            $flags,
            $file,
            isset($options['max-age-hours']) ? (int) $options['max-age-hours'] : 24,
            recipePilotEvidenceScopeFromOptions($options, $flags)
        );
    } else {
        throw new InvalidArgumentException('Choose --template, --validate, or --list-markers.');
    }
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'error' => get_class($exception),
        'message' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipePilotEvidencePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipePilotEvidenceUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_pilot_evidence.php (--template [--output=/absolute/path.md] [--force] | --validate --file=/absolute/path.md | --list-markers) [--json] [--max-age-hours=24] [--pos-tenant=0] [--pos-branch=0] [--store-id=0] [--operator=name] [--note=text]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Creates pending pilot evidence templates and validates completed pilot evidence for recipe rollout readiness.\n");
    fwrite(STDOUT, "Template generation is intentionally not enough to pass readiness; every generated marker starts as pending.\n");
    fwrite(STDOUT, "Validation also checks the evidence recipe mode and any provided POS tenant/branch/store scope.\n");
    fwrite(STDOUT, "Validation checks the Evidence completed at UTC timestamp instead of trusting file modification time alone.\n");
    fwrite(STDOUT, "Validation also requires non-placeholder evidence detail lines for the required checks.\n");
    fwrite(STDOUT, "Validation shows token groups for high-risk detail lines so operators know what proof each line must contain.\n");
    fwrite(STDOUT, "Templates and marker listings include evidence command hints, but those hints do not count as completed evidence.\n");
    fwrite(STDOUT, "Validation requires checked operator QA checklist lines for recipe rollout scenarios.\n");
    fwrite(STDOUT, "Validation requires isolated runtime proof command results with both the proof command path and success marker for the high-risk endpoint/service paths.\n");
    fwrite(STDOUT, "This tool does not connect to the database and does not write stock, accounting, reservation, sync, or recipe rows.\n");
}

function recipePilotEvidenceTemplate(RecipePilotEvidenceService $service, RecipeFeatureFlags $flags, array $options): array
{
    $content = $service->template($flags, [
        'pos_tenant' => $options['pos-tenant'] ?? '',
        'pos_branch' => $options['pos-branch'] ?? '',
        'store_id' => $options['store-id'] ?? '',
        'operator' => $options['operator'] ?? '',
        'note' => $options['note'] ?? '',
    ]);
    $output = trim((string) ($options['output'] ?? ''));
    if ($output === '') {
        return [
            'ok' => true,
            'mode' => $flags->mode(),
            'required_mode' => $flags->mode(),
            'required_scope' => recipePilotEvidenceScopeFromOptions($options, $flags),
            'written' => false,
            'content' => $content,
            'required_markers' => $service->requiredMarkers($flags),
            'required_details' => $service->requiredDetails($flags),
            'detail_token_requirements' => $service->requiredDetailTokenGroups($flags),
            'evidence_command_hints' => $service->evidenceCommandHints($flags),
            'required_checks' => $service->requiredChecks($flags),
            'required_runtime_proofs' => $service->requiredRuntimeProofs($flags),
        ];
    }
    if (is_file($output) && empty($options['force'])) {
        throw new RuntimeException('Output file already exists. Pass --force to overwrite it.');
    }

    $directory = dirname($output);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Output directory is not writable: ' . $directory);
    }
    if (file_put_contents($output, $content . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write pilot evidence template.');
    }

    return [
        'ok' => true,
        'mode' => $flags->mode(),
        'required_mode' => $flags->mode(),
        'required_scope' => recipePilotEvidenceScopeFromOptions($options, $flags),
        'written' => true,
        'path' => $output,
        'required_markers' => $service->requiredMarkers($flags),
        'required_details' => $service->requiredDetails($flags),
        'detail_token_requirements' => $service->requiredDetailTokenGroups($flags),
        'evidence_command_hints' => $service->evidenceCommandHints($flags),
        'required_checks' => $service->requiredChecks($flags),
        'required_runtime_proofs' => $service->requiredRuntimeProofs($flags),
    ];
}

function recipePilotEvidenceScopeFromOptions(array $options, ?RecipeFeatureFlags $flags = null): array
{
    $scope = [];
    foreach ([
        'pos-tenant' => 'pos_tenant',
        'pos-branch' => 'pos_branch',
        'store-id' => 'store_id',
    ] as $optionKey => $scopeKey) {
        if (!array_key_exists($optionKey, $options) || $options[$optionKey] === '' || $options[$optionKey] === null || (int) $options[$optionKey] < 0) {
            continue;
        }
        $scope[$scopeKey] = (int) $options[$optionKey];
    }

    if ($flags !== null) {
        $appBranch = $flags->appConfig()['branch'] ?? [];
        if (!array_key_exists('pos_tenant', $scope)
            && is_array($appBranch)
            && array_key_exists('pos_tenant', $appBranch)
            && $appBranch['pos_tenant'] !== null
            && $appBranch['pos_tenant'] !== ''
            && (int) $appBranch['pos_tenant'] >= 0
        ) {
            $scope['pos_tenant'] = (int) $appBranch['pos_tenant'];
        }

        if (!array_key_exists('pos_branch', $scope)) {
            $pilotBranch = trim((string) (($flags->config()['pilot'] ?? [])['pos_branch'] ?? ''));
            if ($pilotBranch !== '' && (int) $pilotBranch >= 0) {
                $scope['pos_branch'] = (int) $pilotBranch;
            }
        }
        if (!array_key_exists('pos_branch', $scope)
            && is_array($appBranch)
            && array_key_exists('pos_branch', $appBranch)
            && $appBranch['pos_branch'] !== null
            && $appBranch['pos_branch'] !== ''
            && (int) $appBranch['pos_branch'] >= 0
        ) {
            $scope['pos_branch'] = (int) $appBranch['pos_branch'];
        }
    }

    return $scope;
}

function recipePilotEvidencePrintHuman(array $result): void
{
    if (empty($result['ok'])) {
        fwrite(STDERR, 'Recipe pilot evidence: failed' . PHP_EOL);
        fwrite(STDERR, '- ' . (string) ($result['message'] ?? $result['error'] ?? 'unknown error') . PHP_EOL);
        if (!empty($result['missing_markers']) && is_array($result['missing_markers'])) {
            fwrite(STDERR, "- missing markers:\n");
            foreach ($result['missing_markers'] as $marker) {
                fwrite(STDERR, '  - ' . (string) $marker . PHP_EOL);
            }
        }
        if (!empty($result['missing_details']) && is_array($result['missing_details'])) {
            fwrite(STDERR, "- missing details:\n");
            foreach ($result['missing_details'] as $detail) {
                fwrite(STDERR, '  - ' . (string) $detail . PHP_EOL);
            }
        }
        if (!empty($result['detail_token_requirements']) && is_array($result['detail_token_requirements'])) {
            fwrite(STDERR, "- accepted detail proof tokens:\n");
            foreach ($result['detail_token_requirements'] as $detail => $groups) {
                fwrite(STDERR, '  - ' . (string) $detail . ': ');
                $rendered = [];
                foreach ((array) $groups as $group) {
                    $rendered[] = implode(' + ', array_map('strval', (array) $group));
                }
                fwrite(STDERR, implode(' OR ', $rendered) . PHP_EOL);
            }
        }
        if (!empty($result['missing_checks']) && is_array($result['missing_checks'])) {
            fwrite(STDERR, "- missing checks:\n");
            foreach ($result['missing_checks'] as $check) {
                fwrite(STDERR, '  - ' . (string) $check . PHP_EOL);
            }
        }
        if (!empty($result['missing_runtime_proofs']) && is_array($result['missing_runtime_proofs'])) {
            fwrite(STDERR, "- missing runtime proofs:\n");
            foreach ($result['missing_runtime_proofs'] as $proof) {
                fwrite(STDERR, '  - ' . (string) $proof . PHP_EOL);
            }
        }
        if (!empty($result['scope_mismatches']) && is_array($result['scope_mismatches'])) {
            fwrite(STDERR, "- scope mismatches:\n");
            foreach ($result['scope_mismatches'] as $scopeKey => $mismatch) {
                fwrite(STDERR, '  - ' . (string) $scopeKey . ': expected ' . (string) ($mismatch['expected'] ?? '') . ', evidence ' . (string) ($mismatch['evidence'] ?? '') . PHP_EOL);
            }
        }
        return;
    }

    if (array_key_exists('content', $result)) {
        fwrite(STDOUT, (string) $result['content'] . PHP_EOL);
        return;
    }

    fwrite(STDOUT, 'Recipe pilot evidence: OK' . PHP_EOL);
    if (isset($result['path'])) {
        fwrite(STDOUT, '- path: ' . (string) $result['path'] . PHP_EOL);
    }
    if (array_key_exists('required', $result)) {
        fwrite(STDOUT, '- required: ' . (!empty($result['required']) ? 'yes' : 'no') . PHP_EOL);
    }
    if (isset($result['required_mode'])) {
        fwrite(STDOUT, '- required mode: ' . (string) $result['required_mode'] . PHP_EOL);
    }
    if (!empty($result['required_scope']) && is_array($result['required_scope'])) {
        fwrite(STDOUT, "- required scope:\n");
        foreach ($result['required_scope'] as $scopeKey => $scopeValue) {
            fwrite(STDOUT, '  - ' . (string) $scopeKey . ': ' . (string) $scopeValue . PHP_EOL);
        }
    }
    if (!empty($result['markers']) && is_array($result['markers'])) {
        fwrite(STDOUT, "- markers:\n");
        foreach ($result['markers'] as $marker) {
            fwrite(STDOUT, '  - ' . (string) $marker . PHP_EOL);
        }
    }
    if (!empty($result['details']) && is_array($result['details'])) {
        fwrite(STDOUT, "- details:\n");
        foreach ($result['details'] as $detail) {
            fwrite(STDOUT, '  - ' . (string) $detail . PHP_EOL);
        }
    }
    if (!empty($result['detail_token_requirements']) && is_array($result['detail_token_requirements'])) {
        fwrite(STDOUT, "- detail proof token groups:\n");
        foreach ($result['detail_token_requirements'] as $detail => $groups) {
            fwrite(STDOUT, '  - ' . (string) $detail . ': ');
            $rendered = [];
            foreach ((array) $groups as $group) {
                $rendered[] = implode(' + ', array_map('strval', (array) $group));
            }
            fwrite(STDOUT, implode(' OR ', $rendered) . PHP_EOL);
        }
    }
    if (!empty($result['evidence_command_hints']) && is_array($result['evidence_command_hints'])) {
        fwrite(STDOUT, "- evidence command hints:\n");
        foreach ($result['evidence_command_hints'] as $label => $hint) {
            fwrite(STDOUT, '  - ' . (string) $label . ': ' . (string) $hint . PHP_EOL);
        }
    }
    if (!empty($result['checks']) && is_array($result['checks'])) {
        fwrite(STDOUT, "- checks:\n");
        foreach ($result['checks'] as $check) {
            fwrite(STDOUT, '  - ' . (string) $check . PHP_EOL);
        }
    }
}
