<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanPenaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_penalty_uses_whole_overdue_calendar_days_and_is_persisted(): void
    {
        Carbon::setTestNow('2026-06-11 03:30:00');

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
            'loan_type' => 'weekly',
            'weekly_emi' => 100,
            'next_due_date' => '2026-06-09',
            'penalty_per_day' => 50,
            'status' => 'active',
        ]);

        $this->assertSame(100.0, $loan->accruedPenalty());
        $this->assertSame(100.0, $loan->accruedPenalty());

        $penalties = $loan->penalties()->orderBy('penalty_date')->get();

        $this->assertSame(['2026-06-09', '2026-06-09'], $penalties
            ->map(fn ($penalty) => $penalty->emi_due_date->toDateString())
            ->all());
        $this->assertSame(['2026-06-10', '2026-06-11'], $penalties
            ->map(fn ($penalty) => $penalty->penalty_date->toDateString())
            ->all());
        $this->assertSame([1, 1], $penalties->pluck('penalty_days')->all());
        $this->assertEquals([50, 50], $penalties->pluck('penalty_amount')->all());
        $this->assertEquals([0, 0], $penalties->pluck('paid_amount')->all());
        $this->assertSame(['pending', 'pending'], $penalties->pluck('status')->all());
        $this->assertSame(2, $loan->penalties()->count());
    }

    public function test_backdated_on_time_collection_removes_the_automatic_penalty(): void
    {
        Carbon::setTestNow('2026-06-11 09:00:00');

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
            'next_due_date' => '2026-06-10',
            'penalty_per_day' => 50,
            'status' => 'active',
        ]);

        $this->assertSame(50.0, $loan->outstandingPenalty());

        $this->postJson('/api/collections', [
            'loan_id' => $loan->id,
            'user_id' => $user->id,
            'collection_date' => '2026-06-10',
            'amount_collected' => 10,
            'collection_mode' => 'cash',
        ])->assertCreated()->assertJsonPath('penalty_amount', 0);

        $this->assertDatabaseCount('penalties', 0);
        $this->assertSame('2026-06-11', $loan->fresh()->next_due_date->toDateString());
        $this->assertSame(0.0, $loan->fresh()->outstandingPenalty());
    }
}
