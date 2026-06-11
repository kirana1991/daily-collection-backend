<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('loans')
            ->where('loan_type', '100_days')
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function ($loan) {
                $latestCollectionDate = DB::table('collection_entries')
                    ->where('loan_id', $loan->id)
                    ->max('collection_date');

                $baseDate = $latestCollectionDate ?: $loan->loan_date;

                DB::table('loans')
                    ->where('id', $loan->id)
                    ->update([
                        'next_due_date' => Carbon::parse($baseDate)->addDay()->toDateString(),
                    ]);
            });
    }

    public function down(): void
    {
        // The previous due dates cannot be reconstructed safely.
    }
};
