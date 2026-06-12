<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateLoanTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_loan_preserves_selected_first_emi_date(): void
    {
        $client = Client::create([
            'client_code' => 'DM-C-0001',
            'name' => 'Test Client',
            'mobile' => '9999999999',
            'address' => 'Test address',
        ]);
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/loans', [
            'client_id' => $client->id,
            'responsible_user_id' => $user->id,
            'loan_date' => '2026-06-11',
            'loan_amount' => 1000,
            'loan_type' => '100_days',
            'daily_collection_amount' => 10,
            'weekly_emi' => 70,
            'next_due_date' => '2026-06-15',
            'penalty_per_day' => 50,
        ]);

        $response->assertCreated();

        $loan = Loan::where('client_id', $client->id)->sole();

        $this->assertSame('2026-06-15', $loan->next_due_date->toDateString());
    }

    public function test_first_emi_date_cannot_be_before_loan_date(): void
    {
        $client = Client::create([
            'client_code' => 'DM-C-0001',
            'name' => 'Test Client',
            'mobile' => '9999999999',
            'address' => 'Test address',
        ]);
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->postJson('/api/loans', [
            'client_id' => $client->id,
            'responsible_user_id' => $user->id,
            'loan_date' => '2026-06-11',
            'loan_amount' => 1000,
            'loan_type' => 'weekly',
            'weekly_emi' => 100,
            'next_due_date' => '2026-06-10',
        ])->assertUnprocessable()->assertJsonValidationErrors('next_due_date');
    }
}
