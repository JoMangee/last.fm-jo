<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/spotify.php';

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

$action = trim((string)($body['action'] ?? ''));
$data   = trim((string)($body['data'] ?? ''));

$tokenResult = spotify_get_valid_token();
if (!$tokenResult['ok']) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => $tokenResult['error'] ?? 'Not authenticated with Spotify']);
    exit;
}

$token = (string)$tokenResult['token'];

switch ($action) {
    case 'top_tracks':
        $limit     = max(1, min(50, (int)($body['limit'] ?? 5)));
        $timeRange = in_array($data, ['short_term', 'medium_term', 'long_term'], true)
            ? $data : 'long_term';
        $result = spotify_get_top_tracks($token, $limit, $timeRange);
        if ($result['ok']) {
            $result['tracks'] = array_map(fn($t) => [
                'name'    => (string)($t['name'] ?? ''),
                'artists' => implode(', ', array_map(
                    fn($a) => (string)($a['name'] ?? ''),
                    (array)($t['artists'] ?? [])
                )),
                'album'   => (string)($t['album']['name'] ?? ''),
                'uri'     => (string)($t['uri'] ?? ''),
            ], $result['tracks']);
        }
        break;

    case 'play':
        // data = Spotify URI (spotify:track:xxx / spotify:album:xxx / spotify:playlist:xxx)
        // or empty string to resume current playback
        $result = spotify_play($token, $data);
        break;

    case 'status':
        $result = spotify_get_playback_state($token);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action. Valid: top_tracks, play, status']);
        exit;
}

http_response_code($result['ok'] ? 200 : 502);
echo json_encode($result);
