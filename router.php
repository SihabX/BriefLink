<?php
// Front controller for pretty URLs
// Usage: php -S localhost:8000 router.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';
$base = __DIR__;
$reserved = ['admin', 'index.php', 'links.php', 'router.php', '_config.json', '_rate.json', '_admin'];

// Serve existing static files directly
if ($uri !== '/' && is_file($base . $uri)) {
    return false;
}

// Route: home
if ($uri === '/' || $uri === '/index.php') {
    require $base . '/index.php';
    return true;
}

// Route: admin panel
if ($uri === '/admin') {
    require $base . '/admin.php';
    return true;
}

// Route: /{code} or /{prefix}/{code}
$parts = explode('/', trim($uri, '/'));
$code = null;

if (count($parts) === 2) {
    $code = $parts[1]; // /prefix/code
} elseif (count($parts) === 1 && !in_array($parts[0], $reserved) && !str_contains($parts[0], '.')) {
    $code = $parts[0]; // /code
}

if ($code && preg_match('/^[a-zA-Z0-9_-]{1,30}$/', $code)) {
    $_GET['c'] = $code;
    require $base . '/links.php';
    return true;
}

http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>404</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
<div class="text-center"><h1 class="text-6xl font-bold text-gray-300 mb-4">404</h1>
<p class="text-gray-500 mb-6">Page not found.</p>
<a href="/" class="text-blue-600 hover:underline">Go Home</a></div>
</body>
</html>
