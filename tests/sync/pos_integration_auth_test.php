<?php

require_once __DIR__ . '/../../classes/Pos/Security/PosIntegrationAuth.php';

putenv('POSMAIN_ALLOW_OPEN_INTEGRATIONS=1');
$_ENV['POSMAIN_ALLOW_OPEN_INTEGRATIONS'] = '1';

$payload = ['items' => [['itemId' => '1', 'qty' => 1]]];
PosIntegrationAuth::requireCofeSignature($payload, [], null);

$source = file_get_contents(__DIR__ . '/../../classes/Pos/Security/PosIntegrationAuth.php');
if (strpos($source, 'shouldFailClosedWithoutSecret') === false) {
    throw new RuntimeException('PosIntegrationAuth should expose fail-closed helper');
}

if (strpos($source, 'INTEGRATION_DISABLED') === false) {
    throw new RuntimeException('PosIntegrationAuth should define INTEGRATION_DISABLED');
}

echo "pos-integration-auth-ok\n";
