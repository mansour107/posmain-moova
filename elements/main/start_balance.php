<?php
$posmainStartBalanceConfig = function_exists('posmain_app_config') ? posmain_app_config() : [];
$posmainStartBalanceLiveInventory = strtolower((string) ($posmainStartBalanceConfig['inventory']['ledger_mode'] ?? 'off')) === 'live';
$posmainItemOpeningHref = $posmainStartBalanceLiveInventory
    ? 'inventory_adjustments.php?legacy_opening_balance=retired'
    : 'items_start_balance.php';
$posmainItemOpeningLabel = $posmainStartBalanceLiveInventory
    ? 'افتتاحية وتسوية المخزون'
    : 'الارصدة الافتتاحية للأصناف';
?>
<div class="row">
    <div class="p-1 pt-20 col-sm-2">
        <a class="btn btn-block p-3 rounded-0  hover:bg-orange-600 hover:text-orange-50 bg-zinc-500 text-slate-50 text-center btn" href="start_balance.php">
            <i class="fa fa-file-invoice text-md"></i>
        <br>
        الارصدة الافتتاحية للحسابات</a>
    </div>
    <div class="p-1 pt-20 col-sm-2">
        <a class="btn btn-block p-3 rounded-0  hover:bg-orange-600 hover:text-orange-50 bg-zinc-500 text-slate-50 text-center btn" href="<?= htmlspecialchars($posmainItemOpeningHref, ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa fa-file-invoice text-md"></i>
        <br>
        <?= htmlspecialchars($posmainItemOpeningLabel, ENT_QUOTES, 'UTF-8') ?></a>
    </div>
</div>
