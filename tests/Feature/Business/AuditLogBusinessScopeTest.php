<?php

namespace Tests\Feature\Business;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BusinessContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Q12 — audit log business ownership.
 *
 * Proves that audit logs are stamped from the verified server-side business
 * context, that the viewer is scoped to the active business (super-admin
 * included, failing closed without a context), and that legacy behaviour is
 * unchanged while tenancy is disabled.
 */
class AuditLogBusinessScopeTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private Outlet $outletA;

    private User $superAdmin;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        config()->set('multi_business.enabled', true);
        app(BusinessContext::class)->clear();

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->outletA = Outlet::create([
            'business_id' => $this->businessA->id,
            'code' => 'OA',
            'name' => 'Outlet A',
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        $this->superAdmin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->superAdmin->markEmailAsVerified();

        $this->userA = User::factory()->create(['name' => 'User A']);
        $this->userA->markEmailAsVerified();
        $this->userA->givePermissionTo('audit-logs-access');
        $this->userA->outlets()->attach($this->outletA->id, ['is_default' => true]);

        BusinessUser::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->userA->id,
            'role' => BusinessUser::ROLE_MANAGER,
            'status' => BusinessUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        app(BusinessContext::class)->clear();
    }

    private function makeLog(?int $businessId, ?int $userId, string $description): AuditLog
    {
        return AuditLog::create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'event' => 'created',
            'module' => 'tests',
            'description' => $description,
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- Stamping

    public function test_log_service_stamps_active_business_when_tenancy_enabled(): void
    {
        $service = app(AuditLogService::class);
        $context = app(BusinessContext::class);

        $context->setActive($this->businessA);
        $logA = $service->log('created', 'tests', null, 'A');

        $context->setActive($this->businessB);
        $logB = $service->log('created', 'tests', null, 'B');

        $this->assertSame($this->businessA->id, $logA->business_id);
        $this->assertSame($this->businessB->id, $logB->business_id);
    }

    public function test_log_service_leaves_business_id_null_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        // Even with a context present, the flag being off keeps legacy behaviour.
        app(BusinessContext::class)->setActive($this->businessA);

        $log = app(AuditLogService::class)->log('created', 'tests', null, 'legacy');

        $this->assertNull($log->business_id);
    }

    public function test_log_service_leaves_business_id_null_without_context_when_tenancy_enabled(): void
    {
        app(BusinessContext::class)->clear();

        $log = app(AuditLogService::class)->log('created', 'tests', null, 'no-context');

        $this->assertNull($log->business_id);
    }

    // ------------------------------------------------------------------ Viewer

    public function test_index_only_shows_active_business_logs_for_super_admin(): void
    {
        $this->makeLog($this->businessA->id, $this->superAdmin->id, 'owned-a');
        $this->makeLog($this->businessB->id, $this->superAdmin->id, 'owned-b');
        $this->makeLog(null, $this->superAdmin->id, 'legacy-null');

        $this->actingAs($this->superAdmin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $descriptions = collect($page->toArray()['props']['auditLogs']['data'])
                    ->pluck('description');

                $this->assertTrue($descriptions->contains('owned-a'));
                $this->assertFalse($descriptions->contains('owned-b'));
                $this->assertFalse($descriptions->contains('legacy-null'));
            });
    }

    public function test_index_scopes_to_business_b_too(): void
    {
        $this->makeLog($this->businessA->id, $this->superAdmin->id, 'owned-a');
        $this->makeLog($this->businessB->id, $this->superAdmin->id, 'owned-b');

        $this->actingAs($this->superAdmin)
            ->withSession(['active_business_id' => $this->businessB->id])
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $descriptions = collect($page->toArray()['props']['auditLogs']['data'])
                    ->pluck('description');

                $this->assertTrue($descriptions->contains('owned-b'));
                $this->assertFalse($descriptions->contains('owned-a'));
            });
    }

    public function test_index_hides_legacy_unowned_logs_from_business_user(): void
    {
        $this->makeLog($this->businessA->id, $this->userA->id, 'owned-a');
        $this->makeLog(null, $this->userA->id, 'legacy-null');

        $this->actingAs($this->userA)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $descriptions = collect($page->toArray()['props']['auditLogs']['data'])
                    ->pluck('description');

                $this->assertTrue($descriptions->contains('owned-a'));
                $this->assertFalse($descriptions->contains('legacy-null'));
            });
    }

    public function test_index_forbidden_without_active_business_context_for_non_super_admin(): void
    {
        $loner = User::factory()->create();
        $loner->markEmailAsVerified();
        $loner->givePermissionTo('audit-logs-access');

        $this->actingAs($loner)
            ->get(route('audit-logs.index'))
            ->assertForbidden();
    }

    public function test_show_returns_404_for_other_business_log(): void
    {
        $logB = $this->makeLog($this->businessB->id, $this->superAdmin->id, 'owned-b');

        $this->actingAs($this->superAdmin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('audit-logs.show', $logB))
            ->assertNotFound();
    }

    public function test_show_reaches_own_business_log(): void
    {
        $logA = $this->makeLog($this->businessA->id, $this->superAdmin->id, 'owned-a');

        $this->actingAs($this->superAdmin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->get(route('audit-logs.show', $logA))
            ->assertOk();
    }

    // ------------------------------------------------------------- Legacy mode

    public function test_viewer_is_unrestricted_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);
        app(BusinessContext::class)->clear();

        $this->makeLog($this->businessA->id, $this->superAdmin->id, 'owned-a');
        $this->makeLog($this->businessB->id, $this->superAdmin->id, 'owned-b');
        $this->makeLog(null, $this->superAdmin->id, 'legacy-null');

        $this->actingAs($this->superAdmin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $descriptions = collect($page->toArray()['props']['auditLogs']['data'])
                    ->pluck('description');

                $this->assertTrue($descriptions->contains('owned-a'));
                $this->assertTrue($descriptions->contains('owned-b'));
                $this->assertTrue($descriptions->contains('legacy-null'));
            });
    }
}
