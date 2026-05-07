<?php
/**
 * ملف حذف ملف إصلاح كلمات المرور
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $files_to_delete = [
        'fix_passwords.php',
        'delete_fix_file.php'
    ];
    
    foreach ($files_to_delete as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    echo "Files deleted successfully";
} else {
    echo "Invalid request";
}
?>
