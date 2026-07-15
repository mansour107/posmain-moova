<?php

$source = (string) file_get_contents(__DIR__ . '/../../includes/pos_login_screen.php');
$pinPadJs = (string) file_get_contents(__DIR__ . '/../../js/pin_pad.js');

posPinEntryAssert(strpos($source, "include __DIR__ . '/pin_pad_fragment.php'") !== false, 'unlock screen should render the shared PIN pad fragment');
posPinEntryAssert(strpos($source, 'id="posUnlockPinPad"') === false, 'PIN pad ID should be supplied by the shared fragment');
posPinEntryAssert(strpos($source, '$pinPadId = \'posUnlockPinPad\'') !== false, 'unlock screen should configure the shared POS PIN pad');
posPinEntryAssert(strpos($source, '$pinPadEndpoint = \'ajax/pos_pin_login.php\'') !== false, 'unlock screen should retain the POS PIN endpoint');
posPinEntryAssert(strpos($source, 'for ($i = 0; $i < 6; $i++)') === false, 'legacy six-digit dots must not remain');
posPinEntryAssert(strpos($source, 'pin_pad.js') !== false, 'unlock screen should load the shared PIN pad controller');

posPinEntryAssert(strpos($pinPadJs, 'pin.length === digits && opts.autoSubmit !== false') !== false, 'shared PIN pad should auto-submit after the fourth digit');
posPinEntryAssert(strpos($pinPadJs, 'dir="ltr"') !== false, 'shared PIN pad dots should be LTR');
posPinEntryAssert(strpos($pinPadJs, 'digitFromKeyboardEvent') !== false, 'shared PIN pad should accept physical keyboard digits');
posPinEntryAssert(
    preg_match('/navigator\.onLine\s*===\s*false/', $pinPadJs) !== 1,
    'shared PIN pad must not block submit when browser reports offline'
);

echo "pos-pin-entry-contract-ok\n";

function posPinEntryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
