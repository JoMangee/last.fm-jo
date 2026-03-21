<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/spotify.php';

session_start();

$config  = spotify_config();
$session = spotify_session_data();
$error   = '';
$tracks  = [];

// ── Connect: redirect user to Spotify authorization page ─────────────────────
if (isset($_GET['connect'])) {
    if ($config['client_id'] === '' || $config['client_secret'] === '') {
        $error = 'Missing SPOTIFY_CLIENT_ID or SPOTIFY_CLIENT_SECRET in .env';
    } elseif ($config['redirect_uri'] === '') {
        $error = 'Missing SPOTIFY_REDIRECT_URI in .env';
    } else {
        $state = bin2hex(random_bytes(16));
        $_SESSION['spotify_oauth_state'] = $state;
        $authUrl = spotify_get_auth_url($config['client_id'], $config['redirect_uri'], $state,
            'user-read-private user-top-read user-read-playback-state user-modify-playback-state user-read-currently-playing'
        );
        header('Location: ' . $authUrl);
        exit;
    }
}

// ── Disconnect: remove saved session ─────────────────────────────────────────
if (isset($_GET['disconnect'])) {
    $file = spotify_data_dir() . '/spotify_session.json';
    if (is_file($file)) {
        unlink($file);
    }
    unset($_SESSION['spotify_oauth_state']);
    header('Location: /spotify');
    exit;
}

// ── If connected, try to load top tracks (auto-refresh if expired) ───────────
if ($session !== null) {
    $accessToken = (string)($session['access_token'] ?? '');
    $expiresAt   = (int)($session['expires_at'] ?? 0);

    // Refresh the token if it has expired (with a 60-second buffer)
    if (time() >= $expiresAt - 60) {
        $refreshResult = spotify_refresh_token(
            $config['client_id'],
            $config['client_secret'],
            (string)($session['refresh_token'] ?? '')
        );
        if ($refreshResult['ok']) {
            $accessToken          = (string)$refreshResult['access_token'];
            $session['access_token'] = $accessToken;
            $session['expires_at']   = time() + (int)$refreshResult['expires_in'];
            $dataDir = spotify_data_dir();
            file_put_contents(
                $dataDir . '/spotify_session.json',
                (string)json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            chmod($dataDir . '/spotify_session.json', 0600);
        } else {
            $error = 'Could not refresh token: ' . (string)($refreshResult['error'] ?? 'unknown');
        }
    }

    if ($error === '' && $accessToken !== '') {
        $topResult = spotify_get_top_tracks($accessToken, 5, 'long_term');
        if ($topResult['ok']) {
            $tracks = $topResult['tracks'];
        } else {
            $error = 'Could not fetch top tracks: ' . (string)($topResult['error'] ?? 'unknown');
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
    <title>LastBot - Spotify</title>
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
            width: min(720px, 100%);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.06);
        }
        h1 { margin-top: 0; font-size: 1.8rem; }
        p, li { color: var(--muted); line-height: 1.5; }
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
            padding: 10px 20px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
        }
        .button:hover { background: var(--accent-dark); }
        .button-ghost {
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--line);
        }
        .button-ghost:hover { background: #f3f2ed; }
        hr { border: none; border-top: 1px solid var(--line); margin: 20px 0; }
        .status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-ok  { background: #dcfce7; color: #14532d; }
        .status-bad { background: #fee2e2; color: #7f1d1d; }
        .track-list { list-style: none; padding: 0; margin: 0; }
        .track-list li {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid var(--line);
        }
        .track-list li:last-child { border-bottom: none; }
        .track-num { color: var(--muted); width: 1.5rem; text-align: right; flex-shrink: 0; }
        .track-art  { width: 48px; height: 48px; border-radius: 6px; object-fit: cover; flex-shrink: 0; }
        .track-info { flex: 1; min-width: 0; }
        .track-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .track-artist { color: var(--muted); font-size: 0.85rem; }
        .nav { margin-bottom: 16px; }
        .nav a { color: var(--muted); text-decoration: none; font-size: 0.9rem; }
        .nav a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="nav"><a href="/">&larr; Back to LastBot</a></div>
    <h1>&#127911; Spotify</h1>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif ?>

    <?php if ($session === null): ?>
        <!-- ── Not connected ────────────────────────────────────────── -->
        <p>Connect your Spotify account to see your top tracks and allow this app to make API calls on your behalf.</p>

        <p>
            <strong>Config status:</strong><br>
            Client ID:
            <span class="status <?= $config['client_id'] !== '' ? 'status-ok' : 'status-bad' ?>">
                <?= $config['client_id'] !== '' ? 'set' : 'missing' ?>
            </span>
            &nbsp;
            Client secret:
            <span class="status <?= $config['client_secret'] !== '' ? 'status-ok' : 'status-bad' ?>">
                <?= $config['client_secret'] !== '' ? 'set' : 'missing' ?>
            </span>
            &nbsp;
            Redirect URI:
            <span class="status <?= $config['redirect_uri'] !== '' ? 'status-ok' : 'status-bad' ?>">
                <?= $config['redirect_uri'] !== '' ? 'set' : 'missing' ?>
            </span>
        </p>

        <a class="button" href="?connect">Connect with Spotify</a>

    <?php else: ?>
        <!-- ── Connected: show top tracks ───────────────────────────── -->
        <p>
            Connected as <strong><?= h((string)($session['username'] ?? 'unknown')) ?></strong>.
            <a class="button button-ghost" href="?disconnect" style="margin-left:12px;font-size:0.85rem;padding:6px 14px;">Disconnect</a>
        </p>

        <hr>
        <h2 style="margin-top:0;">Your top 5 tracks <small style="font-weight:normal;color:var(--muted);font-size:0.8rem;">(all time)</small></h2>

        <?php if (empty($tracks)): ?>
            <p>No tracks found.</p>
        <?php else: ?>
            <ol class="track-list">
                <?php foreach ($tracks as $i => $track):
                    $name    = h((string)($track['name'] ?? ''));
                    $artists = implode(', ', array_map(
                        fn($a) => h((string)($a['name'] ?? '')),
                        (array)($track['artists'] ?? [])
                    ));
                    $imgUrl = h((string)($track['album']['images'][2]['url'] ?? $track['album']['images'][0]['url'] ?? ''));
                    $spotifyUrl = h((string)($track['external_urls']['spotify'] ?? '#'));
                ?>
                <li>
                    <span class="track-num"><?= $i + 1 ?></span>
                    <?php if ($imgUrl !== ''): ?>
                        <img class="track-art" src="<?= $imgUrl ?>" alt="">
                    <?php endif ?>
                    <div class="track-info">
                        <div class="track-name">
                            <a href="<?= $spotifyUrl ?>" target="_blank" rel="noopener noreferrer"
                               style="color:inherit;text-decoration:none;"><?= $name ?></a>
                        </div>
                        <div class="track-artist"><?= $artists ?></div>
                    </div>
                </li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>
    <?php endif ?>
</div>
</body>
</html>
