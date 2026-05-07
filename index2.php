<?php

// Parse the URL path
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string for clean URL
if (strpos($requestUri, '?') !== false) {
    $requestUri = strstr($requestUri, '?', true);
}

// Define your routes
$routes = [
    'GET' => [
        '/hors/test' => '/test.php',
        '/hors/login' => '/index1.php',
        '/hors/main' => '/dashboard.php',
            ],
    'POST' => [
        '/contact' => 'contactFormHandler',
    ]
];

// Function to handle file inclusion
function handleFile($filePath) {
    include $filePath;
}

// Function to handle 404 errors
function handle404() {
    http_response_code(404);
    echo '404 Not Found';
}

// Function to show available pages
function showAvailablePages($routes) {
    echo "<h1>Available Pages (المتاح)</h1>";
    echo "<ul>";
    foreach ($routes as $method => $paths) {
        foreach ($paths as $path => $handler) {
            echo "<li><strong>$method</strong>: <a href='$path'>$path</a></li>";
        }
    }
    echo "</ul>";
}

// Route the request
if (array_key_exists($requestMethod, $routes) && array_key_exists($requestUri, $routes[$requestMethod])) {
    $handler = $routes[$requestMethod][$requestUri];
    
    if (is_callable($handler)) {
        // Call the handler function if it's callable
        $handler($routes); // Pass routes to the handler function if it's 'showAvailablePages'
    } elseif (is_string($handler) && file_exists(__DIR__ . $handler)) {
        // Include the file if it exists
        handleFile(__DIR__ . $handler);
    } else {
        // Handle invalid route
        handle404();
    }
} else {
    // Handle 404 Not Found for undefined routes
    handle404();
}

// Handler functions
function contactFormHandler() {
    // Handle the form submission
    echo 'Form submitted successfully!';
}
