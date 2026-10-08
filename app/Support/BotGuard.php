<?php

namespace App\Support;

use App\Services\AuditLogService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BotGuard
{
    public static function payload(): array
    {
        return [
            'enabled' => config('security.bot_guard.enabled', true),
            'honeypot_field' => config('security.bot_guard.honeypot_field', 'company_website'),
            'token_field' => config('security.bot_guard.token_field', 'bot_guard_token'),
            'token' => static::issueToken(),
        ];
    }

    public static function issueToken(): string
    {
        return Crypt::encryptString(json_encode([
            'iat' => now()->timestamp,
            'nonce' => Str::random(16),
        ], JSON_THROW_ON_ERROR));
    }

    public static function validate(Request $request): void
    {
        if (! config('security.bot_guard.enabled', true)) {
            return;
        }

        $honeypotField = config('security.bot_guard.honeypot_field', 'company_website');
        $tokenField = config('security.bot_guard.token_field', 'bot_guard_token');
        $message = config('security.bot_guard.message', 'Permintaan tidak valid. Silakan coba lagi.');

        $reason = null;

        if (filled($request->input($honeypotField))) {
            $reason = 'honeypot_filled';
        } else {
            $token = $request->string($tokenField)->toString();

            if (blank($token)) {
                $reason = 'missing_token';
            } else {
                try {
                    $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
                    $issuedAt = (int) ($payload['iat'] ?? 0);
                    $age = now()->timestamp - $issuedAt;

                    if ($issuedAt <= 0) {
                        $reason = 'invalid_token_payload';
                    } elseif ($age < config('security.bot_guard.min_submit_seconds', 2)) {
                        $reason = 'submitted_too_fast';
                    } elseif ($age > config('security.bot_guard.token_ttl_seconds', 1800)) {
                        $reason = 'expired_token';
                    }
                } catch (DecryptException|\JsonException) {
                    $reason = 'invalid_token';
                }
            }
        }

        if ($reason === null) {
            return;
        }

        app(AuditLogService::class)->log(
            event: 'security.bot_guard_blocked',
            module: 'security',
            auditable: ['target_label' => $request->route()?->getName() ?? $request->path()],
            description: 'Permintaan auth publik diblokir oleh bot guard.',
            meta: [
                'severity' => 'warning',
                'reason' => $reason,
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
            ],
        );

        throw ValidationException::withMessages([
            'human' => $message,
        ]);
    }
}
