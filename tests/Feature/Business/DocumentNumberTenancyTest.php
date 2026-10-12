<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\GoodsReceiving;
use App\Models\PurchaseOrder;
use App\Models\SalesReturn;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\GoodsReceivingService;
use App\Services\PurchaseOrderService;
use App\Services\StockTransferService;
use App\Services\SupplierReturnService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UNIQUE-10 (Q4, decision B — per-business document numbers/codes).
 *
 * The six inventory/document tables (purchase_orders, goods_receivings,
 * supplier_returns, stock_transfers, sales_returns, stock_opnames) now carry
 * business ownership via BelongsToBusiness, their unique identifiers become
 * composite `(business_id, column)` after the conditional migration swap, and
 * the document number generator sequences per business.
 *
 * Flag off = legacy mode: the generator keeps the single global sequence.
 */
class DocumentNumberTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function activeBusiness(): Business
    {
        $business = Business::factory()->create();
        app(BusinessContext::class)->setActive($business);

        return $business;
    }

    private function today(): string
    {
        return now()->format('Ymd');
    }

    public function test_generator_uses_global_sequence_when_flag_off(): void
    {
        $service = app(PurchaseOrderService::class);

        $first = $service->generateDocumentNumber();
        $this->assertSame('PO-'.$this->today().'-0001', $first);

        PurchaseOrder::create(['document_number' => $first, 'status' => 'draft']);

        $this->assertSame('PO-'.$this->today().'-0002', $service->generateDocumentNumber());
    }

    public function test_all_document_services_delegate_to_the_shared_generator_when_flag_off(): void
    {
        $this->assertSame('PO-'.$this->today().'-0001', app(PurchaseOrderService::class)->generateDocumentNumber());
        $this->assertSame('GR-'.$this->today().'-0001', app(GoodsReceivingService::class)->generateDocumentNumber());
        $this->assertSame('SR-'.$this->today().'-0001', app(SupplierReturnService::class)->generateDocumentNumber());
        $this->assertSame('ST-'.$this->today().'-0001', app(StockTransferService::class)->generateDocumentNumber());
    }

    public function test_document_sequence_is_scoped_per_business_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $businessA = $this->activeBusiness();
        $service = app(PurchaseOrderService::class);

        $a1 = $service->generateDocumentNumber();
        $this->assertSame('PO-'.$this->today().'-0001', $a1);
        PurchaseOrder::create(['document_number' => $a1, 'status' => 'draft']);

        $a2 = $service->generateDocumentNumber();
        $this->assertSame('PO-'.$this->today().'-0002', $a2);
        PurchaseOrder::create(['document_number' => $a2, 'status' => 'draft']);

        app(BusinessContext::class)->clear();
        $businessB = $this->activeBusiness();

        // Business B restarts its own sequence; it never sees A's rows.
        $this->assertSame('PO-'.$this->today().'-0001', $service->generateDocumentNumber());

        $this->assertSame(2, PurchaseOrder::withoutGlobalScope('business')->where('business_id', $businessA->id)->count());
        $this->assertSame(0, PurchaseOrder::withoutGlobalScope('business')->where('business_id', $businessB->id)->count());
    }

    public function test_generator_survives_tenancy_without_active_business_context(): void
    {
        config()->set('multi_business.enabled', true);
        app(BusinessContext::class)->clear();

        // The counter query is deliberately counter-scoped, so the fail-closed
        // "no context" read branch (1 = 0) never makes the generator restart.
        $this->assertSame('PO-'.$this->today().'-0001', app(PurchaseOrderService::class)->generateDocumentNumber());
    }

    public function test_document_models_stamp_business_id_from_context_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);
        $business = $this->activeBusiness();

        $po = PurchaseOrder::create(['document_number' => 'PO-A', 'status' => 'draft']);
        $gr = GoodsReceiving::create(['document_number' => 'GR-A', 'purchase_order_id' => $po->id]);
        $sr = SupplierReturn::create(['document_number' => 'SR-A', 'status' => 'draft']);
        $st = StockTransfer::create([
            'document_number' => 'ST-A',
            'status' => 'draft',
            'source_warehouse_id' => Warehouse::factory()->create()->id,
            'destination_warehouse_id' => Warehouse::factory()->create()->id,
            'created_by' => User::factory()->create()->id,
        ]);

        foreach ([$po, $gr, $sr, $st] as $document) {
            $this->assertSame($business->id, (int) $document->business_id);
        }

        // SalesReturn / StockOpname share the same trait; a heavyweight
        // Transaction fixture is not needed to confirm their wiring.
        foreach ([SalesReturn::class, StockOpname::class] as $model) {
            $this->assertContains(BelongsToBusiness::class, class_uses_recursive($model));
        }
    }

    public function test_document_reads_are_scoped_per_business_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $businessA = $this->activeBusiness();
        $poA = PurchaseOrder::create(['document_number' => 'PO-A', 'status' => 'draft']);
        GoodsReceiving::create(['document_number' => 'GR-A', 'purchase_order_id' => $poA->id]);

        app(BusinessContext::class)->clear();
        $businessB = $this->activeBusiness();
        PurchaseOrder::create(['document_number' => 'PO-B', 'status' => 'draft']);

        $this->assertSame(1, PurchaseOrder::withoutGlobalScope('business')->where('business_id', $businessA->id)->count());
        $this->assertSame(1, PurchaseOrder::withoutGlobalScope('business')->where('business_id', $businessB->id)->count());

        app(BusinessContext::class)->clear();
        app(BusinessContext::class)->setActive($businessA);
        $this->assertSame('PO-A', PurchaseOrder::first()->document_number);
        $this->assertSame('GR-A', GoodsReceiving::first()->document_number);

        app(BusinessContext::class)->clear();
        app(BusinessContext::class)->setActive($businessB);
        $this->assertSame('PO-B', PurchaseOrder::first()->document_number);
        $this->assertFalse(GoodsReceiving::exists());
    }

    public function test_same_document_number_is_allowed_across_businesses_but_rejected_within_one(): void
    {
        config()->set('multi_business.enabled', true);

        $businessA = $this->activeBusiness();
        PurchaseOrder::create(['document_number' => 'PO-DUP', 'status' => 'draft']);

        app(BusinessContext::class)->clear();
        $businessB = $this->activeBusiness();
        // Same number in a different business: allowed under the composite
        // `(business_id, document_number)` index (fresh DB swaps immediately).
        PurchaseOrder::create(['document_number' => 'PO-DUP', 'status' => 'draft']);

        $this->assertSame(2, PurchaseOrder::withoutGlobalScope('business')->where('document_number', 'PO-DUP')->count());
        $this->assertSame(1, PurchaseOrder::withoutGlobalScope('business')->where('business_id', $businessA->id)->count());
        $this->assertSame(1, PurchaseOrder::withoutGlobalScope('business')->where('business_id', $businessB->id)->count());

        // Same number again inside business B: the composite unique rejects it.
        $this->expectException(QueryException::class);
        PurchaseOrder::create(['document_number' => 'PO-DUP', 'status' => 'draft']);
    }
}
