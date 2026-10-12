<?php

namespace Tests\Feature\Business;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinancialOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FIN-01/05 — validated backfill (apply mode, dual confirmation) and FIN-02
 * manual assignment workflow.
 *
 * Covers: dry-run never writes; --apply demands --confirm-financial-ownership;
 * only chain-proven rows get a business_id; idempotency; staged --limit
 * rollouts; per-row audit with run_id (D6); conflict → non-zero exit; JSON run
 * report with provenance (D7); and the report command's D8/D9 fixes.
 */
class FinancialOwnershipBackfillTest extends TestCase
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

    private function purchaseOrder(int $warehouseId, string $document): PurchaseOrder
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

    private function transaction(int $warehouseId, string $invoice): Transaction
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

    private function service(): FinancialOwnershipService
    {
        return app(FinancialOwnershipService::class);
    }

    // ---------------------------------------------------------------------
    // Dry-run & dual confirmation
    // ---------------------------------------------------------------------

    public function test_dry_run_writes_nothing(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-DRY');
        $payable = $this->payable($po->id, 'PAY-DRY');

        $this->artisan('business:financial-ownership-backfill')->assertExitCode(0);

        $row = DB::table('payables')->where('id', $payable->id)->first();
        $this->assertNull($row->business_id);
        $this->assertSame('unassigned', $row->ownership_status);
    }

    public function test_apply_requires_dual_confirmation(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-CONF');
        $payable = $this->payable($po->id, 'PAY-CONF');

        $this->artisan('business:financial-ownership-backfill --apply')->assertExitCode(1);

        $row = DB::table('payables')->where('id', $payable->id)->first();
        $this->assertNull($row->business_id);
        $this->assertSame('unassigned', $row->ownership_status);
    }

    // ---------------------------------------------------------------------
    // Apply mode
    // ---------------------------------------------------------------------

    public function test_apply_writes_only_auto_resolvable_rows(): void
    {
        $outletB = $this->outlet($this->businessB, 'OB');
        $warehouseA = $this->warehouse($this->businessA, null, 'WA');
        $warehouseB = $this->warehouse($this->businessB, $outletB, 'WB');

        $payableA = $this->payable($this->purchaseOrder($warehouseA->id, 'PO-A')->id, 'PAY-A');
        $payableB = $this->payable($this->purchaseOrder($warehouseB->id, 'PO-B')->id, 'PAY-B');
        $payableManual = $this->payable(null, 'PAY-MANUAL');
        $receivableAuto = $this->receivable($this->transaction($warehouseA->id, 'INV-A')->id, 'RCV-A');
        $receivableManual = $this->receivable(null, 'RCV-MANUAL');

        $this->artisan('business:financial-ownership-backfill --apply --confirm-financial-ownership')
            ->assertExitCode(0);

        $this->assertSame($this->businessA->id, (int) DB::table('payables')->where('id', $payableA->id)->value('business_id'));
        $this->assertSame('resolved', DB::table('payables')->where('id', $payableA->id)->value('ownership_status'));

        $this->assertSame($this->businessB->id, (int) DB::table('payables')->where('id', $payableB->id)->value('business_id'));

        $this->assertNull(DB::table('payables')->where('id', $payableManual->id)->value('business_id'));
        $this->assertSame('manual_required', DB::table('payables')->where('id', $payableManual->id)->value('ownership_status'));

        $this->assertSame($this->businessA->id, (int) DB::table('receivables')->where('id', $receivableAuto->id)->value('business_id'));
        $this->assertNull(DB::table('receivables')->where('id', $receivableManual->id)->value('business_id'));
        $this->assertSame('manual_required', DB::table('receivables')->where('id', $receivableManual->id)->value('ownership_status'));
    }

    public function test_apply_is_idempotent(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');

        for ($i = 0; $i < 3; $i++) {
            $this->payable($this->purchaseOrder($warehouse->id, 'PO-RUN-'.$i)->id, 'PAY-RUN-'.$i);
        }

        $first = $this->service()->applyBackfill('payables', 10, null);
        $this->assertSame(3, $first['written']);

        $second = $this->service()->applyBackfill('payables', 10, null);
        $this->assertSame(0, $second['written']);
        $this->assertSame(3, $second['skipped_already_owned']);
    }

    public function test_apply_limit_stages_rollout(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');

        for ($i = 0; $i < 5; $i++) {
            $this->payable($this->purchaseOrder($warehouse->id, 'PO-STAGE-'.$i)->id, 'PAY-STAGE-'.$i);
        }

        $staged = $this->service()->applyBackfill('payables', 10, 2);
        $this->assertSame(2, $staged['written']);

        $remaining = DB::table('payables')->whereNull('business_id')->count();
        $this->assertSame(3, $remaining);

        $rest = $this->service()->applyBackfill('payables', 10, null);
        $this->assertSame(3, $rest['written']);
        $this->assertSame(0, DB::table('payables')->whereNull('business_id')->count());
    }

    public function test_apply_writes_per_row_audit_with_run_id(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-AUDIT');
        $this->payable($po->id, 'PAY-AUDIT');

        $this->service()->applyBackfill('payables', 10, null, 'fin-20261011-test');

        $logs = AuditLog::where('event', 'financial.ownership_backfilled')->get();

        $this->assertCount(1, $logs);
        $this->assertSame('fin-20261011-test', $logs->first()->meta['run_id']);
        $this->assertSame('payables', $logs->first()->meta['table']);
    }

    public function test_apply_exits_nonzero_when_conflicts_require_review(): void
    {
        // Warehouse says A, its outlet says B → chain proves no business.
        $outletB = $this->outlet($this->businessB, 'OB');
        $warehouse = $this->warehouse($this->businessA, $outletB, 'WCONF');
        $payable = $this->payable($this->purchaseOrder($warehouse->id, 'PO-CONF')->id, 'PAY-CONF');

        $this->artisan('business:financial-ownership-backfill --apply --confirm-financial-ownership --table=payables')
            ->assertExitCode(1);

        $row = DB::table('payables')->where('id', $payable->id)->first();
        $this->assertNull($row->business_id);
        $this->assertSame('ambiguous', $row->ownership_status);
    }

    public function test_apply_writes_json_run_report_with_provenance(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-REPORT');
        $this->payable($po->id, 'PAY-REPORT');

        $reportPath = sys_get_temp_dir().'/fin-backfill-'.uniqid().'.json';

        $this->artisan('business:financial-ownership-backfill --apply --confirm-financial-ownership --report='.$reportPath)
            ->assertExitCode(0);

        $report = json_decode((string) file_get_contents($reportPath), true);

        $this->assertSame('apply', $report['mode']);
        $this->assertArrayHasKey('provenance', $report);
        $this->assertArrayHasKey('database', $report['provenance']);
        $this->assertArrayHasKey('multi_business_enabled', $report['provenance']);
        $this->assertArrayHasKey('run_id', $report['tables']['payables']);
        $this->assertSame(1, $report['tables']['payables']['written']);

        unlink($reportPath);
    }

    // ---------------------------------------------------------------------
    // Report command — D7 / D8 / D9 fixes
    // ---------------------------------------------------------------------

    public function test_report_json_includes_provenance_records_when_list_and_d9_reflects_schema(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $po = $this->purchaseOrder($warehouse->id, 'PO-J');
        $this->payable($po->id, 'PAY-J');
        $this->payable(null, 'PAY-J-MANUAL');

        Artisan::call('business:financial-ownership-report', ['--format' => 'json', '--list' => '1']);
        $decoded = json_decode(Artisan::output(), true);

        // D7
        $this->assertArrayHasKey('provenance', $decoded);
        $this->assertArrayHasKey('database', $decoded['provenance']);
        $this->assertSame('business:financial-ownership-report', $decoded['provenance']['generated_by']);

        // D8
        $this->assertArrayHasKey('records', $decoded['tables']['payables']);
        $this->assertCount(2, $decoded['tables']['payables']['records']);

        // D9 — schema present ⇒ the "unreachable" limitation is replaced by the
        // resolved.conflict note.
        $limitations = json_encode($decoded['limitations'], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('ALREADY_RESOLVED is currently unreachable', $limitations);
        $this->assertStringContainsString('resolved.conflict', $limitations);
    }

    // ---------------------------------------------------------------------
    // FIN-02 — manual assignment workflow
    // ---------------------------------------------------------------------

    public function test_manual_assignment_sets_ownership_and_audits(): void
    {
        $payable = $this->payable(null, 'PAY-ASSIGN');

        $result = $this->service()->assign(
            businessId: $this->businessA->id,
            table: 'payables',
            id: $payable->id,
            note: 'verified against supplier book',
        );

        $this->assertSame('resolved', $result['ownership_status']);
        $this->assertSame($this->businessA->id, (int) DB::table('payables')->where('id', $payable->id)->value('business_id'));
        $this->assertSame('resolved', DB::table('payables')->where('id', $payable->id)->value('ownership_status'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'financial.ownership_assigned']);
    }

    public function test_reassignment_requires_reason(): void
    {
        $payable = $this->payable(null, 'PAY-REASSIGN');
        $this->service()->assign($this->businessA->id, 'payables', $payable->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->assign($this->businessB->id, 'payables', $payable->id);
    }

    public function test_reassignment_with_reason_and_override_succeeds(): void
    {
        $payable = $this->payable(null, 'PAY-REASSIGN-2');
        $this->service()->assign($this->businessA->id, 'payables', $payable->id);

        $result = $this->service()->assign(
            businessId: $this->businessB->id,
            table: 'payables',
            id: $payable->id,
            reason: 'data correction after audit',
            overwriteConflict: true,
        );

        $this->assertSame($this->businessB->id, (int) DB::table('payables')->where('id', $payable->id)->value('business_id'));
        $this->assertSame('resolved', $result['ownership_status']);
    }

    public function test_assignment_against_a_proven_chain_requires_override(): void
    {
        $warehouse = $this->warehouse($this->businessA, null, 'WA');
        $payable = $this->payable($this->purchaseOrder($warehouse->id, 'PO-CHAIN')->id, 'PAY-CHAIN');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->assign($this->businessB->id, 'payables', $payable->id);
    }

    public function test_assignment_to_inactive_business_is_rejected(): void
    {
        $archived = Business::factory()->create(['status' => Business::STATUS_ARCHIVED]);
        $payable = $this->payable(null, 'PAY-INACTIVE');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->assign($archived->id, 'payables', $payable->id);
    }

    public function test_assign_command_exposes_failures(): void
    {
        $payable = $this->payable(null, 'PAY-CMD');

        $this->artisan('business:financial-ownership-assign payables '.$payable->id.' '.$this->businessA->id)
            ->assertSuccessful();

        $this->assertSame($this->businessA->id, (int) DB::table('payables')->where('id', $payable->id)->value('business_id'));

        $this->artisan('business:financial-ownership-assign payables 999999 '.$this->businessA->id)
            ->assertExitCode(1);
    }
}
