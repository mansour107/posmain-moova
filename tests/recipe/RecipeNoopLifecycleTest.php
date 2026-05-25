<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Recipe/RecipeOrderLifecycleService.php';

class RecipeNoopLifecycleTest extends TestCase
{
    public function testDisabledLifecycleReturnsNoopAndWritesNothing(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => false,
                'mode' => 'off',
            ],
        ]);
        $resolver = new RecipeScopeResolver([
            'branch' => [
                'pos_tenant' => 0,
                'pos_branch' => 0,
                'uuid' => null,
            ],
        ]);
        $service = new RecipeOrderLifecycleService($flags, $resolver);

        $result = $service->onOrderPaid([
            'order_id' => 123,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('order_paid', $result['action']);
        $this->assertSame('off', $result['mode']);
        $this->assertFalse($result['recipe_enabled']);
        $this->assertTrue($result['noop']);
        $this->assertSame([], $result['writes']);
        $this->assertSame(0, $result['scope']['pos_tenant']);
        $this->assertSame(0, $result['scope']['pos_branch']);
        $this->assertSame(0, $result['scope']['store_id']);
    }

    public function testLifecycleShellKeepsEnabledReadOnlyModeNonWriting(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'read_only',
            ],
        ]);
        $service = new RecipeOrderLifecycleService($flags, new RecipeScopeResolver());

        $result = $service->onOrderLineAdded([
            'pos_tenant' => 2,
            'pos_branch' => 3,
            'store_id' => 5,
            'channel' => 'moova',
            'order_type' => 'delivery',
        ]);

        $this->assertTrue($result['recipe_enabled']);
        $this->assertSame('read_only', $result['mode']);
        $this->assertTrue($result['noop']);
        $this->assertSame([], $result['writes']);
        $this->assertSame(2, $result['scope']['pos_tenant']);
        $this->assertSame(3, $result['scope']['pos_branch']);
        $this->assertSame(5, $result['scope']['store_id']);
        $this->assertSame('moova', $result['scope']['channel']);
        $this->assertSame('delivery', $result['scope']['order_type']);
    }
}

