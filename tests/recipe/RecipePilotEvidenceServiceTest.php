<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Recipe/RecipePilotEvidenceService.php';

class RecipePilotEvidenceServiceTest extends TestCase
{
    public function testReadOnlyModeDoesNotRequireEvidenceFile(): void
    {
        $result = $this->service()->validate($this->flags([
            'enabled' => true,
            'mode' => 'read_only',
        ]), '');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['required']);
    }

    public function testReserveOnlyModeRequiresReservationEvidenceButNotConsumptionEvidence(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'reserve_only',
            'reservations' => true,
            'accounting' => true,
            'availability' => true,
        ]);

        $missingFile = $this->service()->validate($flags, '');
        $path = $this->tempEvidence($this->completedEvidence($flags));
        try {
            $completed = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($missingFile['ok']);
        $this->assertTrue($missingFile['required']);
        $this->assertSame('recipe_pilot_evidence_file_not_provided', $missingFile['blocker']);
        $this->assertContains('Recipe reservation lifecycle smoke passed: pass', $this->service()->requiredMarkers($flags));
        $this->assertNotContains('POS/table recipe smoke passed: pass', $this->service()->requiredMarkers($flags));
        $this->assertContains('Recipe reservation evidence', $this->service()->requiredDetails($flags));
        $this->assertNotContains('Recipe COGS accountant review: pass', $this->service()->requiredMarkers($flags));
        $this->assertNotContains('Recipe availability and menu sync smoke passed: pass', $this->service()->requiredMarkers($flags));
        $this->assertNotContains('Recipe COGS accountant evidence', $this->service()->requiredDetails($flags));
        $this->assertNotContains('Recipe availability and menu sync evidence', $this->service()->requiredDetails($flags));
        $this->assertNotContains('Production batch evidence', $this->service()->requiredDetails($flags));
        $this->assertNotContains('Waste and stock adjustment evidence', $this->service()->requiredDetails($flags));
        $this->assertNotContains('Paid refund/void evidence', $this->service()->requiredDetails($flags));
        $this->assertContains('Recipe reservation lifecycle smoke', $this->service()->requiredChecks($flags));
        $this->assertNotContains('Production batch UI smoke', $this->service()->requiredChecks($flags));
        $this->assertArrayHasKey('Recipe reservation lifecycle runtime proof', $this->service()->requiredRuntimeProofs($flags));
        $this->assertArrayNotHasKey('Production endpoint runtime proof', $this->service()->requiredRuntimeProofs($flags));
        $this->assertSame([
            ['tests/sync/recipe_reservation_lifecycle_runtime_test.php', 'recipe-reservation-lifecycle-runtime-ok'],
            ['stock_reservations', 'qty_reserved'],
            ['reservation lifecycle', 'qty_reserved'],
        ], $this->service()->requiredDetailTokenGroups($flags)['Recipe reservation evidence']);
        $this->assertTrue($completed['ok']);
        $this->assertTrue($completed['required']);
    }

    public function testOptionalEvidenceFollowsEffectiveModeNotRawFlags(): void
    {
        $consumeFlags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
            'accounting' => true,
            'availability' => true,
            'moova_sync' => true,
            'allow_negative_stock_with_approval' => true,
        ]);
        $accountingFlags = $this->flags([
            'enabled' => true,
            'mode' => 'accounting_pilot',
            'consumption' => true,
            'accounting' => true,
            'availability' => true,
        ]);

        $this->assertNotContains('Recipe COGS accountant review: pass', $this->service()->requiredMarkers($consumeFlags));
        $this->assertNotContains('Recipe availability and menu sync smoke passed: pass', $this->service()->requiredMarkers($consumeFlags));
        $this->assertNotContains('Recipe COGS accountant evidence', $this->service()->requiredDetails($consumeFlags));
        $this->assertNotContains('Recipe availability and menu sync evidence', $this->service()->requiredDetails($consumeFlags));
        $this->assertNotContains('Moova/Cofe recipe replay evidence', $this->service()->requiredDetails($consumeFlags));
        $this->assertNotContains('Manager recipe stock override evidence', $this->service()->requiredDetails($consumeFlags));
        $this->assertArrayNotHasKey('POS grid availability endpoint runtime proof', $this->service()->requiredRuntimeProofs($consumeFlags));
        $this->assertStringNotContainsString('--include-availability', $this->service()->evidenceCommandHints($consumeFlags)['Isolated runtime proofs']);

        $this->assertContains('Recipe COGS accountant review: pass', $this->service()->requiredMarkers($accountingFlags));
        $this->assertNotContains('Recipe availability and menu sync smoke passed: pass', $this->service()->requiredMarkers($accountingFlags));
        $this->assertContains('Recipe COGS accountant evidence', $this->service()->requiredDetails($accountingFlags));
        $this->assertNotContains('Recipe availability and menu sync evidence', $this->service()->requiredDetails($accountingFlags));
    }

    public function testTemplateUsesPendingMarkersAndDoesNotAccidentallyPass(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'accounting' => true,
            'availability' => true,
        ]);
        $template = $this->service()->template($flags, [
            'pos_tenant' => 0,
            'pos_branch' => 2,
            'store_id' => 5,
            'operator' => 'PHPUnit',
        ]);
        $path = $this->tempEvidence($template);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('Recipe Pilot Evidence: pending', $template);
        $this->assertStringContainsString('Evidence completed at UTC: pending', $template);
        $this->assertStringContainsString('Recipe schema evidence: pending', $template);
        $this->assertStringContainsString('Recipe runtime preflight reviewed: pending', $template);
        $this->assertStringContainsString('Recipe runtime preflight evidence: pending', $template);
        $this->assertStringContainsString('Pilot fixture verification evidence: pending', $template);
        $this->assertStringContainsString('## Evidence Command Hints', $template);
        $this->assertStringContainsString('These lines are hints only', $template);
        $this->assertStringContainsString('Recipe runtime preflight evidence: php tools/recipe_runtime_preflight.php --json', $template);
        $this->assertStringContainsString('Modifier substitution recipe evidence: php tools/recipe_management_surface_smoke.php', $template);
        $this->assertStringContainsString('Production batch evidence: php tools/recipe_stock_operations_surface_smoke.php', $template);
        $this->assertStringContainsString('Waste and stock adjustment evidence: php tools/recipe_stock_operations_surface_smoke.php', $template);
        $this->assertStringContainsString('inventory_adjustments.php operator review', $template);
        $this->assertStringContainsString('Isolated runtime proofs: php tools/recipe_runtime_proof_suite.php --include-availability --json', $template);
        $this->assertStringContainsString('- [ ] Recipe management UI smoke', $template);
        $this->assertStringContainsString('Modifier substitution recipe evidence: pending', $template);
        $this->assertStringContainsString('Production batch evidence: pending', $template);
        $this->assertStringContainsString('Waste and stock adjustment evidence: pending', $template);
        $this->assertStringContainsString('Paid refund/void evidence: pending', $template);
        $this->assertStringContainsString('- [ ] Modifier substitution recipe UI smoke', $template);
        $this->assertStringContainsString('Modifier substitution management endpoint runtime proof: pending', $template);
        $this->assertStringContainsString('tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php', $template);
        $this->assertStringNotContainsString('Recipe Pilot Evidence: pass', $template);
        $this->assertStringNotContainsString('Recipe COGS accountant review: pass', $template);
        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_markers_missing', $result['blocker']);
    }

    public function testValidateCompletedAccountingAndAvailabilityEvidence(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'accounting' => true,
            'availability' => true,
        ]);
        $path = $this->tempEvidence($this->completedEvidence($flags));

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['required']);
        $this->assertContains('Recipe COGS accountant review: pass', $result['required_markers']);
        $this->assertContains('Recipe availability and menu sync smoke passed: pass', $result['required_markers']);
        $this->assertContains('Recipe runtime preflight evidence', $result['required_details']);
        $this->assertContains('Pilot fixture verification evidence', $result['required_details']);
        $this->assertContains('Modifier substitution recipe evidence', $result['required_details']);
        $this->assertContains('Production batch evidence', $result['required_details']);
        $this->assertContains('Waste and stock adjustment evidence', $result['required_details']);
        $this->assertContains('Paid refund/void evidence', $result['required_details']);
        $this->assertContains('Recipe COGS accountant evidence', $result['required_details']);
        $this->assertContains('Recipe availability and menu sync evidence', $result['required_details']);
        $this->assertContains('Modifier substitution recipe UI smoke', $result['required_checks']);
        $this->assertContains('Recipe accounting journal review', $result['required_checks']);
        $this->assertContains('Recipe availability POS and menu sync smoke', $result['required_checks']);
        $this->assertArrayHasKey('Modifier substitution management endpoint runtime proof', $result['required_runtime_proofs']);
        $this->assertArrayHasKey('POS grid availability endpoint runtime proof', $result['required_runtime_proofs']);
        $this->assertSame([
            ['tools/recipe_pilot_fixture.php --verify --json', 'fixture_ready_for_operator_qa'],
        ], $result['detail_token_requirements']['Pilot fixture verification evidence']);
        $this->assertSame([
            ['POS order', 'table order'],
            ['cashier-browser', 'table order'],
        ], $result['detail_token_requirements']['POS/table smoke evidence']);
    }

    public function testActiveEvidenceMustMatchCurrentRecipeMode(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $path = $this->tempEvidence(str_replace(
            'Recipe mode: consume_pilot',
            'Recipe mode: availability_pilot',
            $this->completedEvidence($flags)
        ));

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_mode_mismatch', $result['blocker']);
        $this->assertSame('consume_pilot', $result['expected_mode']);
        $this->assertSame('availability_pilot', $result['evidence_mode']);
    }

    public function testActiveEvidenceMustMatchExpectedPilotScope(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $path = $this->tempEvidence($this->completedEvidence($flags, [
            'pos_tenant' => 0,
            'pos_branch' => 2,
            'store_id' => 5,
        ]));

        try {
            $result = $this->service()->validate($flags, $path, 24, [
                'pos_tenant' => 0,
                'pos_branch' => 3,
                'store_id' => 5,
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_scope_mismatch', $result['blocker']);
        $this->assertSame('3', $result['scope_mismatches']['pos_branch']['expected']);
        $this->assertSame('2', $result['scope_mismatches']['pos_branch']['evidence']);
    }

    public function testCompletedMarkersWithoutEvidenceDetailsAreRejected(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $path = $this->tempEvidence("Recipe mode: consume_pilot\n" . implode("\n", $this->service()->requiredMarkers($flags)));

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_details_missing', $result['blocker']);
        $this->assertContains('POS/table smoke evidence', $result['missing_details']);
    }

    public function testActiveEvidenceRequiresPilotFixtureVerificationDetail(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ]);
        $content = str_replace(
            "Pilot fixture verification evidence: tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true\n",
            '',
            $this->completedEvidence($flags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_details_missing', $result['blocker']);
        $this->assertContains('Pilot fixture verification evidence', $result['missing_details']);
    }

    public function testPilotFixtureVerificationEvidenceRequiresVerifyCommandProof(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ]);
        $content = str_replace(
            'Pilot fixture verification evidence: tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true',
            'Pilot fixture verification evidence: reviewed by PHPUnit at 2026-05-24T00:00:00Z',
            $this->completedEvidence($flags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_details_missing', $result['blocker']);
        $this->assertContains('Pilot fixture verification evidence', $result['missing_details']);
    }

    public function testHighRiskEvidenceDetailsRequireRecognizableProofTokens(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ]);
        $content = str_replace(
            $this->detailEvidenceLine('Production batch evidence'),
            'Production batch evidence: reviewed by PHPUnit at 2026-05-24T00:00:00Z',
            $this->completedEvidence($flags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_details_missing', $result['blocker']);
        $this->assertContains('Production batch evidence', $result['missing_details']);
        $this->assertArrayHasKey('Production batch evidence', $result['detail_token_requirements']);
    }

    public function testCombinedEvidenceDetailsRequireAllRequiredProofParts(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ]);
        $content = str_replace(
            $this->detailEvidenceLine('POS/table smoke evidence'),
            'POS/table smoke evidence: POS order 1001 completed once',
            $this->completedEvidence($flags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_details_missing', $result['blocker']);
        $this->assertContains('POS/table smoke evidence', $result['missing_details']);
        $this->assertSame([
            ['POS order', 'table order'],
            ['cashier-browser', 'table order'],
        ], $result['detail_token_requirements']['POS/table smoke evidence']);
    }

    public function testRequiredDetailTokenGroupsExposeOnlyRequiredDetails(): void
    {
        $baseFlags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ]);
        $fullFlags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'consumption' => true,
            'accounting' => true,
            'availability' => true,
            'moova_sync' => true,
            'allow_negative_stock_with_approval' => true,
        ], [
            'role' => 'fake_cloud',
        ]);

        $base = $this->service()->requiredDetailTokenGroups($baseFlags);
        $full = $this->service()->requiredDetailTokenGroups($fullFlags);

        $this->assertArrayHasKey('Pilot fixture verification evidence', $base);
        $this->assertArrayNotHasKey('Moova/Cofe recipe replay evidence', $base);
        $this->assertArrayNotHasKey('Hosted/cloud runtime schema evidence', $base);
        $this->assertArrayHasKey('Recipe COGS accountant evidence', $full);
        $this->assertArrayHasKey('Moova/Cofe recipe replay evidence', $full);
        $this->assertArrayHasKey('Manager recipe stock override evidence', $full);
        $this->assertArrayHasKey('Hosted/cloud runtime schema evidence', $full);
    }

    public function testEvidenceCommandHintsFollowActiveOptionalFlags(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'moova_sync' => true,
            'allow_negative_stock_with_approval' => true,
        ], [
            'role' => 'fake_cloud',
        ]);

        $hints = $this->service()->evidenceCommandHints($flags);

        $this->assertStringContainsString('tools/recipe_management_surface_smoke.php', $hints['Modifier substitution recipe evidence']);
        $this->assertStringContainsString('tools/recipe_stock_operations_surface_smoke.php', $hints['Production batch evidence']);
        $this->assertStringContainsString('tools/recipe_stock_operations_surface_smoke.php', $hints['Waste and stock adjustment evidence']);
        $this->assertStringContainsString('inventory_adjustments.php', $hints['Waste and stock adjustment evidence']);
        $this->assertArrayHasKey('Recipe availability and menu sync evidence', $hints);
        $this->assertArrayHasKey('Manager recipe stock override evidence', $hints);
        $this->assertArrayHasKey('Moova/Cofe recipe replay evidence', $hints);
        $this->assertArrayHasKey('Hosted/cloud runtime schema evidence', $hints);
        $this->assertStringContainsString('--include-availability', $hints['Isolated runtime proofs']);
        $this->assertStringContainsString('--include-manager-override', $hints['Isolated runtime proofs']);
        $this->assertStringContainsString('--include-moova-sync', $hints['Isolated runtime proofs']);
    }

    public function testCompletedMarkersAndDetailsWithoutCheckedQaScenariosAreRejected(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $lines = ['Recipe mode: consume_pilot'];
        $lines = array_merge($lines, $this->service()->requiredMarkers($flags));
        foreach ($this->service()->requiredDetails($flags) as $detail) {
            $lines[] = $this->detailEvidenceLine($detail);
        }
        $path = $this->tempEvidence(implode("\n", $lines));

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_checks_missing', $result['blocker']);
        $this->assertContains('Recipe management UI smoke', $result['missing_checks']);
    }

    public function testMoovaSyncRequiresExternalReplayChecklist(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'moova_sync' => true,
        ]);

        $this->assertContains('Moova/Cofe recipe replay smoke', $this->service()->requiredChecks($flags));
        $this->assertContains('Moova/Cofe recipe replay evidence', $this->service()->requiredDetails($flags));
        $this->assertArrayHasKey('Moova menu sync payload endpoint runtime proof', $this->service()->requiredRuntimeProofs($flags));
        $this->assertArrayHasKey('Moova/Cofe replay runtime proof', $this->service()->requiredRuntimeProofs($flags));
        $this->assertArrayHasKey('Legacy Cofe endpoint runtime proof', $this->service()->requiredRuntimeProofs($flags));
    }

    public function testMoovaSyncEvidenceUsesEffectiveRuntimeFlag(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => false,
            'moova_sync' => true,
        ]);

        $this->assertNotContains('Moova/Cofe recipe replay smoke', $this->service()->requiredChecks($flags));
        $this->assertNotContains('Moova/Cofe recipe replay evidence', $this->service()->requiredDetails($flags));
        $this->assertArrayNotHasKey('Moova menu sync payload endpoint runtime proof', $this->service()->requiredRuntimeProofs($flags));
        $this->assertArrayNotHasKey('Moova/Cofe replay runtime proof', $this->service()->requiredRuntimeProofs($flags));
    }

    public function testActiveEvidenceRequiresIsolatedRuntimeProofCommandResults(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $content = str_replace(
            "Modifier substitution management endpoint runtime proof: php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php -> recipe-modifier-substitution-management-endpoint-runtime-ok\n",
            '',
            $this->completedEvidence($flags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_runtime_proofs_missing', $result['blocker']);
        $this->assertContains('Modifier substitution management endpoint runtime proof', $result['missing_runtime_proofs']);
        $this->assertArrayHasKey('Modifier substitution management endpoint runtime proof', $result['required_runtime_proofs']);
    }

    public function testActiveEvidenceRuntimeProofRequiresCommandAndSuccessMarker(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $content = str_replace(
            'Modifier substitution management endpoint runtime proof: php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php -> recipe-modifier-substitution-management-endpoint-runtime-ok',
            'Modifier substitution management endpoint runtime proof: pending (run: php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php)',
            $this->completedEvidence($flags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_runtime_proofs_missing', $result['blocker']);
        $this->assertContains('Modifier substitution management endpoint runtime proof', $result['missing_runtime_proofs']);
    }

    public function testManagerOverrideEvidenceRequiredWhenNegativeStockApprovalIsEnabled(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => true,
            'allow_negative_stock_with_approval' => true,
        ]);

        $this->assertContains('Manager recipe stock override evidence', $this->service()->requiredDetails($flags));
        $this->assertContains('Manager recipe stock override smoke', $this->service()->requiredChecks($flags));
        $this->assertArrayHasKey('Manager recipe stock override endpoint runtime proof', $this->service()->requiredRuntimeProofs($flags));

        $content = str_replace(
            $this->detailEvidenceLine('Manager recipe stock override evidence') . "\n",
            '',
            $this->completedEvidence($flags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($flags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_details_missing', $result['blocker']);
        $this->assertContains('Manager recipe stock override evidence', $result['missing_details']);
    }

    public function testManagerOverrideEvidenceRequiresEffectiveRecipeAvailability(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'availability_pilot',
            'availability' => false,
            'allow_negative_stock_with_approval' => true,
        ]);

        $this->assertNotContains('Manager recipe stock override evidence', $this->service()->requiredDetails($flags));
        $this->assertNotContains('Manager recipe stock override smoke', $this->service()->requiredChecks($flags));
        $this->assertArrayNotHasKey('Manager recipe stock override endpoint runtime proof', $this->service()->requiredRuntimeProofs($flags));
    }

    public function testHostedOrRouterRuntimeRequiresHostedSchemaEvidenceDetail(): void
    {
        $cloudFlags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ], [
            'role' => 'cloud',
        ]);
        $routerFlags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ], [
            'router' => ['enabled' => true],
        ]);
        $fakeCloudFlags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
        ], [
            'role' => 'fake_cloud',
        ]);

        $this->assertContains('Hosted/cloud runtime schema evidence', $this->service()->requiredDetails($cloudFlags));
        $this->assertContains('Hosted/cloud runtime schema evidence', $this->service()->requiredDetails($routerFlags));
        $this->assertContains('Hosted/cloud runtime schema evidence', $this->service()->requiredDetails($fakeCloudFlags));

        $content = str_replace(
            $this->detailEvidenceLine('Hosted/cloud runtime schema evidence') . "\n",
            '',
            $this->completedEvidence($cloudFlags)
        );
        $path = $this->tempEvidence($content);

        try {
            $result = $this->service()->validate($cloudFlags, $path);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_details_missing', $result['blocker']);
        $this->assertContains('Hosted/cloud runtime schema evidence', $result['missing_details']);
    }

    public function testStaleEvidenceBlocksActiveMode(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $path = $this->tempEvidence($this->completedEvidence($flags));
        touch($path, time() - 7200);

        try {
            $result = $this->service()->validate($flags, $path, 1);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_file_too_old', $result['blocker']);
    }

    public function testTouchedOldEvidenceTimestampStillBlocksActiveMode(): void
    {
        $flags = $this->flags([
            'enabled' => true,
            'mode' => 'consume_pilot',
        ]);
        $staleCompletedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 7200);
        $path = $this->tempEvidence($this->completedEvidence($flags, [], $staleCompletedAt));

        try {
            touch($path, time());
            $result = $this->service()->validate($flags, $path, 1);
        } finally {
            @unlink($path);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('recipe_pilot_evidence_completed_at_too_old', $result['blocker']);
        $this->assertSame($staleCompletedAt, $result['completed_at_utc']);
    }

    private function service(): RecipePilotEvidenceService
    {
        return new RecipePilotEvidenceService();
    }

    private function flags(array $recipeOverrides, array $appOverrides = []): RecipeFeatureFlags
    {
        return new RecipeFeatureFlags([
            'recipe' => array_replace_recursive([
                'enabled' => false,
                'mode' => 'off',
                'accounting' => false,
                'availability' => false,
            ], $recipeOverrides),
        ] + $appOverrides);
    }

    private function tempEvidence(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'recipe-pilot-evidence-');
        $this->assertIsString($path);
        file_put_contents($path, $content);

        return $path;
    }

    private function completedEvidence(RecipeFeatureFlags $flags, array $scope = [], ?string $completedAtUtc = null): string
    {
        $lines = [
            'Evidence completed at UTC: ' . ($completedAtUtc ?: gmdate('Y-m-d\TH:i:s\Z')),
            'Recipe mode: ' . $flags->mode(),
        ];
        if ($scope) {
            $lines[] = 'POS tenant: ' . (string) ($scope['pos_tenant'] ?? '');
            $lines[] = 'POS branch: ' . (string) ($scope['pos_branch'] ?? '');
            $lines[] = 'Store: ' . (string) ($scope['store_id'] ?? '');
        }
        $lines = array_merge($lines, $this->service()->requiredMarkers($flags));
        foreach ($this->service()->requiredDetails($flags) as $detail) {
            $lines[] = $this->detailEvidenceLine($detail);
        }
        foreach ($this->service()->requiredChecks($flags) as $check) {
            $lines[] = '- [x] ' . $check;
        }
        foreach ($this->service()->requiredRuntimeProofs($flags) as $label => $tokens) {
            $lines[] = $label . ': php ' . $tokens[0] . ' -> ' . $tokens[1];
        }

        return implode("\n", $lines);
    }

    private function detailEvidenceLine(string $detail): string
    {
        $values = [
            'Recipe schema evidence' => 'tools/run_migrations.php --dry-run -> 0 pending sync schema change(s)',
            'Recipe runtime preflight evidence' => 'tools/recipe_runtime_preflight.php --json ready_for_recipe_operator_qa=true',
            'Pilot fixture verification evidence' => 'tools/recipe_pilot_fixture.php --verify --json fixture_ready_for_operator_qa=true',
            'Recipe operational dashboard evidence' => 'recipe_operational_dashboard.php reviewed with issue_total=0 and zero blockers',
            'Recipe stock reconciliation evidence' => 'recipe_stock_reconciliation.php reconciliation CSV reviewed for pilot scope',
            'POS/table smoke evidence' => 'POS order 1001 and table order 1002 completed once',
            'Migrated runtime write smoke evidence' => 'tools/recipe_migrated_write_smoke.php --json --apply stock_preflight ok=true idempotency_replayed=true recipe_consumption movement cost positive',
            'Recipe reservation evidence' => 'tests/sync/recipe_reservation_lifecycle_runtime_test.php -> recipe-reservation-lifecycle-runtime-ok with stock_reservations qty_reserved reviewed',
            'Recipe report export and role QA evidence' => 'tools/recipe_report_export_smoke.php -> recipe-report-export-smoke-contract-ok with CSV export reviewed',
            'Modifier substitution recipe evidence' => 'recipe_manage.php modifier substitution oat milk recipe v4 activated and previewed',
            'Production batch evidence' => 'recipe_production.php production batch 501 previewed and committed by operator QA',
            'Waste and stock adjustment evidence' => 'inventory_adjustments.php waste movement 601 and stock adjustment 602 reviewed',
            'Paid refund/void evidence' => 'ajax/refund_order.php paid order 1003 refund and void dialogs exercised with policy selection',
            'Recipe rollback evidence' => 'POSMAIN_RECIPE_MODE=off rollback path documented',
            'Recipe COGS accountant evidence' => 'accountant reviewed COGS journal sample 2001',
            'Recipe availability and menu sync evidence' => 'recipe_pos_grid_availability_endpoint_runtime_test.php menu availability revision 3001 reached Moova smoke',
            'Moova/Cofe recipe replay evidence' => 'recipe_moova_replay_runtime_test.php Moova replay event mv-3001 and Cofe replay consumed once',
            'Manager recipe stock override evidence' => 'ajax/manager_approval.php Manager recipe stock override approval 7001 audited',
            'Hosted/cloud runtime schema evidence' => 'tools/recipe_hosted_schema_preflight.php Hosted/cloud runtime schema evidence checked 1 target(s), 1 ready',
        ];

        return $detail . ': ' . ($values[$detail] ?? 'reviewed by PHPUnit at 2026-05-24T00:00:00Z');
    }
}
