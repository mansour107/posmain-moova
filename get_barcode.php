<?php
/**
 * Proxy script to fetch barcode image from external service
 * This solves CORS issues with html2canvas
 */

if (!isset($_GET['code'])) {
    http_response_code(400);
    exit('Missing barcode code');
}

$code = $_GET['code'];
$barcode_url = "https://barcode.tec-it.com/barcode.ashx?data=" . urlencode($code) . "&code=Code128&translate-esc=on&dpi=96";

// Fetch the barcode image
$image_data = @file_get_contents($barcode_url);

if ($image_data === false) {
    http_response_code(500);
    exit('Failed to fetch barcode');
}

// Set appropriate headers
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400'); // Cache for 24 hours

// Output the image
echo $image_data;
?>
