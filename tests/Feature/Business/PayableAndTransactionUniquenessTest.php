<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Payable;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BusinessContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UNIQUE-10 (§13.20) — the two identifier constraints beyond the six-table swap:
 *
 *  - `transactions.invoice`     -> global unique (random token, read without a
 *                                  business context by PDF / public share / portal)
 *  - `payables.document_number` -> composite `(business_id, document_number)`
 *
 * Both migrations are data-guarded. A fresh test database has no legacy rows to
 * trip the guard, so this suite exercises exactly the state production reaches
 * after `business:backfill` + this migration.
 *
 * Legacy invariants are locked too: MySQL/SQLite treat NULL business_id rows as
 * distinct, so the composite never constrains unscoped rows — flag-off installs
 * keep allowing duplicates while the constraint is latent.
 */
class PayableAndTransactionUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function activeBusiness(): Business
    {
        $business = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
        app(BusinessContext::class)->setActive($business);

        return $business;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionAttributes(string $invoice): array
    {
        return [
            'cashier_id' => User::factory()->create()->id,
            'invoice' => $invoice,
            'cash' => 50000,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 50000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payableAttributes(?int $businessId, string $documentNumber): array
    {
        return [
            'business_id' => $businessId,
            'document_number' => $documentNumber,
            'total' => 250000,
            'paid' => 0,
            'status' => 'unpaid',
            'ownership_status' => $businessId === null ? 'unassigned' : 'resolved',
            'ownership_evaluated_at' => $businessId === null ? null : now(),
        ];
    }

    public function test_transaction_invoice_is_globally_unique_in_fresh_database(): void
    {
        Transaction::create($this->transactionAttributes('TRX-GLOBAL-DUP'));

        $this->expectException(QueryException::class);
        Transaction::create($this->transactionAttributes('TRX-GLOBAL-DUP'));
    }

    public function test_transaction_invoice_uniqueness_is_global_not_per_business(): void
    {
        config()->set('multi_business.enabled', true);

        $businessA = $this->activeBusiness();
        Transaction::create($this->transactionAttributes('TRX-SHARED'));

        app(BusinessContext::class)->clear();
        $businessB = $this->activeBusiness();

        // Same invoice under a different business is still rejected: the lookups
        // that read `invoice` (PDF, /share/transactions/{invoice}, portal) carry
        // no business context, so the token must be globally unique.
        $this->expectException(QueryException::class);
        Transaction::create($this->transactionAttributes('TRX-SHARED'));
    }

    public function test_payable_document_number_is_unique_per_business_when_tenancy_enabled(): void
    {
        config()->set('multi_business.enabled', true);

        $businessA = $this->activeBusiness();
        Payable::create($this->payableAttributes($businessA->id, 'PAY-DUP'));

        app(BusinessContext::class)->clear();
        $businessB = $this->activeBusiness();
        // Same number in another business: allowed under the composite index.
        Payable::create($this->payableAttributes($businessB->id, 'PAY-DUP'));

        $this->assertSame(
            2,
            Payable::withoutGlobalScopes()->where('document_number', 'PAY-DUP')->count()
        );

        // Same number again inside business B: the composite unique rejects it.
        $this->expectException(QueryException::class);
        Payable::create($this->payableAttributes($businessB->id, 'PAY-DUP'));
    }

    public function test_payable_document_number_duplicates_stay_allowed_for_unscoped_rows_when_flag_off(): void
    {
        // Flag off => no business context => business_id stays NULL. The
        // composite index treats NULL rows as distinct, so the legacy
        // single-tenant behaviour (no uniqueness) is preserved.
        Payable::create($this->payableAttributes(null, 'PAY-LEGACY-DUP'));
        Payable::create($this->payableAttributes(null, 'PAY-LEGACY-DUP'));

        $this->assertSame(
            2,
            Payable::withoutGlobalScopes()->where('document_number', 'PAY-LEGACY-DUP')->count()
        );
    }

    public function test_payable_store_rejects_duplicate_document_number_with_validation(): void
    {
        config()->set('multi_business.enabled', true);

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $admin->markEmailAsVerified();

        $business = $this->activeBusiness();

        $session = fn () => $this->actingAs($admin)
            ->withSession(['active_business_id' => $business->id]);

        $payload = [
            'document_number' => 'PAY-HTTP-DUP',
            'total' => 100000,
            'status' => 'unpaid',
        ];

        $session()->post(route('payables.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('payables.index'));

        // The duplicate is reported as a field error, not a 500 QueryException.
        $session()->post(route('payables.store'), $payload)
            ->assertSessionHasErrors('document_number');

        $this->assertSame(
            1,
            Payable::withoutGlobalScopes()->where('document_number', 'PAY-HTTP-DUP')->count()
        );
    }
}
