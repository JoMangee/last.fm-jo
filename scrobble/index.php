<?php
define(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/lastfm.php';

header('Content-Type: application/json');

// Authentication token for GET requests


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $raw = (string)file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    // Validate bit secret - configurable timestamp comparison prevents timing attacks
    $bitSecret = bit_secret();
    if ($bitSecret === '' || !isset($body['secret']) || !hash_equals($bitSecret, (string)$body['secret'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $artist = trim((string)$body['artist'] ?: '');
    $track = trim((string)$body['track'] ?: '');
    $album = trim((string)$body['album'] ?: '');
} elseif ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // GET parameters
    $artist = trim((string)$_GET['artist'] ?: '');
    $track = trim((string)$_GET['track'] ?: '');
    $album = trim((string)$_GET['album'] ?: '');
    $salt = trim((string)$_GET['salt'] ?: '');
    $digest = trim((string)$_GET['digest'] ?: '');

    if ($artist == '' || $track == '' || $salt == '' || $digest == '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'artist, track, salt, and digest are required']);
        exit;
    }

    // Compute expected digest: md5(token . $salt)
    $expected = md5(scrobble_token() . $salt);
    if (!hash_equals($expected, $digest)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if ($artist == '' || $track == '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'artist and track are required']);
    exit;
}

$config = api_config();
$sessionKey = api_session_key();

if ($sessionKey == '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Last.fm not authenticated - visit /lastfm to connect']);
    exit;
}

$result = lastfm_scrobble(
    $config['api_key'],
    $config['shared_secret'],
    $sessionKey,
    $artist,
    $track,
    $album
);

http_response_code($result['ok'] ? 200 : 500);
echo json_encode($result);