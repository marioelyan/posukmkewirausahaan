<?php

namespace App\Http\Requests;

use App\Rules\BelongsToActiveBusiness;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockOpnameItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id', new BelongsToActiveBusiness('products')],
        ];
    }
}
