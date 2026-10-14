<?php

namespace App\Http\Requests;

use App\Models\Outlet;
use App\Services\OutletAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->id ?? null;
        $isCreate = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$isCreate ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'selectedRoles' => ['required', 'array', 'min:1'],
            'selectedRoles.*' => ['string', Rule::exists('roles', 'name')],
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['integer', 'exists:outlets,id'],
            'default_outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $outletIds = collect($this->input('outlet_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique();
            $defaultOutletId = (int) $this->input('default_outlet_id');

            if ($defaultOutletId && ! $outletIds->contains($defaultOutletId)) {
                $validator->errors()->add('default_outlet_id', 'Outlet default harus termasuk outlet yang dipilih.');
            }

            if ($outletIds->isNotEmpty()) {
                $activeCount = Outlet::query()
                    ->whereIn('id', $outletIds)
                    ->where('is_active', true)
                    ->count();

                if ($activeCount !== $outletIds->count()) {
                    $validator->errors()->add('outlet_ids', 'Semua outlet yang dipilih harus aktif.');
                }
            }

            $actor = $this->user();

            if (! $actor) {
                return;
            }

            if (! $actor->isSuperAdmin() && collect($this->input('selectedRoles', []))->contains('super-admin')) {
                $validator->errors()->add('selectedRoles', 'Anda tidak berwenang menetapkan role super-admin.');
            }

            if ($outletIds->isNotEmpty()) {
                $accessibleOutletIds = app(OutletAccessService::class)
                    ->accessibleOutlets($actor)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id);

                if ($outletIds->diff($accessibleOutletIds)->isNotEmpty()) {
                    $validator->errors()->add('outlet_ids', 'Anda tidak berwenang menetapkan outlet tersebut.');
                }
            }
        });
    }
}
