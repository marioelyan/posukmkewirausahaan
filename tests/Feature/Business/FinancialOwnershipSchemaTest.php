<?php

namespace Tests\Feature\Business;

use App\Models\Business;
use App\Models\Payable;
use App\Models\Receivable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FIN-01 — ownership schema (migrations 2026_10_11_100001..100003).
 *
 * The columns are additive and nullable; fresh records default to
 * `ownership_status = 'unassigned'` with no business. The business FK uses
 * RESTRICT (design D4): an in-use business can never be hard-deleted.
 */
class FinancialOwnershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessA = Business::factory()->create(['status' => Business::STATUS_ACTIVE]);
    }

    public function test_ownership_schema_columns_are_present_nullable_and_default_unassigned(): void
    {
        foreach (['payables', 'receivables'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'business_id'));
            $this->assertTrue(Schema::hasColumn($table, 'ownership_status'));
            $this->assertTrue(Schema::hasColumn($table, 'ownership_evaluated_at'));
            $this->assertTrue(Schema::hasColumn($table, 'ownership_note'));
        }

        $payable = Payable::create(['document_number' => 'PAY-S', 'total' => 100, 'status' => 'unpaid', 'paid' => 0]);
        $receivable = Receivable::create(['invoice' => 'RCV-S', 'total' => 100, 'status' => 'unpaid', 'paid' => 0]);

        $p = DB::table('payables')->where('id', $payable->id)->first();
        $r = DB::table('receivables')->where('id', $receivable->id)->first();

        $this->assertNull($p->business_id);
        $this->assertSame('unassigned', $p->ownership_status);
        $this->assertNull($p->ownership_evaluated_at);
        $this->assertNull($p->ownership_note);

        $this->assertNull($r->business_id);
        $this->assertSame('unassigned', $r->ownership_status);
        $this->assertNull($r->ownership_evaluated_at);
    }

    public function test_business_referenced_by_a_payable_cannot_be_deleted(): void
    {
        Payable::create([
            'document_number' => 'PAY-FK',
            'total' => 100,
            'status' => 'unpaid',
            'paid' => 0,
            'business_id' => $this->businessA->id,
            'ownership_status' => 'resolved',
        ]);

        $this->expectException(QueryException::class);
        $this->businessA->delete();
    }

    public function test_business_referenced_by_a_receivable_cannot_be_deleted(): void
    {
        Receivable::create([
            'invoice' => 'RCV-FK',
            'total' => 100,
            'status' => 'unpaid',
            'paid' => 0,
            'business_id' => $this->businessA->id,
            'ownership_status' => 'resolved',
        ]);

        $this->expectException(QueryException::class);
        $this->businessA->delete();
    }

    public function test_model_fillable_allows_persisted_ownership_stamping(): void
    {
        $payable = Payable::create([
            'document_number' => 'PAY-STAMPED',
            'total' => 100,
            'status' => 'unpaid',
            'business_id' => $this->businessA->id,
            'ownership_status' => 'resolved',
            'ownership_evaluated_at' => now(),
            'ownership_note' => 'FIN-04 creation stamp',
        ]);

        $this->assertSame($this->businessA->id, (int) $payable->business_id);
        $this->assertSame('resolved', $payable->ownership_status);
        $this->assertSame('FIN-04 creation stamp', $payable->ownership_note);
    }
}
