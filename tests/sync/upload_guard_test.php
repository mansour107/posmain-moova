<?php

require_once __DIR__ . '/../../includes/upload_guard.php';

$tmpPng = tempnam(sys_get_temp_dir(), 'posmain-upload-');
file_put_contents($tmpPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

$valid = posmain_validate_image_upload([
    'name' => 'avatar.png',
    'tmp_name' => $tmpPng,
    'size' => filesize($tmpPng),
    'error' => UPLOAD_ERR_OK,
]);
uploadGuardAssert(($valid['extension'] ?? '') === 'png', 'valid PNG should be accepted');
uploadGuardAssert(($valid['mime'] ?? '') === 'image/png', 'valid PNG MIME should be detected');

uploadGuardExpectInvalid(function () use ($tmpPng) {
    posmain_validate_image_upload([
        'name' => 'shell.php',
        'tmp_name' => $tmpPng,
        'size' => filesize($tmpPng),
        'error' => UPLOAD_ERR_OK,
    ]);
}, 'PHP extension should be denied even with image bytes');

$tmpText = tempnam(sys_get_temp_dir(), 'posmain-upload-');
file_put_contents($tmpText, '<?php echo "owned";');
uploadGuardExpectInvalid(function () use ($tmpText) {
    posmain_validate_image_upload([
        'name' => 'avatar.png',
        'tmp_name' => $tmpText,
        'size' => filesize($tmpText),
        'error' => UPLOAD_ERR_OK,
    ]);
}, 'non-image bytes should not pass as PNG');

uploadGuardExpectInvalid(function () use ($tmpPng) {
    posmain_validate_spreadsheet_upload([
        'name' => 'items.php',
        'tmp_name' => $tmpPng,
        'size' => filesize($tmpPng),
        'error' => UPLOAD_ERR_OK,
    ]);
}, 'spreadsheet import should reject PHP extension');

$serverName = posmain_upload_server_filename('bad ../name', 'PNG');
uploadGuardAssert(preg_match('/^bad_+name_\d{8}_\d{6}_[a-f0-9]{16}\.png$/', $serverName) === 1, 'server filename should be generated and sanitized');

@unlink($tmpPng);
@unlink($tmpText);

echo "upload-guard-ok\n";

function uploadGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function uploadGuardExpectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }

    throw new RuntimeException($message);
}
