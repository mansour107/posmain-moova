<?php

$routes = [
    'do/doadd_user.php' => [
        "require_once __DIR__ . '/../includes/upload_guard.php'",
        'posmain_store_image_upload($_FILES[\'img\']',
    ],
    'do/doedit_user.php' => [
        "require_once __DIR__ . '/../includes/upload_guard.php'",
        'posmain_store_image_upload($_FILES[\'img\']',
    ],
    'do/uploaditems.php' => [
        "require_once __DIR__ . '/../includes/upload_guard.php'",
        'posmain_validate_spreadsheet_upload($_FILES[\'file\'])',
    ],
];

foreach ($routes as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    uploadRouteAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        uploadRouteAssert(strpos($source, $snippet) !== false, $path . ' missing upload guard snippet: ' . $snippet);
    }
    uploadRouteAssert(strpos($source, 'move_uploaded_file(') === false, $path . ' should not move uploads directly');
}

$htaccess = file_get_contents(__DIR__ . '/../../uploads/.htaccess');
uploadRouteAssert(is_string($htaccess), 'unable to read uploads/.htaccess');
uploadRouteAssert(strpos($htaccess, 'RemoveHandler .php') !== false, 'uploads/.htaccess should remove PHP handlers');
uploadRouteAssert(strpos($htaccess, 'Require all denied') !== false, 'uploads/.htaccess should deny executable file requests');

echo "upload-route-contract-ok\n";

function uploadRouteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
