<?php

namespace Tests\Unit;

use App\Models\Loan;
use PHPUnit\Framework\TestCase;

class LoanDueDateTest extends TestCase
{
    public function test_daily_loan_first_due_date_is_the_loan_date(): void
    {
        $loan = new Loan(['loan_type' => '100_days']);

        $this->assertSame('2026-06-09', $loan->firstDueDateFrom('2026-06-09'));
    }

    public function test_daily_loan_next_due_date_is_the_next_day(): void
    {
        $loan = new Loan(['loan_type' => '100_days']);

        $this->assertSame(1, $loan->collectionIntervalDays());
        $this->assertSame('2026-06-10', $loan->nextDueDateFrom('2026-06-09'));
    }

    public function test_weekly_loan_next_due_date_is_seven_days_later(): void
    {
        $loan = new Loan(['loan_type' => 'weekly']);

        $this->assertSame(7, $loan->collectionIntervalDays());
        $this->assertSame('2026-06-16', $loan->nextDueDateFrom('2026-06-09'));
    }
}
