<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'cashier_shift_id' => $this->cashier_shift_id,
            'date' => $this->date?->toDateString(),
            'amount' => $this->amount,
            'source' => $this->source,
            'note' => $this->note,
            'proof_reference' => $this->proof_reference,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
