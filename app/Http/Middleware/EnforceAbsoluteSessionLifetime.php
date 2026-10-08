<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAbsoluteSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $startedAt = (int) $request->session()->get('security.session_started_at', 0);

        if ($startedAt <= 0) {
            $request->session()->put('security.session_started_at', now()->timestamp);

            return $next($request);
        }

        $absoluteLifetime = (int) config('security.session.absolute_lifetime_seconds', 43200);

        if ((now()->timestamp - $startedAt) <= $absoluteLifetime) {
            return $next($request);
        }

        app(AuditLogService::class)->log(
            event: 'security.session_expired_absolute',
            module: 'security',
            auditable: $request->user(),
            description: 'Sesi berakhir karena melewati batas waktu absolut.',
            meta: [
                'severity' => 'warning',
                'route' => $request->route()?->getName(),
            ],
        );

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('error', 'Sesi Anda sudah berakhir. Silakan masuk kembali.');
    }
}
