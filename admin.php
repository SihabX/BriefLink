<?php
session_start();

define('ADMIN_HASH_FILE', __DIR__ . '/_admin');
define('DATA_FILE', __DIR__ . '/links.json');
define('CONFIG_FILE', __DIR__ . '/_config.json');

foreach ([DATA_FILE, ADMIN_HASH_FILE, CONFIG_FILE] as $f) {
    if (!file_exists($f)) {
        $init = ($f === DATA_FILE) ? '[]' : (($f === CONFIG_FILE) ? json_encode(['prefix' => 'c', 'prefix_enabled' => true, 'code_length' => 6], JSON_PRETTY_PRINT) : password_hash('admin', PASSWORD_DEFAULT));
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

function saveConfig($config) {
    $fp = @fopen(CONFIG_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
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

// Read flash messages (set from previous redirect)
$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Auth
$storedHash = trim(@file_get_contents(ADMIN_HASH_FILE)) ?: password_hash('admin', PASSWORD_DEFAULT);

// Login (PRG on success, inline error on failure)
if (isset($_POST['login'])) {
    if (password_verify($_POST['password'] ?? '', $storedHash)) {
        $_SESSION['admin_auth'] = true;
        $_SESSION['admin_auth_time'] = time();
        header('Location: admin.php', true, 303);
        exit;
    }
    $error = 'Invalid password.';
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$authenticated = !empty($_SESSION['admin_auth']) && (time() - ($_SESSION['admin_auth_time'] ?? 0)) < 7200;
if (!$authenticated) $_SESSION['admin_auth'] = false;

// Process POST actions (authenticated only) — all redirect via PRG
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $_SESSION['flash_error'] = 'Security token mismatch. Please try again.';
        header('Location: admin.php', true, 303);
        exit;
    }

    $links = readJson(DATA_FILE);
    $config = loadConfig();

    // Save settings
    if (isset($_POST['save_settings'])) {
        $config['prefix'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['prefix'] ?? 'c');
        if ($config['prefix'] === '') $config['prefix'] = 'c';
        $config['prefix_enabled'] = isset($_POST['prefix_enabled']);
        $config['code_length'] = max(4, min(10, intval($_POST['code_length'] ?? 6)));
        saveConfig($config);
        $_SESSION['flash_success'] = 'Settings saved.';
        header('Location: admin.php', true, 303);
        exit;
    }

    // Delete link
    if (isset($_POST['delete'])) {
        $delId = $_POST['delete'];
        $before = count($links);
        $links = array_values(array_filter($links, fn($l) => $l['id'] !== $delId));
        if (count($links) < $before) {
            writeJson(DATA_FILE, $links);
            $_SESSION['flash_success'] = 'Link deleted.';
        } else {
            $_SESSION['flash_error'] = 'Link not found.';
        }
        header('Location: admin.php', true, 303);
        exit;
    }

    // Edit link
    if (isset($_POST['edit_code']) && isset($_POST['edit_url'])) {
        $editCode = $_POST['edit_code'];
        $editUrl = trim($_POST['edit_url']);
        $editPassword = $_POST['edit_password'] ?? '';
        $removePassword = isset($_POST['edit_remove_pw']);

        if (!preg_match('/^[a-zA-Z0-9_-]{1,30}$/', $editCode)) {
            $_SESSION['flash_error'] = 'Invalid link code.';
        } else {
            if (!preg_match('#^https?://#i', $editUrl)) $editUrl = 'https://' . $editUrl;
            if (!filter_var($editUrl, FILTER_VALIDATE_URL)) {
                $_SESSION['flash_error'] = 'Invalid destination URL.';
            } else {
                $found = false;
                foreach ($links as &$l) {
                    if ($l['id'] === $editCode) {
                        $l['url'] = $editUrl;
                        if ($removePassword) {
                            unset($l['password']);
                        } elseif ($editPassword !== '') {
                            $l['password'] = password_hash($editPassword, PASSWORD_DEFAULT);
                        }
                        $found = true;
                        break;
                    }
                }
                unset($l);
                if ($found) {
                    writeJson(DATA_FILE, $links);
                    $_SESSION['flash_success'] = 'Link updated.';
                } else {
                    $_SESSION['flash_error'] = 'Link not found.';
                }
            }
        }
        header('Location: admin.php', true, 303);
        exit;
    }

    // Change admin password
    if (isset($_POST['change_password'])) {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';
        if (!password_verify($currentPw, $storedHash)) {
            $_SESSION['flash_error'] = 'Current password is incorrect.';
        } elseif (strlen($newPw) < 6) {
            $_SESSION['flash_error'] = 'New password must be at least 6 characters.';
        } elseif ($newPw !== $confirmPw) {
            $_SESSION['flash_error'] = 'New passwords do not match.';
        } else {
            $newHash = password_hash($newPw, PASSWORD_DEFAULT);
            if (@file_put_contents(ADMIN_HASH_FILE, $newHash) !== false) {
                $storedHash = $newHash;
                $_SESSION['flash_success'] = 'Password changed successfully.';
            } else {
                $_SESSION['flash_error'] = 'Failed to save new password. Check file permissions.';
            }
        }
        header('Location: admin.php', true, 303);
        exit;
    }
}

$links = readJson(DATA_FILE);
$config = loadConfig();
$totalLinks = count($links);
$totalClicks = array_sum(array_column($links, 'clicks'));
$siteBase = siteUrl();
$editTarget = isset($_GET['edit']) ? $_GET['edit'] : '';
$editLink = null;
if ($editTarget) {
    foreach ($links as $l) {
        if ($l['id'] === $editTarget) { $editLink = $l; break; }
    }
}

if (!$authenticated):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login &middot; URL Shortener</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen flex items-center justify-center">
<div class="w-full max-w-sm mx-4">
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-14 h-14 bg-gray-800 text-white rounded-2xl text-xl mb-3">&#x1F512;</div>
        <h1 class="text-2xl font-bold text-gray-900">Admin Login</h1>
        <p class="text-gray-500 text-sm mt-1">Enter your password to continue</p>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">&#9888; <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" required placeholder="Password"
                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition mb-4">
            <button type="submit" name="login"
                    class="w-full bg-gray-900 text-white font-semibold py-3 rounded-xl hover:bg-gray-800 transition">Sign In</button>
        </form>
        <p class="text-center text-xs text-gray-400 mt-4"><a href="/" class="hover:text-gray-600">&larr; Back to Home</a></p>
    </div>
    <p class="text-center text-xs text-gray-400 mt-4">Default password: <code class="bg-gray-100 px-1.5 py-0.5 rounded">admin</code> &middot; Change it after login.</p>
</div>
</body>
</html>
<?php else: ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel &middot; URL Shortener</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-6xl mx-auto px-4 py-8">

    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Admin Panel</h1>
            <p class="text-sm text-gray-500">Manage your shortened links</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="/" class="text-sm text-blue-600 hover:underline">New Link</a>
            <a href="?logout" class="text-sm text-gray-500 hover:text-red-600 transition"
               onclick="return confirm('Sign out?')">Sign Out</a>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success): ?>
    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-700">&#10003; <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">&#9888; <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-2xl font-bold text-gray-900"><?= number_format($totalLinks) ?></p>
            <p class="text-xs text-gray-500">Total Links</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-2xl font-bold text-blue-600"><?= number_format($totalClicks) ?></p>
            <p class="text-xs text-gray-500">Total Clicks</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-2xl font-bold text-gray-900"><?= number_format($totalLinks ? round($totalClicks / $totalLinks, 1) : 0) ?></p>
            <p class="text-xs text-gray-500">Avg Clicks/Link</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-2xl font-bold text-gray-900"><?= number_format($totalLinks ? count(array_filter($links, fn($l) => ($l['clicks'] ?? 0) > 0)) : 0) ?></p>
            <p class="text-xs text-gray-500">Active Links</p>
        </div>
    </div>

    <!-- Settings -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">URL Settings</h2>
        <form method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">URL Prefix</label>
                <input type="text" name="prefix" value="<?= htmlspecialchars($config['prefix']) ?>" maxlength="20"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm font-mono"
                       placeholder="c">
                <p class="text-xs text-gray-400 mt-1">Prefix segment before code</p>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Enable Prefix</label>
                <label class="flex items-center gap-2 px-3 py-2 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" name="prefix_enabled" value="1" <?= $config['prefix_enabled'] ? 'checked' : '' ?> class="rounded">
                    <span class="text-sm text-gray-700"><?= $config['prefix_enabled'] ? 'On' : 'Off' ?></span>
                </label>
                <p class="text-xs text-gray-400 mt-1"><?= $config['prefix_enabled'] ? $siteBase . '/' . $config['prefix'] . '/CODE' : $siteBase . '/CODE' ?></p>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Code Length</label>
                <input type="number" name="code_length" value="<?= (int)$config['code_length'] ?>" min="4" max="10"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                <p class="text-xs text-gray-400 mt-1">Characters per code (4&ndash;10)</p>
            </div>
            <button type="submit" name="save_settings"
                    class="px-5 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition h-fit">
                Save Settings
            </button>
        </form>
    </div>

    <!-- Edit Card -->
    <?php if ($editLink): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-blue-200 p-6 mb-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Edit Link: <?= htmlspecialchars($editLink['id']) ?></h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="edit_code" value="<?= htmlspecialchars($editLink['id']) ?>">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Destination URL</label>
                <input type="url" name="edit_url" required value="<?= htmlspecialchars($editLink['url']) ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Password <span class="text-gray-400 font-normal">(fill to set/change)</span></label>
                    <input type="text" name="edit_password" placeholder="Leave empty to keep current" maxlength="100"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                </div>
                <?php if (!empty($editLink['password'])): ?>
                <div>
                    <label class="flex items-center gap-2 px-3 py-2 mt-5 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="edit_remove_pw" value="1" class="rounded">
                        <span class="text-sm text-red-600">Remove password protection</span>
                    </label>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-5 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">Save</button>
                <a href="admin.php" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700 transition">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Links Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">All Links</h2>
            <span class="text-xs text-gray-400"><?= count($links) ?> total</span>
        </div>

        <?php if (empty($links)): ?>
        <div class="p-12 text-center">
            <p class="text-gray-400 text-lg mb-2">No links yet</p>
            <a href="/" class="text-blue-600 hover:underline text-sm">Create your first link &rarr;</a>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <th class="px-6 py-3">Short Link</th>
                        <th class="px-6 py-3">Destination</th>
                        <th class="px-6 py-3 text-center">Clicks</th>
                        <th class="px-6 py-3 text-center">Protected</th>
                        <th class="px-6 py-3 text-center">Created</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($links as $link): ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-3.5">
                            <a href="<?= htmlspecialchars(shortUrl($link['id'])) ?>" target="_blank" class="font-mono text-blue-600 text-xs hover:underline break-all">
                                <?= htmlspecialchars(shortUrl($link['id'])) ?>
                            </a>
                        </td>
                        <td class="px-6 py-3.5 max-w-[200px]">
                            <p class="truncate text-gray-600" title="<?= htmlspecialchars($link['url']) ?>"><?= htmlspecialchars($link['url']) ?></p>
                        </td>
                        <td class="px-6 py-3.5 text-center font-medium"><?= number_format($link['clicks'] ?? 0) ?></td>
                        <td class="px-6 py-3.5 text-center"><?= !empty($link['password']) ? '<span class="text-base">&#x1F512;</span>' : '<span class="text-gray-300">&ndash;</span>' ?></td>
                        <td class="px-6 py-3.5 text-center text-gray-500 text-xs whitespace-nowrap"><?= date('M j, Y', strtotime($link['created'])) ?></td>
                        <td class="px-6 py-3.5 text-right whitespace-nowrap">
                            <a href="?edit=<?= urlencode($link['id']) ?>" class="text-gray-400 hover:text-gray-600 text-xs font-medium mr-3">Edit</a>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this link? This cannot be undone.')">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="delete" value="<?= htmlspecialchars($link['id']) ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Change Password -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mt-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Change Admin Password</h2>
        <form method="POST" class="max-w-md space-y-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Current Password</label>
                <input type="password" name="current_password" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">New Password (min 6 chars)</label>
                <input type="password" name="new_password" required minlength="6"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Confirm New Password</label>
                <input type="password" name="confirm_password" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
            </div>
            <button type="submit" name="change_password"
                    class="px-5 py-2 bg-gray-900 text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition">
                Update Password
            </button>
        </form>
    </div>

    <p class="text-center text-xs text-gray-400 mt-8">
        <a href="/" class="hover:text-gray-600 transition">&larr; Back to Shortener</a>
    </p>
</div>
</body>
</html>
<?php endif; ?>
