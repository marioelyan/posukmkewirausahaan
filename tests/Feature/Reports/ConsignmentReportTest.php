<?php

namespace Tests\Feature\Reports;

use App\Models\Payable;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * K4 Konsinyasi: laporan hutang titipan per penitip.
 *
 * Hanya hutang dari penjualan (transaction_id terisi) yang masuk laporan.
 * Hutang manual / PO tidak ikut.
 */
class ConsignmentReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Supplier $ani;

    protected Supplier $budi;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'payables-access', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('payables-access');

        $this->ani = Supplier::create(['name' => 'Ibu Ani', 'phone' => '081234567890']);
        $this->budi = Supplier::create(['name' => 'Pak Budi', 'phone' => '089876543210']);
    }

    private function makeTransaction(): Transaction
    {
        return Transaction::create([
            'cashier_id' => $this->user->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);
    }

    private function makePayable(Supplier $supplier, int $total, int $paid = 0, ?Transaction $transaction = null): Payable
    {
        return Payable::create([
            'supplier_id' => $supplier->id,
            'transaction_id' => $transaction?->id,
            'document_number' => 'KON-'.Str::upper(Str::random(8)),
            'total' => $total,
            'paid' => $paid,
            'status' => $paid > 0 ? ($paid >= $total ? 'paid' : 'partial') : 'unpaid',
        ]);
    }

    public function test_report_shows_consignment_totals_per_supplier(): void
    {
        // Satu hutang per penitip per transaksi (invarian K2): dua hutang
        // Ibu Ani berasal dari dua penjualan berbeda.
        $this->makePayable($this->ani, 25000, 10000, $this->makeTransaction());
        $this->makePayable($this->ani, 15000, 15000, $this->makeTransaction());
        $this->makePayable($this->budi, 20000, 0, $this->makeTransaction());
        // Hutang manual (tanpa transaction_id) tidak boleh masuk laporan.
        $this->makePayable($this->budi, 99999, 0, null);

        $response = $this->actingAs($this->user)->get(route('reports.consignment.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Reports/Consignment')
            ->where('grand.documents', 3)
            ->where('grand.total', 60000)
            ->where('grand.paid', 25000)
            ->where('grand.remaining', 35000)
            ->has('perSupplier', 2)
            ->has('payables.data', 3)
        );
    }

    public function test_report_filters_by_supplier(): void
    {
        $trx = $this->makeTransaction();
        $this->makePayable($this->ani, 25000, 0, $trx);
        $this->makePayable($this->budi, 20000, 0, $trx);

        $response = $this->actingAs($this->user)
            ->get(route('reports.consignment.index', ['supplier_id' => $this->ani->id]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('grand.documents', 1)
            ->where('grand.total', 25000)
            ->has('perSupplier', 1)
            ->has('payables.data', 1)
        );
    }

    public function test_report_filters_by_status(): void
    {
        $trx = $this->makeTransaction();
        $this->makePayable($this->ani, 25000, 25000, $trx);
        $this->makePayable($this->budi, 20000, 0, $trx);

        $response = $this->actingAs($this->user)
            ->get(route('reports.consignment.index', ['status' => 'paid']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('grand.documents', 1)
            ->has('payables.data', 1)
        );
    }

    public function test_user_without_payables_access_cannot_open_report(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('reports.consignment.index'))
            ->assertForbidden();
    }
}
