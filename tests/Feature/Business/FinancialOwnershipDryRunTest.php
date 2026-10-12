<?php

namespace Tests\Feature\Business;

use App\Console\Commands\FinancialOwnershipReportCommand;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\FinancialOwnershipResolver;
use App\Support\Tenancy\FinancialOwnershipResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2A — FIN-01 read-only ownership dry-run.
 *
 * Proves the resolver classifies financial records only when their persisted
 * parent chain proves exactly one business, fails closed everywhere else, and
 * that the report command never writes.
 */
class FinancialOwnershipDryRunTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->businessB = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        $this->cashier = User::factory()->create();
    }

    private function resolver(): FinancialOwnershipResolver
    {
        return app(FinancialOwnershipResolver::class);
    }

    private function outlet(Business $business, string $code): Outlet
    {
        return Outlet::create([
            'business_id' => $business->id,
            'code' => $code,
            'name' => 'Outlet '.$code,
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);
    }

    private function warehouse(?Business $business, ?Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create([
            'business_id' => $business?->id,
            'outlet_id' => $outlet?->id,
            'code' => $code,
            'name' => 'Warehouse '.$code,
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function purchaseOrder(?int $warehouseId, string $document): PurchaseOrder
    {
        return PurchaseOrder::create([
            'document_number' => $document,
            'status' => 'ordered',
            'warehouse_id' => $warehouseId,
        ]);
    }

    private function payable(?int $purchaseOrderId, string $document): Payable
    {
        return Payable::create([
            'document_number' => $document,
            'total' => 1000,
            'paid' => 0,
            'status' => 'unpaid',
            'purchase_order_id' => $purchaseOrderId,
        ]);
    }

    private function transaction(?int $warehouseId, string $invoice): Transaction
    {
        return Transaction::create([
            'cashier_id' => $this->cashier->id,
            'invoice' => $invoice,
            'grand_total' => 1000,
            'cash' => 1000,
            'change' => 0,
            'discount' => 0,
            'warehouse_id' => $warehouseId,
        ]);
    }

    private function receivable(?int $transactionId, string $invoice): Receivable
    {
        return Receivable::create([
            'invoice' => $invoice,
            'total' => 1000,
            'paid' => 0,
            'status' => 'unpaid',
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * Write a dangling foreign-key reference to simulate imported/legacy data.
     *
     * `Schema::disableForeignKeyConstraints()` is a no-op inside the test
     * transaction, so on SQLite we defer FK enforcement until the (rolled back)
     * transaction boundary instead.
     */
    private function writeDanglingReference(callable $mutate): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            $mutate();

            return;
        }

        Schema::disableForeignKeyConstraints();
        $mutate();
        Schema::enableForeignKeyConstraints();
    }

    // ---------------------------------------------------------------------
    // A. AUTO_RESOLVABLE — exactly one proven business
    // ---------------------------------------------------------------------

    public function test_payable_with_full_chain_via_warehouse_business_is_auto_resolvable(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-1');
        $payable = $this->payable($po->id, 'PAY-1');

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame(FinancialOwnershipResult::AUTO_RESOLVABLE, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_AUTO_WAREHOUSE, $result->reasonCode);
        $this->assertSame($this->businessA->id, $result->proposedBusinessId);
        $this->assertTrue($result->isAutoResolvable());
        $this->assertNull($result->evidence['existing_business_id']);
    }

    public function test_receivable_with_full_chain_via_outlet_business_is_auto_resolvable(): void
    {
        // Warehouse has no business_id; ownership must come from its outlet.
        $outlet = $this->outlet($this->businessB, 'OB');
        $warehouse = $this->warehouse(null, $outlet, 'WB');
        $transaction = $this->transaction($warehouse->id, 'INV-1');
        $receivable = $this->receivable($transaction->id, 'RCV-1');

        $result = $this->resolver()->resolve('receivables', $receivable->id);

        $this->assertSame(FinancialOwnershipResult::AUTO_RESOLVABLE, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_AUTO_OUTLET, $result->reasonCode);
        $this->assertSame($this->businessB->id, $result->proposedBusinessId);
    }

    public function test_classification_is_deterministic_across_repeated_resolution(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-DET');
        $payable = $this->payable($po->id, 'PAY-DET');

        $first = $this->resolver()->resolve('payables', $payable->id);
        $second = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame($first->toArray(), $second->toArray());
    }

    // ---------------------------------------------------------------------
    // B. MANUAL_ASSIGNMENT_REQUIRED — no parent
    // ---------------------------------------------------------------------

    public function test_manual_payable_without_parent_requires_manual_assignment(): void
    {
        $payable = $this->payable(null, 'PAY-MANUAL');

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame(FinancialOwnershipResult::MANUAL_ASSIGNMENT_REQUIRED, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_MANUAL_NO_PARENT, $result->reasonCode);
        $this->assertNull($result->proposedBusinessId);
    }

    public function test_manual_receivable_without_parent_requires_manual_assignment(): void
    {
        $receivable = $this->receivable(null, 'RCV-MANUAL');

        $result = $this->resolver()->resolve('receivables', $receivable->id);

        $this->assertSame(FinancialOwnershipResult::MANUAL_ASSIGNMENT_REQUIRED, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_MANUAL_NO_PARENT, $result->reasonCode);
        $this->assertNull($result->proposedBusinessId);
    }

    // ---------------------------------------------------------------------
    // C. AMBIGUOUS_OWNERSHIP — chain present but unresolved
    // ---------------------------------------------------------------------

    public function test_parent_without_warehouse_is_ambiguous(): void
    {
        $po = $this->purchaseOrder(null, 'PO-NOWH');
        $payable = $this->payable($po->id, 'PAY-NOWH');

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame(FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_AMBIGUOUS_PARENT_WITHOUT_WAREHOUSE, $result->reasonCode);
    }

    public function test_warehouse_without_business_or_outlet_is_ambiguous(): void
    {
        // Central warehouse: no business_id and no outlet.
        $warehouse = $this->warehouse(null, null, 'PUSAT');
        $po = $this->purchaseOrder($warehouse->id, 'PO-PUSAT');
        $payable = $this->payable($po->id, 'PAY-PUSAT');

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame(FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_AMBIGUOUS_UNOWNED_WAREHOUSE, $result->reasonCode);
    }

    public function test_conflicting_warehouse_and_outlet_business_is_ambiguous(): void
    {
        // Warehouse says A, its outlet says B.
        $outlet = $this->outlet($this->businessB, 'OB');
        $warehouse = $this->warehouse($this->businessA, $outlet, 'WCONFLICT');
        $po = $this->purchaseOrder($warehouse->id, 'PO-CONFLICT');
        $payable = $this->payable($po->id, 'PAY-CONFLICT');

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame(FinancialOwnershipResult::AMBIGUOUS_OWNERSHIP, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_AMBIGUOUS_CONFLICT, $result->reasonCode);
        $this->assertNull($result->proposedBusinessId);
    }

    // ---------------------------------------------------------------------
    // D. DANGLING_OR_DELETED_PARENT — missing parent / intermediate
    // ---------------------------------------------------------------------

    public function test_dangling_parent_reference_fails_closed(): void
    {
        $payable = $this->payable(null, 'PAY-DANGLE-P');

        // Simulate imported/legacy data created without FK enforcement.
        $this->writeDanglingReference(function () use ($payable) {
            DB::table('payables')->where('id', $payable->id)->update(['purchase_order_id' => 999_999]);
        });

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame(FinancialOwnershipResult::DANGLING_OR_DELETED_PARENT, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_DANGLING_PARENT, $result->reasonCode);
    }

    public function test_dangling_warehouse_reference_fails_closed(): void
    {
        $po = $this->purchaseOrder(null, 'PO-DANGLE-W');
        $payable = $this->payable($po->id, 'PAY-DANGLE-W');

        $this->writeDanglingReference(function () use ($po) {
            DB::table('purchase_orders')->where('id', $po->id)->update(['warehouse_id' => 999_999]);
        });

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame(FinancialOwnershipResult::DANGLING_OR_DELETED_PARENT, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_DANGLING_WAREHOUSE, $result->reasonCode);
    }

    // ---------------------------------------------------------------------
    // E. ALREADY_RESOLVED / F. INVALID_OR_UNSUPPORTED_CHAIN
    // ---------------------------------------------------------------------

    public function test_already_resolved_requires_persisted_ownership(): void
    {
        // FIN-01 ownership schema is now part of the codebase; a fresh (unowned)
        // record must still never classify as ALREADY_RESOLVED.
        $this->assertTrue(Schema::hasColumn('payables', 'business_id'));
        $this->assertTrue(Schema::hasColumn('receivables', 'business_id'));
        $this->assertTrue(Schema::hasColumn('payables', 'ownership_status'));
        $this->assertTrue(Schema::hasColumn('receivables', 'ownership_status'));

        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-AR');
        $payable = $this->payable($po->id, 'PAY-AR');

        $result = $this->resolver()->resolve('payables', $payable->id);

        $this->assertNotSame(FinancialOwnershipResult::ALREADY_RESOLVED, $result->classification);
        $this->assertNull($result->evidence['existing_business_id']);
    }

    public function test_unknown_record_table_fails_closed(): void
    {
        $result = $this->resolver()->resolve('invoices', 1);

        $this->assertSame(FinancialOwnershipResult::INVALID_OR_UNSUPPORTED_CHAIN, $result->classification);
        $this->assertSame(FinancialOwnershipResolver::R_UNSUPPORTED, $result->reasonCode);
        $this->assertNull($result->proposedBusinessId);
    }

    // ---------------------------------------------------------------------
    // Command — read-only guarantees
    // ---------------------------------------------------------------------

    public function test_report_command_has_no_apply_option(): void
    {
        $command = $this->app->make(FinancialOwnershipReportCommand::class);
        $definition = $command->getDefinition();

        $this->assertFalse($definition->hasOption('apply'));
        $this->assertFalse($definition->hasOption('confirm-legacy-ownership'));
        $this->assertTrue($definition->hasOption('format'));
        $this->assertTrue($definition->hasOption('table'));
    }

    public function test_report_command_does_not_mutate_any_data(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-RO');
        $this->payable($po->id, 'PAY-RO');
        $this->payable(null, 'PAY-RO-MANUAL');
        $transaction = $this->transaction($warehouse->id, 'INV-RO');
        $this->receivable($transaction->id, 'RCV-RO');

        $snapshot = fn () => [
            'payables' => DB::table('payables')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'receivables' => DB::table('receivables')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'purchase_orders' => DB::table('purchase_orders')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'transactions' => DB::table('transactions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'warehouses' => DB::table('warehouses')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];

        $before = $snapshot();

        Artisan::call('business:financial-ownership-report');

        $this->assertSame($before, $snapshot());
        $this->assertTrue(Schema::hasColumn('payables', 'business_id'));
        $this->assertTrue(Schema::hasColumn('receivables', 'business_id'));
    }

    public function test_blanket_business_backfill_does_not_touch_financial_records(): void
    {
        // The blanket backfill refuses to guess when multiple businesses exist;
        // start from a clean slate so it can create its default business.
        DB::table('businesses')->delete();

        $payable = $this->payable(null, 'PAY-UNTOUCHED');
        $receivable = $this->receivable(null, 'RCV-UNTOUCHED');
        $payableUpdatedAt = $payable->updated_at;
        $receivableUpdatedAt = $receivable->updated_at;

        // Outlets/warehouses/master data get blanket-mapped to the created
        // business; financial records must not be touched.
        $this->artisan('business:backfill --create-default --apply --confirm-legacy-ownership')
            ->assertSuccessful();

        $this->assertNull($payable->fresh()->purchase_order_id);
        $this->assertEquals($payableUpdatedAt, $payable->fresh()->updated_at);
        $this->assertNull($receivable->fresh()->transaction_id);
        $this->assertEquals($receivableUpdatedAt, $receivable->fresh()->updated_at);
    }

    public function test_report_command_json_lists_classifications_and_counts(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-JSON');
        $this->payable($po->id, 'PAY-JSON-1');
        $this->payable(null, 'PAY-JSON-MANUAL');
        $this->payable(null, 'PAY-JSON-MANUAL-2');

        Artisan::call('business:financial-ownership-report', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('payables', $decoded['tables']);
        $payables = $decoded['tables']['payables'];

        $this->assertSame(3, $payables['total']);
        $this->assertSame(1, $payables['by_classification'][FinancialOwnershipResult::AUTO_RESOLVABLE]['count']);
        $this->assertSame(2, $payables['by_classification'][FinancialOwnershipResult::MANUAL_ASSIGNMENT_REQUIRED]['count']);
        $this->assertSame(2, $payables['by_reason'][FinancialOwnershipResolver::R_MANUAL_NO_PARENT]);
    }

    public function test_report_command_chunks_large_tables_without_losing_rows(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->payable(null, 'PAY-BULK-'.$i);
        }

        Artisan::call('business:financial-ownership-report', [
            '--format' => 'json',
            '--chunk' => '7',
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(25, $decoded['tables']['payables']['total']);
        $this->assertSame(25, $decoded['tables']['payables']['by_classification'][FinancialOwnershipResult::MANUAL_ASSIGNMENT_REQUIRED]['count']);
    }

    public function test_classification_is_independent_of_feature_flag(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-FLAG');
        $payable = $this->payable($po->id, 'PAY-FLAG');

        config()->set('multi_business.enabled', false);
        $legacy = $this->resolver()->resolve('payables', $payable->id);

        config()->set('multi_business.enabled', true);
        $tenancy = $this->resolver()->resolve('payables', $payable->id);

        $this->assertSame($legacy->toArray(), $tenancy->toArray());
        $this->assertSame($this->businessA->id, $tenancy->proposedBusinessId);
    }

    public function test_report_command_runs_with_tenancy_disabled(): void
    {
        config()->set('multi_business.enabled', false);
        $this->payable(null, 'PAY-LEGACY-CMD');

        $this->artisan('business:financial-ownership-report')->assertSuccessful();
    }
}
