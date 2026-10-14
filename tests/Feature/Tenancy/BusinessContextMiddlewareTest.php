<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'business'])->get('/_test/business-context', function () {
            $context = app(BusinessContext::class);

            return response()->json([
                'business_id' => $context->businessId(),
                'role' => $context->role(),
                'has_active_business' => $context->hasActiveBusiness(),
            ]);
        });

        Route::middleware(['web', 'business', 'business.required'])->get('/_test/business-required', function () {
            return response()->json(['ok' => true]);
        });
    }

    private function member(User $user, Business $business, string $role = BusinessUser::ROLE_STAFF): void
    {
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => BusinessUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
    }

    public function test_valid_membership_resolves_active_business(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $this->member($user, $business, BusinessUser::ROLE_OWNER);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/_test/business-context')
            ->assertOk()
            ->assertJson([
                'business_id' => $business->id,
                'role' => BusinessUser::ROLE_OWNER,
                'has_active_business' => true,
            ]);
    }

    public function test_single_membership_is_resolved_without_session(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $this->member($user, $business);

        $this->actingAs($user)
            ->get('/_test/business-context')
            ->assertOk()
            ->assertJson([
                'business_id' => $business->id,
                'has_active_business' => true,
            ]);
    }

    public function test_multiple_memberships_without_session_resolve_to_nothing(): void
    {
        $user = User::factory()->create();
        $this->member($user, Business::factory()->create());
        $this->member($user, Business::factory()->create());

        $this->actingAs($user)
            ->get('/_test/business-context')
            ->assertOk()
            ->assertJson([
                'business_id' => null,
                'has_active_business' => false,
            ]);
    }

    public function test_foreign_session_selection_never_resolves_to_that_business(): void
    {
        $user = User::factory()->create();
        $owned = Business::factory()->create();
        $foreign = Business::factory()->create();
        $this->member($user, $owned);

        // The stale selection is dropped rather than trusted: the request
        // recovers into the business the user actually belongs to, and never
        // into the foreign one.
        $this->actingAs($user)
            ->withSession(['active_business_id' => $foreign->id])
            ->get('/_test/business-context')
            ->assertOk()
            ->assertJson([
                'business_id' => $owned->id,
                'has_active_business' => true,
            ])
            ->assertJsonMissing(['business_id' => $foreign->id]);

        $this->assertSame($owned->id, session('active_business_id'));
    }

    public function test_business_id_query_parameter_is_not_trusted(): void
    {
        $user = User::factory()->create();
        $owned = Business::factory()->create();
        $this->member($user, $owned);
        $foreign = Business::factory()->create();

        $this->actingAs($user)
            ->get('/_test/business-context?business_id='.$foreign->id)
            ->assertOk()
            ->assertJson(['business_id' => $owned->id])
            ->assertJsonMissing(['business_id' => $foreign->id]);
    }

    public function test_super_admin_is_not_automatically_owner_of_every_business(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        Business::factory()->create();

        $this->actingAs($superAdmin)
            ->get('/_test/business-context')
            ->assertOk()
            ->assertJson([
                'business_id' => null,
                'has_active_business' => false,
            ]);
    }

    public function test_super_admin_can_select_an_existing_business_explicitly(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $business = Business::factory()->create();

        $this->actingAs($superAdmin)
            ->withSession(['active_business_id' => $business->id])
            ->get('/_test/business-context')
            ->assertOk()
            ->assertJson([
                'business_id' => $business->id,
                'has_active_business' => true,
            ]);
    }

    public function test_context_does_not_leak_between_requests(): void
    {
        $user = User::factory()->create();
        $owned = Business::factory()->create();
        $foreign = Business::factory()->create();
        $this->member($user, $owned);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $owned->id])
            ->get('/_test/business-context')
            ->assertJson(['business_id' => $owned->id]);

        // A selection for a business the user does not belong to must never
        // become the context; it heals back to their own business instead.
        $this->actingAs($user)
            ->withSession(['active_business_id' => $foreign->id])
            ->get('/_test/business-context')
            ->assertJson(['business_id' => $owned->id])
            ->assertJsonMissing(['business_id' => $foreign->id]);

        $this->assertSame($owned->id, session('active_business_id'));
    }

    public function test_required_middleware_blocks_requests_without_context(): void
    {
        config()->set('multi_business.enabled', true);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_test/business-required')
            ->assertForbidden();
    }

    public function test_required_middleware_allows_requests_with_context(): void
    {
        config()->set('multi_business.enabled', true);

        $user = User::factory()->create();
        $business = Business::factory()->create();
        $this->member($user, $business);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/_test/business-required')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    private function memberWithStatus(User $user, Business $business, string $status): void
    {
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessUser::ROLE_STAFF,
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    public function test_absent_membership_cannot_reach_tenant_required_routes(): void
    {
        config()->set('multi_business.enabled', true);

        $user = User::factory()->create();
        $business = Business::factory()->create();

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/_test/business-required')
            ->assertForbidden();
    }

    public function test_suspended_membership_cannot_reach_tenant_required_routes(): void
    {
        config()->set('multi_business.enabled', true);

        $user = User::factory()->create();
        $business = Business::factory()->create();
        $this->memberWithStatus($user, $business, BusinessUser::STATUS_SUSPENDED);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/_test/business-required')
            ->assertForbidden();
    }

    public function test_revoked_membership_cannot_reach_tenant_required_routes(): void
    {
        config()->set('multi_business.enabled', true);

        $user = User::factory()->create();
        $business = Business::factory()->create();
        $this->memberWithStatus($user, $business, BusinessUser::STATUS_REVOKED);

        $this->actingAs($user)
            ->withSession(['active_business_id' => $business->id])
            ->get('/_test/business-required')
            ->assertForbidden();
    }
}
