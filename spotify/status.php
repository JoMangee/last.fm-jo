<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/spotify.php';

header('Content-Type: application/json');

$config  = spotify_config();
$session = spotify_session_data();

$report = [
    'env' => [
        'client_id_set'     => $config['client_id'] !== '',
        'client_secret_set' => $config['client_secret'] !== '',
        'redirect_uri'      => $config['redirect_uri'],
    ],
    'session_file' => [
        'data_dir'  => spotify_data_dir(),
        'file_path' => spotify_data_dir() . '/spotify_session.json',
        'exists'    => is_file(spotify_data_dir() . '/spotify_session.json'),
        'readable'  => is_readable(spotify_data_dir() . '/spotify_session.json'),
    ],
    'session' => null,
    'api_test' => null,
];

if ($session !== null) {
    $report['session'] = [
        'username'      => $session['username'] ?? '(missing)',
        'has_access'    => isset($session['access_token']) && $session['access_token'] !== '',
        'has_refresh'   => isset($session['refresh_token']) && $session['refresh_token'] !== '',
        'expires_at'    => $session['expires_at'] ?? 0,
        'expired'       => isset($session['expires_at']) && time() >= (int)$session['expires_at'],
        'saved_at'      => $session['saved_at'] ?? '(missing)',
        'token_preview' => isset($session['access_token'])
            ? substr((string)$session['access_token'], 0, 10) . '...'
            : '(empty)',
    ];

    // Try a lightweight API call
    $token = (string)($session['access_token'] ?? '');
    if ($token !== '') {
        $result = spotify_api_get('v1/me', $token);
        $report['api_test'] = $result;
    } else {
        $report['api_test'] = ['ok' => false, 'error' => 'No access token in session'];
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
