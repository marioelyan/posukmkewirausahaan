<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use App\Services\BusinessMembershipResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BusinessMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function membership(User $user, Business $business, array $attributes = []): BusinessUser
    {
        return BusinessUser::create(array_merge([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessUser::ROLE_STAFF,
            'status' => BusinessUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ], $attributes));
    }

    public function test_business_schema_is_present(): void
    {
        $this->assertTrue(Schema::hasTable('businesses'));
        $this->assertTrue(Schema::hasTable('business_user'));
        $this->assertTrue(Schema::hasColumn('outlets', 'business_id'));
        $this->assertTrue(Schema::hasColumn('business_user', 'role'));
        $this->assertTrue(Schema::hasColumn('business_user', 'status'));
        $this->assertTrue(Schema::hasColumn('business_user', 'default_outlet_id'));
    }

    public function test_duplicate_membership_is_rejected(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();

        $this->membership($user, $business);

        $this->expectException(QueryException::class);

        $this->membership($user, $business);
    }

    public function test_user_can_belong_to_multiple_businesses_with_different_roles(): void
    {
        $user = User::factory()->create();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $this->membership($user, $businessA, ['role' => BusinessUser::ROLE_OWNER]);
        $this->membership($user, $businessB, ['role' => BusinessUser::ROLE_STAFF]);

        $resolver = app(BusinessMembershipResolver::class);

        $this->assertSame(
            BusinessUser::ROLE_OWNER,
            $resolver->activeMembershipFor($user, $businessA->id)?->role,
        );
        $this->assertSame(
            BusinessUser::ROLE_STAFF,
            $resolver->activeMembershipFor($user, $businessB->id)?->role,
        );
        $this->assertCount(2, $user->businesses);
    }

    public function test_user_without_membership_cannot_access_a_business(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();

        $resolver = app(BusinessMembershipResolver::class);

        $this->assertFalse($resolver->isActiveMember($user, $business->id));
        $this->assertNull($resolver->activeMembershipFor($user, $business->id));
        $this->assertTrue($resolver->accessibleBusinesses($user)->isEmpty());
    }

    public function test_non_active_membership_does_not_grant_access(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();

        $this->membership($user, $business, ['status' => BusinessUser::STATUS_SUSPENDED]);

        $resolver = app(BusinessMembershipResolver::class);

        $this->assertFalse($resolver->isActiveMember($user, $business->id));
        $this->assertTrue($resolver->accessibleBusinesses($user)->isEmpty());
    }

    public function test_suspended_business_does_not_grant_access_even_to_active_membership(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['status' => Business::STATUS_SUSPENDED]);

        $this->membership($user, $business);

        $resolver = app(BusinessMembershipResolver::class);

        $this->assertFalse($resolver->isActiveMember($user, $business->id));
        $this->assertNull($resolver->soleActiveMembershipFor($user));
    }

    public function test_sole_active_membership_is_used_only_when_unambiguous(): void
    {
        $user = User::factory()->create();

        $resolver = app(BusinessMembershipResolver::class);

        $this->assertNull($resolver->soleActiveMembershipFor($user));

        $businessA = Business::factory()->create();
        $this->membership($user, $businessA);

        $this->assertSame($businessA->id, $resolver->soleActiveMembershipFor($user)?->business_id);

        $businessB = Business::factory()->create();
        $this->membership($user, $businessB);

        $this->assertNull($resolver->soleActiveMembershipFor($user));
    }

    public function test_business_outlets_relation_uses_additive_column(): void
    {
        $business = Business::factory()->create();
        $outlet = Outlet::create([
            'business_id' => $business->id,
            'code' => 'MAL',
            'name' => 'Malabar',
        ]);

        $this->assertTrue($outlet->business->is($business));
        $this->assertSame($outlet->id, $business->outlets()->first()->id);
    }
}
