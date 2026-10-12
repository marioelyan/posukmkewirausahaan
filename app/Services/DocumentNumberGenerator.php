<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Per-business document number generation (UNIQUE-10).
 *
 * Produces the same `PREFIX-YYYYMMDD-####` shape the legacy generators used,
 * but the sequence is scoped per business when tenancy is enabled AND an
 * active business context exists. With the flag off — or without a context —
 * the legacy global sequence is kept, so existing installs behave exactly as
 * before.
 *
 * The counter query is deliberately counter-scoped (`withoutGlobalScope`) and
 * filters `business_id` explicitly: the ambient model scope must never skew
 * the counter (e.g. the fail-closed "no context" branch would otherwise make
 * every generator restart at 0001).
 */
class DocumentNumberGenerator
{
    public function __construct(private readonly BusinessContext $context) {}

    /**
     * @param  class-string  $modelClass  Model whose table has `business_id`.
     */
    public function next(string $modelClass, string $prefix): string
    {
        $businessId = config('multi_business.enabled') ? $this->context->businessId() : null;

        $base = $prefix.now()->format('Ymd').'-';

        $last = $modelClass::withoutGlobalScope('business')
            ->where('document_number', 'like', $base.'%')
            ->when($businessId !== null, fn ($query) => $query->where('business_id', $businessId))
            ->orderByDesc('document_number')
            ->value('document_number');

        $next = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        return $base.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
