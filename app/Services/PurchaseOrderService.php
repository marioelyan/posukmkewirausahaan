<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService,
        private readonly DocumentNumberGenerator $documentNumberGenerator
    ) {}

    public function generateDocumentNumber(): string
    {
        return $this->documentNumberGenerator->next(PurchaseOrder::class, 'PO-');
    }

    public function createOrder(array $data, array $items, int $userId): PurchaseOrder
    {
        $warehouseId = $data['warehouse_id'] ?? null;
        if ($warehouseId) {
            $user = User::findOrFail($userId);
            $warehouse = Warehouse::findOrFail($warehouseId);
            abort_unless($this->outletAccessService->canUseWarehouse($user, $warehouse), 403);
        }

        return DB::transaction(function () use ($data, $items, $userId) {
            $order = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'document_number' => $data['document_number'] ?? $this->generateDocumentNumber(),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'qty_ordered' => $item['qty_ordered'],
                    'qty_received' => 0,
                    'unit_price' => $item['unit_price'],
                ]);
            }

            $this->auditLogService->log(
                event: 'purchase_order.created',
                module: 'purchase',
                auditable: $order,
                description: 'Purchase order '.$order->document_number.' dibuat.',
                after: [
                    'document_number' => $order->document_number,
                    'supplier_id' => $order->supplier_id,
                    'status' => 'draft',
                    'total_items' => count($items),
                ],
                meta: ['purchase_order_id' => $order->id],
            );

            return $order;
        });
    }

    public function placeOrder(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $before = $order->replicate();

            $order->update([
                'status' => 'ordered',
                'ordered_at' => now(),
            ]);

            $this->auditLogService->log(
                event: 'purchase_order.ordered',
                module: 'purchase',
                auditable: $order,
                description: 'Purchase order '.$order->document_number.' dipesan ke supplier.',
                before: ['status' => $before->status],
                after: ['status' => 'ordered'],
                meta: ['purchase_order_id' => $order->id],
            );
        });
    }

    public function cancelOrder(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $before = $order->replicate();

            $order->update([
                'status' => 'cancelled',
            ]);

            $this->auditLogService->log(
                event: 'purchase_order.cancelled',
                module: 'purchase',
                auditable: $order,
                description: 'Purchase order '.$order->document_number.' dibatalkan.',
                before: ['status' => $before->status],
                after: ['status' => 'cancelled'],
                meta: ['purchase_order_id' => $order->id],
            );
        });
    }
}
