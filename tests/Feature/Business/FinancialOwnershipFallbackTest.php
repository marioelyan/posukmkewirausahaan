<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use App\Services\PayableAgingService;
use App\Services\ReceivableService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase-1 containment (Q7/Q9/Q15).
 *
 * A single active outlet — or a missing/dangling parent — must never be treated
 * as proof of ownership for financial records. Under tenancy the legacy
 * single-outlet fallback is disabled, so unowned payables/receivables fail
 * closed; legacy mode keeps the old behaviour untouched.
 */
class FinancialOwnershipFallbackTest extends TestCase
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
        // Business B has no *active* outlet: the global active-outlet count stays
        // at 1, the exact condition the legacy fallback treated as "safe".
        $this->outletB = $this->makeOutlet($this->businessB, 'OB', false);

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

    private function makeWarehouse(Business $business, Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create([
            'business_id' => $business->id,
            'outlet_id' => $outlet->id,
            'code' => $code,
            'name' => 'Warehouse '.$code,
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function asBusinessA()
    {
        return $this->actingAs($this->admin)
            ->withSession(['active_business_id' => $this->businessA->id]);
    }

    private function manualPayable(string $document): Payable
    {
        return Payable::create([
            'document_number' => $document,
            'total' => 1000,
            'status' => 'unpaid',
            'paid' => 0,
        ]);
    }

    private function manualReceivable(string $invoice): Receivable
    {
        return Receivable::create([
            'invoice' => $invoice,
            'total' => 1000,
            'status' => 'unpaid',
            'paid' => 0,
        ]);
    }

    private function ownedPayable(): Payable
    {
        $po = PurchaseOrder::create([
            'document_number' => 'PO-A',
            'status' => 'ordered',
            'warehouse_id' => $this->warehouseA->id,
        ]);

        return Payable::create([
            'document_number' => 'PAY-A',
            'total' => 1000,
            'purchase_order_id' => $po->id,
        ]);
    }

    private function ownedReceivable(): Receivable
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'INV-A',
            'grand_total' => 1000,
            'cash' => 1000,
            'change' => 0,
            'discount' => 0,
            'warehouse_id' => $this->warehouseA->id,
        ]);

        return Receivable::create([
            'invoice' => 'RCV-A',
            'total' => 1000,
            'transaction_id' => $transaction->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // Service scopes — unowned records fail closed under tenancy
    // ---------------------------------------------------------------------

    public function test_single_active_outlet_is_not_proof_of_ownership_for_manual_payables_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);

        $manual = $this->manualPayable('PAY-MANUAL-B');

        $ids = app(PayableAgingService::class)
            ->scopeQuery(Payable::query(), collect([$this->warehouseA->id]))
            ->pluck('id');

        $this->assertTrue($ids->doesntContain($manual->id));
    }

    public function test_single_active_outlet_is_not_proof_of_ownership_for_manual_receivables_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);

        $manual = $this->manualReceivable('RCV-MANUAL-B');

        $ids = app(ReceivableService::class)
            ->scopeQuery(Receivable::query(), collect([$this->warehouseA->id]))
            ->pluck('id');

        $this->assertTrue($ids->doesntContain($manual->id));
    }

    public function test_owned_payable_stays_visible_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);

        $payable = $this->ownedPayable();

        $ids = app(PayableAgingService::class)
            ->scopeQuery(Payable::query(), collect([$this->warehouseA->id]))
            ->pluck('id');

        $this->assertTrue($ids->contains($payable->id));
    }

    public function test_owned_receivable_stays_visible_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);

        $receivable = $this->ownedReceivable();

        $ids = app(ReceivableService::class)
            ->scopeQuery(Receivable::query(), collect([$this->warehouseA->id]))
            ->pluck('id');

        $this->assertTrue($ids->contains($receivable->id));
    }

    // ---------------------------------------------------------------------
    // Legacy mode is preserved
    // ---------------------------------------------------------------------

    public function test_legacy_mode_still_surfaces_manual_payables_when_single_outlet(): void
    {
        config()->set('multi_business.enabled', false);

        $manual = $this->manualPayable('PAY-LEGACY');

        $ids = app(PayableAgingService::class)
            ->scopeQuery(Payable::query(), collect([$this->warehouseA->id]))
            ->pluck('id');

        $this->assertTrue($ids->contains($manual->id));
    }

    public function test_legacy_mode_still_surfaces_manual_receivables_when_single_outlet(): void
    {
        config()->set('multi_business.enabled', false);

        $manual = $this->manualReceivable('RCV-LEGACY');

        $ids = app(ReceivableService::class)
            ->scopeQuery(Receivable::query(), collect([$this->warehouseA->id]))
            ->pluck('id');

        $this->assertTrue($ids->contains($manual->id));
    }

    // ---------------------------------------------------------------------
    // Null warehouse must not bypass authorization
    // ---------------------------------------------------------------------

    public function test_null_warehouse_is_denied_under_tenancy_even_with_single_outlet(): void
    {
        config()->set('multi_business.enabled', true);

        $this->assertFalse(app(OutletAccessService::class)->canUseWarehouse($this->admin, null));
    }

    public function test_null_warehouse_legacy_bypass_still_active_when_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);

        $this->assertTrue(app(OutletAccessService::class)->canUseWarehouse($this->admin, null));
    }

    // ---------------------------------------------------------------------
    // Read + mutation endpoints deny unowned records
    // ---------------------------------------------------------------------

    public function test_manual_payable_show_and_pay_are_denied_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);
        $manual = $this->manualPayable('PAY-MANUAL-B');

        $this->asBusinessA()->get(route('payables.show', $manual))->assertNotFound();

        $this->asBusinessA()->post(route('payables.pay', $manual), [
            'amount' => 100,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertNotFound();
    }

    public function test_orphaned_payable_is_denied_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);

        // A payable whose purchase-order parent has been removed becomes an
        // unowned/orphaned record; it must fail closed, not fall back to any
        // single-outlet or null-parent shortcut.
        $payable = $this->ownedPayable();
        $payable->purchaseOrder->delete(); // FK is nullOnDelete
        $payable->refresh();

        $this->assertNull($payable->purchase_order_id);

        $this->asBusinessA()->get(route('payables.show', $payable))->assertNotFound();
    }

    public function test_manual_receivable_show_and_pay_are_denied_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);
        $manual = $this->manualReceivable('RCV-MANUAL-B');

        $this->asBusinessA()->get(route('receivables.show', $manual))->assertNotFound();

        $this->asBusinessA()->post(route('receivables.pay', $manual), [
            'amount' => 100,
            'paid_at' => now()->toDateString(),
            'method' => 'cash',
        ])->assertNotFound();
    }

    public function test_payables_index_does_not_expose_unowned_records_under_tenancy(): void
    {
        config()->set('multi_business.enabled', true);
        $this->manualPayable('PAY-MANUAL-B');

        $this->asBusinessA()
            ->get(route('payables.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Payables/Index')
                ->has('payables.data', 0));
    }

    public function test_payables_index_legacy_mode_shows_manual_records(): void
    {
        config()->set('multi_business.enabled', false);
        $this->manualPayable('PAY-LEGACY');

        $this->asBusinessA()
            ->get(route('payables.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Payables/Index')
                ->has('payables.data', 1));
    }
}
