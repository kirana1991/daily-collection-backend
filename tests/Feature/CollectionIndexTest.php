<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CollectionEntry;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CollectionIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_collections_default_to_all_results_with_pagination(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        [$client, $loan] = $this->createLoan();

        $this->createCollection($client, $loan, '2026-06-11', 100);
        $this->createCollection($client, $loan, '2026-06-10', 200);
        $this->createCollection($client, $loan, '2026-05-15', 300);

        $this->getJson('/api/collections?per_page=2')
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('total_amount', 600)
            ->assertJsonCount(2, 'data');
    }

    public function test_collections_can_filter_today_yesterday_and_month(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        [$client, $loan] = $this->createLoan();

        $this->createCollection($client, $loan, '2026-06-11', 100);
        $this->createCollection($client, $loan, '2026-06-10', 200);
        $this->createCollection($client, $loan, '2026-06-01', 300);
        $this->createCollection($client, $loan, '2026-05-31', 400);

        $this->getJson('/api/collections?period=today')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('total_amount', 100);
        $this->getJson('/api/collections?period=yesterday')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('total_amount', 200);
        $this->getJson('/api/collections?period=month')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('total_amount', 600);
    }

    private function createLoan(): array
    {
        $client = Client::create([
            'client_code' => 'DM-C-0001',
            'name' => 'Test Client',
            'mobile' => '9999999999',
            'address' => 'Test address',
        ]);
        $loan = Loan::create([
            'loan_code' => 'DM-L-0001',
            'client_id' => $client->id,
            'loan_date' => '2026-01-01',
            'loan_amount' => 1000,
            'loan_type' => 'weekly',
            'weekly_emi' => 100,
            'next_due_date' => '2026-01-08',
            'status' => 'active',
        ]);

        return [$client, $loan];
    }

    private function createCollection(Client $client, Loan $loan, string $date, float $amount): void
    {
        CollectionEntry::create([
            'client_id' => $client->id,
            'loan_id' => $loan->id,
            'collection_date' => $date,
            'amount_collected' => $amount,
            'emi_amount' => $amount,
            'penalty_amount' => 0,
            'collection_mode' => 'cash',
            'collected_at' => "{$date} 10:00:00",
            'status' => 'approved',
        ]);
    }
}
