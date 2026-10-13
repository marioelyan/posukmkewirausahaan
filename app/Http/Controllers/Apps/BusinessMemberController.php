<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * C1 — platform-admin management of business memberships.
 *
 * A membership (`business_user`) is the tenancy source of truth for what a user
 * MAY DO inside a business (role -> BusinessRoleCapabilities). Outlet access is
 * a separate concern (`user_outlets`) and governs WHAT DATA the member sees, so
 * both are written together here: adding a member without outlet access would
 * leave them with permissions but no visible outlets/warehouses.
 *
 * Platform-admin only (routes sit in the `platform.admin` group). Writes require
 * a fresh password confirmation. Super-admins are never members — they keep the
 * explicit platform path (AUTH-01).
 */
class BusinessMemberController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function store(Request $request, Business $business): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('business_user', 'user_id')
                    ->where(fn ($query) => $query->where('business_id', $business->id)),
                function ($attribute, $value, $fail) {
                    $user = User::find($value);

                    if ($user && $user->isSuperAdmin()) {
                        $fail('Super-admin tidak dapat dijadikan anggota business (jalur platform AUTH-01).');
                    }
                },
            ],
            'role' => ['required', Rule::in(BusinessUser::ROLES)],
            'status' => ['nullable', Rule::in(BusinessUser::STATUSES)],
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['integer'],
            'default_outlet_id' => ['nullable', 'integer'],
        ]);

        $status = $validated['status'] ?? BusinessUser::STATUS_ACTIVE;

        $this->assertOutletsBelongToBusiness($business, $validated);
        [$outletIds, $defaultOutletId] = $this->resolveOutletAccess($business, $validated);

        DB::transaction(function () use ($business, $validated, $status, $outletIds, $defaultOutletId) {
            $member = BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => (int) $validated['user_id'],
                'role' => $validated['role'],
                'status' => $status,
                'default_outlet_id' => $defaultOutletId,
                'invited_by_user_id' => auth()->id(),
                'invited_at' => now(),
                'joined_at' => $status === BusinessUser::STATUS_ACTIVE ? now() : null,
            ]);

            $this->syncOutlets($business, (int) $member->user_id, $outletIds, $defaultOutletId);

            $this->auditLog->log(
                event: 'business.member.added',
                module: 'business',
                auditable: $business,
                description: 'Anggota business ditambahkan.',
                after: [
                    'member_user_id' => $member->user_id,
                    'role' => $member->role,
                    'status' => $member->status,
                    'outlet_ids' => $outletIds,
                ],
            );
        });

        return redirect()->route('businesses.show', $business->id)
            ->with('success', 'Anggota business ditambahkan.');
    }

    public function update(Request $request, Business $business, BusinessUser $member): RedirectResponse
    {
        abort_unless((int) $member->business_id === (int) $business->id, 404);

        $validated = $request->validate([
            'role' => ['required', Rule::in(BusinessUser::ROLES)],
            'status' => ['required', Rule::in(BusinessUser::STATUSES)],
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['integer'],
            'default_outlet_id' => ['nullable', 'integer'],
        ]);

        // A partial PATCH that omits outlet_ids must keep the member's current
        // outlet access rather than silently detaching everything.
        if (! array_key_exists('outlet_ids', $validated)) {
            $validated['outlet_ids'] = $this->currentOutletIds($business, $member);
        }

        $this->assertOutletsBelongToBusiness($business, $validated);
        $this->assertOwnerPreserved($business, $member, $validated);

        [$outletIds, $defaultOutletId] = $this->resolveOutletAccess(
            $business,
            $validated,
            $member->default_outlet_id,
        );

        DB::transaction(function () use ($business, $member, $validated, $outletIds, $defaultOutletId) {
            $before = [
                'role' => $member->role,
                'status' => $member->status,
                'outlet_ids' => $this->currentOutletIds($business, $member),
            ];

            $member->update([
                'role' => $validated['role'],
                'status' => $validated['status'],
                'default_outlet_id' => $defaultOutletId,
                'joined_at' => $member->joined_at
                    ?? ($validated['status'] === BusinessUser::STATUS_ACTIVE ? now() : null),
            ]);

            $this->syncOutlets($business, (int) $member->user_id, $outletIds, $defaultOutletId);

            $this->auditLog->log(
                event: 'business.member.updated',
                module: 'business',
                auditable: $business,
                description: 'Anggota business diperbarui.',
                before: $before,
                after: [
                    'role' => $member->role,
                    'status' => $member->status,
                    'outlet_ids' => $outletIds,
                ],
                meta: ['member_user_id' => $member->user_id],
            );
        });

        return redirect()->route('businesses.show', $business->id)
            ->with('success', 'Anggota business diperbarui.');
    }

    public function destroy(Business $business, BusinessUser $member): RedirectResponse
    {
        abort_unless((int) $member->business_id === (int) $business->id, 404);

        abort_if(
            $business->owner_user_id !== null
                && (int) $member->user_id === (int) $business->owner_user_id,
            422,
            'Owner business tidak dapat dihapus. Tetapkan owner baru terlebih dahulu.',
        );

        DB::transaction(function () use ($business, $member) {
            $outletIds = Outlet::query()
                ->where('business_id', $business->id)
                ->pluck('id')
                ->all();

            if ($member->user) {
                $member->user->outlets()->detach($outletIds);
            }

            $this->auditLog->log(
                event: 'business.member.removed',
                module: 'business',
                auditable: $business,
                description: 'Anggota business dihapus.',
                before: [
                    'member_user_id' => $member->user_id,
                    'role' => $member->role,
                    'status' => $member->status,
                ],
            );

            $member->delete();
        });

        return redirect()->route('businesses.show', $business->id)
            ->with('success', 'Anggota business dihapus.');
    }

    /**
     * @return array{0: array<int, int>, 1: int|null}
     */
    private function resolveOutletAccess(Business $business, array $data, ?int $current = null): array
    {
        $outletIds = collect($data['outlet_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $default = isset($data['default_outlet_id']) ? (int) $data['default_outlet_id'] : null;

        if (! $default || ! $outletIds->contains($default)) {
            $default = ($current && $outletIds->contains($current))
                ? $current
                : $outletIds->first();
        }

        return [$outletIds->all(), $default];
    }

    /**
     * Every selected outlet must belong to this business and be active, and the
     * default outlet must be one of the selected ones.
     */
    private function assertOutletsBelongToBusiness(Business $business, array $data): void
    {
        $outletIds = collect($data['outlet_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($outletIds->isNotEmpty()) {
            $valid = Outlet::query()
                ->whereIn('id', $outletIds)
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            if ($outletIds->diff($valid)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'outlet_ids' => 'Semua outlet harus milik business ini dan aktif.',
                ]);
            }
        }

        $default = isset($data['default_outlet_id']) ? (int) $data['default_outlet_id'] : null;

        if ($default && ! $outletIds->contains($default)) {
            throw ValidationException::withMessages([
                'default_outlet_id' => 'Outlet default harus termasuk outlet yang dipilih.',
            ]);
        }
    }

    private function assertOwnerPreserved(Business $business, BusinessUser $member, array $data): void
    {
        if ($business->owner_user_id === null
            || (int) $member->user_id !== (int) $business->owner_user_id) {
            return;
        }

        $role = $data['role'] ?? $member->role;
        $status = $data['status'] ?? $member->status;

        abort_if(
            $role !== BusinessUser::ROLE_OWNER || $status !== BusinessUser::STATUS_ACTIVE,
            422,
            'Owner business tidak dapat diturunkan atau dinonaktifkan. Tetapkan owner baru terlebih dahulu.',
        );
    }

    /**
     * Sync the member's outlet access for THIS business only. Outlets from other
     * businesses the user may belong to are left untouched.
     *
     * @param  array<int, int>  $outletIds
     */
    private function syncOutlets(Business $business, int $userId, array $outletIds, ?int $defaultOutletId): void
    {
        $user = User::find($userId);

        if (! $user) {
            return;
        }

        $businessOutletIds = Outlet::query()
            ->where('business_id', $business->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $sync = collect($outletIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->mapWithKeys(fn ($id) => [$id => ['is_default' => $id === $defaultOutletId]])
            ->all();

        $user->outlets()->detach($businessOutletIds);

        if (! empty($sync)) {
            $user->outlets()->syncWithoutDetaching($sync);
        }
    }

    /**
     * @return array<int, int>
     */
    private function currentOutletIds(Business $business, BusinessUser $member): array
    {
        if (! $member->user) {
            return [];
        }

        return $member->user->outlets()
            ->where('outlets.business_id', $business->id)
            ->pluck('outlets.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
