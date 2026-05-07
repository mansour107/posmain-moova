<?php
/**
 * Asset Optimization Script
 * دمج وتصغير ملفات CSS و JS
 */

// ملفات CSS المطلوب دمجها
$css_files = [
    'assets/libs/fontawesome.min.css',
    'plugins/fontawesome-free/css/all.min.css',
    'plugins/datatables-bs4/css/dataTables.bootstrap4.min.css',
    'plugins/datatables-responsive/css/responsive.bootstrap4.min.css',
    'plugins/datatables-buttons/css/buttons.bootstrap4.min.css',
    'dist/css/ionicons.min.css',
    'plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css',
    'plugins/icheck-bootstrap/icheck-bootstrap.min.css',
    'plugins/jqvmap/jqvmap.min.css',
    'dist/css/adminlte.min.css',
    'dist/css/animate.css',
    'plugins/overlayScrollbars/css/OverlayScrollbars.min.css',
    'plugins/daterangepicker/daterangepicker.css',
    'plugins/summernote/summernote-bs4.css',
    'plugins/hadi/google.css',
    'assets/libs/playpen-sans-arabic-local.css',
    'dist/css/bootstrap4.2.min.css',
    'dist/css/custom.css',
    'plugins/select2/css/select2.min.css',
    'plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css',
    'dist/css/hadianime.css',
    'dist/css/horstec.css',
    'assets/styles/dashboard.css',
    'assets/styles/sidebar-fixes.css',
    'css/operations_responsive.css'
];

// دمج ملفات CSS
$combined_css = '';
$missing_files = [];

foreach ($css_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // إزالة التعليقات
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        
        // إزالة المسافات الزائدة
        $content = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $content);
        
        $combined_css .= $content . "\n";
    } else {
        $missing_files[] = $file;
    }
}

// حفظ الملف المدمج
$output_file = 'dist/css/combined.min.css';
file_put_contents($output_file, $combined_css);

$css_size = strlen($combined_css);
$css_size_kb = number_format($css_size / 1024, 2);

echo "✅ تم دمج " . count($css_files) . " ملف CSS\n";
echo "📦 الحجم: {$css_size_kb} KB\n";
echo "💾 تم الحفظ في: {$output_file}\n\n";

if (!empty($missing_files)) {
    echo "⚠️ ملفات غير موجودة:\n";
    foreach ($missing_files as $file) {
        echo "  - {$file}\n";
    }
}

// إنشاء ملف header محسّن
$optimized_header = <<<'HTML'
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title><?php echo $title ?? 'نظام الإدارة'; ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <!-- Favicon -->
  <link rel="icon" href="assets/favicon/favicon.png" type="image/ico">
  
  <!-- Combined CSS (All styles in one file) -->
  <link rel="stylesheet" href="dist/css/combined.min.css">
  
  <!-- Page-specific CSS will be loaded here -->
  <?php if (isset($page_css)): ?>
    <link rel="stylesheet" href="<?php echo $page_css; ?>">
  <?php endif; ?>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
HTML;

file_put_contents('includes/header_optimized.php', $optimized_header);
echo "\n✅ تم إنشاء includes/header_optimized.php\n";

echo "\n📝 للاستخدام:\n";
echo "1. استبدل include('includes/header.php') بـ include('includes/header_optimized.php')\n";
echo "2. أعد تحميل الصفحة\n";
echo "3. ستلاحظ تحسن كبير في السرعة!\n";
?>
