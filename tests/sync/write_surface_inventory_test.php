<?php

use PHPUnit\Framework\TestCase;

class WriteSurfaceInventoryTest extends TestCase
{
    public function testAuditClassifiesKnownSyncCriticalWritePaths()
    {
        $root = realpath(__DIR__ . '/../..');
        $this->assertNotFalse($root);

        $command = 'php ' . escapeshellarg($root . '/tools/audit_write_paths.php') . ' --json';
        exec($command, $lines, $code);

        $this->assertSame(0, $code, implode("\n", $lines));
        $payload = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('surfaces', $payload);

        $byPath = [];
        foreach ($payload['surfaces'] as $surface) {
            $this->assertNotEmpty($surface['categories'], $surface['path']);
            $byPath[$surface['path']] = $surface;
        }

        $this->assertPathHasCategory($byPath, 'do/doadd_invoice.php', 'pos_order');
        $this->assertPathHasCategory($byPath, 'do/doadd_invoice.php', 'payments/accounting');
        $this->assertPathHasCategory($byPath, 'classes/PosOrderService.php', 'pos_order');
        $this->assertPathHasCategory($byPath, 'classes/PosOrderService.php', 'table_state');
        $this->assertPathHasCategory($byPath, 'classes/Moova/MoovaNewOrderApplyService.php', 'moova_bridge');
        $this->assertPathHasCategory($byPath, 'classes/Moova/MoovaNewOrderApplyService.php', 'pos_order');
        $this->assertPathHasCategory($byPath, 'classes/Moova/MoovaChangeOrderApplyService.php', 'moova_bridge');
        $this->assertPathHasCategory($byPath, 'classes/Moova/MoovaChangeOrderApplyService.php', 'pos_order');
        $this->assertPathHasCategory($byPath, 'ajax/cofe_create_order.php', 'moova_bridge');
        $this->assertPathHasCategory($byPath, 'ajax/cofe_create_order.php', 'pos_order');
        $this->assertPathHasCategory($byPath, 'ajax/moova_confirm_order.php', 'moova_bridge');
        $this->assertPathHasOperation($byPath, 'ajax/moova_confirm_order.php', 'DELEGATE');
        $this->assertPathHasCategory($byPath, 'ajax/moova_change_order.php', 'moova_bridge');
        $this->assertPathHasOperation($byPath, 'ajax/moova_change_order.php', 'DELEGATE');
        $this->assertTrue(
            $this->pathHasCategory($byPath, 'close_shift.php', 'shift_session')
                || $this->pathHasCategory($byPath, 'do_close_shift_z.php', 'shift_session'),
            'Expected close_shift.php or do_close_shift_z.php to be classified as shift_session.'
        );
        $this->assertTrue(
            $this->pathHasCategory($byPath, 'item_categories.php', 'menu_catalog')
                || $this->pathHasCategory($byPath, 'do/doadd_group.php', 'menu_catalog')
                || $this->pathHasCategory($byPath, 'do/doedit_group.php', 'menu_catalog'),
            'Expected at least one item/menu category write path to be classified as menu_catalog.'
        );
    }

    public function testMaintainedInventoryMentionsCurrentHighRiskPaths()
    {
        $root = realpath(__DIR__ . '/../..');
        $doc = file_get_contents($root . '/docs/local_first_write_surface_inventory.md');

        $this->assertIsString($doc);
        foreach ([
            'do/doadd_invoice.php',
            'classes/PosOrderService.php',
            'classes/Moova/MoovaNewOrderApplyService.php',
            'classes/Moova/MoovaChangeOrderApplyService.php',
            'ajax/cofe_create_order.php',
            'ajax/moova_confirm_order.php',
            'ajax/moova_change_order.php',
            'close_shift.php',
            'do_close_shift_z.php',
            'do/doadd_group.php',
            'do/doedit_group.php',
        ] as $needle) {
            $this->assertStringContainsString($needle, $doc);
        }
    }

    private function assertPathHasCategory(array $byPath, $path, $category)
    {
        $this->assertTrue(
            $this->pathHasCategory($byPath, $path, $category),
            sprintf('Expected %s to have category %s.', $path, $category)
        );
    }

    private function pathHasCategory(array $byPath, $path, $category)
    {
        return isset($byPath[$path]) && in_array($category, $byPath[$path]['categories'], true);
    }

    private function assertPathHasOperation(array $byPath, $path, $operation)
    {
        $this->assertArrayHasKey($path, $byPath, sprintf('Expected %s to be in the audit output.', $path));
        $operations = array_column($byPath[$path]['writes'], 'operation');
        $this->assertContains($operation, $operations, sprintf('Expected %s to have operation %s.', $path, $operation));
    }
}

class write_surface_inventory_test extends WriteSurfaceInventoryTest
{
}
