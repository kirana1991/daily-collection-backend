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
            ->each(function ($loan): void {
                $latestCollectionDate = DB::table('collection_entries')
                    ->where('loan_id', $loan->id)
                    ->max('collection_date');

                DB::table('loans')
                    ->where('id', $loan->id)
                    ->update([
                        'next_due_date' => $latestCollectionDate
                            ? Carbon::parse($latestCollectionDate)->addDay()->toDateString()
                            : Carbon::parse($loan->loan_date)->toDateString(),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('loans')
            ->where('loan_type', '100_days')
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function ($loan): void {
                $hasCollections = DB::table('collection_entries')
                    ->where('loan_id', $loan->id)
                    ->exists();

                if (! $hasCollections) {
                    DB::table('loans')
                        ->where('id', $loan->id)
                        ->update([
                            'next_due_date' => Carbon::parse($loan->loan_date)->addDay()->toDateString(),
                        ]);
                }
            });
    }
};
