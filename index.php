<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/lastfm.php';

session_start();

$config  = app_config();
$error   = '';
$success = '';

// Step 1: request a token and redirect to Last.fm
if (isset($_GET['connect'])) {
    if ($config['api_key'] === '' || $config['shared_secret'] === '') {
        $error = 'Missing LASTFM_API_KEY or LASTFM_SHARED_SECRET in .env';
    } else {
        $tokenResult = lastfm_get_token($config['api_key'], $config['shared_secret']);
        if (!$tokenResult['ok']) {
            $error = (string)($tokenResult['error'] ?? 'Could not get Last.fm token');
        } else {
            $_SESSION['lastfm_pending_token'] = $tokenResult['token'];
            $authUrl = lastfm_auth_url($config['api_key'], $tokenResult['token'], $config['callback_url']);
            header('Location: ' . $authUrl);
            exit;
        }
    }
}

// Step 2: Last.fm didn't redirect to /callback — exchange the pending token here
if (isset($_GET['complete']) && !empty($_SESSION['lastfm_pending_token'])) {
    $token = (string)$_SESSION['lastfm_pending_token'];
    if ($config['api_key'] === '' || $config['shared_secret'] === '') {
        $error = 'Missing LASTFM_API_KEY or LASTFM_SHARED_SECRET in .env';
    } else {
        $sessionResult = lastfm_get_session($config['api_key'], $config['shared_secret'], $token);
        if (!$sessionResult['ok']) {
            $error = 'Could not exchange token: ' . (string)($sessionResult['error'] ?? 'unknown error');
        } else {
            $sess    = $sessionResult['session'];
            $dataDir = __DIR__ . '/data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0775, true);
            }
            $payload = json_encode([
                'session_key' => $sess['key'] ?? '',
                'username'    => $sess['name'] ?? '',
                'saved_at'    => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($dataDir . '/session.json', $payload) === false) {
                $error = 'Could not write session.json. Check write permissions on: ' . $dataDir;
            } else {
                chmod($dataDir . '/session.json', 0600);
                unset($_SESSION['lastfm_pending_token']);
                $success = 'Authenticated as ' . htmlspecialchars((string)($sess['name'] ?? ''), ENT_QUOTES, 'UTF-8') . ' — session key saved.';
            }
        }
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
    <title>LastBot - Last.fm Stub</title>
    <style>
        :root {
            --bg: #f3f2ed;
            --card: #ffffff;
            --ink: #1e1f23;
            --accent: #c71818;
            --muted: #58606d;
            --line: #d9dce3;
        }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            color: var(--ink);
            background: radial-gradient(circle at 20% 10%, #fff8f2, var(--bg));
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .card {
            width: min(720px, 100%);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.06);
        }
        h1 {
            margin-top: 0;
            font-size: 1.8rem;
        }
        p, li {
            color: var(--muted);
            line-height: 1.5;
        }
        .error {
            padding: 12px;
            border-radius: 10px;
            background: #ffe6e6;
            color: #771717;
            border: 1px solid #f4b5b5;
            margin-bottom: 12px;
        }
        .button {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 600;
            margin-top: 8px;
        }
        .ok {
            padding: 12px;
            border-radius: 10px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            margin-bottom: 12px;
            font-weight: 600;
        }
        code {
            background: #f2f4f8;
            padding: 2px 6px;
            border-radius: 6px;
            color: #273246;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>LastBot Last.fm Stub</h1>
        <p>This page starts the Last.fm auth flow and sends users to the configured callback endpoint.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="ok"><?php echo h($success); ?></div>
        <?php endif; ?>

        <?php if ($success === ''): ?>
            <a class="button" href="?connect=1">Connect Last.fm</a>

            <?php if (!empty($_SESSION['lastfm_pending_token'])): ?>
                <p style="margin-top:16px">If Last.fm showed &ldquo;Application authenticated&rdquo; but didn&rsquo;t redirect back, click below to complete the auth:</p>
                <a class="button" style="background:#1a6b3a" href="?complete=1">Complete authentication</a>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Config Check</h2>
        <ul>
            <li>API key: <?php echo $config['api_key'] !== '' ? 'set' : 'missing'; ?></li>
            <li>Shared secret: <?php echo $config['shared_secret'] !== '' ? 'set' : 'missing'; ?></li>
            <li>Callback URL: <code><?php echo h($config['callback_url']); ?></code></li>
            <li>Session key: <?php echo app_session_key() !== '' ? 'saved' : 'not yet saved'; ?></li>
        </ul>
    </main>
</body>
</html>