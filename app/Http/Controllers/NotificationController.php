<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductNotificationRead;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Mark a single low-stock notification as read for the current user.
     */
    public function markLowStockRead(Request $request)
    {
        $request->validate([
            'product_id' => ['required', 'exists:products,id'],
        ]);

        ProductNotificationRead::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'product_id' => $request->product_id,
            ],
            []
        );

        return back()->with('status', 'notification-read');
    }

    /**
     * Mark all low-stock notifications as read for the current user.
     */
    public function markAllLowStockRead(Request $request)
    {
        $productIds = Product::where('stock', '<=', 0)->pluck('id')->all();

        if (count($productIds) === 0) {
            return back();
        }

        $payload = collect($productIds)->map(function ($productId) use ($request) {
            return [
                'user_id' => $request->user()->id,
                'product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        });

        ProductNotificationRead::upsert(
            $payload->toArray(),
            ['user_id', 'product_id'],
            ['updated_at']
        );

        return back()->with('status', 'notification-read-all');
    }
}
