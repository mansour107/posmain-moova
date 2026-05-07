<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$logFile = __DIR__ . '/logs/php_errors.log';

if (file_exists($logFile)) {
    echo '<pre>';
    echo htmlspecialchars(file_get_contents($logFile));
    echo '</pre>';
} else {
    echo 'Log file not found at: ' . $logFile;
    
    // Check directory permissions
    echo '<h3>Directory Info:</h3>';
    echo 'Logs directory exists: ' . (is_dir(__DIR__ . '/logs') ? 'Yes' : 'No') . '<br>';
    echo 'Logs directory is writable: ' . (is_writable(__DIR__ . '/logs') ? 'Yes' : 'No') . '<br>';
    
    // Try to create a test file
    $testFile = __DIR__ . '/logs/test.log';
    if (@file_put_contents($testFile, 'test') !== false) {
        echo 'Successfully wrote to test file: ' . $testFile . '<br>';
        unlink($testFile);
    } else {
        echo 'Failed to write to test file. Check directory permissions.<br>';
    }
    
    // Show PHP error log path
    echo 'PHP error_log setting: ' . ini_get('error_log') . '<br>';
}
?>
