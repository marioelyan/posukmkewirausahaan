<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'account_code' => $this->account_code,
            'is_active' => $this->is_active,
            'expenses_count' => $this->whenCounted('expenses'),
        ];
    }
}
