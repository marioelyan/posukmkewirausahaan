<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftCashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cashier_shift_id' => $this->cashier_shift_id,
            'type' => $this->type,
            'kind' => $this->kind,
            'expense_id' => $this->expense_id,
            'amount' => $this->amount,
            'note' => $this->note,
            'user_id' => $this->user_id,
            'expense' => $this->whenLoaded('expense', fn () => new ExpenseResource($this->expense)),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
