<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Tenancy\BusinessProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Q6-A — platform business management (super-admin only).
 *
 * Routes are gated by the `platform.admin` middleware and intentionally sit
 * outside the `business.required` group: managing businesses must be possible
 * without an active business context (e.g. to un-suspend a suspended install).
 *
 * Lifecycle: index / create / store / update (rename) / updateStatus. There is
 * no destroy — a business cannot be hard-deleted; suspend is the safe lifecycle
 * op. Suspending the last active business is blocked so tenancy can never end
 * up with zero active tenants (which would fail closed for every context).
 */
class BusinessAdminController extends Controller
{
    public function index(): Response
    {
        $businesses = Business::query()
            ->withCount(['outlets', 'memberships'])
            ->with('owner:id,name,email')
            ->orderBy('id')
            ->get()
            ->map(function (Business $business) {
                return [
                    'id' => $business->id,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'status' => $business->status,
                    'currency' => $business->currency,
                    'owner' => $business->owner?->only(['id', 'name', 'email']),
                    'outlets_count' => $business->outlets_count,
                    'memberships_count' => $business->memberships_count,
                    'created_at' => $business->created_at?->toISOString(),
                ];
            })
            ->values();

        return Inertia::render('Dashboard/Businesses/Index', [
            'businesses' => $businesses,
            'multiBusinessEnabled' => (bool) config('multi_business.enabled'),
        ]);
    }

    public function create(): Response
    {
        $users = User::query()
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => $user->only(['id', 'name', 'email']))
            ->values();

        return Inertia::render('Dashboard/Businesses/Create', [
            'users' => $users,
        ]);
    }

    /**
     * C1 — business detail + membership management.
     *
     * Lists the business membership (role/status) and the outlet access each
     * member currently has. Candidate users exclude super-admins (they use the
     * platform path) and existing members.
     */
    public function show(Business $business): Response
    {
        $business->loadCount(['outlets', 'memberships']);

        $members = $business->memberships()
            ->with([
                'user:id,name,email,avatar',
                'user.outlets',
                'defaultOutlet:id,code,name',
            ])
            ->orderBy('id')
            ->get()
            ->map(function (BusinessUser $member) use ($business) {
                $outletIds = $member->user
                    ? $member->user->outlets
                        ->where('business_id', $business->id)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all()
                    : [];

                return [
                    'id' => $member->id,
                    'user' => $member->user?->only(['id', 'name', 'email', 'avatar']),
                    'role' => $member->role,
                    'status' => $member->status,
                    'default_outlet' => $member->defaultOutlet?->only(['id', 'code', 'name']),
                    'outlet_ids' => $outletIds,
                    'joined_at' => $member->joined_at?->toISOString(),
                    'is_owner' => $business->owner_user_id !== null
                        && (int) $member->user_id === (int) $business->owner_user_id,
                ];
            })
            ->values();

        $memberUserIds = $business->memberships()->pluck('user_id');

        $candidateUsers = User::query()
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super-admin'))
            ->whereNotIn('id', $memberUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => $user->only(['id', 'name', 'email']))
            ->values();

        return Inertia::render('Dashboard/Businesses/Show', [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'status' => $business->status,
                'currency' => $business->currency,
                'owner_user_id' => $business->owner_user_id,
                'outlets_count' => $business->outlets_count,
                'memberships_count' => $business->memberships_count,
            ],
            'members' => $members,
            'candidateUsers' => $candidateUsers,
            'outlets' => $business->outlets()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn ($outlet) => [
                    'id' => $outlet->id,
                    'code' => $outlet->code,
                    'name' => $outlet->name,
                ])
                ->values(),
            'roles' => BusinessUser::ROLES,
            'statuses' => BusinessUser::STATUSES,
        ]);
    }

    public function store(Request $request, BusinessProvisioner $provisioner): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'owner_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $user = User::find($value);

                        if ($user && $user->isSuperAdmin()) {
                            $fail('Super-admin tidak dapat dijadikan owner business (jalur platform AUTH-01).');
                        }
                    }
                },
            ],
        ]);

        $owner = $validated['owner_id'] ?? null
            ? User::find($validated['owner_id'])
            : null;

        $business = $provisioner->provision($validated['name'], $owner);

        return redirect()->route('businesses.index')
            ->with('success', "Business '{$business->name}' berhasil dibuat.");
    }

    public function update(Request $request, Business $business, AuditLogService $auditLog): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $before = $business->name;
        $business->update(['name' => trim($validated['name'])]);

        $auditLog->log(
            event: 'business.updated',
            module: 'business',
            auditable: $business,
            description: 'Nama business diperbarui.',
            before: ['name' => $before],
            after: ['name' => $business->name],
        );

        return redirect()->route('businesses.index')
            ->with('success', "Business '{$business->name}' diperbarui.");
    }

    public function updateStatus(Request $request, Business $business, AuditLogService $auditLog): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:active,suspended',
        ]);

        if ($business->status === Business::STATUS_ACTIVE
            && $validated['status'] === Business::STATUS_SUSPENDED
            && Business::active()->count() <= 1) {
            abort(422, 'Tidak dapat menonaktifkan satu-satunya business aktif.');
        }

        $before = $business->status;
        $business->update(['status' => $validated['status']]);

        $auditLog->log(
            event: 'business.status_updated',
            module: 'business',
            auditable: $business,
            description: 'Status business diubah.',
            before: ['status' => $before],
            after: ['status' => $business->status],
        );

        return redirect()->route('businesses.index')
            ->with('success', "Status business '{$business->name}' diperbarui.");
    }
}
