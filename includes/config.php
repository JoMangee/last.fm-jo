<?php
declare(strict_types=1);

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

function app_config(): array
{
    return [
        'api_key' => getenv('LASTFM_API_KEY') ?: '',
        'shared_secret' => getenv('LASTFM_SHARED_SECRET') ?: '',
        'callback_url' => getenv('LASTFM_CALLBACK_URL') ?: '',
    ];
}
