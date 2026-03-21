<?php
declare(strict_types=1);

/**
 * Last.fm API helpers.
 *
 * Implements the three-step Last.fm web auth flow:
 *   1. lastfm_get_token()   — request an unauthorised token
 *   2. lastfm_auth_url()    — build the URL that sends the user to Last.fm
 *   3. lastfm_get_session() — exchange the authorised token for a session key
 *
 * All functions return an associative array with at minimum:
 *   ['ok' => true,  ...]  on success
 *   ['ok' => false, 'error' => string] on failure
 */

/**
 * Build the API method signature required by authenticated Last.fm calls.
 *
 * Concatenates sorted key/value pairs (excluding 'format'), appends the
 * shared secret, and returns the MD5 hex digest.
 *
 * @param array  $params       API parameters to sign.
 * @param string $sharedSecret Application shared secret from Last.fm.
 * @return string 32-character MD5 hex signature.
 */
function lastfm_build_signature(array $params, string $sharedSecret): string
{
    unset($params['format']);

    ksort($params);

    $base = '';
    foreach ($params as $key => $value) {
        $base .= (string)$key . (string)$value;
    }

    return md5($base . $sharedSecret);
}

/**
 * Perform a GET request, preferring cURL over file_get_contents.
 *
 * @param string $url Fully-formed URL including query string.
 * @return array{ok: bool, status?: int, body?: string, error?: string}
 */
function lastfm_http_get(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'HTTP request failed'];
        }

        return ['ok' => true, 'status' => $status, 'body' => $body];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "Accept: application/json\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['ok' => false, 'error' => 'HTTP request failed'];
    }

    return ['ok' => true, 'status' => 200, 'body' => $body];
}

/**
 * Request an unauthorised token from Last.fm (auth.getToken).
 *
 * The token must be authorised by the user via lastfm_auth_url() before
 * it can be exchanged for a session key.
 *
 * @param string $apiKey       Application API key.
 * @param string $sharedSecret Application shared secret.
 * @return array{ok: bool, token?: string, error?: string}
 */
function lastfm_get_token(string $apiKey, string $sharedSecret): array
{
    $params = [
        'api_key' => $apiKey,
        'method' => 'auth.getToken',
    ];

    $params['api_sig'] = lastfm_build_signature($params, $sharedSecret);
    $params['format'] = 'json';

    $url = 'https://ws.audioscrobbler.com/2.0/?' . http_build_query($params);
    $response = lastfm_http_get($url);
    if (!$response['ok']) {
        return $response;
    }

    $decoded = json_decode((string)$response['body'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from Last.fm'];
    }

    if (isset($decoded['error'])) {
        return ['ok' => false, 'error' => (string)($decoded['message'] ?? 'Unknown Last.fm error')];
    }

    if (!isset($decoded['token'])) {
        return ['ok' => false, 'error' => 'No token returned by Last.fm'];
    }

    return ['ok' => true, 'token' => (string)$decoded['token']];
}

/**
 * Build the Last.fm authorisation URL to redirect the user to.
 *
 * After the user approves access, Last.fm sends them to $callbackUrl
 * with a `token` query parameter. If $callbackUrl is empty, Last.fm
 * shows a plain confirmation page instead of redirecting.
 *
 * @param string $apiKey      Application API key.
 * @param string $token       Unauthorised token from lastfm_get_token().
 * @param string $callbackUrl URL Last.fm will redirect back to (may be empty).
 * @return string Full authorisation URL.
 */
function lastfm_auth_url(string $apiKey, string $token, string $callbackUrl): string
{
    $query = [
        'api_key' => $apiKey,
        'token' => $token,
    ];

    if ($callbackUrl !== '') {
        $query['cb'] = $callbackUrl;
    }

    return 'https://www.last.fm/api/auth/?' . http_build_query($query);
}

/**
 * Exchange an authorised token for a persistent Last.fm session key (auth.getSession).
 *
 * The returned session key does not expire and should be stored securely
 * server-side. It is used to sign all subsequent authenticated API calls.
 *
 * @param string $apiKey       Application API key.
 * @param string $sharedSecret Application shared secret.
 * @param string $token        Token previously authorised by the user.
 * @return array{ok: bool, session?: array{key: string, name: string, subscriber: int}, error?: string}
 */
function lastfm_get_session(string $apiKey, string $sharedSecret, string $token): array
{
    $params = [
        'api_key' => $apiKey,
        'method' => 'auth.getSession',
        'token' => $token,
    ];

    $params['api_sig'] = lastfm_build_signature($params, $sharedSecret);
    $params['format'] = 'json';

    $url = 'https://ws.audioscrobbler.com/2.0/?' . http_build_query($params);
    $response = lastfm_http_get($url);
    if (!$response['ok']) {
        return $response;
    }

    $decoded = json_decode((string)$response['body'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from Last.fm'];
    }

    if (isset($decoded['error'])) {
        return ['ok' => false, 'error' => (string)($decoded['message'] ?? 'Unknown Last.fm error')];
    }

    if (!isset($decoded['session']) || !is_array($decoded['session'])) {
        return ['ok' => false, 'error' => 'No session returned by Last.fm'];
    }

    return ['ok' => true, 'session' => $decoded['session']];
}

/**
 * Perform a POST request to the Last.fm API.
 *
 * @param string $url    Target URL.
 * @param array  $fields POST body as key/value pairs.
 * @return array{ok: bool, status?: int, body?: string, error?: string}
 */
function lastfm_http_post(string $url, array $fields): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $error !== '' ? $error : 'HTTP request failed'];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string)$body];
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($fields),
            'timeout' => 20,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['ok' => false, 'error' => 'HTTP request failed'];
    }

    return ['ok' => true, 'status' => 200, 'body' => $body];
}

/**
 * Scrobble a track to Last.fm (track.scrobble).
 *
 * @param string $apiKey       Application API key.
 * @param string $sharedSecret Application shared secret.
 * @param string $sessionKey   Authenticated session key.
 * @param string $artist       Artist name.
 * @param string $track        Track name.
 * @param string $album        Album name (optional).
 * @param int    $timestamp    Unix timestamp of when the track was played (0 = now).
 * @return array{ok: bool, accepted?: int, error?: string}
 */
function lastfm_scrobble(
    string $apiKey,
    string $sharedSecret,
    string $sessionKey,
    string $artist,
    string $track,
    string $album = '',
    int $timestamp = 0
): array {
    if ($timestamp === 0) {
        $timestamp = time();
    }

    $params = [
        'api_key'      => $apiKey,
        'artist[0]'    => $artist,
        'method'       => 'track.scrobble',
        'sk'           => $sessionKey,
        'timestamp[0]' => (string)$timestamp,
        'track[0]'     => $track,
    ];

    if ($album !== '') {
        $params['album[0]'] = $album;
    }

    $params['api_sig'] = lastfm_build_signature($params, $sharedSecret);
    $params['format']  = 'json';

    $response = lastfm_http_post('https://ws.audioscrobbler.com/2.0/', $params);
    if (!$response['ok']) {
        return $response;
    }

    $decoded = json_decode((string)$response['body'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from Last.fm'];
    }

    if (isset($decoded['error'])) {
        return ['ok' => false, 'error' => (string)($decoded['message'] ?? 'Last.fm scrobble error')];
    }

    $accepted = (int)($decoded['scrobbles']['@attr']['accepted'] ?? 0);
    if ($accepted === 0) {
        $ignoredMsg = (string)($decoded['scrobbles']['scrobble']['ignoredMessage']['#text'] ?? 'Scrobble ignored');
        return ['ok' => false, 'error' => $ignoredMsg];
    }

    return ['ok' => true, 'accepted' => $accepted];
}
