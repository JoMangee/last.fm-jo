<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/lastfm.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw  = (string)file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// Validate bot secret — constant-time compare prevents timing attacks
$botSecret = bot_secret();
if ($botSecret === '' || !isset($body['secret']) || !hash_equals($botSecret, (string)$body['secret'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$artist = trim((string)($body['artist'] ?? ''));
$track  = trim((string)($body['track'] ?? ''));
$album  = trim((string)($body['album'] ?? ''));

if ($artist === '' || $track === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'artist and track are required']);
    exit;
}

$config     = app_config();
$sessionKey = app_session_key();

if ($sessionKey === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Last.fm not authenticated — visit /lastfm to connect']);
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

http_response_code($result['ok'] ? 200 : 502);
echo json_encode($result);
