<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/spotify.php';

session_start();

$config = spotify_config();
$error  = '';
$username = '';

$code  = isset($_GET['code'])  ? trim((string)$_GET['code'])  : '';
$state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';

// Spotify can also return an error query param (e.g. user denied access)
if (isset($_GET['error'])) {
    $error = 'Spotify authorization denied: ' . htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8');
} elseif ($code === '') {
    $error = 'No authorization code received from Spotify.';
} elseif ($config['client_id'] === '' || $config['client_secret'] === '') {
    $error = 'Missing SPOTIFY_CLIENT_ID or SPOTIFY_CLIENT_SECRET in .env';
} else {
    // Validate CSRF state
    $expectedState = (string)($_SESSION['spotify_oauth_state'] ?? '');
    if ($expectedState === '' || !hash_equals($expectedState, $state)) {
        $error = 'State mismatch — possible CSRF. Please try connecting again.';
    } else {
        unset($_SESSION['spotify_oauth_state']);

        // Exchange the code for tokens
        $tokenResult = spotify_exchange_code(
            $config['client_id'],
            $config['client_secret'],
            $code,
            $config['redirect_uri']
        );

        if (!$tokenResult['ok']) {
            $error = 'Could not exchange code: ' . (string)($tokenResult['error'] ?? 'unknown error');
        } else {
            // Fetch the user's display name from the profile endpoint
            $profileResult = spotify_api_get('v1/me', (string)$tokenResult['access_token']);
            $username = $profileResult['ok']
                ? (string)($profileResult['data']['display_name'] ?? $profileResult['data']['id'] ?? 'unknown')
                : 'unknown';

            $dataDir = realpath(__DIR__ . '/../../data') ?: (__DIR__ . '/../../data');
            if (!is_dir($dataDir)) {
                if (!mkdir($dataDir, 0750, true)) {
                    $error = 'Could not create data directory: ' . $dataDir;
                }
            }

            if ($error === '') {
                $payload = json_encode([
                    'access_token'  => $tokenResult['access_token'],
                    'refresh_token' => $tokenResult['refresh_token'],
                    'expires_at'    => time() + (int)$tokenResult['expires_in'],
                    'username'      => $username,
                    'saved_at'      => date('c'),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                $written = file_put_contents($dataDir . '/spotify_session.json', $payload);
                if ($written === false) {
                    $error = 'Could not write spotify_session.json. Check write permissions on: ' . $dataDir;
                } else {
                    chmod($dataDir . '/spotify_session.json', 0600);
                }
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
    <title>LastBot - Spotify Callback</title>
    <style>
        :root {
            --bg: #f3f2ed;
            --card: #ffffff;
            --ink: #1e1f23;
            --accent: #1db954;
            --accent-dark: #158a3e;
            --muted: #58606d;
            --line: #d9dce3;
        }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            color: var(--ink);
            background: radial-gradient(circle at 20% 10%, #f0fff4, var(--bg));
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .card {
            width: min(560px, 100%);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.06);
            text-align: center;
        }
        h1 { margin-top: 0; font-size: 1.6rem; }
        p { color: var(--muted); line-height: 1.5; }
        .error {
            padding: 12px;
            border-radius: 10px;
            background: #ffe6e6;
            color: #771717;
            border: 1px solid #f4b5b5;
            margin-bottom: 12px;
            text-align: left;
        }
        .success {
            padding: 12px;
            border-radius: 10px;
            background: #dcfce7;
            color: #14532d;
            border: 1px solid #86efac;
            margin-bottom: 12px;
        }
        .button {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .button:hover { background: var(--accent-dark); }
    </style>
</head>
<body>
<div class="card">
    <h1>&#127911; Spotify</h1>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
        <a class="button" href="/spotify">Try again</a>
    <?php else: ?>
        <div class="success">
            &#10003; Authenticated as <strong><?= h($username) ?></strong> — tokens saved.
        </div>
        <p>You can now close this page or continue to the Spotify dashboard.</p>
        <a class="button" href="/spotify">View top tracks</a>
    <?php endif ?>
</div>
</body>
</html>
