<?php
$widgetHtml = __DIR__ . '/assets/moova-pos-widget/pos-widget.html';

if (!is_file($widgetHtml)) {
    http_response_code(404);
    echo 'Moova POS widget assets are missing.';
    exit;
}

$html = file_get_contents($widgetHtml);
$cssVersion = (string) @filemtime(__DIR__ . '/assets/moova-pos-widget/pos-widget.css');
$jsVersion = (string) @filemtime(__DIR__ . '/assets/moova-pos-widget/pos-widget.js');
$html = str_replace('href="/pos-widget.css"', 'href="assets/moova-pos-widget/pos-widget.css?v=' . $cssVersion . '"', $html);
$html = str_replace(
    '<script defer src="/pos-widget.js"></script>',
    '<script>window.__COFE_WIDGET_PROXY__="moova_pos_proxy.php?path=";</script>' . "\n" .
    '  <script defer src="assets/moova-pos-widget/pos-widget.js?v=' . $jsVersion . '"></script>',
    $html
);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
