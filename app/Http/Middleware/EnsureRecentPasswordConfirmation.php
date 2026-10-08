<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = (int) config('auth.password_timeout', 900);

        if ($confirmedAt > 0 && (time() - $confirmedAt) <= $timeout) {
            return $next($request);
        }

        $intendedUrl = $request->headers->get('referer') ?: route('dashboard.access');
        $request->session()->put('url.intended', $intendedUrl);
        $request->session()->put('security.step_up_context', [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'intended' => $intendedUrl,
        ]);

        app(AuditLogService::class)->log(
            event: 'security.privileged_action_challenged',
            module: 'security',
            auditable: ['target_label' => $request->route()?->getName() ?? $request->path()],
            description: 'Aksi sensitif memerlukan konfirmasi password ulang.',
            meta: [
                'severity' => 'high',
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
            ],
        );

        return redirect()->route('password.confirm');
    }
}
