<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\FinancialOwnershipService;
use App\Support\Tenancy\TenantOwnership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FIN-01/02/05 — quarantine read-barrier (global scope + TenantOwnership)
 * and FIN-04 creation-path stamping.
 *
 * With `multi_business.enabled = true`:
 *  - model queries on payables/receivables see resolved-and-owned rows plus the
 *    never-evaluated (unassigned) transition window; every evaluated but
 *    non-resolved status is quarantined even when the parent chain proves a
 *    business (design §2.2/§2.3);
 *  - with no active business context every query fails closed;
 *  - TenantOwnership prefers the persisted business_id once 'resolved', keeps
 *    the chain fallback for unassigned rows, and blocks quarantined rows;
 *  - new payables must be created inside an active business context (FIN-04).
 */
class FinancialOwnershipQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private Outlet $outletA;

    private Outlet $outletB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $this->admin->markEmailAsVerified();

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);

        $this->outletA = $this->makeOutlet($this->businessA, 'OA', true);
        $this->outletB = $this->makeOutlet($this->businessB, 'OB', true);

        $this->warehouseA = $this->makeWarehouse($this->businessA, $this->outletA, 'WA');
        $this->warehouseB = $this->makeWarehouse($this->businessB, $this->outletB, 'WB');
    }

    private function makeOutlet(Business $business, string $code, bool $active): Outlet
    {
        return Outlet::create([
            'business_id' => $business->id,
            'code' => $code,
            'name' => 'Outlet '.$code,
            'is_active' => $active,
            'is_sales_enabled' => $active,
        ]);
    }

    private function makeWarehouse(?Business $business, ?Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create([
            'business_id' => $business?->id,
            'outlet_id' => $outlet?->id,
            'code' => $code,
            'name' => 'Warehouse '.$code,
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function setContextA(): void
    {
        app(BusinessContext::class)->setActive($this->businessA);
    }

    private function service(): FinancialOwnershipService
    {
        return app(FinancialOwnershipService::class);
    }

    private function payable(string $document, ?int $businessId = null, string $status = 'unassigned'): Payable
    {
        return Payable::create([
            'document_number' => $document,
            'total' => 1000,
            'paid' => 0,
            'status' => 'unpaid',
            'business_id' => $businessId,
            'ownership_status' => $status,
        ]);
    }

    private function receivable(string $invoice, ?int $businessId = null, string $status = 'unassigned'): Receivable
    {
        return Receivable::create([
            'invoice' => $invoice,
            'total' => 1000,
            'paid' => 0,
            'status' => 'unpaid',
            'business_id' => $businessId,
            'ownership_status' => $status,
        ]);
    }

    private function payableWithPoInWarehouseA(string $document, string $status = 'unassigned', ?int $businessId = null): Payable
    {
        $po = PurchaseOrder::create([
            'document_number' => 'PO-'.$document,
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseA->id,
        ]);

        return Payable::create([
            'document_number' => $document,
            'total' => 1000,
            'paid' => 0,
            'status' => 'unpaid',
            'purchase_order_id' => $po->id,
            'business_id' => $businessId,
            'ownership_status' => $status,
        ]);
    }

    // ---------------------------------------------------------------------
    // Global scope — authoritative ownership + quarantine
    // ---------------------------------------------------------------------

    public function test_resolved_rows_visible_only_for_own_business_when_flag_enabled(): void
    {
        config()->set('multi_business.enabled', true);
        $this->setContextA();

        $own = $this->payable('PAY-OWN', $this->businessA->id, 'resolved');
        $other = $this->payable('PAY-OTHER', $this->businessB->id, 'resolved');
        $unassigned = $this->payable('PAY-TRANSITION');

        $ids = Payable::query()->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($other->id));
        $this->assertTrue($ids->contains($unassigned->id));
    }

    public function test_evaluated_but_not_resolved_rows_are_quarantined(): void
    {
        config()->set('multi_business.enabled', true);
        $this->setContextA();

        $ambiguousOwn = $this->payable('PAY-AMB', $this->businessA->id, 'ambiguous');
        $manualRequired = $this->payable('PAY-MAN', null, 'manual_required');
        $dangling = $this->receivable('RCV-DANG', null, 'dangling');

        $payableIds = Payable::query()->pluck('id');
        $this->assertFalse($payableIds->contains($ambiguousOwn->id));
        $this->assertFalse($payableIds->contains($manualRequired->id));

        $this->assertFalse(Receivable::query()->pluck('id')->contains($dangling->id));
    }

    public function test_quarantined_row_hidden_even_when_chain_proves_ownership(): void
    {
        config()->set('multi_business.enabled', true);
        $this->setContextA();

        // Chain (PO → warehouseA) proves business A, but the record was evaluated
        // as 'ambiguous' — quarantine wins over chain evidence.
        $quarantined = $this->payableWithPoInWarehouseA('PAY-CHAIN-AMB', 'ambiguous');
        $otherQuarantined = $this->payableWithPoInWarehouseA('PAY-CHAIN-MAN', 'manual_required');

        $ids = Payable::query()->pluck('id');
        $this->assertFalse($ids->contains($quarantined->id));
        $this->assertFalse($ids->contains($otherQuarantined->id));
    }

    public function test_without_active_context_resolved_hidden_and_transition_visible(): void
    {
        config()->set('multi_business.enabled', true);

        $resolvedA = $this->payable('PAY-R', $this->businessA->id, 'resolved');
        $quarantined = $this->payable('PAY-Q', $this->businessB->id, 'ambiguous');
        $unassigned = $this->payable('PAY-TRANSITION-2');

        $ids = Payable::query()->pluck('id');

        $this->assertFalse($ids->contains($resolvedA->id));
        $this->assertFalse($ids->contains($quarantined->id));
        $this->assertTrue($ids->contains($unassigned->id));
    }

    public function test_global_scope_is_noop_when_flag_disabled(): void
    {
        config()->set('multi_business.enabled', false);
        $this->setContextA();

        $this->payable('PAY-1', $this->businessB->id, 'resolved');
        $this->payable('PAY-2', null, 'ambiguous');

        $this->assertSame(2, Payable::query()->count());
    }

    // ---------------------------------------------------------------------
    // TenantOwnership — persisted ownership, quarantine, chain fallback
    // ---------------------------------------------------------------------

    public function test_tenant_ownership_prefers_persisted_business_id_when_resolved(): void
    {
        config()->set('multi_business.enabled', true);
        $this->setContextA();

        $own = $this->payable('PAY-OWN-TO', $this->businessA->id, 'resolved');
        $other = $this->payable('PAY-OTHER-TO', $this->businessB->id, 'resolved');

        $tenant = app(TenantOwnership::class);

        $this->assertTrue($tenant->entityBelongsToActiveBusiness('payables', $own->id));
        $this->assertFalse($tenant->entityBelongsToActiveBusiness('payables', $other->id));
    }

    public function test_tenant_ownership_fails_closed_for_quarantined_rows_even_with_matching_business(): void
    {
        config()->set('multi_business.enabled', true);
        $this->setContextA();

        // business_id matches A but ownership is quarantined → deny (D5).
        $ambiguousOwn = $this->payable('PAY-AMB-TO', $this->businessA->id, 'ambiguous');
        $chainAmbiguous = $this->payableWithPoInWarehouseA('PAY-CHAIN-ARM', 'ambiguous');

        $tenant = app(TenantOwnership::class);

        $this->assertFalse($tenant->entityBelongsToActiveBusiness('payables', $ambiguousOwn->id));
        $this->assertFalse($tenant->entityBelongsToActiveBusiness('payables', $chainAmbiguous->id));
    }

    public function test_tenant_ownership_falls_back_to_chain_for_unassigned_rows(): void
    {
        config()->set('multi_business.enabled', true);
        $this->setContextA();

        $chainOwned = $this->payableWithPoInWarehouseA('PAY-CHAIN');
        $manual = $this->payable('PAY-MANUAL');

        $tenant = app(TenantOwnership::class);

        $this->assertTrue($tenant->entityBelongsToActiveBusiness('payables', $chainOwned->id));
        $this->assertFalse($tenant->entityBelongsToActiveBusiness('payables', $manual->id));
    }

    // ---------------------------------------------------------------------
    // FIN-04 — creation paths under tenancy
    // ---------------------------------------------------------------------

    public function test_payable_controller_store_denied_without_active_business_context(): void
    {
        config()->set('multi_business.enabled', true);

        // A super-admin never reaches this path because ResolveBusinessContext
        // falls back to the first active business. Use a non-super member with
        // two memberships so no sole membership auto-resolves and no explicit
        // context is selected.
        $member = User::where('email', 'manager@gmail.com')->firstOrFail();
        $member->markEmailAsVerified();

        foreach ([$this->businessA, $this->businessB] as $business) {
            BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $member->id,
                'role' => BusinessUser::ROLE_MANAGER,
                'status' => BusinessUser::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
        }

        $this->actingAs($member)
            ->post(route('payables.store'), ['total' => 1000])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('payables')->count());
    }

    public function test_payable_controller_store_stamps_ownership_from_context(): void
    {
        config()->set('multi_business.enabled', true);

        $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id])
            ->post(route('payables.store'), ['total' => 1000, 'document_number' => 'PAY-CTX'])
            ->assertRedirect(route('payables.index'));

        $row = DB::table('payables')->where('document_number', 'PAY-CTX')->first();
        $this->assertSame($this->businessA->id, (int) $row->business_id);
        $this->assertSame('resolved', $row->ownership_status);
        $this->assertNotNull($row->ownership_evaluated_at);
    }

    public function test_stamp_from_warehouse_derives_business_for_creation_paths(): void
    {
        $stamped = $this->service()->stampFromWarehouse($this->warehouseA->id);
        $this->assertSame($this->businessA->id, $stamped['business_id']);
        $this->assertSame('resolved', $stamped['ownership_status']);
        $this->assertNotNull($stamped['ownership_evaluated_at']);

        $bare = $this->makeWarehouse(null, null, 'PUSAT');
        $unstamped = $this->service()->stampFromWarehouse($bare->id);
        $this->assertNull($unstamped['business_id']);
        $this->assertSame('unassigned', $unstamped['ownership_status']);
        $this->assertNull($unstamped['ownership_evaluated_at']);
    }
}
