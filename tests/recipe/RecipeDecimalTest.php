<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Recipe/RecipeDecimal.php';

class RecipeDecimalTest extends TestCase
{
    public function testNormalizeRoundsSubUnitDecimalsWithoutWholeNumberDrift(): void
    {
        $this->assertSame('0.333334', RecipeDecimal::normalize('0.3333335'));
        $this->assertSame('0.5556', RecipeDecimal::normalize('0.55555', 4));
        $this->assertSame('1.0000', RecipeDecimal::normalize('0.99995', 4));
        $this->assertSame('2.333334', RecipeDecimal::normalize('2.3333335'));
    }

    public function testCompareHandlesNegativeAndLargeDecimalsWithoutFloatMath(): void
    {
        $this->assertSame(-1, RecipeDecimal::compare('-0.000001', '0'));
        $this->assertSame(1, RecipeDecimal::compare('10.000000', '2.000000'));
        $this->assertSame(-1, RecipeDecimal::compare('-10.000000', '-2.000000'));
        $this->assertTrue(RecipeDecimal::isPositive('0.000001'));
        $this->assertFalse(RecipeDecimal::isPositive('-0.000001'));
    }
}
