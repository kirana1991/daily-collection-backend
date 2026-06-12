<?php

use App\Models\Loan;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('penalties:accrue', function () {
    $synced = 0;

    Loan::query()
        ->where('status', 'active')
        ->whereDate('next_due_date', '<', now()->toDateString())
        ->eachById(function (Loan $loan) use (&$synced): void {
            $synced += $loan->syncCurrentPenalties();
        });

    $this->info("Synchronized {$synced} daily penalty entries.");
})->purpose('Accrue overdue loan penalties using whole calendar days');

Schedule::command('penalties:accrue')->dailyAt('00:05');

Artisan::command('installments:rebuild', function () {
    $rebuilt = 0;

    Loan::query()->eachById(function (Loan $loan) use (&$rebuilt): void {
        $loan->rebuildCollectionAccounting();
        $rebuilt++;
    });

    $this->info("Rebuilt installment accounting for {$rebuilt} loans.");
})->purpose('Rebuild installment schedules, allocations, and penalties for all loans');
