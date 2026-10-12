<?php

namespace App\Imports;

use App\Models\Customer;
use App\Services\BusinessContext;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use RuntimeException;

class CustomersImport implements ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    public function model(array $row)
    {
        $attributes = [
            'name' => $row['nama'],
            'no_telp' => $row['telepon'] ?? null,
            'address' => $row['alamat'] ?? null,
        ];

        // `WithBatchInserts` makes Maatwebsite flush rows through a raw
        // `Customer::query()->insert()`, which bypasses Eloquent's `creating`
        // event (and therefore the `BelongsToBusiness` ownership hook). Assign
        // ownership explicitly from the verified server-side context so an
        // uploaded row can never create an unowned or cross-business customer.
        // A client-supplied `business_id` is never read from the file.
        if (config('multi_business.enabled')) {
            $businessId = app(BusinessContext::class)->businessId();

            if ($businessId === null) {
                throw new RuntimeException('Active business context is required to import customers.');
            }

            $attributes['business_id'] = $businessId;
        }

        return new Customer($attributes);
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            'nama.required' => 'Nama customer wajib diisi.',
        ];
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 100;
    }
}
