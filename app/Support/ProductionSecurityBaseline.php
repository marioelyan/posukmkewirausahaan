<?php

namespace App\Support;

class ProductionSecurityBaseline
{
    /**
     * @return array<int, array{key: string, message: string}>
     */
    public static function issues(): array
    {
        if (config('app.env') !== 'production') {
            return [];
        }

        $issues = [];

        if (config('app.debug')) {
            $issues[] = [
                'key' => 'app_debug',
                'message' => 'APP_DEBUG masih aktif. Nonaktifkan debug di production.',
            ];
        }

        $appUrl = (string) config('app.url');

        if (blank($appUrl) || ! str_starts_with($appUrl, 'https://')) {
            $issues[] = [
                'key' => 'app_url_https',
                'message' => 'APP_URL harus menggunakan HTTPS yang valid di production.',
            ];
        }

        if (config('session.secure') !== true) {
            $issues[] = [
                'key' => 'session_secure_cookie',
                'message' => 'SESSION_SECURE_COOKIE harus bernilai true di production.',
            ];
        }

        return $issues;
    }

    public static function hasIssues(): bool
    {
        return self::issues() !== [];
    }
}
