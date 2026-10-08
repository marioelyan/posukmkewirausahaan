<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), accelerometer=(), gyroscope=()'
        );

        // HSTS only over HTTPS in production to avoid locking out plain-HTTP dev.
        if ($request->secure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP enforced. Google Fonts hosts are allowed for style/font sources.
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        // Vite dev server (npm run dev) serves modules + HMR from its own origin
        // (127.0.0.1:5173), a different origin than the app (:8000). A strict
        // script-src 'self' blocks those modules and blanks the page, so allow
        // that origin only outside production.
        $isLocal = app()->environment('local');
        $devAssets = $isLocal ? ' http://127.0.0.1:5173 http://localhost:5173' : '';
        $devWs = $isLocal ? ' ws://127.0.0.1:5173 ws://localhost:5173' : '';

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' data: https://fonts.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com{$devAssets}",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'{$devAssets}",
            "connect-src 'self'{$devAssets}{$devWs}",
        ];

        // Production only: upgrade-insecure-requests would rewrite the plain-HTTP
        // Vite dev URLs to HTTPS and break local development.
        if (! $isLocal) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
