<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

class InventoryDecimal
{
    public static function normalize($value, int $scale = 6): string
    {
        return RecipeDecimal::normalize($value, $scale);
    }

    public static function zero(int $scale = 6): string
    {
        return RecipeDecimal::zero($scale);
    }

    public static function isPositive($value): bool
    {
        return RecipeDecimal::isPositive($value);
    }

    public static function add($left, $right, int $scale = 6): string
    {
        return RecipeDecimal::add($left, $right, $scale);
    }

    public static function subtract($left, $right, int $scale = 6): string
    {
        return RecipeDecimal::subtract($left, $right, $scale);
    }

    public static function multiply($left, $right, int $scale = 6): string
    {
        return RecipeDecimal::multiply($left, $right, $scale);
    }

    public static function divide($left, $right, int $scale = 6): string
    {
        return RecipeDecimal::divide($left, $right, $scale);
    }

    public static function compare($left, $right, int $scale = 6): int
    {
        return RecipeDecimal::compare($left, $right, $scale);
    }
}
