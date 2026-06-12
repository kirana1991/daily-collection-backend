<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CollectionEntry;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InstallmentDueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_multiple_missed_daily_emis_have_separate_dues_and_penalties(): void
    {
        Carbon::setTestNow('2026-06-12 09:00:00');

        $client = Client::create([
            'client_code' => 'DM-C-0001',
            'name' => 'Test Client',
            'mobile' => '9999999999',
            'address' => 'Test address',
        ]);
        $loan = Loan::create([
            'loan_code' => 'DM-L-0001',
            'client_id' => $client->id,
            'loan_date' => '2026-06-09',
            'loan_amount' => 1000,
            'loan_type' => '100_days',
            'daily_collection_amount' => 10,
            'weekly_emi' => 70,
            'first_emi_date' => '2026-06-09',
            'next_due_date' => '2026-06-09',
            'penalty_per_day' => 50,
            'status' => 'active',
        ]);
        CollectionEntry::create([
            'client_id' => $client->id,
            'loan_id' => $loan->id,
            'collection_date' => '2026-06-09',
            'amount_collected' => 10,
            'emi_amount' => 0,
            'penalty_amount' => 0,
            'collection_mode' => 'cash',
            'collected_at' => '2026-06-09 09:00:00',
            'status' => 'approved',
        ]);

        $loan->rebuildCollectionAccounting();

        $this->assertSame(['2026-06-10', '2026-06-11'], $loan->overdueInstallments()
            ->pluck('due_date')
            ->map->toDateString()
            ->all());
        $this->assertSame(20.0, $loan->overdueEmiAmount());
        $this->assertSame(10.0, $loan->dueTodayAmount());
        $this->assertSame(150.0, $loan->outstandingPenalty());
        $penaltyDates = $loan->penalties()
            ->orderBy('emi_due_date')
            ->orderBy('penalty_date')
            ->get()
            ->map(fn ($penalty) => [
                $penalty->emi_due_date->toDateString(),
                $penalty->penalty_date->toDateString(),
                (float) $penalty->penalty_amount,
            ])
            ->all();

        $this->assertSame([
            ['2026-06-10', '2026-06-11', 50.0],
            ['2026-06-10', '2026-06-12', 50.0],
            ['2026-06-11', '2026-06-12', 50.0],
        ], $penaltyDates);
    }

    public function test_pending_dues_report_exposes_overdue_and_today_separately(): void
    {
        Carbon::setTestNow('2026-06-12 09:00:00');

        $this->test_multiple_missed_daily_emis_have_separate_dues_and_penalties();

        $this->getJson('/api/reports/pending-dues')
            ->assertOk()
            ->assertJsonPath('rows.0.overdue_installments_count', 2)
            ->assertJsonPath('rows.0.pending_emi', 20)
            ->assertJsonPath('rows.0.due_today', 10)
            ->assertJsonPath('rows.0.pending_penalty', 150)
            ->assertJsonPath('rows.0.total_due', 180);
    }

    public function test_one_emi_payment_clears_only_the_oldest_missed_installment(): void
    {
        Carbon::setTestNow('2026-06-12 09:00:00');

        $this->test_multiple_missed_daily_emis_have_separate_dues_and_penalties();
        $loan = Loan::sole();

        CollectionEntry::create([
            'client_id' => $loan->client_id,
            'loan_id' => $loan->id,
            'collection_date' => '2026-06-12',
            'amount_collected' => 10,
            'emi_amount' => 0,
            'penalty_amount' => 0,
            'collection_mode' => 'cash',
            'collected_at' => '2026-06-12 09:00:00',
            'status' => 'approved',
        ]);
        $loan->rebuildCollectionAccounting();

        $this->assertSame(['2026-06-11'], $loan->overdueInstallments()
            ->pluck('due_date')
            ->map->toDateString()
            ->all());
        $this->assertSame(10.0, $loan->overdueEmiAmount());
        $this->assertSame(10.0, $loan->dueTodayAmount());
        $this->assertSame('2026-06-11', $loan->fresh()->next_due_date->toDateString());
    }
}
