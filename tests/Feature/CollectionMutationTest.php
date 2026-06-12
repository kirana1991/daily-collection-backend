<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CollectionMutationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_collection_can_be_edited_and_loan_accounting_is_rebuilt(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        [$loan, $user] = $this->createLoanAndUser();
        $collection = $this->createCollection($loan, $user, 10);

        $this->putJson("/api/collections/{$collection['id']}", [
            'collection_date' => '2026-06-10',
            'amount_collected' => 25,
            'collection_mode' => 'upi',
        ])->assertOk()
            ->assertJsonPath('amount_collected', 25)
            ->assertJsonPath('emi_amount', 25)
            ->assertJsonPath('collection_mode', 'upi');

        $this->assertSame(25.0, $loan->fresh()->paidEmi());
        $this->assertSame('2026-06-12', $loan->fresh()->next_due_date->toDateString());
        $this->assertSame('Original remark', $loan->collections()->sole()->remarks);
    }

    public function test_collection_remarks_cannot_be_edited(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        [$loan, $user] = $this->createLoanAndUser();
        $collection = $this->createCollection($loan, $user, 10);

        $this->putJson("/api/collections/{$collection['id']}", [
            'collection_date' => '2026-06-10',
            'amount_collected' => 10,
            'collection_mode' => 'cash',
            'remarks' => 'Changed remark',
        ])->assertUnprocessable()->assertJsonValidationErrors('remarks');

        $this->assertSame('Original remark', $loan->collections()->sole()->remarks);
    }

    public function test_collection_can_be_deleted_and_loan_accounting_is_rebuilt(): void
    {
        Carbon::setTestNow('2026-06-11 10:00:00');
        [$loan, $user] = $this->createLoanAndUser();
        $collection = $this->createCollection($loan, $user, 10);

        $this->deleteJson("/api/collections/{$collection['id']}")->assertNoContent();

        $loan->refresh();

        $this->assertDatabaseCount('collection_entries', 0);
        $this->assertSame(0.0, $loan->paidEmi());
        $this->assertSame('2026-06-10', $loan->next_due_date->toDateString());
        $this->assertSame(50.0, $loan->outstandingPenalty());
    }

    private function createLoanAndUser(): array
    {
        $client = Client::create([
            'client_code' => 'DM-C-0001',
            'name' => 'Test Client',
            'mobile' => '9999999999',
            'address' => 'Test address',
        ]);
        $user = User::create([
            'name' => 'Executive',
            'email' => 'executive@example.com',
            'password' => 'password',
            'role' => 'collection_executive',
            'status' => 'active',
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

        return [$loan, $user];
    }

    private function createCollection(Loan $loan, User $user, float $amount): array
    {
        return $this->postJson('/api/collections', [
            'loan_id' => $loan->id,
            'user_id' => $user->id,
            'collection_date' => '2026-06-10',
            'amount_collected' => $amount,
            'collection_mode' => 'cash',
            'remarks' => 'Original remark',
        ])->assertCreated()->json();
    }
}
