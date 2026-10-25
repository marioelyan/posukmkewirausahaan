<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fase 1 slice 1: aturan JournalService (spec §4) — seimbang, satu sisi
 * per baris, idempotensi per dokumen sumber, dan jurnal balik.
 */
class JournalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccountSeeder::class]);
    }

    private function balancedLines(): array
    {
        return [
            ['code' => '1100', 'debit' => 10000],
            ['code' => '4100', 'credit' => 10000],
        ];
    }

    public function test_seeder_seeds_full_chart_without_computed_line(): void
    {
        $this->assertSame(count(Account::CHART), Account::count());
        // CHART keys are numeric strings, which PHP stores as integers;
        // the database returns codes as strings, so compare stringified.
        $this->assertSame(
            array_map('strval', array_keys(Account::CHART)),
            Account::orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame(0, Account::where('code', '3200')->count(), '3200 is computed, not posted.');
    }

    public function test_unbalanced_entry_is_rejected(): void
    {
        try {
            app(JournalService::class)->post('TestDoc', 1, null, '2026-10-25', 'Unbalanced', [
                ['code' => '1100', 'debit' => 10000],
                ['code' => '4100', 'credit' => 9000],
            ]);
            $this->fail('Expected InvalidArgumentException for unbalanced entry.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, JournalEntry::count(), 'Rejected entry must not be saved.');
        }
    }

    public function test_line_with_both_sides_is_rejected(): void
    {
        try {
            app(JournalService::class)->post('TestDoc', 2, null, '2026-10-25', 'Both sides', [
                ['code' => '1100', 'debit' => 5000, 'credit' => 5000],
                ['code' => '4100', 'credit' => 10000],
            ]);
            $this->fail('Expected InvalidArgumentException for a line filling both sides.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, JournalEntry::count());
        }
    }

    public function test_unknown_account_code_is_rejected(): void
    {
        try {
            app(JournalService::class)->post('TestDoc', 3, null, '2026-10-25', 'Unknown code', [
                ['code' => '9999', 'debit' => 10000],
                ['code' => '4100', 'credit' => 10000],
            ]);
            $this->fail('Expected InvalidArgumentException for unknown account code.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, JournalEntry::count());
        }
    }

    public function test_idempotent_post_returns_existing_entry(): void
    {
        $service = app(JournalService::class);

        $first = $service->post('TestDoc', 4, null, '2026-10-25', 'Original', $this->balancedLines());
        $second = $service->post('TestDoc', 4, null, '2026-10-25', 'Retry', $this->balancedLines());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::count(), 'Retry must not create a duplicate.');
        $this->assertSame(2, $first->lines()->count());
    }

    public function test_reversal_swaps_sides_and_links_original(): void
    {
        $service = app(JournalService::class);

        $original = $service->post('TestDoc', 5, null, '2026-10-25', 'Original', $this->balancedLines());
        $reversal = $service->reverse($original);

        $this->assertSame($original->id, (int) $reversal->reverses_entry_id);
        $this->assertSame(JournalService::SOURCE_REVERSAL, $reversal->source_type);
        $this->assertSame($original->id, (int) $reversal->source_id);

        $swapped = [];
        foreach ($reversal->lines()->with('account')->get() as $line) {
            $swapped[$line->account->code] = [(int) $line->debit, (int) $line->credit];
        }
        $this->assertSame([0, 10000], $swapped['1100']);
        $this->assertSame([10000, 0], $swapped['4100']);
        $this->assertSame(
            (int) $reversal->lines()->sum('debit'),
            (int) $reversal->lines()->sum('credit'),
            'Reversal must be balanced.'
        );

        try {
            $service->reverse($original);
            $this->fail('Expected InvalidArgumentException for a second direct reversal.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(2, JournalEntry::count(), 'Only original + one reversal may exist.');
        }
    }
}
