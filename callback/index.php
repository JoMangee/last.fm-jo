<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/lastfm.php';

$config = app_config();
$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$error = '';
$session = null;

if ($token === '') {
    $error = 'Missing token from Last.fm callback.';
} elseif ($config['api_key'] === '' || $config['shared_secret'] === '') {
    $error = 'Missing LASTFM_API_KEY or LASTFM_SHARED_SECRET in .env';
} else {
    $sessionResult = lastfm_get_session($config['api_key'], $config['shared_secret'], $token);
    if (!$sessionResult['ok']) {
        $error = (string)($sessionResult['error'] ?? 'Could not exchange token for session');
    } else {
        $session = $sessionResult['session'];
        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0750, true);
        }
        $payload = json_encode([
            'session_key' => $session['key'] ?? '',
            'username'    => $session['name'] ?? '',
            'saved_at'    => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($dataDir . '/session.json', $payload);
        chmod($dataDir . '/session.json', 0600);
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LastBot - Callback</title>
    <style>
        :root {
            --bg: #f3f2ed;
            --card: #ffffff;
            --ink: #1e1f23;
            --ok: #166534;
            --error: #7f1d1d;
            --line: #d9dce3;
        }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            color: var(--ink);
            background: radial-gradient(circle at 80% 10%, #f3fff5, var(--bg));
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .card {
            width: min(760px, 100%);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.06);
        }
        .ok {
            color: var(--ok);
            font-weight: 700;
        }
        .error {
            color: var(--error);
            font-weight: 700;
        }
        pre {
            background: #f4f6fb;
            border-radius: 12px;
            padding: 14px;
            overflow: auto;
            border: 1px solid #dbe1eb;
        }
        a {
            color: #1f4f8a;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Last.fm Callback</h1>

        <?php if ($error !== ''): ?>
            <p class="error"><?php echo h($error); ?></p>
        <?php else: ?>
            <p class="ok">Success! Authenticated as <strong><?php echo h((string)($session['name'] ?? '')); ?></strong> and session key saved to server.</p>
            <p>The session key is stored in <code>data/session.json</code> (not web-accessible). Your bot can now use it to scrobble.</p>
        <?php endif; ?>

        <p><a href="/lastfm">Back to LastBot homepage</a></p>
    </main>
</body>
</html>