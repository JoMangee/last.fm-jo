<?php
declare(strict_types=1);

/**
 * Spotify API helpers.
 *
 * Implements the Spotify Authorization Code Flow:
 *   1. spotify_get_auth_url()    — build the URL that sends the user to Spotify
 *   2. spotify_exchange_code()   — exchange the auth code for access/refresh tokens
 *   3. spotify_refresh_token()   — get a new access token from a refresh token
 *
 * Authenticated calls:
 *   4. spotify_api_get()         — send a GET request with a Bearer token
 *   5. spotify_get_top_tracks()  — fetch the user's top tracks
 */

/**
 * Build the Spotify authorization URL to send the user to.
 *
 * @param string $clientId    Spotify application client ID.
 * @param string $redirectUri Registered redirect URI.
 * @param string $state       Random CSRF state token.
 * @param string $scope       Space-separated list of scopes.
 * @return string Full authorization URL.
 */
function spotify_get_auth_url(
    string $clientId,
    string $redirectUri,
    string $state,
    string $scope = 'user-top-read'
): string {
    return 'https://accounts.spotify.com/authorize?' . http_build_query([
        'client_id'     => $clientId,
        'response_type' => 'code',
        'redirect_uri'  => $redirectUri,
        'state'         => $state,
        'scope'         => $scope,
    ]);
}

/**
 * Perform a POST request to the Spotify Accounts service.
 *
 * @param string $url     Target URL.
 * @param array  $fields  POST body as key/value pairs.
 * @param array  $headers Additional HTTP headers.
 * @return array{ok: bool, status?: int, body?: string, error?: string}
 */
function spotify_http_post(string $url, array $fields, array $headers = []): array
{
    $body = http_build_query($fields);
    $allHeaders = array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'HTTP request failed'];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string)$response];
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $allHeaders),
            'content' => $body,
            'timeout' => 20,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['ok' => false, 'error' => 'HTTP request failed'];
    }

    return ['ok' => true, 'status' => 200, 'body' => $response];
}

/**
 * Perform a Bearer-authenticated GET request to the Spotify Web API.
 *
 * @param string $endpoint Spotify API endpoint (e.g. 'v1/me/top/tracks').
 * @param string $token    Access token.
 * @return array{ok: bool, status?: int, data?: array, error?: string}
 */
function spotify_api_get(string $endpoint, string $token): array
{
    $url = 'https://api.spotify.com/' . ltrim($endpoint, '/');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'HTTP request failed'];
        }
    } else {
        $context  = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
                'timeout' => 20,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status   = 200;
        if ($response === false) {
            return ['ok' => false, 'error' => 'HTTP request failed'];
        }
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from Spotify'];
    }

    if (isset($decoded['error'])) {
        $msg = (string)($decoded['error']['message'] ?? 'Spotify API error');
        return ['ok' => false, 'status' => $status, 'error' => $msg];
    }

    return ['ok' => true, 'status' => $status, 'data' => $decoded];
}

/**
 * Exchange an authorization code for access and refresh tokens.
 *
 * @param string $clientId     Spotify client ID.
 * @param string $clientSecret Spotify client secret.
 * @param string $code         Authorization code received from Spotify.
 * @param string $redirectUri  Must match the URI used in the authorization request.
 * @return array{ok: bool, access_token?: string, refresh_token?: string, expires_in?: int, error?: string}
 */
function spotify_exchange_code(
    string $clientId,
    string $clientSecret,
    string $code,
    string $redirectUri
): array {
    $credentials = base64_encode($clientId . ':' . $clientSecret);

    $result = spotify_http_post(
        'https://accounts.spotify.com/api/token',
        [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ],
        ['Authorization: Basic ' . $credentials]
    );

    if (!$result['ok']) {
        return $result;
    }

    $decoded = json_decode((string)$result['body'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from Spotify token endpoint'];
    }

    if (isset($decoded['error'])) {
        $desc = (string)($decoded['error_description'] ?? $decoded['error']);
        return ['ok' => false, 'error' => $desc];
    }

    return [
        'ok'            => true,
        'access_token'  => (string)($decoded['access_token'] ?? ''),
        'refresh_token' => (string)($decoded['refresh_token'] ?? ''),
        'expires_in'    => (int)($decoded['expires_in'] ?? 3600),
    ];
}

/**
 * Refresh an expired access token using a stored refresh token.
 *
 * @param string $clientId     Spotify client ID.
 * @param string $clientSecret Spotify client secret.
 * @param string $refreshToken Previously obtained refresh token.
 * @return array{ok: bool, access_token?: string, expires_in?: int, error?: string}
 */
function spotify_refresh_token(
    string $clientId,
    string $clientSecret,
    string $refreshToken
): array {
    $credentials = base64_encode($clientId . ':' . $clientSecret);

    $result = spotify_http_post(
        'https://accounts.spotify.com/api/token',
        [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ],
        ['Authorization: Basic ' . $credentials]
    );

    if (!$result['ok']) {
        return $result;
    }

    $decoded = json_decode((string)$result['body'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from Spotify token endpoint'];
    }

    if (isset($decoded['error'])) {
        $desc = (string)($decoded['error_description'] ?? $decoded['error']);
        return ['ok' => false, 'error' => $desc];
    }

    return [
        'ok'           => true,
        'access_token' => (string)($decoded['access_token'] ?? ''),
        'expires_in'   => (int)($decoded['expires_in'] ?? 3600),
    ];
}

/**
 * Fetch the authenticated user's top tracks.
 *
 * @param string $accessToken  Valid Spotify access token.
 * @param int    $limit        Number of tracks to return (1–50).
 * @param string $timeRange    'short_term' | 'medium_term' | 'long_term'
 * @return array{ok: bool, tracks?: array, error?: string}
 */
function spotify_get_top_tracks(
    string $accessToken,
    int $limit = 5,
    string $timeRange = 'long_term'
): array {
    $limit = max(1, min(50, $limit));
    $endpoint = 'v1/me/top/tracks?' . http_build_query([
        'time_range' => $timeRange,
        'limit'      => $limit,
    ]);

    $result = spotify_api_get($endpoint, $accessToken);
    if (!$result['ok']) {
        return $result;
    }

    $items = $result['data']['items'] ?? [];
    return ['ok' => true, 'tracks' => $items];
}

/**
 * Perform a Bearer-authenticated PUT request to the Spotify Web API.
 *
 * Returns ['ok' => true] on 204 No Content (common for playback endpoints).
 *
 * @param string     $endpoint Spotify API endpoint.
 * @param string     $token    Access token.
 * @param array|null $body     Optional JSON body.
 * @return array{ok: bool, status?: int, data?: array, error?: string}
 */
function spotify_api_put(string $endpoint, string $token, ?array $body = null): array
{
    $url      = 'https://api.spotify.com/' . ltrim($endpoint, '/');
    $jsonBody = $body !== null ? (string)json_encode($body) : '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'HTTP request failed'];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'PUT',
                'header'  => 'Authorization: Bearer ' . $token . "\r\nContent-Type: application/json\r\n",
                'content' => $jsonBody,
                'timeout' => 20,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status   = 200;
        if ($response === false) {
            return ['ok' => false, 'error' => 'HTTP request failed'];
        }
    }

    // 204 No Content is success for playback endpoints
    if ($status === 204 || (string)$response === '') {
        return ['ok' => true, 'status' => $status];
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        return ['ok' => $status < 400, 'status' => $status];
    }

    if (isset($decoded['error'])) {
        $msg = (string)($decoded['error']['message'] ?? 'Spotify API error');
        return ['ok' => false, 'status' => $status, 'error' => $msg];
    }

    return ['ok' => true, 'status' => $status, 'data' => $decoded];
}

/**
 * Get the current Spotify playback state.
 *
 * @param string $accessToken Valid access token.
 * @return array{ok: bool, playing?: bool, track?: string, artists?: string, device?: string, error?: string}
 */
function spotify_get_playback_state(string $accessToken): array
{
    $result = spotify_api_get('v1/me/player', $accessToken);
    if (!$result['ok']) {
        return $result;
    }

    // 204 means no active device
    if (($result['status'] ?? 0) === 204 || empty($result['data'])) {
        return ['ok' => true, 'playing' => false, 'status_text' => 'No active device'];
    }

    $data = $result['data'];
    return [
        'ok'      => true,
        'playing' => (bool)($data['is_playing'] ?? false),
        'track'   => (string)($data['item']['name'] ?? ''),
        'artists' => implode(', ', array_map(
            fn($a) => (string)($a['name'] ?? ''),
            (array)($data['item']['artists'] ?? [])
        )),
        'album'   => (string)($data['item']['album']['name'] ?? ''),
        'device'  => (string)($data['device']['name'] ?? ''),
    ];
}

/**
 * Start or resume Spotify playback, optionally for a specific URI.
 *
 * @param string $accessToken Valid access token.
 * @param string $uri         Spotify URI (track/album/playlist), or '' to resume.
 * @return array{ok: bool, status?: int, error?: string}
 */
function spotify_play(string $accessToken, string $uri = ''): array
{
    $body = $uri !== '' ? ['uris' => [$uri]] : null;
    return spotify_api_put('v1/me/player/play', $accessToken, $body);
}

/**
 * Return a valid (auto-refreshed) Spotify access token from the saved session.
 *
 * Saves the refreshed token back to data/spotify_session.json if refreshed.
 *
 * @return array{ok: bool, token?: string, error?: string}
 */
function spotify_get_valid_token(): array
{
    $session = spotify_session_data();
    if ($session === null) {
        return ['ok' => false, 'error' => 'Not authenticated with Spotify'];
    }

    $accessToken = (string)($session['access_token'] ?? '');
    $expiresAt   = (int)($session['expires_at'] ?? 0);

    if (time() >= $expiresAt - 60) {
        $config        = spotify_config();
        $refreshResult = spotify_refresh_token(
            $config['client_id'],
            $config['client_secret'],
            (string)($session['refresh_token'] ?? '')
        );
        if (!$refreshResult['ok']) {
            return ['ok' => false, 'error' => 'Token refresh failed: ' . ($refreshResult['error'] ?? 'unknown')];
        }
        $accessToken             = (string)$refreshResult['access_token'];
        $session['access_token'] = $accessToken;
        $session['expires_at']   = time() + (int)$refreshResult['expires_in'];
        $dataFile = __DIR__ . '/../data/spotify_session.json';
        file_put_contents($dataFile, (string)json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($dataFile, 0600);
    }

    return ['ok' => true, 'token' => $accessToken];
}
