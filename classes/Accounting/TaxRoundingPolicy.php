<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

final class TaxRoundingPolicy
{
    public const DEFAULT_SCALE = 6;

    public static function isEnabled(array $config = []): bool
    {
        if (function_exists('posmain_app_config')) {
            $app = posmain_app_config();
            $config = array_merge($app['tax'] ?? [], $config);
        }

        return !empty($config['enabled']);
    }

    public static function lineTaxAmount($taxableBase, $ratePercent, int $scale = self::DEFAULT_SCALE): string
    {
        $base = RecipeDecimal::normalize($taxableBase, $scale);
        $rate = RecipeDecimal::normalize($ratePercent, $scale);
        $factor = RecipeDecimal::divide($rate, '100', $scale + 2);

        return RecipeDecimal::multiply($base, $factor, $scale);
    }

    public static function netFromGrossInclusive($gross, $ratePercent, int $scale = self::DEFAULT_SCALE): string
    {
        $grossDecimal = RecipeDecimal::normalize($gross, $scale);
        $rate = RecipeDecimal::normalize($ratePercent, $scale);
        $divisor = RecipeDecimal::add('1', RecipeDecimal::divide($rate, '100', $scale + 2), $scale + 2);

        return RecipeDecimal::divide($grossDecimal, $divisor, $scale);
    }
}
