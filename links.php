<?php
session_start();

define('DATA_FILE', __DIR__ . '/links.json');

function readJson($file) {
    clearstatcache(true, $file);
    if (!file_exists($file) || !is_readable($file)) return [];
    $data = @file_get_contents($file);
    if ($data === false) return [];
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function writeJson($file, $data) {
    $fp = @fopen($file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    $written = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $written !== false;
}

function siteUrl() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return rtrim("$proto://$host", '/');
}

$code = $_GET['c'] ?? '';

if (!$code || !preg_match('/^[a-zA-Z0-9_-]{1,30}$/', $code)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Invalid Link</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<script src="https://cdn.tailwindcss.com"></script></head>';
    echo '<body class="bg-gray-50 min-h-screen flex items-center justify-center">';
    echo '<div class="text-center"><h1 class="text-6xl font-bold text-gray-300 mb-4">400</h1>';
    echo '<p class="text-gray-500 mb-6">Invalid or missing short code.</p>';
    echo '<a href="/" class="text-blue-600 hover:underline">Back to Home</a></div></body></html>';
    exit;
}

$links = readJson(DATA_FILE);
$found = false;
$index = null;

foreach ($links as $i => $link) {
    if ($link['id'] === $code) {
        $found = true;
        $index = $i;
        break;
    }
}

if (!$found) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Link Not Found</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<script src="https://cdn.tailwindcss.com"></script></head>';
    echo '<body class="bg-gray-50 min-h-screen flex items-center justify-center">';
    echo '<div class="text-center"><h1 class="text-6xl font-bold text-gray-300 mb-4">404</h1>';
    echo '<p class="text-gray-500 mb-6">This short link does not exist.</p>';
    echo '<a href="/" class="text-blue-600 hover:underline">Create one</a></div></body></html>';
    exit;
}

$link = $links[$index];

// Password protection ----------------------------------------------------
if (!empty($link['password'])) {
    $sessionKey = 'link_pw_' . $link['id'];
    $pwError = '';

    if (!isset($_SESSION[$sessionKey])) {
        if (isset($_POST['link_password'])) {
            if (password_verify($_POST['link_password'], $link['password'])) {
                $_SESSION[$sessionKey] = true;
            } else {
                $pwError = 'Incorrect password.';
            }
        }

        if (!isset($_SESSION[$sessionKey])) {
            $siteBase = siteUrl();
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Password Required</title>
            <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="bg-gray-50 min-h-screen flex items-center justify-center">
            <div class="w-full max-w-sm mx-4">
                <div class="text-center mb-6">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-blue-100 text-blue-600 rounded-2xl text-xl mb-3">&#x1F512;</div>
                    <h1 class="text-xl font-bold text-gray-900">Protected Link</h1>
                    <p class="text-sm text-gray-500 mt-1">Enter the password to continue</p>
                </div>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                    <?php if ($pwError): ?>
                    <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">&#9888; <?= htmlspecialchars($pwError) ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="password" name="link_password" required placeholder="Link password"
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none mb-4 transition">
                        <button type="submit"
                                class="w-full bg-blue-600 text-white font-semibold py-3 rounded-xl hover:bg-blue-700 transition">
                            Unlock Link
                        </button>
                    </form>
                    <p class="text-center mt-4"><a href="/" class="text-xs text-gray-400 hover:text-gray-600">&larr; Back to Home</a></p>
                </div>
            </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

// Redirect ----------------------------------------------------------------
$links[$index]['clicks'] = ($links[$index]['clicks'] ?? 0) + 1;
writeJson(DATA_FILE, $links);

header('Location: ' . $link['url'], true, 301);
exit;
