<?php

/**
 * Shared signed movement types for drawer cash ledger math.
 * @return array<string, int>
 */
function posmain_drawer_movement_signs(): array
{
    return [
        'sale_cash' => 1,
        'refund_cash' => -1,
        'paid_in' => 1,
        'paid_out' => -1,
        'safe_drop' => -1,
        'opening' => 1,
        'closing_adjustment' => 1,
        'no_sale' => 0,
    ];
}
