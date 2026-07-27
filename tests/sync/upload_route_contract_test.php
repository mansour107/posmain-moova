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

$htaccessPath = __DIR__ . '/../../uploads/.htaccess';
uploadRouteAssert(is_file($htaccessPath), 'uploads/.htaccess must exist');
$htaccess = file_get_contents($htaccessPath);
uploadRouteAssert(is_string($htaccess), 'unable to read uploads/.htaccess');

uploadRouteAssert(
    strpos($htaccess, 'Options -Indexes -ExecCGI -Includes') !== false,
    'uploads/.htaccess should disable directory indexes, CGI execution, and server-side includes'
);
uploadRouteAssert(strpos($htaccess, 'RemoveHandler .php') !== false, 'uploads/.htaccess should remove PHP handlers');
uploadRouteAssert(strpos($htaccess, 'RemoveType .php') !== false, 'uploads/.htaccess should remove PHP MIME types');

$executableBlockMatched = preg_match(
    '/<FilesMatch\\s+"([^"]+)">\\s*Require all denied\\s*<\\/FilesMatch>/s',
    $htaccess,
    $executableBlock
);
uploadRouteAssert($executableBlockMatched === 1, 'uploads/.htaccess should deny executable file requests in a FilesMatch block');

$executablePattern = (string) ($executableBlock[1] ?? '');
uploadRouteAssert(
    strpos($executablePattern, '(?i)') !== false,
    'uploads/.htaccess executable deny pattern should be case-insensitive'
);
foreach (['php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'jsp', 'asp', 'aspx', 'shtml'] as $extension) {
    uploadRouteAssert(
        strpos($executablePattern, $extension) !== false,
        'uploads/.htaccess executable deny pattern should cover .' . $extension
    );
}

$outsideFilesMatch = preg_replace('/<FilesMatch\\b[^>]*>.*?<\\/FilesMatch>/s', '', $htaccess);
uploadRouteAssert(is_string($outsideFilesMatch), 'unable to inspect uploads/.htaccess access scope');
uploadRouteAssert(
    strpos($outsideFilesMatch, 'Require all denied') === false,
    'uploads/.htaccess must not blanket-deny legitimate static uploads'
);

echo "upload-route-contract-ok\n";

function uploadRouteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
