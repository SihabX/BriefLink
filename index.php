<?php
session_start();

define('DATA_FILE', __DIR__ . '/links.json');
define('RATE_FILE', __DIR__ . '/_rate.json');
define('CONFIG_FILE', __DIR__ . '/_config.json');

foreach ([DATA_FILE, RATE_FILE, CONFIG_FILE] as $f) {
    if (!file_exists($f)) {
        $init = ($f === DATA_FILE) ? '[]' : (($f === CONFIG_FILE) ? json_encode(['prefix' => 'c', 'prefix_enabled' => true, 'code_length' => 6], JSON_PRETTY_PRINT) : '[]');
        @file_put_contents($f, $init);
    }
}

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

function loadConfig() {
    $default = ['prefix' => 'c', 'prefix_enabled' => true, 'code_length' => 6];
    if (!file_exists(CONFIG_FILE)) return $default;
    $data = json_decode(@file_get_contents(CONFIG_FILE), true);
    return is_array($data) ? array_merge($default, $data) : $default;
}

function generateCode($len) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $len; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function validateUrl($url) {
    $url = trim($url);
    if ($url === '') return false;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    foreach (['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'] as $l) {
        if (strcasecmp($host, $l) === 0) return false;
    }
    if (stripos($host, '.local') !== false || stripos($host, '.internal') !== false) return false;
    return $url;
}

function checkRateLimit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rates = readJson(RATE_FILE);
    $now = time();
    $window = 3600;
    $max = 20;
    if (isset($rates[$ip]) && is_array($rates[$ip])) {
        $rates[$ip] = array_values(array_filter($rates[$ip], fn($t) => $t > $now - $window));
    } else {
        $rates[$ip] = [];
    }
    if (count($rates[$ip]) >= $max) return false;
    $rates[$ip][] = $now;
    writeJson(RATE_FILE, $rates);
    return true;
}

function siteUrl() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return rtrim("$proto://$host$dir", '/');
}

function shortUrl($code) {
    $config = loadConfig();
    $base = siteUrl();
    if (!empty($config['prefix_enabled'])) {
        return $base . '/' . $config['prefix'] . '/' . $code;
    }
    return $base . '/' . $code;
}

$config = loadConfig();
$error = '';
$shortUrl = $_SESSION['flash_url'] ?? '';
unset($_SESSION['flash_url']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = $_POST['url'] ?? '';
    $custom = trim($_POST['custom'] ?? '');
    $linkPassword = $_POST['link_password'] ?? '';
    $url = validateUrl($url);

    if (!$url) {
        $error = 'Please enter a valid public URL (http:// or https://).';
    } elseif (!checkRateLimit()) {
        $error = 'Rate limit exceeded (max 20/hour). Please try again later.';
    } else {
        $links = readJson(DATA_FILE);
        $existingCodes = array_column($links, 'id');

        if ($custom !== '') {
            if (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $custom)) {
                $error = 'Custom alias must be 3-20 characters (letters, numbers, hyphens, underscores).';
            } elseif (in_array($custom, $existingCodes)) {
                $error = 'This alias is already taken. Please choose another.';
            } else {
                $code = $custom;
            }
        } else {
            do { $code = generateCode($config['code_length']); } while (in_array($code, $existingCodes));
        }

        if (!$error) {
            $entry = [
                'id' => $code,
                'url' => $url,
                'created' => date('Y-m-d H:i:s'),
                'clicks' => 0,
            ];
            if ($linkPassword !== '') {
                $entry['password'] = password_hash($linkPassword, PASSWORD_DEFAULT);
            }
            $links[] = $entry;
            if (writeJson(DATA_FILE, $links)) {
                $_SESSION['flash_url'] = shortUrl($code);
                header('Location: ' . $_SERVER['REQUEST_URI'], true, 303);
                exit;
            } else {
                $error = 'Failed to save. Check directory write permissions.';
            }
        }
    }
}

$links = readJson(DATA_FILE);
$totalLinks = count($links);
$totalClicks = array_sum(array_column($links, 'clicks'));
$siteBase = siteUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>URL Shortener</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">

<div class="max-w-2xl mx-auto px-4 py-12">

    <!-- Header -->
    <div class="text-center mb-10">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 text-white rounded-2xl text-2xl mb-4">&#x2702;</div>
        <h1 class="text-4xl font-bold text-gray-900 tracking-tight">URL Shortener</h1>
        <p class="text-gray-500 mt-2">Paste a long URL &mdash; get a short, shareable link instantly</p>
    </div>

    <!-- Shortener Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 mb-8">
        <form method="POST" class="space-y-5" id="shortenForm">
            <div>
                <label for="url" class="block text-sm font-medium text-gray-700 mb-1.5">Destination URL</label>
                <input type="text" id="url" name="url" required placeholder="https://example.com/very/long/path"
                       value="<?= htmlspecialchars($_POST['url'] ?? '') ?>"
                       class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition placeholder:text-gray-400">
            </div>
            <div>
                <label for="custom" class="block text-sm font-medium text-gray-700 mb-1.5">
                    Custom alias <span class="text-gray-400 font-normal">(optional, 3&ndash;20 chars)</span>
                </label>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-400 whitespace-nowrap font-mono"><?= htmlspecialchars($siteBase) ?>/</span>
                    <input type="text" id="custom" name="custom" placeholder="my-link" maxlength="20"
                           value="<?= htmlspecialchars($_POST['custom'] ?? '') ?>"
                           class="flex-1 min-w-0 px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition placeholder:text-gray-400 font-mono text-sm">
                </div>
            </div>
            <div>
                <label for="link_password" class="block text-sm font-medium text-gray-700 mb-1.5">
                    Password protect <span class="text-gray-400 font-normal">(optional)</span>
                </label>
                <input type="text" id="link_password" name="link_password" placeholder="Leave empty for no password"
                       maxlength="100"
                       class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition placeholder:text-gray-400">
            </div>
            <button type="submit" id="submitBtn"
                    class="w-full bg-blue-600 text-white font-semibold py-3.5 px-6 rounded-xl hover:bg-blue-700 active:bg-blue-800 transition text-lg">
                Shorten URL
            </button>
        </form>

        <!-- Result -->
        <?php if ($shortUrl): ?>
        <div class="mt-6 p-5 bg-green-50 border border-green-200 rounded-xl" id="resultBox">
            <p class="text-green-800 font-medium text-sm mb-3">&#10003; Link created successfully!</p>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="<?= htmlspecialchars($shortUrl) ?>" id="shortUrl"
                       class="flex-1 px-3 py-2.5 bg-white border border-gray-300 rounded-lg text-sm font-mono select-all">
                <button onclick="copyUrl()" id="copyBtn"
                        class="px-5 py-2.5 bg-gray-900 text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition whitespace-nowrap">
                    Copy
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="mt-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <p class="text-red-700 text-sm">&#9888; <?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 text-center">
            <p class="text-3xl font-bold text-gray-900"><?= number_format($totalLinks) ?></p>
            <p class="text-sm text-gray-500">Links created</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 text-center">
            <p class="text-3xl font-bold text-blue-600"><?= number_format($totalClicks) ?></p>
            <p class="text-sm text-gray-500">Total clicks</p>
        </div>
    </div>

    <!-- Footer -->
    <div class="text-center mt-8 space-x-4 text-sm">
        <a href="/admin" class="text-gray-400 hover:text-gray-600 transition">Admin Panel</a>
        <span class="text-gray-300">|</span>
        <span class="text-gray-400">JSON storage &middot; No database</span>
    </div>
</div>

<script>
function copyUrl() {
    var input = document.getElementById('shortUrl');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(function() {
        var btn = document.getElementById('copyBtn');
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.remove('bg-gray-900', 'hover:bg-gray-800');
        btn.classList.add('bg-green-600', 'hover:bg-green-700');
        setTimeout(function() {
            btn.textContent = orig;
            btn.classList.remove('bg-green-600', 'hover:bg-green-700');
            btn.classList.add('bg-gray-900', 'hover:bg-gray-800');
        }, 2000);
    });
}
document.getElementById('shortenForm')?.addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Shortening...';
    btn.classList.add('opacity-70');
});
</script>
</body>
</html>
