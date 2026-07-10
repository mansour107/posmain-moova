<?php

$sourceTeam = (string) file_get_contents(__DIR__ . '/../../team.php');
$sourceService = (string) file_get_contents(__DIR__ . '/../../classes/Security/TeamHubService.php');
$sourceMutations = (string) file_get_contents(__DIR__ . '/../../classes/Security/TeamHubMutationService.php');
$sourcePin = (string) file_get_contents(__DIR__ . '/../../classes/Security/PinService.php');

teamHubPinRevealAssert(
    strpos($sourceTeam, 'posmain_one_time_pin_reveal') !== false,
    'team.php must support one-time PIN reveal after reset'
);
teamHubPinRevealAssert(
    strpos($sourceService, 'Reversible PIN reveal is retired') !== false,
    'TeamHubService must not reveal stored PINs'
);
teamHubPinRevealAssert(
    strpos($sourceService, 'u.pin_enc') === false,
    'TeamHubService must not select pin_enc'
);
teamHubPinRevealAssert(
    strpos($sourceMutations, 'posmain_one_time_pin_reveal') !== false,
    'resetPin must stash one-time reveal in session'
);
teamHubPinRevealAssert(
    strpos($sourceMutations, "'must_change' => true") !== false,
    'resetPin must force change on next login'
);
teamHubPinRevealAssert(
    strpos($sourcePin, 'PIN_REVEAL_DISABLED') !== false,
    'PinService must refuse reversible encrypt'
);
teamHubPinRevealAssert(
    preg_match('/function revealPinForOwner[\s\S]*?return null;/', $sourcePin) === 1,
    'revealPinForOwner must always return null'
);

echo "team-hub-pin-reveal-contract-ok\n";

function teamHubPinRevealAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
