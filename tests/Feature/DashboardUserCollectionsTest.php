<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CollectionEntry;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardUserCollectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_collection_summary_includes_all_user_roles(): void
    {
        Carbon::setTestNow('2026-06-12 10:00:00');
        $admin = $this->createUser('Admin User', 'admin@example.com', 'admin');
        $manager = $this->createUser('Manager User', 'manager@example.com', 'manager');
        $executive = $this->createUser('Executive User', 'executive@example.com', 'collection_executive');
        $loan = $this->createClosedLoan();

        $this->createCollection($loan, $admin, '2026-06-12', 100);
        $this->createCollection($loan, $manager, '2026-06-11', 200);

        $rows = collect($this->getJson('/api/dashboard')
            ->assertOk()
            ->json('collection_by_user'));

        $this->assertCount(3, $rows);
        $this->assertSame(100, $rows->firstWhere('id', $admin->id)['today_collection']);
        $this->assertSame(200, $rows->firstWhere('id', $manager->id)['yesterday_collection']);
        $this->assertSame(0, $rows->firstWhere('id', $executive->id)['month_collection']);
    }

    private function createUser(string $name, string $email, string $role): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createClosedLoan(): Loan
    {
        $client = Client::create([
            'client_code' => 'DM-C-0001',
            'name' => 'Test Client',
            'mobile' => '9999999999',
            'address' => 'Test address',
        ]);

        return Loan::create([
            'loan_code' => 'DM-L-0001',
            'client_id' => $client->id,
            'loan_date' => '2026-06-01',
            'loan_amount' => 1000,
            'loan_type' => 'weekly',
            'weekly_emi' => 100,
            'first_emi_date' => '2026-06-08',
            'next_due_date' => null,
            'status' => 'closed',
        ]);
    }

    private function createCollection(Loan $loan, User $user, string $date, float $amount): void
    {
        CollectionEntry::create([
            'client_id' => $loan->client_id,
            'loan_id' => $loan->id,
            'user_id' => $user->id,
            'collection_date' => $date,
            'amount_collected' => $amount,
            'emi_amount' => $amount,
            'penalty_amount' => 0,
            'collection_mode' => 'cash',
            'collected_at' => "{$date} 09:00:00",
            'status' => 'approved',
        ]);
    }
}
