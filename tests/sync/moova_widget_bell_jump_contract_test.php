<?php

$root = dirname(__DIR__, 2);
$host = file_get_contents($root . '/elements/pos/cofe_widget.php');

function moovaWidgetJumpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

moovaWidgetJumpAssert(is_string($host), 'Unable to read Moova host widget');
moovaWidgetJumpAssert(
    strpos($host, 'function getFixedContainingBlockRect') !== false,
    'Host must convert panel frame coords against fixed containing blocks'
);
moovaWidgetJumpAssert(
    strpos($host, 'borderTopWidth') !== false,
    'Containing-block math must use padding edge (subtract border widths)'
);
moovaWidgetJumpAssert(
    strpos($host, 'slotRect.top - containingBlock.top') !== false,
    'Panel top must be containing-block relative, not raw viewport Y'
);
moovaWidgetJumpAssert(
    strpos($host, 'containingBlock.right - slotRect.right') !== false,
    'Panel right must be containing-block relative'
);

echo "moova-widget-bell-jump-contract-ok\n";
