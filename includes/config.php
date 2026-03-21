<?php
declare(strict_types=1);

/**
 * Load a .env file into the process environment.
 *
 * Reads KEY=VALUE pairs from the given file path and injects them via
 * putenv()/$_ENV. Lines beginning with # and blank lines are ignored.
 * Existing environment variables are never overwritten.
 *
 * @param string $filePath Absolute path to the .env file.
 */
function load_env_file(string $filePath): void
{
    if (!is_file($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($key === '') {
            continue;
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

load_env_file(__DIR__ . '/../.env');

/**
 * Return the application configuration values read from the environment.
 *
 * @return array{api_key: string, shared_secret: string, callback_url: string}
 */
function app_config(): array
{
    return [
        'api_key'       => getenv('LASTFM_API_KEY') ?: '',
        'shared_secret' => getenv('LASTFM_SHARED_SECRET') ?: '',
        'callback_url'  => getenv('LASTFM_CALLBACK_URL') ?: '',
    ];
}

/**
 * Read the persisted Last.fm session key from data/session.json.
 *
 * Returns an empty string if the file does not exist or is unreadable.
 *
 * @return string The session key, or '' if not yet saved.
 */
function app_session_key(): string
{
    $file = __DIR__ . '/../data/session.json';
    if (!is_file($file)) {
        return '';
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? (string)($data['session_key'] ?? '') : '';
}

/**
 * Return the Spotify application configuration values from the environment.
 *
 * @return array{client_id: string, client_secret: string, redirect_uri: string}
 */
function spotify_config(): array
{
    return [
        'client_id'     => getenv('SPOTIFY_CLIENT_ID') ?: '',
        'client_secret' => getenv('SPOTIFY_CLIENT_SECRET') ?: '',
        'redirect_uri'  => getenv('SPOTIFY_REDIRECT_URI') ?: '',
    ];
}

/**
 * Read the persisted Spotify session data from data/spotify_session.json.
 *
 * Returns null if the file does not exist or is unreadable.
 *
 * @return array{access_token: string, refresh_token: string, expires_at: int, username?: string}|null
 */
function spotify_session_data(): ?array
{
    $file = __DIR__ . '/../data/spotify_session.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
