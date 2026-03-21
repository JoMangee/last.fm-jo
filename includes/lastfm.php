<?php
declare(strict_types=1);

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
