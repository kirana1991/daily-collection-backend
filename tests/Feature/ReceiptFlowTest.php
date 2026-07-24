<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CollectionEntry;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_collection_receipt_generation_work(): void
    {
        Carbon::setTestNow('2026-06-12 10:00:00');
        $client = Client::create([
            'client_code' => 'DM-C-0001',
            'name' => 'Test Client',
            'mobile' => '9999999999',
            'address' => 'Test address',
        ]);
        $loan = Loan::create([
            'loan_code' => 'DM-L-0001',
            'client_id' => $client->id,
            'loan_date' => '2026-06-01',
            'loan_amount' => 1000,
            'loan_type' => '100_days',
            'daily_collection_amount' => 10,
            'weekly_emi' => 70,
            'first_emi_date' => '2026-06-10',
            'next_due_date' => '2026-06-10',
            'penalty_per_day' => 50,
            'status' => 'active',
        ]);
        $collection = CollectionEntry::create([
            'client_id' => $client->id,
            'loan_id' => $loan->id,
            'collection_date' => '2026-06-10',
            'amount_collected' => 10,
            'emi_amount' => 0,
            'penalty_amount' => 0,
            'collection_mode' => 'cash',
            'collected_at' => '2026-06-10 09:00:00',
            'status' => 'approved',
        ]);
        $loan->rebuildCollectionAccounting();

        $this->postJson("/api/collections/{$collection->id}/receipt")
            ->assertCreated()
            ->assertJsonPath('receipt.receipt_code', 'DM-R-00001')
            ->assertJsonPath('receipt.collection_entry_id', $collection->id)
            ->assertJsonPath('collection.id', $collection->id)
            ->assertJsonPath('collection.amount_collected', 10);

        $this->postJson("/api/collections/{$collection->id}/receipt")
            ->assertOk()
            ->assertJsonPath('receipt.receipt_code', 'DM-R-00001');

        $this->assertDatabaseHas('receipts', [
            'loan_id' => $loan->id,
            'client_id' => $client->id,
            'collection_entry_id' => $collection->id,
            'receipt_code' => 'DM-R-00001',
        ]);
        $this->assertDatabaseCount('receipts', 1);
    }
}
