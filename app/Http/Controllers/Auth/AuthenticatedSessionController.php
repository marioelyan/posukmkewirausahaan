<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Business;
use App\Services\AuditLogService;
use App\Services\BusinessMembershipResolver;
use App\Support\BotGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly BusinessMembershipResolver $membershipResolver,
    ) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'canRegister' => config('security.auth.public_registration'),
            'status' => session('status'),
            'botGuard' => BotGuard::payload(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $request->session()->put('security.session_started_at', now()->timestamp);

        $user = $request->user();

        $this->auditLogService->log(
            event: 'auth.login_succeeded',
            module: 'auth',
            auditable: $user,
            description: 'Login berhasil.',
            meta: [
                'severity' => 'info',
                'route' => $request->route()?->getName(),
                'remember' => $request->boolean('remember'),
            ],
        );

        $routePriority = [
            'transactions-access' => 'transactions.index',
            'receivables-access' => 'receivables.index',
            'payables-access' => 'payables.index',
            'customers-access' => 'customers.index',
            'suppliers-access' => 'suppliers.index',
            'reports-access' => 'reports.sales.index',
            'dashboard-access' => 'dashboard',
        ];

        $defaultRoute = 'dashboard.access';
        foreach ($routePriority as $permission => $routeName) {
            if ($user && $user->can($permission)) {
                $defaultRoute = $routeName;
                break;
            }
        }

        $contextResolved = $this->establishBusinessContext($request);

        // A user that cannot be placed inside any active business would hit
        // `business.required` (403) on every route above. Land them somewhere
        // that is outside that group instead: the super-admin can activate or
        // create a business there, everyone else sees the "no access" page.
        // Enforcement itself is untouched — reaching those routes directly
        // without a context still returns 403.
        if (! $contextResolved) {
            return redirect()->route(
                $user->isSuperAdmin() ? 'businesses.index' : 'dashboard.access'
            );
        }

        return redirect()->intended(route($defaultRoute, absolute: false));
    }

    /**
     * Normalise `active_business_id` for the brand new session.
     *
     * The switcher is rendered inside the guarded dashboard, so a member who
     * reaches the dashboard without a selection has no way to make one: that is
     * the logout → login → 403 lockout. Writing a selection that is *verified
     * against their membership* on every later request means a member always
     * lands in a business they are entitled to (and can switch from there),
     * while a super-admin keeps the dynamic first-active-business fallback —
     * any leftover stale selection is dropped so it cannot fail closed.
     *
     * @return bool whether the next request will be able to resolve a context
     */
    private function establishBusinessContext(LoginRequest $request): bool
    {
        if (! config('multi_business.enabled')) {
            // Legacy mode: no business context is ever required.
            return true;
        }

        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $request->session()->forget('active_business_id');

            return Business::query()
                ->where('status', Business::STATUS_ACTIVE)
                ->exists();
        }

        $membership = $this->membershipResolver->membershipOwningOpenShiftFor($user)
            ?? $this->membershipResolver->preferredActiveMembershipFor($user);

        if (! $membership) {
            $request->session()->forget('active_business_id');

            return false;
        }

        $request->session()->put('active_business_id', $membership->business_id);

        return true;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->auditLogService->log(
            event: 'auth.logout',
            module: 'auth',
            auditable: $request->user(),
            description: 'Logout berhasil.',
            meta: [
                'severity' => 'info',
                'route' => $request->route()?->getName(),
            ],
        );

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
